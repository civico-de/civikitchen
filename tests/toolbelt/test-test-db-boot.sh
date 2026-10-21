#!/usr/bin/env bash
# patch-test-db-boot.php routes CIVICRM_UF=UnitTests boots of the standalone
# stub at the test database and at a cache that lives in it; the buildkit
# settings shim test-db-cache.php does the cache half. Core's bootSettings()
# and civibuild's settings header are stubbed; the settings files set a file
# cache the way a site would in civicrm.settings.php.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
patcher="$root/docker/runtime/patch-test-db-boot.php"

stub="$work/civicrm.standalone.php"
cat > "$stub" <<'PHP'
<?php
namespace Civi\Core {
  class SettingsManager {
    public static function bootSettings($settingsPath) { require $settingsPath; }
  }
}
namespace {
  $settingsPath = __DIR__ . '/civicrm.settings.php';
  \Civi\Core\SettingsManager::bootSettings($settingsPath);
}
PHP
cat > "$work/civicrm.settings.php" <<'PHP'
<?php
if (!defined('CIVICRM_DB_CACHE_CLASS')) {
  define('CIVICRM_DB_CACHE_CLASS', 'FileCache');
}
PHP

cp "$stub" "$work/unpatched"
mkdir "$work/old"
php "$patcher" "$stub" >/dev/null || fail "patching the stub failed"
cp "$stub" "$work/patched"
php "$patcher" "$stub" >/dev/null || fail "re-patching the stub failed"
cmp -s "$stub" "$work/patched" || fail "a second patch run must leave the stub unchanged"

# Boots the patched stub; prints the cache class and the DB name core would compose.
boot() {
  local cv="$1"
  shift
  env CIVICRM_DB_NAME=civicrm "$@" php -r '
    $cv = $argv[2];
    if ($cv !== "") { $GLOBALS["_CV"]["TEST_DB_DSN"] = $cv; }
    require $argv[1];
    echo CIVICRM_DB_CACHE_CLASS, " ", getenv("CIVICRM_DB_NAME"), "\n";' "$stub" "$cv"
}

out="$(boot 'mysql://u:p@db:3306/scratch_test' CIVICRM_UF=UnitTests)"
[[ "$out" == "ArrayCache scratch_test" ]] \
  || fail "a UnitTests boot must use the TEST_DB_DSN database and a DB-backed cache: '$out'"

out="$(boot '' CIVICRM_UF=UnitTests)"
[[ "$out" == "ArrayCache civicrm_test" ]] \
  || fail "a UnitTests boot without TEST_DB_DSN must fall back to <db>_test and a DB-backed cache: '$out'"

out="$(boot 'mysql://u:p@db:3306/scratch_test')"
[[ "$out" == "FileCache civicrm" ]] \
  || fail "the dev site must keep its own database and cache class: '$out'"

# The stub leaves the scan depth to core (3): test fixture extensions sit at
# ext/<key>/tests/<fixture>/info.xml.
depth() {
  env CIVICRM_DB_NAME=civicrm "$@" php -r 'require $argv[1];
    echo $GLOBALS["civicrm_setting"]["domain"]["ext_max_depth"] ?? "unset", "\n";' "$stub"
}
[[ "$(depth)" == "unset" ]] || fail "the dev site must keep core's extension scan depth: '$(depth)'"
[[ "$(depth CIVICRM_UF=UnitTests)" == "unset" ]] || fail "a UnitTests boot must keep core's extension scan depth"

php "$patcher" "$work/absent.php" || fail "a missing stub must be a no-op"

# A stub patched by an older image (block without the cache define) gets the
# current block, then boots with a DB-backed cache.
cp "$work/civicrm.settings.php" "$work/old/civicrm.settings.php"
cp "$work/unpatched" "$work/old/civicrm.standalone.php"
php -r '
  $f = $argv[1];
  $old = "// [civikitchen-test-db] Route UnitTests boots at the test DB.\n"
    . "if (getenv(\"CIVICRM_UF\") === \"UnitTests\" && !defined(\"CIVICRM_DSN\")) {\n"
    . "  putenv(\"CIVICRM_DB_NAME=\" . getenv(\"CIVICRM_DB_NAME\") . \"_test\");\n}\n\n";
  $a = "\\Civi\\Core\\SettingsManager::bootSettings";
  $s = file_get_contents($f);
  file_put_contents($f, str_replace($a, $old . $a, $s));' "$work/old/civicrm.standalone.php"
out="$(env CIVICRM_DB_NAME=civicrm CIVICRM_UF=UnitTests php -r 'require $argv[1];
  echo CIVICRM_DB_CACHE_CLASS, " ", getenv("CIVICRM_DB_NAME"), "\n";' "$work/old/civicrm.standalone.php")"
[[ "$out" == "FileCache civicrm_test" ]] || fail "the old-block fixture must lack the cache define: '$out'"
php "$patcher" "$work/old/civicrm.standalone.php" >/dev/null || fail "upgrading an old block failed"
cmp -s "$work/old/civicrm.standalone.php" "$work/patched" \
  || fail "an old block must be replaced by exactly the current one"
out="$(env CIVICRM_DB_NAME=civicrm CIVICRM_UF=UnitTests php -r 'require $argv[1];
  echo CIVICRM_DB_CACHE_CLASS, " ", getenv("CIVICRM_DB_NAME"), "\n";' "$work/old/civicrm.standalone.php")"
[[ "$out" == "ArrayCache civicrm_test" ]] || fail "an upgraded stub must boot with a DB-backed cache: '$out'"

# ~/.cv.json and the stub live in the container, the config marker in private/:
# a recreated container must get both back on every boot, outside that bundle.
entrypoint="$root/docker/standalone/entrypoint.sh"
grep -qx 'ck_wire_test_db_boot' "$entrypoint" \
  || fail "the entrypoint must call ck_wire_test_db_boot unconditionally at top level"
# shellcheck source=docker/runtime/provision.sh
. "$root/docker/runtime/provision.sh"
if declare -f ck_post_install_config ck_setup_test_db | grep -qE "ck_wire_test_db_boot|patch-test-db-boot|cv\.json"; then
  fail "the .cv.json write and the stub patch must not sit behind the configured marker"
fi
# Runs ck_wire_test_db_boot with php and chown stubbed; homes and stub in $work.
wire() {
  env CK_ROOT_HOME="$work/root" CK_WEB_USER_HOME="$work/www" CK_BOOT_STUB="$work/stub-arg" \
    CIVICRM_DB_USER=u CIVICRM_DB_PASSWORD='p"w' CIVICRM_DB_HOST=db CIVICRM_DB_PORT=3306 \
    CIVICRM_DB_NAME=civicrm PHP_CALL="$work/php-call" "$@" bash -c '
      . "$0"
      php() { printf "%s\n" "$*" > "$PHP_CALL"; }
      chown() { :; }
      ck_wire_test_db_boot' "$root/docker/runtime/provision.sh"
}
dsn_in() {
  php -r 'echo json_decode(file_get_contents($argv[1]), TRUE)["sites"]["/var/www/html/civicrm.standalone.php"]["TEST_DB_DSN"];' "$1"
}
mkdir "$work/root" "$work/www"
wire
grep -qx "/usr/local/share/civikitchen/patch-test-db-boot.php $work/stub-arg" "$work/php-call" \
  || fail "ck_wire_test_db_boot must patch CK_BOOT_STUB by default"
for f in "$work/root/.cv.json" "$work/www/.cv.json"; do
  [[ "$(dsn_in "$f")" == 'mysql://u:p"w@db:3306/civicrm_test?new_link=true' ]] \
    || fail "$f must carry the test DSN"
done

site_key_in() {
  php -r '$s = json_decode(file_get_contents($argv[1]), TRUE)["sites"]["/var/www/html/civicrm.standalone.php"]; echo $s[$argv[2]] ?? "<unset>";' "$1" "$2"
}
[[ "$(site_key_in "$work/www/.cv.json" ADMIN_USER)" == '<unset>' ]] \
  || fail "without a demo user ~/.cv.json must not name an ADMIN_USER"
/bin/rm -f "$work/root/.cv.json" "$work/www/.cv.json"
wire CIVIKITCHEN_DEMO_USER=admin CIVIKITCHEN_DEMO_PASS='s\e"cret'
for role in ADMIN DEMO; do
  [[ "$(site_key_in "$work/www/.cv.json" "${role}_USER")" == 'admin' \
    && "$(site_key_in "$work/www/.cv.json" "${role}_PASS")" == 's\e"cret' \
    && "$(site_key_in "$work/www/.cv.json" "${role}_EMAIL")" == 'admin@example.org' ]] \
    || fail "the demo user must reach ~/.cv.json as ${role}_USER/_PASS/_EMAIL for browser tests"
done
[[ "$(dsn_in "$work/www/.cv.json")" == 'mysql://u:p"w@db:3306/civicrm_test?new_link=true' ]] \
  || fail "the test DSN must stay beside the users in ~/.cv.json"

echo '{"sites":{}}' > "$work/root/.cv.json"
wire
[[ "$(cat "$work/root/.cv.json")" == '{"sites":{}}' ]] || fail "an existing ~/.cv.json must not be clobbered"

/bin/rm -f "$work/php-call" "$work/root/.cv.json" "$work/www/.cv.json"
wire CIVIKITCHEN_TEST_DB=0
[[ ! -e "$work/root/.cv.json" && ! -e "$work/www/.cv.json" ]] \
  || fail "CIVIKITCHEN_TEST_DB=0 must skip the .cv.json write"
[[ -e "$work/php-call" ]] \
  || fail "CIVIKITCHEN_TEST_DB=0 must still patch the stub, which reads a hand-set TEST_DB_DSN"

# An unwritable stub must fail, not report a patch it did not apply. Root writes anyway.
if [[ "$(id -u)" -ne 0 ]]; then
  echo '<?php \Civi\Core\SettingsManager::bootSettings($settingsPath);' > "$work/readonly.php"
  chmod 444 "$work/readonly.php"
  if php "$patcher" "$work/readonly.php" >"$work/out" 2>/dev/null; then
    fail "an unwritable stub must exit non-zero"
  fi
  if grep -q Patched "$work/out"; then
    fail "an unwritable stub must not be reported as patched"
  fi
fi

# Buildkit flavors: civibuild's settings header loads pre.d before the template.
cat > "$work/civibuild.settings.php" <<PHP
<?php
require '$root/docker/buildkit/test-db-cache.php';
if (!defined('CIVICRM_UF')) {
  define('CIVICRM_UF', getenv('CIVICRM_UF') ?: 'WordPress');
}
if (!defined('CIVICRM_DB_CACHE_CLASS')) {
  define('CIVICRM_DB_CACHE_CLASS', 'FileCache');
}
echo CIVICRM_DB_CACHE_CLASS, "\n";
PHP
out="$(CIVICRM_UF=UnitTests php "$work/civibuild.settings.php")"
[[ "$out" == "ArrayCache" ]] || fail "a buildkit UnitTests boot must use a DB-backed cache: '$out'"
out="$(php -r 'define("CIVICRM_UF", "UnitTests"); require $argv[1];' "$work/civibuild.settings.php")"
[[ "$out" == "ArrayCache" ]] || fail "a predefined CIVICRM_UF=UnitTests must select a DB-backed cache: '$out'"
out="$(env -u CIVICRM_UF php "$work/civibuild.settings.php")"
[[ "$out" == "FileCache" ]] || fail "the buildkit dev site must keep its cache class: '$out'"

echo "test-db boot patch: ok"
