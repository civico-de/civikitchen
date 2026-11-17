<?php
// Create-only helpers for profile scripts (ck_seed_search_page converges by
// name); each script keeps its own guard. Include from scripts/<file>.php with:
//   require_once __DIR__ . '/../../lib.php';

use Civi\Api4\Address;
use Civi\Api4\Afform;
use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\Group;
use Civi\Api4\GroupContact;
use Civi\Api4\SavedSearch;
use Civi\Api4\SearchDisplay;

/**
 * Create an Individual with email and optional address; returns the contact id.
 * $address = [street, postal code, city] (German address, location type Home);
 * country_id:name takes the ISO code.
 */
function ck_seed_individual(string $first, string $last, string $email, array $address = []): int {
  $contact = Contact::create(FALSE)
    ->addValue('contact_type', 'Individual')
    ->addValue('first_name', $first)
    ->addValue('last_name', $last)
    ->execute()->first();
  Email::create(FALSE)
    ->addValue('contact_id', $contact['id'])
    ->addValue('email', $email)
    ->execute();
  if ($address) {
    [$street, $plz, $city] = $address;
    Address::create(FALSE)
      ->addValue('contact_id', $contact['id'])
      ->addValue('location_type_id:name', 'Home')
      ->addValue('street_address', $street)
      ->addValue('postal_code', $plz)
      ->addValue('city', $city)
      ->addValue('country_id:name', 'DE')
      ->execute();
  }
  return $contact['id'];
}

/**
 * Create an Organization with optional email/address; returns the contact id.
 */
function ck_seed_org(string $name, ?string $email = NULL, array $address = []): int {
  $contact = Contact::create(FALSE)
    ->addValue('contact_type', 'Organization')
    ->addValue('organization_name', $name)
    ->execute()->first();
  if ($email) {
    Email::create(FALSE)
      ->addValue('contact_id', $contact['id'])
      ->addValue('email', $email)
      ->execute();
  }
  if ($address) {
    [$street, $plz, $city] = $address;
    Address::create(FALSE)
      ->addValue('contact_id', $contact['id'])
      ->addValue('location_type_id:name', 'Main')
      ->addValue('street_address', $street)
      ->addValue('postal_code', $plz)
      ->addValue('city', $city)
      ->addValue('country_id:name', 'DE')
      ->execute();
  }
  return $contact['id'];
}

/**
 * Derive an ASCII email from a German name: "Käthe", "Groß" -> kaethe.gross@<domain>.
 */
function ck_seed_email(string $first, string $last, string $domain): string {
  return strtolower(strtr("{$first}.{$last}", ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'])) . '@' . $domain;
}

/**
 * Create a group (machine name + title).
 */
function ck_seed_group(string $name, string $title): void {
  Group::create(FALSE)
    ->addValue('name', $name)
    ->addValue('title', $title)
    ->addValue('is_active', TRUE)
    ->execute();
}

/**
 * Add a contact to a group by the group's machine name.
 */
function ck_seed_group_contact(int $contactId, string $groupName): void {
  GroupContact::create(FALSE)
    ->addValue('group_id:name', $groupName)
    ->addValue('contact_id', $contactId)
    ->addValue('status', 'Added')
    ->execute();
}

/**
 * TRUE when a contact with this exact organization name exists — the standard
 * "already seeded" guard, keyed on the org a seed creates.
 */
function ck_seed_org_exists(string $name): bool {
  return (bool) Contact::get(FALSE)
    ->addWhere('organization_name', '=', $name)
    ->selectRowCount()
    ->execute()->count();
}

/**
 * Runs a seed's writes in one transaction: a failed seed leaves nothing behind,
 * so its "already seeded" guard never skips a half-written seed on the next boot.
 */
function ck_seed_atomically(callable $seed): void {
  CRM_Core_Transaction::create()->run(fn () => $seed());
}

/**
 * A SavedSearch "$name" with a table display and an Afform page at $route,
 * matched by name. The Afform is a file, so snapshot profiles list the caller
 * in after_restore. $columns maps select keys to column labels.
 */
function ck_seed_search_page(string $name, string $title, string $entity, array $apiParams, array $columns, string $route): void {
  $search = SavedSearch::save(FALSE)
    ->setRecords([['name' => $name, 'label' => $title, 'api_entity' => $entity, 'api_params' => ['version' => 4] + $apiParams]])
    ->setMatch(['name'])
    ->execute()->single();
  $fields = [];
  foreach ($columns as $key => $label) {
    $fields[] = ['type' => 'field', 'key' => $key, 'label' => $label, 'sortable' => TRUE];
  }
  SearchDisplay::save(FALSE)
    ->setRecords([[
      'name' => "{$name}_Table",
      'label' => $title,
      'saved_search_id' => $search['id'],
      'type' => 'table',
      'settings' => ['limit' => 50, 'pager' => [], 'actions' => FALSE, 'classes' => ['table', 'table-striped'], 'columns' => $fields],
    ]])
    ->setMatch(['name', 'saved_search_id'])
    ->execute();
  Afform::save(FALSE)
    ->setLayoutFormat('html')
    ->addRecord([
      'name' => "afsearch{$name}",
      'type' => 'search',
      'title' => $title,
      'server_route' => $route,
      'permission' => ['access CiviCRM'],
      'layout' => "<div af-fieldset=\"\"><crm-search-display-table search-name=\"{$name}\" display-name=\"{$name}_Table\"></crm-search-display-table></div>",
    ])
    ->execute();
}
