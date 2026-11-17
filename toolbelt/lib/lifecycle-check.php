<?php

/**
 * Post-uninstall DB invariants for cklifecycle: tables, civicrm_managed rows,
 * option groups and scheduled jobs left behind. Run through `cv scr` while the
 * extension is uninstalled; parameters arrive in the environment.
 */

$key = getenv('CK_LC_KEY') ?: '';
$dir = getenv('CK_LC_DIR') ?: '';
$prefix = getenv('CK_LC_PREFIX') ?: '';
if ($key === '' || $dir === '' || $prefix === '') {
  fwrite(STDERR, "lifecycle-check: CK_LC_KEY, CK_LC_DIR and CK_LC_PREFIX must be set.\n");
  exit(2);
}

$findings = [];

/**
 * Table names the extension's artifacts create; civix names tables after the
 * entity, so the prefix scan is only a second net.
 */
$declared = [];
foreach (glob($dir . '/sql/*.sql') ?: [] as $sqlFile) {
  if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([A-Za-z0-9_]+)/i', (string) file_get_contents($sqlFile), $m)) {
    $declared = array_merge($declared, $m[1]);
  }
}
$schemaFiles = [];
$schemaDir = $dir . '/xml/schema';
if (is_dir($schemaDir)) {
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($schemaDir, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'xml') {
      $schemaFiles[] = $file->getPathname();
    }
  }
}
foreach ($schemaFiles as $schemaFile) {
  $xml = @simplexml_load_file($schemaFile);
  if ($xml !== FALSE && isset($xml->name) && preg_match('/^[A-Za-z0-9_]+$/', (string) $xml->name)) {
    $declared[] = (string) $xml->name;
  }
}
$declared = array_values(array_unique(array_filter($declared, static fn(string $t): bool => str_starts_with($t, 'civicrm_'))));

$leftoverTables = [];
foreach ($declared as $table) {
  if (CRM_Core_DAO::checkTableExists($table)) {
    $leftoverTables[$table] = 'declared in sql/ or xml/schema/';
  }
}
foreach (['civicrm_' . $prefix . '%', $prefix . '%'] as $like) {
  // information_schema rather than SHOW TABLES: the latter's column name
  // carries the database name, so there is no stable property to read.
  $dao = CRM_Core_DAO::executeQuery(
    'SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %1',
    [1 => [$like, 'String']],
  );
  while ($dao->fetch()) {
    $leftoverTables[(string) $dao->t] ??= 'matches the extension prefix';
  }
}

// Managed entities nothing owns, unless core kept them by policy (cleanup
// `never`, or `unused` while referenced).
$kept = [];
$dao = CRM_Core_DAO::executeQuery(
  'SELECT id, entity_type, entity_id, name, cleanup FROM civicrm_managed WHERE module = %1',
  [1 => [$key, 'String']],
);
while ($dao->fetch()) {
  if (in_array($dao->cleanup, ['never', 'unused'], TRUE)) {
    $kept[$dao->entity_type][] = (int) $dao->entity_id;
    continue;
  }
  $findings[] = "orphaned civicrm_managed row #{$dao->id} ({$dao->entity_type} '{$dao->name}') for module {$key}";
}

// A custom group core keeps by policy keeps its table.
foreach ($kept['CustomGroup'] ?? [] as $customGroupId) {
  unset($leftoverTables[(string) CRM_Core_DAO::singleValueQuery(
    'SELECT table_name FROM civicrm_custom_group WHERE id = %1',
    [1 => [$customGroupId, 'Integer']],
  )]);
}
foreach ($leftoverTables as $table => $why) {
  $findings[] = "table `{$table}` still exists after uninstall ({$why}) — drop it in sql/auto_uninstall.sql or uninstall()";
}

$dao = CRM_Core_DAO::executeQuery(
  'SELECT og.id, og.name FROM civicrm_option_group og WHERE og.name LIKE %1',
  [1 => [$prefix . '%', 'String']],
);
while ($dao->fetch()) {
  if (in_array((int) $dao->id, $kept['OptionGroup'] ?? [], TRUE)) {
    continue;
  }
  $findings[] = "option group '{$dao->name}' (#{$dao->id}) survived uninstall";
}
$dao = CRM_Core_DAO::executeQuery(
  'SELECT ov.id, ov.name, ov.option_group_id, og.name AS group_name FROM civicrm_option_value ov'
  . ' INNER JOIN civicrm_option_group og ON og.id = ov.option_group_id'
  . ' WHERE ov.name LIKE %1',
  [1 => [$prefix . '%', 'String']],
);
while ($dao->fetch()) {
  if (in_array((int) $dao->id, $kept['OptionValue'] ?? [], TRUE) || in_array((int) $dao->option_group_id, $kept['OptionGroup'] ?? [], TRUE)) {
    continue;
  }
  $findings[] = "option value '{$dao->name}' (#{$dao->id}, group {$dao->group_name}) survived uninstall";
}

$dao = CRM_Core_DAO::executeQuery(
  'SELECT id, name, api_entity, api_action FROM civicrm_job WHERE name LIKE %1 OR api_entity LIKE %1',
  [1 => [$prefix . '%', 'String']],
);
while ($dao->fetch()) {
  if (in_array((int) $dao->id, $kept['Job'] ?? [], TRUE)) {
    continue;
  }
  $findings[] = "scheduled job '{$dao->name}' (#{$dao->id}, {$dao->api_entity}.{$dao->api_action}) survived uninstall";
}

if ($findings === []) {
  echo "cklifecycle: post-uninstall DB invariants clean (no tables, managed rows, option values or jobs left behind).\n";
  exit(0);
}
foreach ($findings as $finding) {
  echo "cklifecycle: LEFTOVER: {$finding}\n";
}
exit(1);
