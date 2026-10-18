#!/usr/bin/env bash
# The managed test bootstrap's ck_headless() installs the extension's info.xml
# <requires>, transitively and one per install(), before the extension itself,
# touching the extension system only once its step runs; it restores the core foreign keys its own signing drops before 6.20, signs
# a step with the extension's install files, and drains the status messages the
# build queues. Civi is stubbed: what is under test is the
# info.xml reader and the calls the builder receives.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

mkdir -p "$work/ext/tests/phpunit"
cp "$root/scaffold/template/extension/tests/phpunit/ckHeadless.php" "$work/ext/tests/phpunit/"
cat > "$work/ext/info.xml" <<'XML'
<?xml version="1.0"?>
<extension key="org.example.fixture" type="module">
  <file>fixture</file>
  <requires>
    <ext>org.example.base</ext>
    <ext> org.example.dep </ext>
  </requires>
</extension>
XML

cat > "$work/stubs.php" <<'PHP'
<?php
namespace Civi\Test {
  class CiviEnvBuilder {
    public array $steps = [];
    public function install($names): self { \CkProbe::$installed[] = implode("+", (array) $names); return $this; }
    public function callback($cb, $sig = NULL): self { $this->steps[] = [$cb, $sig]; return $this; }
    // Runs the steps as apply() would, once the schema is rebuilt.
    public function run(): void { \CkProbe::$rebuilt = TRUE; foreach ($this->steps as [$cb]) { $cb($this); } }
  }
}
namespace Civi\Test\CiviEnvBuilder {
  class CoreSchemaStep {
    public function getSql(): array { \CkProbe::$signed = TRUE; return ['digest' => 'd', 'content' => 'CORE SCHEMA SQL']; }
  }
}
namespace Civi {
  class Test {
    public static function headless(): \Civi\Test\CiviEnvBuilder {
      \CkProbe::$builtAfterRestore = \CkProbe::$executed !== [];
      return new \Civi\Test\CiviEnvBuilder();
    }
    public static function dsn($part) { return 'civicrm_test'; }
    public static function asPreInstall($cb) { \CkProbe::$preInstall = TRUE; return $cb(); }
    public static function execute($sql) { \CkProbe::$executed[] = $sql; return TRUE; }
  }
}
namespace {
  class CkProbe {
    public static bool $signed = FALSE;
    public static bool $preInstall = FALSE;
    public static bool $builtAfterRestore = FALSE;
    public static bool $rebuilt = FALSE;
    public static array $executed = [];
    public static array $installed = [];
  }
  class CRM_Utils_System {
    public static string $version = '6.19.0';
    public static function version(): string { return self::$version; }
  }
  class CRM_Core_Session {
    public static array $status = ['boot noise'];
    public static function singleton(): self { return new self(); }
    public function getStatus($reset = FALSE) { $s = self::$status; if ($reset) { self::$status = []; } return $s; }
  }
  // Touching the extension system before the headless rebuild is the bug this
  // helper avoids: the stub makes any such call loud.
  class CRM_Extension_System {
    private static ?self $current = NULL;
    private ?CRM_Extension_Manager $manager = NULL;
    public static function singleton(): self {
      if (!CkProbe::$rebuilt) { throw new RuntimeException('ck_headless touched CRM_Extension_System'); }
      return self::$current ??= new self();
    }
    public static function replace(): void { self::$current = new self(); }
    public function getManager(): CRM_Extension_Manager { return $this->manager ??= new CRM_Extension_Manager(); }
  }
  // org.example.dep requires org.example.deep, which the fixture never names.
  class CRM_Extension_Manager {
    private const REQUIRES = ['org.example.dep' => ['org.example.deep'], 'org.example.deep' => []];
    public function findInstallRequirements(array $keys): array {
      $order = [];
      $visit = function (string $key) use (&$visit, &$order): void {
        foreach (self::REQUIRES[$key] ?? [] as $required) { $visit($required); }
        $order[] = $key;
      };
      array_map($visit, $keys);
      return array_values(array_unique($order));
    }
    // Like core's _install(), every install replaces the extension system; a
    // replaced manager installs without the mixins seeing it (templates lost).
    public function install(array $keys): void {
      if (count($keys) !== 1) { throw new RuntimeException('one extension per install()'); }
      if ($this !== CRM_Extension_System::singleton()->getManager()) { throw new RuntimeException('install() through a replaced extension manager'); }
      if (!in_array($keys[0], CkProbe::$installed, TRUE)) { CkProbe::$installed[] = $keys[0]; }
      CRM_Extension_System::replace();
    }
  }
}
PHP

probe='
  require $argv[1];
  require $argv[2];
  CRM_Utils_System::$version = getenv("CK_CORE") ?: "6.19.0";
  $b = ck_headless();
  $last = end($b->steps);
  $b->run();
  echo implode(",", CkProbe::$installed), "|",
    (CkProbe::$signed && CkProbe::$preInstall ? "restored" : "no-restore"), "|",
    (CkProbe::$builtAfterRestore ? "before-build" : "after-build"), "|",
    str_replace("\n", " ", implode(";", CkProbe::$executed)), "|",
    ($last[1] ?? "none"), "|";
  echo (CRM_Core_Session::$status === [] ? "drained" : "kept"), "\n";
'
out="$(php -r "$probe" "$work/stubs.php" "$work/ext/tests/phpunit/ckHeadless.php")"
IFS='|' read -r installed restored order executed sig drained <<<"$out"
[[ "$installed" == "org.example.base,org.example.deep,org.example.dep,org.example.fixture" ]] \
  || fail "expected the requires closure, trimmed, each after its own requires, own key last; got '$installed'"

# An optional integration installs after <requires>, before the extension.
out="$(php -r 'require $argv[1]; require $argv[2]; ck_headless("org.example.optional")->run(); echo implode(",", CkProbe::$installed);' \
  "$work/stubs.php" "$work/ext/tests/phpunit/ckHeadless.php")"
[[ "$out" == "org.example.base,org.example.deep,org.example.dep,org.example.optional,org.example.fixture" ]] \
  || fail "expected optional extensions between <requires> and the own key; got '$out'"

# Signing CoreSchemaStep drops the core foreign keys, so the generated SQL has
# to be replayed — inside asPreInstall, before the builder is handed out.
[[ "$restored" == "restored" ]] || fail "ck_headless did not re-run the core schema SQL under asPreInstall"
[[ "$order" == "before-build" ]] || fail "the foreign keys must be restored before the builder is built"
[[ "$executed" == *"USE \`civicrm_test\`"* ]] \
  || fail "the restore must select the scratch database; got '$executed'"
[[ "$executed" == *"CORE SCHEMA SQL"* ]] || fail "the restore must replay the CoreSchemaStep SQL; got '$executed'"

# 6.20 keeps the keys while signing, and replaying the SQL would add them twice.
out="$(CK_CORE=6.20.beta1 php -r "$probe" "$work/stubs.php" "$work/ext/tests/phpunit/ckHeadless.php")"
IFS='|' read -r _ restored _ executed _ _ <<<"$out"
[[ "$restored" == "no-restore" && "$executed" != *"CORE SCHEMA SQL"* ]] \
  || fail "on 6.20 the core schema SQL must not be replayed; got '$restored' '$executed'"

# A rebuild that dies halfway leaves the previous signature in civitest_revs,
# and the next run trusts a wiped schema. The first step forgets it.
out="$(php -r 'require $argv[1]; require $argv[2];
  CRM_Utils_System::$version = "6.20.0";
  $b = ck_headless(); ($b->steps[0][0])($b);
  echo $b->steps[0][1], "|", implode(";", CkProbe::$executed);' \
  "$work/stubs.php" "$work/ext/tests/phpunit/ckHeadless.php")"
[[ "$out" == "ck-forget-signature|DELETE FROM \`civicrm_test\`.civitest_revs" ]] \
  || fail "the first step must forget the saved signature; got '$out'"

# The build queues managed-entity errors; the last step drains them, so no test
# starts with status messages it did not cause.
[[ "$sig" == "ck-discard-bootstrap-status;ck-install-files:"* ]] || fail "the last step must be the status drain; got '$sig'"
[[ "$drained" == "drained" ]] || fail "the last step did not drain the session status"

# Civi\Test rebuilds the scratch database only when the step signatures
# change; the install-files step must follow the files install() reads.
cat > "$work/ext/info.xml" <<'XML'
<?xml version="1.0"?>
<extension key="org.example.fixture" type="module">
  <file>fixture</file>
  <upgrader>CRM_Fixture_Upgrader</upgrader>
</extension>
XML
mkdir -p "$work/ext/CRM/Fixture" "$work/ext/schema" "$work/ext/vendor/acme/lib" "$work/ext/.git"
echo '<?php // main' > "$work/ext/fixture.php"
echo '<?php // upgrader' > "$work/ext/CRM/Fixture/Upgrader.php"
echo '<?php // page' > "$work/ext/CRM/Fixture/Page.php"
echo '<?php return [];' > "$work/ext/schema/Widget.entityType.php"
install_sig() {
  php -r 'require $argv[1]; require $argv[2];
    $b = ck_headless(); $n = 0;
    foreach ($b->steps as [$cb, $sig]) {
      if (str_contains((string) $sig, "ck-install-files:")) { $n++; echo is_callable($cb) ? $sig : "not-callable"; }
    }
    if ($n !== 1) { echo "steps:$n"; }' "$work/stubs.php" "$work/ext/tests/phpunit/ckHeadless.php"
}
base="$(install_sig)"
[[ "$base" == *ck-install-files:* ]] || fail "expected one callable step signed with the install files; got '$base'"
[[ "$(install_sig)" == "$base" ]] || fail "the install signature must be stable between runs"
touch -t 202001010000 "$work/ext/fixture.php" "$work/ext/schema/Widget.entityType.php"
[[ "$(install_sig)" == "$base" ]] || fail "a new mtime must not change the install signature"
echo '<?php // edited' > "$work/ext/CRM/Fixture/Page.php"
echo '<?php return [];' > "$work/ext/vendor/acme/lib/Other.mgd.php"
echo 'x' > "$work/ext/.git/Ignored.mgd.php"
[[ "$(install_sig)" == "$base" ]] || fail "files install() does not read must not change the install signature"

expect_changed() {
  local next
  next="$(install_sig)"
  [[ "$next" == *ck-install-files:* && "$next" != "$base" ]] || fail "$1 must change the install signature"
  base="$next"
}
mkdir -p "$work/ext/managed"
echo '<?php return [["name" => "ActivityType_Call"]];' > "$work/ext/managed/ActivityType.mgd.php"
expect_changed "a new managed/*.mgd.php"
echo '<?php return [["name" => "Nested"]];' > "$work/ext/CRM/Fixture/Nested.mgd.php"
expect_changed "a *.mgd.php outside managed/"
echo '<?php return ["fields" => []];' > "$work/ext/schema/Widget.entityType.php"
expect_changed "an edited schema file"
mkdir -p "$work/ext/sql" && echo 'CREATE TABLE t (id INT);' > "$work/ext/sql/auto_install.sql"
expect_changed "an install SQL file"
echo '<?php // upgrader with a new step' > "$work/ext/CRM/Fixture/Upgrader.php"
expect_changed "an edited upgrader"
echo '<?php // main with a hook' > "$work/ext/fixture.php"
expect_changed "an edited main file"

# setUpHeadless() runs before every test: one process hashes the files once.
out="$(php -r 'require $argv[1]; require $argv[2];
  $first = end(ck_headless()->steps)[1];
  file_put_contents($argv[3], "<?php // edited mid-run");
  echo $first === end(ck_headless()->steps)[1] ? "once" : "rehashed";' \
  "$work/stubs.php" "$work/ext/tests/phpunit/ckHeadless.php" "$work/ext/fixture.php")"
[[ "$out" == "once" ]] || fail "the install files must be hashed once per process; got '$out'"

# No key in info.xml: a loud error, never a guessed key.
printf '%s\n' '<extension type="module"><file>fixture</file></extension>' > "$work/ext/info.xml"
if php -r 'require $argv[1]; require $argv[2]; ck_headless();' "$work/stubs.php" "$work/ext/tests/phpunit/ckHeadless.php" >/dev/null 2>&1; then
  fail "a missing extension key must throw"
fi

echo "ck_headless: ok"
