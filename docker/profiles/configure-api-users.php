<?php
/**
 * Configure API users from profile.json (apiUsers + authx sections).
 *
 * Runs via `cv scr` from apply.sh. cv boots CiviCRM *and* the host CMS, so
 * one script covers every user framework natively — no drush, no wp-cli:
 *   Drupal8    → Drupal entity API (\Drupal\user\Entity\User / Role)
 *   Backdrop   → user_save() / user_role_save() / user_role_grant_permissions()
 *   WordPress  → wp_insert_user() / add_role() / WP_Role::add_cap()
 *   Standalone → standaloneusers User/Role APIv4 entities
 *
 * Input: path to profile.json via the CK_PROFILE_JSON env var (`cv scr` has
 * no argv passthrough). Idempotent: load-or-create everywhere, so a re-run
 * after an aborted first boot converges instead of duplicating. Fails loudly:
 * an uncaught exception makes cv exit non-zero and apply.sh abort.
 */

$configFile = getenv('CK_PROFILE_JSON');
if (!$configFile || !is_readable($configFile)) {
  throw new \RuntimeException('configure-api-users: CK_PROFILE_JSON not set or unreadable: ' . var_export($configFile, TRUE));
}
$config = json_decode(file_get_contents($configFile), TRUE, 512, JSON_THROW_ON_ERROR);
$credentialsHelper = __DIR__ . '/credentials.php';
if (!is_readable($credentialsHelper)) {
  throw new \RuntimeException("configure-api-users: missing credentials helper {$credentialsHelper}");
}
require_once $credentialsHelper;
$credentialsOutput = ck_credentials_output_mode();
$apiUsers = $config['apiUsers'] ?? [];
$uf = CRM_Core_Config::singleton()->userFramework;
if (!in_array($uf, ['Drupal8', 'Backdrop', 'WordPress', 'Standalone', 'Joomla'], TRUE)) {
  throw new \RuntimeException("configure-api-users: unsupported user framework '{$uf}'");
}
echo "  🔑 Configuring API access ({$uf})...\n";

/**
 * CiviCRM permission → WordPress capability — the same mapping core applies
 * in CRM_Core_Permission_WordPress::check(): munge(strtolower($perm)).
 */
function ck_wp_cap(string $perm): string {
  return CRM_Utils_String::munge(strtolower($perm));
}

/**
 * Grant permissions to a Drupal role (created on demand). Unknown permission
 * names fail closed: extensions are enabled before this script, so Drupal's
 * inventory is authoritative and a typo must not yield a marker-complete site.
 */
function ck_drupal_grant(string $roleId, array $perms, bool $exact = FALSE): void {
  $role = \Drupal\user\Entity\Role::load($roleId)
    ?: \Drupal\user\Entity\Role::create(['id' => $roleId, 'label' => $roleId]);
  $known = array_keys(\Drupal::service('user.permissions')->getPermissions());
  if ($exact) {
    foreach (array_diff($role->getPermissions(), $perms) as $stale) {
      $role->revokePermission($stale);
    }
  }
  foreach ($perms as $perm) {
    if (in_array($perm, $known, TRUE)) {
      $role->grantPermission($perm);
    }
    else {
      throw new \RuntimeException("configure-api-users: unknown Drupal permission '{$perm}' for role '{$roleId}'");
    }
  }
  $role->save();
}

/** Backdrop counterpart of ck_drupal_grant(): same fail-closed inventory check. */
function ck_backdrop_grant(string $roleName, array $perms): void {
  $role = user_role_load($roleName) ?: (object) ['name' => $roleName, 'label' => $roleName];
  $known = array_keys(user_permission_get_modules());
  foreach ($perms as $perm) {
    if (!in_array($perm, $known, TRUE)) {
      throw new \RuntimeException("configure-api-users: unknown Backdrop permission '{$perm}' for role '{$roleName}'");
    }
  }
  $role->permissions = array_values(array_unique($perms));
  user_role_save($role);
}

const CK_PROFILE_CONTACT_SOURCE = 'CiviKitchen profile API user';
const CK_PROFILE_ROLE_OPTION_GROUP = 'civikitchen_managed_api_roles';

/** @return array<string,int> role name => ownership OptionValue id */
function ck_owned_roles(): array {
  $group = \Civi\Api4\OptionGroup::get(FALSE)
    ->addWhere('name', '=', CK_PROFILE_ROLE_OPTION_GROUP)
    ->addSelect('id')->execute()->first();
  if (!$group) return [];
  $owned = [];
  foreach (\Civi\Api4\OptionValue::get(FALSE)
    ->addWhere('option_group_id', '=', $group['id'])
    ->addSelect('id', 'name')->execute() as $value) {
    $owned[(string) $value['name']] = (int) $value['id'];
  }
  return $owned;
}

function ck_claim_roles(array $roleNames): void {
  if (!$roleNames) return;
  $groupId = \Civi\Api4\OptionGroup::save(FALSE)->setRecords([[
    'name' => CK_PROFILE_ROLE_OPTION_GROUP,
    'title' => 'CiviKitchen managed API roles',
    'data_type' => 'String',
    'is_active' => TRUE,
  ]])->setMatch(['name'])->execute()->first()['id'];
  foreach ($roleNames as $roleName) {
    \Civi\Api4\OptionValue::save(FALSE)->setRecords([[
      'option_group_id' => $groupId,
      'name' => $roleName,
      'label' => $roleName,
      'value' => $roleName,
      'is_active' => TRUE,
    ]])->setMatch(['option_group_id', 'name'])->execute();
  }
}

function ck_role_exists(string $uf, string $roleName): bool {
  if ($uf === 'Drupal8') return (bool) \Drupal\user\Entity\Role::load($roleName);
  if ($uf === 'Backdrop') return (bool) user_role_load($roleName);
  if ($uf === 'WordPress') return (bool) get_role($roleName);
  if ($uf === 'Standalone') {
    return (bool) \Civi\Api4\Role::get(FALSE)
      ->addWhere('name', '=', $roleName)->addSelect('id')->execute()->first();
  }
  $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
  return (bool) $db->setQuery(
    $db->getQuery(TRUE)->select('id')->from('#__usergroups')
      ->where($db->quoteName('title') . ' = ' . $db->quote($roleName))
  )->loadResult();
}

function ck_delete_owned_role(string $uf, string $roleName): void {
  if ($uf === 'Drupal8') {
    if ($role = \Drupal\user\Entity\Role::load($roleName)) $role->delete();
  }
  elseif ($uf === 'Backdrop') {
    if (user_role_load($roleName)) user_role_delete($roleName);
  }
  elseif ($uf === 'WordPress') {
    remove_role($roleName);
  }
  elseif ($uf === 'Standalone') {
    $role = \Civi\Api4\Role::get(FALSE)
      ->addWhere('name', '=', $roleName)->addSelect('id')->execute()->first();
    if ($role) \Civi\Api4\Role::delete(FALSE)->addWhere('id', '=', $role['id'])->execute();
  }
  else {
    $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
    $gid = (int) $db->setQuery(
      $db->getQuery(TRUE)->select('id')->from('#__usergroups')
        ->where($db->quoteName('title') . ' = ' . $db->quote($roleName))
    )->loadResult();
    if ($gid) {
      $group = new \Joomla\CMS\Table\Usergroup($db);
      if (!$group->delete($gid)) {
        throw new \RuntimeException("configure-api-users: could not delete stale Joomla role '{$roleName}'");
      }
    }
  }
}

function ck_contact_is_profile_managed(int $contactId): bool {
  $contact = \Civi\Api4\Contact::get(FALSE)
    ->addWhere('id', '=', $contactId)
    ->addSelect('source')
    ->execute()->first();
  return ($contact['source'] ?? NULL) === CK_PROFILE_CONTACT_SOURCE;
}

/**
 * Return the managed contact behind an existing CMS username, or NULL when
 * that username is unused. An existing unmanaged account is a hard collision:
 * profile data must never reset its password, roles, groups, email or UFMatch.
 */
function ck_existing_profile_account_contact(string $uf, string $username): ?int {
  $uid = 0;
  if ($uf === 'Drupal8') {
    $accounts = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $username]);
    $uid = $accounts ? (int) reset($accounts)->id() : 0;
  }
  elseif ($uf === 'Backdrop') {
    $account = user_load_by_name($username);
    $uid = $account ? (int) $account->uid : 0;
  }
  elseif ($uf === 'WordPress') {
    $uid = (int) username_exists($username);
  }
  elseif ($uf === 'Joomla') {
    $uid = (int) \Joomla\CMS\User\UserHelper::getUserId($username);
  }
  elseif ($uf === 'Standalone') {
    $user = \Civi\Api4\User::get(FALSE)
      ->addWhere('username', '=', $username)
      ->addSelect('contact_id')
      ->execute()->first();
    if (!$user) return NULL;
    $contactId = (int) ($user['contact_id'] ?? 0);
    if ($contactId <= 0 || !ck_contact_is_profile_managed($contactId)) {
      throw new \RuntimeException("configure-api-users: username '{$username}' belongs to an unmanaged Standalone account");
    }
    return $contactId;
  }
  if ($uid <= 0) return NULL;
  $match = \Civi\Api4\UFMatch::get(FALSE)
    ->addWhere('uf_id', '=', $uid)
    ->addSelect('contact_id')
    ->execute()->first();
  $contactId = (int) ($match['contact_id'] ?? 0);
  if ($contactId <= 0 || !ck_contact_is_profile_managed($contactId)) {
    throw new \RuntimeException("configure-api-users: username '{$username}' belongs to an unmanaged {$uf} account");
  }
  return $contactId;
}

/** Deactivate managed identities removed from the complete selected set. */
function ck_reconcile_stale_profile_accounts(string $uf, array $desiredUsernames): void {
  $desired = array_fill_keys($desiredUsernames, TRUE);
  $contacts = \Civi\Api4\Contact::get(FALSE)
    ->addWhere('source', '=', CK_PROFILE_CONTACT_SOURCE)
    ->addSelect('id')
    ->execute();
  foreach ($contacts as $contact) {
    $contactId = (int) $contact['id'];
    $uid = 0;
    $username = '';
    if ($uf === 'Standalone') {
      $user = \Civi\Api4\User::get(FALSE)
        ->addWhere('contact_id', '=', $contactId)
        ->addSelect('id', 'username')
        ->execute()->first();
      if ($user) {
        $uid = (int) $user['id'];
        $username = (string) $user['username'];
      }
    }
    else {
      $match = \Civi\Api4\UFMatch::get(FALSE)
        ->addWhere('contact_id', '=', $contactId)
        ->addSelect('uf_id')
        ->execute()->first();
      $uid = (int) ($match['uf_id'] ?? 0);
      if ($uid > 0 && $uf === 'Drupal8') {
        $account = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
        $username = $account ? (string) $account->getAccountName() : '';
        if (!$account) $uid = 0;
      }
      elseif ($uid > 0 && $uf === 'Backdrop') {
        $account = user_load($uid);
        $username = $account ? (string) $account->name : '';
        if (!$account) $uid = 0;
      }
      elseif ($uid > 0 && $uf === 'WordPress') {
        $account = get_userdata($uid);
        $username = $account ? (string) $account->user_login : '';
        if (!$account) $uid = 0;
      }
      elseif ($uid > 0 && $uf === 'Joomla') {
        $account = \Joomla\CMS\Factory::getContainer()
          ->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($uid);
        $username = $account ? (string) $account->username : '';
        if (!$account) $uid = 0;
      }
    }
    if ($username !== '' && isset($desired[$username])) continue;

    if ($uid > 0 && $uf === 'Standalone') {
      \Civi\Api4\User::update(FALSE)->addWhere('id', '=', $uid)
        ->addValue('is_active', FALSE)->addValue('roles', [])->execute();
    }
    elseif ($uid > 0 && $uf === 'Drupal8') {
      foreach ($account->getRoles(TRUE) as $roleName) {
        if (str_starts_with($roleName, 'civikitchen_')) $account->removeRole($roleName);
      }
      $account->block();
      $account->save();
    }
    elseif ($uid > 0 && $uf === 'Backdrop') {
      $account->roles = array_values(array_filter($account->roles, fn($roleName) => !str_starts_with($roleName, 'civikitchen_')));
      $account->status = 0;
      user_save($account);
    }
    elseif ($uid > 0 && $uf === 'WordPress') {
      (new \WP_User($uid))->set_role('');
      wp_set_password(bin2hex(random_bytes(24)), $uid);
    }
    elseif ($uid > 0 && $uf === 'Joomla') {
      $account->block = 1;
      $account->groups = [2];
      if (!$account->save()) {
        throw new \RuntimeException("configure-api-users: could not deactivate stale Joomla user {$uid}");
      }
    }
    \Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $contactId)
      ->addValue('api_key', NULL)
      ->execute();
    echo "     Deactivated stale managed API user: " . ($username ?: "contact#{$contactId}") . "\n";
  }
}

$desiredRoles = array_values(array_unique(array_column($apiUsers, 'role')));
$ownedRoles = ck_owned_roles();
$rolePermissions = [];
foreach ($apiUsers as $spec) {
  $rolePermissions[$spec['role']] = array_values(array_unique(array_merge(
    $rolePermissions[$spec['role']] ?? [],
    $spec['permissions'] ?? []
  )));
}

// Establish ownership and validate every permission for the complete desired
// set before making any changes. In particular, do not deactivate stale users
// and only then discover a username/role collision or typo.
$knownPermissions = [];
foreach (\Civi\Api4\Permission::get(FALSE)->addSelect('name')->execute() as $permission) {
  $knownPermissions[] = (string) $permission['name'];
}
foreach ($apiUsers as $spec) {
  ck_existing_profile_account_contact($uf, $spec['username']);
  foreach ($spec['permissions'] ?? [] as $permission) {
    if (!in_array($permission, $knownPermissions, TRUE)) {
      throw new \RuntimeException("configure-api-users: unknown CiviCRM permission '{$permission}'");
    }
  }
}
foreach ($desiredRoles as $roleName) {
  if (ck_role_exists($uf, $roleName) && !isset($ownedRoles[$roleName])) {
    throw new \RuntimeException("configure-api-users: role '{$roleName}' already exists but is not CiviKitchen-owned");
  }
}

// Credentials are reconciled even when the desired user set is empty.
$credFile = getenv('CK_CREDENTIALS_FILE') ?: ((getenv('HOME') ?: '/home/buildkit') . '/api-credentials.txt');
$credLines = ck_credentials_writes_file($credentialsOutput) ? ck_credentials_read_file($credFile) : [];
if (getenv('CK_RECONCILE_API_USERS') === '1') {
  $credLines = array_intersect_key($credLines, array_fill_keys(array_column($apiUsers, 'username'), TRUE));
}
if (ck_credentials_writes_file($credentialsOutput)) {
  ck_credentials_write_file($credFile, $credLines);
}
else {
  ck_credentials_remove_file($credFile);
}

ck_claim_roles($desiredRoles);
$staleOwnedRoles = [];
if (getenv('CK_RECONCILE_API_USERS') === '1') {
  ck_reconcile_stale_profile_accounts($uf, array_column($apiUsers, 'username'));
  $staleOwnedRoles = array_values(array_diff(array_keys($ownedRoles), $desiredRoles));
}

// === AuthX: one policy for the entire selected profile set ===
// A set with neither API users nor an AuthX policy leaves the site's AuthX as it is.
$authxPolicy = getenv('CK_AUTHX_HEADER_CRED');
$authPermissions = [];
if ($apiUsers || ($authxPolicy !== FALSE && $authxPolicy !== '') || isset($config['authx']['header_cred'])) {
  echo "     Configuring AuthX...\n";
  $authxHeaderCred = !$apiUsers ? [] : ($authxPolicy !== FALSE && $authxPolicy !== ''
    ? explode(',', $authxPolicy)
    : ($config['authx']['header_cred'] ?? ['jwt', 'api_key']));
  if (array_diff($authxHeaderCred, ['jwt', 'api_key', 'pass']) !== []) {
    throw new \RuntimeException('configure-api-users: invalid CK_AUTHX_HEADER_CRED policy');
  }
  if ($apiUsers && !in_array('api_key', $authxHeaderCred, TRUE) && !in_array('pass', $authxHeaderCred, TRUE)) {
    throw new \RuntimeException('configure-api-users: API users need api_key or pass; JWT credentials are not generated');
  }
  if ($apiUsers && $uf === 'Joomla' && !in_array('api_key', $authxHeaderCred, TRUE)) {
    throw new \RuntimeException('configure-api-users: Joomla API users require api_key authentication');
  }
  \Civi::settings()->set('authx_header_cred', $authxHeaderCred);
  if (in_array('pass', $authxHeaderCred, TRUE)) {
    $authPermissions[] = 'authenticate with password';
  }
  if (in_array('api_key', $authxHeaderCred, TRUE)) {
    $authPermissions[] = 'authenticate with api key';
  }
  // Make CiviCRM's permission list known to the CMS before granting any.
  civicrm_api3('System', 'flush');

  // === Per-CMS prep: AuthX permissions for the administrator role ===
  switch ($uf) {
    case 'Drupal8':
      // AuthX's perm guard requires one permission per credential type. Grant it
      // only to the roles that should expose API authentication; granting it to
      // Drupal's global `authenticated` role would expand the profile's access
      // policy to every existing CMS account.
      $adminRole = \Drupal\user\Entity\Role::load('administrator');
      if ($adminRole) {
        foreach (['authenticate with password', 'authenticate with api key'] as $permission) {
          $adminRole->revokePermission($permission);
        }
        foreach ($authPermissions as $permission) $adminRole->grantPermission($permission);
        $adminRole->grantPermission('access CiviCRM');
        $adminRole->grantPermission('administer CiviCRM');
        $adminRole->save();
      }
      break;

    case 'Backdrop':
      if (user_role_load('administrator')) {
        user_role_revoke_permissions('administrator', ['authenticate with password', 'authenticate with api key']);
        user_role_grant_permissions('administrator', array_merge($authPermissions, ['access CiviCRM', 'administer CiviCRM']));
      }
      break;

    case 'WordPress':
      if ($adminRole = get_role('administrator')) {
        foreach (['authenticate with password', 'authenticate with api key'] as $authPermission) {
          $adminRole->remove_cap(ck_wp_cap($authPermission));
        }
        foreach ($authPermissions as $authPermission) {
          $adminRole->add_cap(ck_wp_cap($authPermission));
        }
      }
      break;

    case 'Standalone':
      // Built-in admin role already carries every permission; the per-user
      // roles below get "authenticate with password" added explicitly.
      break;

    case 'Joomla':
      // CRM_Core_Permission_Joomla checks permissions via $user->authorise(perm,
      // 'com_civicrm'), which needs a com_civicrm ACL asset. civibuild never
      // registers the component (its install script can't run on the layout), so
      // that asset is absent — create it the way Joomla's installer would, once,
      // so the per-user grants below have something to attach to. Idempotent.
      $jdb = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
      $civiAsset = new \Joomla\CMS\Table\Asset($jdb);
      if (!$civiAsset->loadByName('com_civicrm')) {
        $civiAsset->name = 'com_civicrm';
        $civiAsset->title = 'CiviCRM';
        $civiAsset->setLocation((new \Joomla\CMS\Table\Asset($jdb))->getRootId(), 'last-child');
        $civiAsset->rules = '{}';
        if (!$civiAsset->check() || !$civiAsset->store()) {
          throw new \RuntimeException('configure-api-users: could not create com_civicrm ACL asset: ' . $civiAsset->getError());
        }
      }
      break;
  }
}

// === Create the API users ===
echo "  👥 Creating API users...\n";

$credentials = [];
foreach ($apiUsers as $spec) {
  $username = $spec['username'];
  $roleName = $spec['role'];
  $perms = $rolePermissions[$roleName];
  $password = ck_credentials_password();
  $email = "{$username}@example.org";
  echo "     Processing user: {$username} (role: {$roleName})\n";

  // Reuse only identities CiviKitchen previously created. Email alone is not
  // ownership proof: a profile must not seize a real contact or CMS account.
  $contactId = ck_existing_profile_account_contact($uf, $username);
  if (!$contactId) {
    $contactId = \Civi\Api4\Contact::get(FALSE)
      ->addWhere('source', '=', CK_PROFILE_CONTACT_SOURCE)
      ->addJoin('Email AS profile_email', 'LEFT', ['id', '=', 'profile_email.contact_id'])
      ->addWhere('profile_email.email', '=', $email)
      ->addSelect('id')
      ->execute()->first()['id'] ?? NULL;
  }
  if (!$contactId) {
    $contactId = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', ucfirst(strtolower($username)))
      ->addValue('last_name', 'User')
      ->addValue('source', CK_PROFILE_CONTACT_SOURCE)
      ->execute()->first()['id'];
    \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('email', $email)
      ->execute();
  }

  switch ($uf) {
    case 'Drupal8':
      ck_drupal_grant($roleName, array_merge($perms, $authPermissions), TRUE);
      $storage = \Drupal::entityTypeManager()->getStorage('user');
      $accounts = $storage->loadByProperties(['name' => $username]);
      $account = $accounts ? reset($accounts) : \Drupal\user\Entity\User::create(['name' => $username]);
      $account->setEmail($email);
      $account->setPassword($password);
      $account->activate();
      foreach ($account->getRoles(TRUE) as $existingRole) {
        if (str_starts_with($existingRole, 'civikitchen_') && $existingRole !== $roleName) {
          $account->removeRole($existingRole);
        }
      }
      $account->addRole($roleName);
      $account->save();
      $uid = (int) $account->id();
      break;

    case 'Backdrop':
      ck_backdrop_grant($roleName, array_merge($perms, $authPermissions));
      $account = user_load_by_name($username) ?: entity_create('user', ['name' => $username]);
      $account->mail = $email;
      $account->pass = $password;
      $account->status = 1;
      $roles = array_filter($account->roles ?? [], fn($existingRole) => !str_starts_with($existingRole, 'civikitchen_'));
      $account->roles = array_values(array_unique(array_merge($roles, [$roleName])));
      user_save($account);
      $uid = (int) $account->uid;
      break;

    case 'WordPress':
      if (!get_role($roleName)) {
        add_role($roleName, $roleName);
      }
      $wpRole = get_role($roleName);
      foreach (array_keys($wpRole->capabilities ?? []) as $capability) {
        $wpRole->remove_cap($capability);
      }
      // authx perm guards + the profile's permissions, as WP capabilities.
      foreach ($authPermissions as $authPermission) {
        $wpRole->add_cap(ck_wp_cap($authPermission));
      }
      foreach ($perms as $perm) {
        $wpRole->add_cap(ck_wp_cap($perm));
      }
      $uid = username_exists($username);
      if (!$uid) {
        $uid = wp_insert_user([
          'user_login' => $username,
          'user_email' => $email,
          'user_pass' => $password,
          'role' => $roleName,
        ]);
        if (is_wp_error($uid)) {
          throw new \RuntimeException("configure-api-users: wp_insert_user({$username}): " . $uid->get_error_message());
        }
      }
      else {
        wp_set_password($password, $uid);
        (new WP_User($uid))->set_role($roleName);
      }
      break;

    case 'Joomla':
      // Fine-grained Joomla ACL — the same least-privilege model as the other
      // CMSs, no Super User. Create a usergroup named after the role, grant it
      // exactly $perms (+ the authx guards) on the com_civicrm asset (ensured in
      // the prep step above), and put the user in that group. CiviCRM's
      // CRM_Core_Permission_Joomla maps each permission to a 'civicrm.<munged>'
      // action on that asset, so this grants precisely what the role needs.
      $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
      $grp = new \Joomla\CMS\Table\Usergroup($db);
      if (!$grp->load(['title' => $roleName])) {
        $grpData = ['title' => $roleName, 'parent_id' => 2];
        $grp->bind($grpData);
        if (!$grp->check() || !$grp->store()) {
          throw new \RuntimeException("configure-api-users: Joomla usergroup '{$roleName}': " . $grp->getError());
        }
      }
      $gid = (int) $grp->id;

      // Grant this role's permissions to the group on the com_civicrm asset.
      $permObj = new \CRM_Core_Permission_Joomla();
      $rules = json_decode($db->setQuery(
        $db->getQuery(TRUE)->select('rules')->from('#__assets')->where($db->quoteName('name') . ' = ' . $db->quote('com_civicrm'))
      )->loadResult() ?: '{}', TRUE);
      foreach ($rules as &$groups) {
        if (is_array($groups)) unset($groups[$gid]);
      }
      unset($groups);
      foreach (array_merge($perms, $authPermissions) as $perm) {
        $action = $permObj->translateJoomlaPermission($perm);
        if (is_array($action)) {
          $rules[$action[0]][$gid] = 1;
        }
      }
      $db->setQuery(
        $db->getQuery(TRUE)->update('#__assets')->set($db->quoteName('rules') . ' = ' . $db->quote(json_encode($rules)))
          ->where($db->quoteName('name') . ' = ' . $db->quote('com_civicrm'))
      )->execute();

      // Create/load the user in Registered + the role group (NOT Super Users).
      $jUserFactory = \Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class);
      $uid = (int) \Joomla\CMS\User\UserHelper::getUserId($username);
      $jUser = $uid ? $jUserFactory->loadUserById($uid) : new \Joomla\CMS\User\User();
      $jData = [
        'name' => ucfirst(strtolower($username)) . ' User',
        'username' => $username,
        'email' => $email,
        'password' => $password,
        'password2' => $password,
        'groups' => [2, $gid],
        'block' => 0,
      ];
      $jUser->bind($jData);
      if (!$jUser->save()) {
        throw new \RuntimeException("configure-api-users: Joomla user '{$username}': " . $jUser->getError());
      }
      $uid = (int) $jUser->id;
      break;

    case 'Standalone':
      // save+match keeps re-runs idempotent; "password" is a write-only field
      // hashed on save; "roles" wants role IDs. The User row IS the uf_match
      // record on Standalone, so the UFMatch step below is skipped.
      $roleId = \Civi\Api4\Role::save(FALSE)
        ->setRecords([
          [
            'name' => $roleName,
            'label' => $roleName,
            'permissions' => array_values(array_unique(array_merge($perms, $authPermissions))),
            'is_active' => TRUE,
          ],
        ])
        ->setMatch(['name'])
        ->execute()->first()['id'];
      $uid = \Civi\Api4\User::save(FALSE)
        ->setRecords([
          [
            'username' => $username,
            'uf_name' => $email,
            'contact_id' => $contactId,
            'password' => $password,
            'roles' => [$roleId],
            'is_active' => TRUE,
          ],
        ])
        ->setMatch(['username'])
        ->execute()->first()['id'];
      break;
  }

  // Link CMS user to CiviCRM contact so authx resolves the right contact.
  if ($uf !== 'Standalone') {
    \Civi\Api4\UFMatch::save(FALSE)
      ->setRecords([['uf_id' => $uid, 'uf_name' => $username, 'contact_id' => $contactId]])
      ->setMatch(['uf_id'])
      ->execute();
  }

  // API key: exactly 32 hex chars — civicrm_contact.api_key is varchar(32),
  // anything longer is silently mangled on save and authx rejects the key we
  // record here. The username mapping is in the credentials file anyway.
  $apiKey = bin2hex(random_bytes(16));
  \Civi\Api4\Contact::update(FALSE)
    ->addWhere('id', '=', $contactId)
    ->addValue('api_key', $apiKey)
    ->execute();

  $credentials[] = [$username, $password, $apiKey];
  $credLines[$username] = "{$username}:{$password}:{$apiKey}";
}

foreach ($staleOwnedRoles as $staleRole) {
  ck_delete_owned_role($uf, $staleRole);
  \Civi\Api4\OptionValue::delete(FALSE)
    ->addWhere('id', '=', $ownedRoles[$staleRole])->execute();
  echo "     Deleted stale managed API role: {$staleRole}\n";
}

if (ck_credentials_writes_file($credentialsOutput)) {
  ck_credentials_write_file($credFile, $credLines);
}

echo "     ✓ API users configured successfully\n\n";
if (ck_credentials_writes_file($credentialsOutput)) {
  echo "     Credentials written to {$credFile} (mode 0600).\n";
}
if (ck_credentials_writes_log($credentialsOutput)) {
  ck_credentials_log($credentials);
}
elseif ($credentialsOutput === 'none') {
  echo "     Credential disclosure disabled (CK_CREDENTIALS_OUTPUT=none).\n";
}
echo "\n";

// Flush so the new roles/permissions take effect.
echo "     Flushing caches...\n";
switch ($uf) {
  case 'Drupal8':
    drupal_flush_all_caches();
    break;

  case 'WordPress':
    wp_cache_flush();
    break;
}
civicrm_api3('System', 'flush');
