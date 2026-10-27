<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/docker/profiles/credentials.php';
require dirname(__DIR__, 2) . '/toolbelt/lib/php/bootstrap.php';

use CiviKitchen\Toolbelt\Filesystem\Directory;

function expect(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
}

putenv('CK_CREDENTIALS_OUTPUT');
expect(ck_credentials_output_mode() === 'file', 'file is the secure default');
foreach (['file', 'log', 'both', 'none'] as $mode) {
  putenv("CK_CREDENTIALS_OUTPUT={$mode}");
  expect(ck_credentials_output_mode() === $mode, "{$mode} output mode is accepted");
}
putenv('CK_CREDENTIALS_OUTPUT=stdout');
try {
  ck_credentials_output_mode();
  expect(FALSE, 'an unknown output mode must fail');
}
catch (RuntimeException $e) {
  expect(str_contains($e->getMessage(), 'must be file, log, both, or none'), 'unknown mode explains the contract');
}

$dir = Directory::temporary('ck-credentials');
register_shutdown_function(Directory::remove(...), $dir);
$path = $dir . '/api-credentials.txt';
$password = ck_credentials_password();
expect(strlen($password) === 48 && ctype_xdigit($password), 'generated password is a 192-bit hex secret');
expect($password !== 'readonly', 'generated password is not derived from the username');
ck_credentials_write_file($path, ['readonly' => "readonly:{$password}:abc"]);
expect(fileperms($path) % 01000 === 0600, 'credentials file is mode 0600');
expect(ck_credentials_read_file($path) === ['readonly' => "readonly:{$password}:abc"], 'credentials round-trip by username');
ck_credentials_write_file($path, [
  'readonly' => "readonly:{$password}:def",
  'mailer' => 'mailer:another-secret:ghi',
]);
expect(ck_credentials_read_file($path)['readonly'] === "readonly:{$password}:def", 'later profiles replace one username');
ck_credentials_remove_file($path);
expect(!file_exists($path), 'non-file output modes remove stale credentials');
ck_credentials_remove_file($path);

ob_start();
ck_credentials_log([['readonly', $password, '0123456789abcdef']]);
$log = (string) ob_get_clean();
expect(str_contains($log, '0123456789abcdef'), 'explicit log mode can render the API key');

$configurator = (string) file_get_contents(dirname(__DIR__, 2) . '/docker/profiles/configure-api-users.php');
expect(!str_contains($configurator, "['admin' => 'admin', 'demo' => 'demo']"), 'profile apply never resets existing CMS passwords to public defaults');
expect(!str_contains($configurator, "ck_drupal_grant('authenticated'"), 'Drupal AuthX access is not granted to every authenticated account');
expect(str_contains($configurator, 'ck_drupal_grant($roleName, array_merge($perms, $authPermissions), TRUE)'), 'Drupal API roles exactly reconcile their profile and AuthX permissions');
expect(str_contains($configurator, "belongs to an unmanaged"), 'existing CMS usernames require a CiviKitchen ownership marker');
expect(!str_contains($configurator, "WARN: unknown Drupal permission"), 'unknown Drupal permissions fail closed');

$driver = (string) file_get_contents(dirname(__DIR__, 2) . '/docker/profiles/apply.sh');
expect(str_contains($driver, 'if ! cv scr --user=admin "${script}"; then'), 'profile scripts fail closed');
expect(!str_contains($driver, 'failed (non-fatal)'), 'profile script failures cannot be swallowed');

echo "credentials helper tests passed\n";
