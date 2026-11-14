<?php
/**
 * Apply a profile's declarative content: config, then seed.
 *
 * Runs via `cv scr` from apply.sh; the profile path comes in CK_PROFILE_JSON
 * (`cv scr` has no argv passthrough).
 *   config  converges on every apply: whatever differs is written back;
 *           anonymous_permissions are added, never revoked.
 *   seed    creates each record once, matched by its anchor (contacts by
 *           external_identifier = ref, contributions by a synthetic trxn_id),
 *           and never touches an existing one, so edits in the UI survive.
 * Every missing prerequisite is listed before anything is written, except
 * that settings and components go first: they must be on to check the rest.
 */

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\Domain;
use Civi\Api4\Email;
use Civi\Api4\FinancialType;
use Civi\Api4\Group;
use Civi\Api4\GroupContact;
use Civi\Api4\MailSettings;
use Civi\Api4\MembershipType;
use Civi\Api4\Permission;
use Civi\Api4\Setting;
use Civi\Api4\SiteEmailAddress;
use CiviKitchen\Toolbelt\Runtime\ProfileData;

require_once dirname(__DIR__, 2) . '/toolbelt/lib/php/bootstrap.php';
require_once __DIR__ . '/cms-permissions.php';

$file = getenv('CK_PROFILE_JSON');
if (!$file || !is_readable($file)) {
  throw new \RuntimeException('apply-content: CK_PROFILE_JSON not set or unreadable: ' . var_export($file, TRUE));
}
$profile = (new ProfileData())->load($file);
$config = $profile['config'] ?? [];
$seed = $profile['seed'] ?? [];
if ($config === [] && $seed === []) {
  return;
}
$domainId = CRM_Core_Config::domainID();

/** The seed key of the n-th contribution (1-based): its ref, else contact ref and position. */
function ck_content_trxn_id(array $contribution, int $position): string {
  return 'profile-seed-' . ($contribution['ref'] ?? $contribution['contact_ref'] . '-' . $position);
}

/** Fields of $values the entity's create action does not know; `field:name` counts as `field`. */
function ck_content_unknown_fields(string $entity, array $values): array {
  static $known = [];
  $known[$entity] ??= array_flip(civicrm_api4($entity, 'getFields', [
    'checkPermissions' => FALSE,
    'action' => 'create',
    'select' => ['name'],
  ])->column('name'));
  return array_values(array_filter(array_keys($values), static fn(string $field): bool =>
    !isset($known[$entity][explode(':', $field, 2)[0]])));
}

/** Throw with every problem listed, if there is one. */
function ck_content_fail(string $file, array $missing): void {
  if ($missing !== []) {
    throw new \RuntimeException("profile {$file} cannot apply:\n  - " . implode("\n  - ", $missing));
  }
}

// ── preflight 1: settings and components, before anything is written ──
// An optional component's APIs (FinancialType, Contribution, MailSettings,
// MembershipType) do not exist while it is off, so those checks wait for step 2.
$missing = [];
$settingNames = array_keys(\Civi\Core\SettingsMetadata::getMetadata([], $domainId));
foreach (array_diff(array_keys($config['settings'] ?? []), $settingNames) as $name) {
  $missing[] = "config.settings: unknown setting {$name}";
}
// enableComponent() only returns FALSE for a name core does not know.
$knownComponents = array_keys(CRM_Core_Component::getComponents());
foreach (array_diff($config['components'] ?? [], $knownComponents) as $name) {
  $missing[] = "config.components: unknown component {$name}";
}
$components = array_merge($config['settings']['enable_components'] ?? Civi::settings()->get('enable_components'), $config['components'] ?? []);
$needs = [
  'CiviMember' => empty($config['membership_types']) ? NULL : 'config.membership_types',
  'CiviContribute' => empty($config['membership_types']) && empty($seed['contributions']) ? NULL : 'config.membership_types, seed.contributions',
  'CiviMail' => isset($config['mailbox_domain']) ? 'config.mailbox_domain' : NULL,
];
foreach (array_filter($needs) as $component => $user) {
  if (!in_array($component, $components, TRUE)) {
    $missing[] = "{$user}: the {$component} component is not enabled (config.components)";
  }
}
ck_content_fail($file, $missing);

// ── config: settings and components first, so they are on for the rest ──
$changed = [];
$settings = array_filter($config['settings'] ?? [], static fn(mixed $value, string $name): bool =>
  Civi::settings()->get($name) != $value, ARRAY_FILTER_USE_BOTH);
if ($settings !== []) {
  Setting::set(FALSE)->setValues($settings)->execute();
  $changed[] = 'settings ' . implode(',', array_keys($settings));
}
// Additive, so composed profiles keep each other's components.
$enable = array_values(array_diff($config['components'] ?? [], Civi::settings()->get('enable_components')));
foreach ($enable as $name) {
  CRM_Core_BAO_ConfigSetting::enableComponent($name);
}
if ($enable !== []) {
  $changed[] = 'components ' . implode(',', $enable);
}

// ── preflight 2: everything the remaining writes need ──
if (!empty($config['anonymous_permissions'])) {
  // Runs after the components are on: their permissions count as active only then.
  $active = Permission::get(FALSE)->addSelect('name')->addWhere('group', '=', 'civicrm')
    ->addWhere('is_active', '=', TRUE)->execute()->column('name');
  foreach (array_diff($config['anonymous_permissions'], $active) as $name) {
    $missing[] = "config.anonymous_permissions: {$name} is no active CiviCRM permission";
  }
}
$groupNames = [];
foreach ($config['groups'] ?? [] as $group) {
  $groupNames[$group['name']] = $group['name'];
  if (isset($group['ref'])) {
    $groupNames[$group['ref']] = $group['name'];
  }
}
$financialTypes = [];
foreach ($config['membership_types'] ?? [] as $type) {
  $financialTypes[$type['financial_type']] = "config.membership_types {$type['name']}";
  if (($type['period_type'] ?? 'rolling') === 'fixed' && !isset($type['fixed_period_start_day'])) {
    $missing[] = "config.membership_types {$type['name']}: a fixed period needs fixed_period_start_day";
  }
}
$contactRefs = array_column($seed['contacts'] ?? [], 'ref');
foreach ($seed['contacts'] ?? [] as $contact) {
  $fields = array_diff_key($contact, array_flip(['ref', 'email', 'groups']));
  foreach (ck_content_unknown_fields('Contact', $fields) as $field) {
    $missing[] = "seed.contacts {$contact['ref']}: unknown Contact field {$field}";
  }
  if (isset($contact['external_identifier'])) {
    $missing[] = "seed.contacts {$contact['ref']}: external_identifier is the ref; drop it";
  }
  foreach ($contact['groups'] ?? [] as $token) {
    if (!isset($groupNames[$token]) && Group::get(FALSE)->addWhere('name', '=', $token)->execute()->count() === 0) {
      $missing[] = "seed.contacts {$contact['ref']}: no group with ref or name {$token}";
    }
  }
}
$trxnIds = [];
foreach ($seed['contributions'] ?? [] as $index => $contribution) {
  $trxnId = ck_content_trxn_id($contribution, $index + 1);
  $label = "seed.contributions {$trxnId}";
  if (isset($trxnIds[$trxnId])) {
    $missing[] = "{$label}: the same ref twice";
  }
  $trxnIds[$trxnId] = TRUE;
  $fields = array_diff_key($contribution, array_flip(['ref', 'contact_ref']));
  foreach (ck_content_unknown_fields('Contribution', $fields) as $field) {
    $missing[] = "{$label}: unknown Contribution field {$field}";
  }
  foreach (['contact_id', 'trxn_id', 'financial_type_id'] as $reserved) {
    if (array_key_exists($reserved, $fields)) {
      $missing[] = "{$label}: {$reserved} cannot be set here" . ($reserved === 'financial_type_id' ? '; use financial_type_id:name' : '');
    }
  }
  if (isset($contribution['financial_type_id:name'])) {
    $financialTypes[$contribution['financial_type_id:name']] = $label;
  }
  else {
    $missing[] = "{$label}: financial_type_id:name is required";
  }
  if (!in_array($contribution['contact_ref'], $contactRefs, TRUE)
    && Contact::get(FALSE)->addWhere('external_identifier', '=', $contribution['contact_ref'])
      ->addWhere('is_deleted', 'IN', [TRUE, FALSE])->execute()->count() === 0) {
    $missing[] = "{$label}: no seed contact with ref {$contribution['contact_ref']}";
  }
}
if ($financialTypes !== []) {
  $knownFinancialTypes = FinancialType::get(FALSE)->addSelect('name')->execute()->column('name');
  foreach (array_diff_key($financialTypes, array_flip($knownFinancialTypes)) as $name => $user) {
    $missing[] = "{$user}: unknown financial type {$name}";
  }
}
$mailSettings = isset($config['mailbox_domain']) ? MailSettings::get(FALSE)->addSelect('id', 'domain')
  ->addWhere('domain_id', '=', $domainId)->addWhere('is_default', '=', TRUE)->execute()->first() : NULL;
if (isset($config['mailbox_domain']) && $mailSettings === NULL) {
  $missing[] = 'config.mailbox_domain: this domain has no default mail account';
}
ck_content_fail($file, $missing);

// ── config: write back whatever else differs ──
$domain = Domain::get(FALSE)->addSelect('name', 'contact_id', 'contact_id.organization_name')
  ->addWhere('id', '=', $domainId)->execute()->single();
if (isset($config['domain_name'])) {
  // As core's domain form does: the domain and its organization share the name.
  if ($domain['name'] !== $config['domain_name']) {
    Domain::update(FALSE)->addWhere('id', '=', $domainId)->addValue('name', $config['domain_name'])->execute();
    $changed[] = 'domain_name';
  }
  if ($domain['contact_id.organization_name'] !== $config['domain_name']) {
    Contact::update(FALSE)->addWhere('id', '=', $domain['contact_id'])
      ->addValue('organization_name', $config['domain_name'])->execute();
    $changed[] = 'domain organization';
  }
}
if (isset($config['from_email'])) {
  $from = SiteEmailAddress::get(FALSE)->addSelect('id', 'email', 'display_name')
    ->addWhere('domain_id', '=', $domainId)->addWhere('is_default', '=', TRUE)->execute()->first();
  $wanted = ['email' => $config['from_email'], 'display_name' => $config['domain_name'] ?? $from['display_name'] ?? $domain['name']];
  if ($from === NULL) {
    SiteEmailAddress::create(FALSE)->setValues($wanted + ['domain_id' => $domainId, 'is_default' => TRUE, 'is_active' => TRUE])->execute();
    $changed[] = 'from_email';
  }
  elseif (array_intersect_key($from, $wanted) != $wanted) {
    SiteEmailAddress::update(FALSE)->addWhere('id', '=', $from['id'])->setValues($wanted)->execute();
    $changed[] = 'from_email';
  }
}
if (isset($config['mailbox_domain']) && $mailSettings['domain'] !== $config['mailbox_domain']) {
  MailSettings::update(FALSE)->addWhere('id', '=', $mailSettings['id'])->addValue('domain', $config['mailbox_domain'])->execute();
  $changed[] = 'mailbox_domain';
}
foreach ($config['groups'] ?? [] as $group) {
  $title = $group['title'] ?? $group['name'];
  $current = Group::get(FALSE)->addSelect('title')->addWhere('name', '=', $group['name'])->execute()->first();
  if ($current === NULL || $current['title'] !== $title) {
    Group::save(FALSE)->addRecord(['name' => $group['name'], 'title' => $title])->setMatch(['name'])->execute();
    $changed[] = "group {$group['name']}";
  }
}
foreach ($config['membership_types'] ?? [] as $type) {
  $wanted = [
    'name' => $type['name'],
    'member_of_contact_id' => $domain['contact_id'],
    'financial_type_id:name' => $type['financial_type'],
    'period_type' => $type['period_type'] ?? 'rolling',
    'duration_unit' => $type['duration_unit'] ?? 'year',
    'duration_interval' => $type['duration_interval'] ?? 1,
    'minimum_fee' => $type['minimum_fee'] ?? 0,
    'is_active' => $type['is_active'] ?? TRUE,
  ];
  if ($wanted['period_type'] === 'fixed') {
    $wanted['fixed_period_start_day'] = $type['fixed_period_start_day'];
  }
  $current = MembershipType::get(FALSE)->setSelect(array_keys($wanted))->addWhere('name', '=', $type['name'])->execute()->first();
  // Loose on purpose: the API returns minimum_fee as "0.00" and integers as strings.
  if ($current === NULL || array_intersect_key($current, $wanted) != $wanted) {
    MembershipType::save(FALSE)->addRecord($wanted)->setMatch(['name'])->execute();
    $changed[] = "membership type {$type['name']}";
  }
}

if (!empty($config['anonymous_permissions'])) {
  // The CMS learns the permissions of components switched on above only from a flush.
  if ($enable !== []) {
    civicrm_api3('System', 'flush');
  }
  ck_grant_anonymous(CRM_Core_Config::singleton()->userFramework, $config['anonymous_permissions']);
  echo '  anonymous visitors may: ', implode(', ', $config['anonymous_permissions']), "\n";
}

// ── seed: create what is missing, leave what exists ──
$created = ['contacts' => 0, 'contributions' => 0];
foreach ($seed['contacts'] ?? [] as $contact) {
  $exists = Contact::get(FALSE)->addWhere('external_identifier', '=', $contact['ref'])
    ->addWhere('is_deleted', 'IN', [TRUE, FALSE])->execute()->count() > 0;
  if ($exists) {
    continue;
  }
  CRM_Core_Transaction::create()->run(static function () use ($contact, $groupNames): void {
    $fields = array_diff_key($contact, array_flip(['ref', 'email', 'groups']));
    $id = Contact::create(FALSE)->setValues(['external_identifier' => $contact['ref']] + $fields + ['contact_type' => 'Individual'])
      ->execute()->single()['id'];
    if (isset($contact['email'])) {
      Email::create(FALSE)->setValues([
        'contact_id' => $id,
        'email' => $contact['email'],
        'is_primary' => TRUE,
        'location_type_id:name' => 'Home',
      ])->execute();
    }
    foreach ($contact['groups'] ?? [] as $token) {
      $group = Group::get(FALSE)->addSelect('id')->addWhere('name', '=', $groupNames[$token] ?? $token)->execute()->single();
      GroupContact::create(FALSE)->setValues(['contact_id' => $id, 'group_id' => $group['id'], 'status' => 'Added'])->execute();
    }
  });
  $created['contacts']++;
}
foreach ($seed['contributions'] ?? [] as $index => $contribution) {
  $trxnId = ck_content_trxn_id($contribution, $index + 1);
  // trxn_id is a unique key, so get skips its is_test = 0 default and finds test rows too.
  if (Contribution::get(FALSE)->addWhere('trxn_id', '=', $trxnId)->execute()->count() > 0) {
    continue;
  }
  $contactId = Contact::get(FALSE)->addSelect('id')->addWhere('external_identifier', '=', $contribution['contact_ref'])
    ->addWhere('is_deleted', 'IN', [TRUE, FALSE])->execute()->single()['id'];
  Contribution::create(FALSE)
    ->setValues(['contact_id' => $contactId, 'trxn_id' => $trxnId] + array_diff_key($contribution, array_flip(['ref', 'contact_ref'])))
    ->execute();
  $created['contributions']++;
}

echo '  config: ', $changed === [] ? 'unchanged' : 'set ' . implode('; ', $changed), "\n";
echo "  seed: {$created['contacts']} contact(s), {$created['contributions']} contribution(s) created\n";
