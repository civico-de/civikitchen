<?php

/**
 * Post-(re)enable settings metadata check for cklifecycle: loads the options of
 * every setting, which fatals on a malformed 'pseudoconstant'. Run through
 * `cv scr` while the extension is installed; parameters arrive in the environment.
 */

$dir = getenv('CK_LC_DIR') ?: '';
if ($dir === '') {
  fwrite(STDERR, "settings-metadata-check: CK_LC_DIR must be set.\n");
  exit(2);
}

$names = [];
foreach (glob($dir . '/settings/*.setting.php') ?: [] as $file) {
  $settings = @include $file;
  if (!is_array($settings)) {
    continue;
  }
  foreach (array_keys($settings) as $name) {
    $names[] = (string) $name;
  }
}

if ($names === []) {
  echo "settings-metadata-check: no settings declared, nothing to check.\n";
  exit(0);
}

// Build the metadata cache first, so a warning from another extension's
// settings is not blamed on the first name below.
\Civi\Core\SettingsMetadata::getMetadata();

$findings = [];
foreach ($names as $name) {
  // Pseudoconstant keys core does not read only warn (undefined $options).
  set_error_handler(static function (int $severity, string $message): bool {
    if (!(error_reporting() & $severity)) {
      return FALSE;
    }
    throw new \ErrorException($message, 0, $severity);
  }, E_WARNING | E_USER_WARNING);
  try {
    \Civi\Core\SettingsMetadata::getMetadata(['name' => [$name]], NULL, TRUE);
  }
  catch (\Throwable $e) {
    $findings[] = "{$name}: " . $e->getMessage();
  }
  finally {
    restore_error_handler();
  }
}

if ($findings === []) {
  echo "settings-metadata-check: options load cleanly for " . count($names) . " setting(s).\n";
  exit(0);
}
foreach ($findings as $finding) {
  echo "settings-metadata-check: FAILED: {$finding}\n";
}
exit(1);
