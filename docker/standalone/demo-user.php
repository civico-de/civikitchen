<?php

// Create a Standalone admin login user, run by entrypoint.sh via `cv scr` after
// `cv core:install`.

if (PHP_SAPI !== 'cli') {
  die("This script must be run from the command line.\n");
}

foreach (['DEMO_USER', 'DEMO_PASS', 'DEMO_EMAIL'] as $var) {
  if (getenv($var) === FALSE || getenv($var) === '') {
    fwrite(STDERR, "Missing env var: $var\n");
    exit(1);
  }
}

$demoUser = getenv('DEMO_USER');
$demoPass = getenv('DEMO_PASS');
$demoEmail = getenv('DEMO_EMAIL');

// Update or create, never save+match: civicrm_uf_match has unique indexes on
// (contact_id, domain_id) and (uf_name, domain_id), and re-asserting either on
// an existing user can collide with another User row and fail every boot.

// `roles:name` drops a name with no Role row without an error.
$assertAdmin = function (int $userId) use ($demoUser): void {
  $roles = \Civi\Api4\User::get(FALSE)
    ->addWhere('id', '=', $userId)
    ->addSelect('roles:name')
    ->execute()->single()['roles:name'] ?? [];
  if (!in_array('admin', $roles, TRUE)) {
    fwrite(STDERR, "[demo-user] User '{$demoUser}' (id={$userId}) does not hold the admin role.\n");
    exit(1);
  }
};

$existing = \Civi\Api4\User::get(FALSE)
  ->addWhere('username', '=', $demoUser)
  ->addSelect('id', 'contact_id')
  ->execute()->first();

if ($existing) {
  // Only the login fields; contact_id/uf_name stay. Raw `roles` takes ids, and a
  // name there inserts role_id 0 (FK violation), hence `roles:name`.
  \Civi\Api4\User::update(FALSE)
    ->addWhere('id', '=', $existing['id'])
    ->addValue('password', $demoPass)
    ->addValue('roles:name', ['admin'])
    ->addValue('is_active', TRUE)
    ->execute();
  $assertAdmin($existing['id']);
  echo "[demo-user] Ensured active admin user '{$demoUser}' (id={$existing['id']}, contact id={$existing['contact_id']}).\n";
  return;
}

// If another user claims $demoEmail, the new one gets an email-less contact:
// standaloneusers fills uf_name from the primary email. Login is by username.
$ufNameTaken = (bool) \Civi\Api4\User::get(FALSE)
  ->addWhere('uf_name', '=', $demoEmail)
  ->execute()->first();
if ($ufNameTaken) {
  echo "[demo-user] Email {$demoEmail} is already another user's login email; creating '{$demoUser}' without one.\n";
}

// Reuse the contact with this email unless another user is linked to it.
$contactId = NULL;
if (!$ufNameTaken) {
  $contactId = \Civi\Api4\Email::get(FALSE)
    ->addWhere('email', '=', $demoEmail)
    ->addSelect('contact_id')
    ->execute()->first()['contact_id'] ?? NULL;
  if ($contactId) {
    $linked = \Civi\Api4\User::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->addSelect('username')
      ->execute()->first();
    if ($linked) {
      echo "[demo-user] Contact {$contactId} ({$demoEmail}) already belongs to user '{$linked['username']}'; creating a fresh contact.\n";
      $contactId = NULL;
    }
  }
}
if (!$contactId) {
  $contactId = \Civi\Api4\Contact::create(FALSE)
    ->addValue('contact_type', 'Individual')
    ->addValue('first_name', 'Demo')
    ->addValue('last_name', 'User')
    ->execute()->single()['id'];
  if (!$ufNameTaken) {
    \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('email', $demoEmail)
      ->execute();
  }
}

$create = \Civi\Api4\User::create(FALSE)
  ->addValue('username', $demoUser)
  ->addValue('contact_id', $contactId)
  ->addValue('password', $demoPass)
  ->addValue('roles:name', ['admin'])
  ->addValue('is_active', TRUE);
if (!$ufNameTaken) {
  $create->addValue('uf_name', $demoEmail);
}
$userId = $create->execute()->first()['id'];
$assertAdmin($userId);

echo "[demo-user] Ensured active admin user '{$demoUser}' (id={$userId}, contact id={$contactId}).\n";
