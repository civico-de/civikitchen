<?php
/**
 * Patch civicrm.standalone.php so CIVICRM_UF=UnitTests boots use the test DB
 * and cache: bootSettings() builds CIVICRM_DSN from env before the settings file.
 * Usage: php patch-test-db-boot.php [stub]; idempotent, no stub exits 0.
 */

$stub = $argv[1] ?? '/var/www/html/civicrm.standalone.php';

if (!is_file($stub)) {
  // Non-standalone layout (no stub) — nothing to do.
  exit(0);
}

$src = file_get_contents($stub);
if ($src === FALSE) {
  fwrite(STDERR, "[civikitchen] WARN: cannot read {$stub} — test-DB boot patch skipped\n");
  exit(1);
}

$inject = <<<'PHP'
// [civikitchen-test-db] Route CIVICRM_UF=UnitTests boots at the isolated
// test database. Core's SettingsManager::bootSettings() composes CIVICRM_DSN
// from the CIVICRM_DB_* env vars BEFORE the settings file loads, so the
// settings file's UnitTests/TEST_DB_DSN branch can never fire here — without
// this block, headless tests silently run against the dev database.
// Rewrite the CIVICRM_DB_* env vars instead of defining CIVICRM_DSN
// directly: core then composes the DSN itself and its settings bag stays
// consistent with the constant. A stub-level define() leaves the bag on the
// main DB and the boot dies in a translation that runs before DAO::init().
if (getenv('CIVICRM_UF') === 'UnitTests' && !defined('CIVICRM_DSN')) {
  $ckTestDsn = $GLOBALS['_CV']['TEST_DB_DSN'] ?? NULL;
  $ckParts = $ckTestDsn ? parse_url($ckTestDsn) : NULL;
  if (!empty($ckParts['path'])) {
    putenv('CIVICRM_DB_NAME=' . ltrim($ckParts['path'], '/'));
    if (!empty($ckParts['host'])) { putenv('CIVICRM_DB_HOST=' . $ckParts['host']); }
    if (!empty($ckParts['port'])) { putenv('CIVICRM_DB_PORT=' . $ckParts['port']); }
    if (!empty($ckParts['user'])) { putenv('CIVICRM_DB_USER=' . $ckParts['user']); }
    if (!empty($ckParts['pass'])) { putenv('CIVICRM_DB_PASSWORD=' . $ckParts['pass']); }
  }
  elseif (getenv('CIVICRM_DB_NAME') && substr((string) getenv('CIVICRM_DB_NAME'), -5) !== '_test') {
    putenv('CIVICRM_DB_NAME=' . getenv('CIVICRM_DB_NAME') . '_test');
  }
  // ArrayCache falls back to SqlGroup: civicrm_cache in the test DB, which
  // the schema rebuild drops. A file or Redis cache would serve stale state.
  if (!defined('CIVICRM_DB_CACHE_CLASS')) {
    define('CIVICRM_DB_CACHE_CLASS', 'ArrayCache');
  }
  unset($ckTestDsn, $ckParts);
}

PHP;

$anchor = '\Civi\Core\SettingsManager::bootSettings';
// The block always sits directly before the anchor, so marker..anchor is the block.
$start = strpos($src, '// [civikitchen-test-db]');
$pos = strpos($src, $anchor, $start === FALSE ? 0 : $start);
if ($pos === FALSE) {
  fwrite(STDERR, "[civikitchen] WARN: bootSettings anchor not found in $stub; test-db boot patch skipped\n");
  exit(0);
}
if ($start === FALSE) {
  $start = $pos;
}
elseif (substr($src, $start, $pos - $start) === $inject) {
  exit(0);
}

$src = substr($src, 0, $start) . $inject . substr($src, $pos);
if (file_put_contents($stub, $src) === FALSE) {
  fwrite(STDERR, "[civikitchen] ERROR: cannot write {$stub} — UnitTests boots would use the dev DB\n");
  exit(1);
}
echo "[civikitchen] Patched $stub: UnitTests boots now use the test DB.\n";
