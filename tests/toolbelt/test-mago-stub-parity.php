<?php

declare(strict_types = 1);

/**
 * stub-parity.php against the pinned core and against copies of core's files
 * that drifted: a new or changed member fails, one only the stub has is listed,
 * private members and docblocks do not count, a missing or broken file fails.
 */

use CiviKitchen\Toolbelt\Filesystem\Directory;

require dirname(__DIR__, 2) . '/toolbelt/lib/php/bootstrap.php';

$core = getenv('CIVICRM_CORE_DIR') ?: '';
if (!is_file("{$core}/Civi.php")) {
  fwrite(STDERR, "FAIL: CIVICRM_CORE_DIR must name a CiviCRM core checkout\n");
  exit(1);
}
$result = 'Civi/Api4/Generic/Result.php';
$work = Directory::temporary('stub-parity');
register_shutdown_function(Directory::remove(...), $work);

function parity(string $core): array {
  exec('php ' . escapeshellarg(dirname(__DIR__, 2) . '/toolbelt/mago/stub-parity.php') . ' ' . escapeshellarg($core) . ' 2>&1', $lines, $status);
  return [$status, implode("\n", $lines)];
}

function expect(string $label, array $actual, int $status, string $output): void {
  if ($actual !== [$status, $output]) {
    fwrite(STDERR, "FAIL: {$label}\nexpected exit {$status}:\n{$output}\ngot exit {$actual[0]}:\n{$actual[1]}\n");
    exit(1);
  }
}

/** A core tree whose Result.php is core's with $search replaced. */
function drifted(string $work, string $core, string $search, string $replace): string {
  $original = (string) file_get_contents("{$core}/Civi/Api4/Generic/Result.php");
  if (!str_contains($original, $search)) {
    fwrite(STDERR, "FAIL: core's Result.php no longer contains: {$search}\n");
    exit(1);
  }
  $tree = $work . '/' . bin2hex(random_bytes(4));
  mkdir("{$tree}/Civi/Api4/Generic", 0700, TRUE);
  file_put_contents("{$tree}/Civi/Api4/Generic/Result.php", str_replace($search, $replace, $original));
  return $tree;
}

$matches = "stub-parity: {$result} matches core";
expect('pinned core', parity($core), 0, $matches);

$hasErrors = '  public function hasErrors(): bool {';
$added = drifted($work, $core, $hasErrors, "  public function added(): int {\n    return 0;\n  }\n\n{$hasErrors}");
expect('a method core adds', parity($added), 1, "stub-parity: {$result} differs from {$added}\n"
  . '  core only: Civi\Api4\Generic\Result: public function added(): int');

$changed = drifted($work, $core, 'bool $log = FALSE', 'bool $log = TRUE');
$addError = 'Civi\Api4\Generic\Result: public function addError(string $message, bool $log = %s, string|int $code = 0, string $title = \'\', string $level = Psr\Log\LogLevel::ERROR, array $metadata = array ( ))';
expect('a changed default', parity($changed), 1, "stub-parity: {$result} differs from {$changed}\n"
  . '  core only: ' . sprintf($addError, 'true') . "\n  stub only: " . sprintf($addError, 'false'));

$protected = drifted($work, $core, 'public function rekey($map)', 'protected function rekey($map)');
expect('a narrowed visibility', parity($protected), 1, "stub-parity: {$result} differs from {$protected}\n"
  . "  core only: Civi\\Api4\\Generic\\Result: protected function rekey(\$map)\n"
  . '  stub only: Civi\Api4\Generic\Result: public function rekey($map)');

$older = drifted($work, $core, "  public function hasCountMatched(): bool {\n    return isset(\$this->matchedCount);\n  }\n\n", '');
expect('a method an older core lacks', parity($older), 0, "stub-parity: {$result} covers core\n"
  . '  stub only: Civi\Api4\Generic\Result: public function hasCountMatched(): bool');

$private = drifted($work, $core, $hasErrors, "  private function helper(): void {}\n\n  /**\n   * @return array<string, mixed>[]\n   */\n{$hasErrors}");
expect('a private method and a docblock', parity($private), 0, $matches);

$noisy = drifted($work, $core, 'namespace Civi\Api4\Generic;', "namespace Civi\\Api4\\Generic;\n\ntrigger_error('loaded', E_USER_DEPRECATED);");
[$status, $output] = parity($noisy);
if ($status !== 0 || !str_ends_with($output, $matches) || str_contains($output, ' only: ')) {
  fwrite(STDERR, "FAIL: a notice while loading core's file\ngot exit {$status}:\n{$output}\n");
  exit(1);
}

$missing = $work . '/empty';
mkdir($missing, 0700, TRUE);
expect('a missing file', parity($missing), 1, "stub-parity: {$missing} has no {$result}");

$broken = drifted($work, $core, $hasErrors, "  public function broken( {\n{$hasErrors}");
[$status, $output] = parity($broken);
if ($status !== 2 || !str_contains($output, "stub-parity: cannot load {$broken}/{$result}")) {
  fwrite(STDERR, "FAIL: a broken core file\ngot exit {$status}:\n{$output}\n");
  exit(1);
}
echo "stub-parity: all checks passed\n";
