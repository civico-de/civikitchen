#!/usr/bin/env bash
# ckcoretest --e2e runs only @group e2e against the dev site, as www-data when started as root:
# no UnitTests UF and no TEST_DB_DSN gate. Headless runs keep both and get core's startup ini.
# A test path below a directory with its own phpunit.xml.dist runs from there.
# A core version without a tag stops before fetching. Fakes record the calls.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# Physical path: ckcoretest resolves suite directories with pwd -P.
work="$(cd "$(mktemp -d)" && pwd -P)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

core="$work/core"
router="$core/tests/extensions/router-test"
mkdir -p "$core/xml" "$core/tests/phpunit/CRM" "$core/tests/phpunit/E2E" "$core/ext/afform/mock" \
  "$router/tests/phpunit/CRM" "$work/bin"
touch "$core/phpunit.xml.dist" "$router/phpunit.xml.dist" "$router/tests/phpunit/CRM/PageTest.php"
echo '<?xml version="1.0"?><version><version_no>6.20.alpha1</version_no></version>' > "$core/xml/version.xml"
cat > "$work/bin/phpunit" <<'FAKE'
#!/usr/bin/env bash
if [[ "${1:-}" == --help ]]; then
  printf '  %s\n' '--filter <pattern>          Filter' '--log-junit <file>          Log' \
    '-c|--configuration <file>   Read' '-d <key[=value]>            Sets' '--stop-on-failure           Stop'
  exit 0
fi
printf 'dir=%s uf=%s args=%s\n' "$PWD" "${CIVICRM_UF:-}" "$*" > "$PHPUNIT_LOG"
FAKE
chmod +x "$work/bin/phpunit"
# ckcoretest itself needs php (bin/ck); only the phpunit launch is recorded.
cat > "$work/bin/php" <<FAKE
#!/usr/bin/env bash
for a in "\$@"; do
  [[ "\$a" == "$work/bin/phpunit" ]] || continue
  printf 'dir=%s uf=%s args=%s\\n' "\$PWD" "\${CIVICRM_UF:-}" "\$*" > "\$PHP_LOG"
  exit 0
done
exec "$(command -v php)" "\$@"
FAKE
chmod +x "$work/bin/php"
# The web user's home is $work/www, which has no ~/.cv.json.
printf '#!/bin/sh\necho "www-data:x:33:33::%s/www:/bin/sh"\n' "$work" > "$work/bin/getent"
chmod +x "$work/bin/getent"
export PATH="$work/bin:$PATH" PHPUNIT_LOG="$work/phpunit.log" PHP_LOG="$work/php.log" CK_CORE_DIR="$core"

printf '#!/bin/sh\necho 1000\n' > "$work/bin/id"
chmod +x "$work/bin/id"
CIVICRM_UF=Standalone bash "$root/toolbelt/bin/ckcoretest" --e2e --ext afform/mock tests/phpunit/E2E >/dev/null
[[ "$(cat "$work/phpunit.log")" == "dir=$core/ext/afform/mock uf=Standalone args=--group e2e tests/phpunit/E2E" ]] \
  || fail "--e2e must run @group e2e from the extension with the site's UF: $(cat "$work/phpunit.log")"

# A core test extension with its own phpunit.xml.dist runs from its directory, path made relative.
CIVICRM_UF=Standalone bash "$root/toolbelt/bin/ckcoretest" --e2e tests/extensions/router-test/tests/phpunit >/dev/null
[[ "$(cat "$work/phpunit.log")" == "dir=$router uf=Standalone args=--group e2e tests/phpunit" ]] \
  || fail "--e2e below a nested phpunit.xml.dist must run from that directory: $(cat "$work/phpunit.log")"
CIVICRM_UF=Standalone bash "$root/toolbelt/bin/ckcoretest" --e2e "$router" >/dev/null
[[ "$(cat "$work/phpunit.log")" == "dir=$router uf=Standalone args=--group e2e ." ]] \
  || fail "an absolute path to the suite directory must run from it: $(cat "$work/phpunit.log")"

# Started as root, --e2e drops to the web user.
printf '#!/bin/sh\necho 0\n' > "$work/bin/id"
printf '#!/usr/bin/env bash\necho "$*" > "$RUNUSER_LOG"\nshift 3\nexec "$@"\n' > "$work/bin/runuser"
chmod +x "$work/bin/id" "$work/bin/runuser"
CIVICRM_UF=Standalone RUNUSER_LOG="$work/runuser.log" bash "$root/toolbelt/bin/ckcoretest" --e2e tests/phpunit/E2E >/dev/null
[[ "$(cat "$work/runuser.log" 2>/dev/null)" == "-u www-data -- $work/bin/phpunit --group e2e tests/phpunit/E2E" ]] \
  || fail "--e2e as root must run phpunit as www-data: $(cat "$work/runuser.log" 2>/dev/null)"
[[ "$(cat "$work/phpunit.log")" == "dir=$core uf=Standalone args=--group e2e tests/phpunit/E2E" ]] \
  || fail "--e2e as root must keep the site's UF: $(cat "$work/phpunit.log")"
/bin/rm -f "$work/bin/id" "$work/bin/runuser"

/bin/rm -f "$work/phpunit.log"
if bash "$root/toolbelt/bin/ckcoretest" --ext afform/mock >/dev/null 2>"$work/err"; then
  fail "a headless run without TEST_DB_DSN must refuse"
fi
grep -q 'No TEST_DB_DSN' "$work/err" || fail "the refusal must name the missing TEST_DB_DSN: $(cat "$work/err")"
[[ ! -e "$work/phpunit.log" ]] || fail "a refused headless run must not start phpunit"

mkdir -p "$work/www"
echo '{"TEST_DB_DSN":"mysql://test@db/test"}' > "$work/www/.cv.json"
bash "$root/toolbelt/bin/ckcoretest" --filter testX tests/phpunit/CRM >/dev/null
[[ "$(cat "$work/php.log" 2>/dev/null)" == "dir=$core uf=UnitTests args=-d sendmail_path=/bin/true -d variables_order=EGPCS $work/bin/phpunit --filter testX tests/phpunit/CRM" ]] \
  || fail "a headless run must start phpunit under php with a sendmail sink and variables_order=EGPCS: $(cat "$work/php.log" 2>/dev/null)"
[[ ! -e "$work/phpunit.log" ]] || fail "a headless run must not start phpunit without the sendmail sink"

# Headless too: the suite's own bootstrap leaves the full boot to the listener.
bash "$root/toolbelt/bin/ckcoretest" --filter testX tests/extensions/router-test/tests/phpunit/CRM/PageTest.php >/dev/null
[[ "$(cat "$work/php.log" 2>/dev/null)" == "dir=$router uf=UnitTests args=-d sendmail_path=/bin/true -d variables_order=EGPCS $work/bin/phpunit --filter testX tests/phpunit/CRM/PageTest.php" ]] \
  || fail "a headless test file below its own phpunit.xml.dist must run from that directory: $(cat "$work/php.log" 2>/dev/null)"

# PHPUnit options without a test path run core's default suite: the last word is no path.
bash "$root/toolbelt/bin/ckcoretest" --filter testX >/dev/null
[[ "$(cat "$work/php.log" 2>/dev/null)" == "dir=$core uf=UnitTests args=-d sendmail_path=/bin/true -d variables_order=EGPCS $work/bin/phpunit --filter testX" ]] \
  || fail "an option value as the last word must reach phpunit unchanged: $(cat "$work/php.log" 2>/dev/null)"
CIVICRM_UF=Standalone bash "$root/toolbelt/bin/ckcoretest" --e2e --stop-on-failure >/dev/null
[[ "$(cat "$work/phpunit.log")" == "dir=$core uf=Standalone args=--group e2e --stop-on-failure" ]] \
  || fail "an option as the last word must reach phpunit unchanged: $(cat "$work/phpunit.log")"
# A value of an option that takes one is no test path, even when it looks like one or exists.
CIVICRM_UF=Standalone bash "$root/toolbelt/bin/ckcoretest" --e2e --log-junit /nonexistent/junit.xml >/dev/null
[[ "$(cat "$work/phpunit.log")" == "dir=$core uf=Standalone args=--group e2e --log-junit /nonexistent/junit.xml" ]] \
  || fail "an option value that looks like a path must reach phpunit unchanged: $(cat "$work/phpunit.log")"
CIVICRM_UF=Standalone bash "$root/toolbelt/bin/ckcoretest" --e2e --filter '/testFoo/' >/dev/null
[[ "$(cat "$work/phpunit.log")" == "dir=$core uf=Standalone args=--group e2e --filter /testFoo/" ]] \
  || fail "a --filter regex must reach phpunit unchanged: $(cat "$work/phpunit.log")"
CIVICRM_UF=Standalone bash "$root/toolbelt/bin/ckcoretest" --e2e -c tests/extensions/router-test/phpunit.xml.dist >/dev/null
[[ "$(cat "$work/phpunit.log")" == "dir=$core uf=Standalone args=--group e2e -c tests/extensions/router-test/phpunit.xml.dist" ]] \
  || fail "an existing option value below a nested suite must not move the run: $(cat "$work/phpunit.log")"
CIVICRM_UF=Standalone bash "$root/toolbelt/bin/ckcoretest" --e2e --stop-on-failure tests/extensions/router-test/tests/phpunit >/dev/null
[[ "$(cat "$work/phpunit.log")" == "dir=$router uf=Standalone args=--group e2e --stop-on-failure tests/phpunit" ]] \
  || fail "a test path after a flag without value must still run from its suite: $(cat "$work/phpunit.log")"

/bin/rm -f "$work/php.log"
if bash "$root/toolbelt/bin/ckcoretest" tests/phpunit/Nope >/dev/null 2>"$work/err"; then
  fail "a test path that does not exist must refuse"
fi
grep -q "no test file or directory 'tests/phpunit/Nope'" "$work/err" || fail "the refusal must name the missing path: $(cat "$work/err")"
[[ ! -e "$work/php.log" ]] || fail "a refused run must not start phpunit"

# Core without tests/ whose version has no tag (an alpha): no clone, a message instead.
alpha="$work/alpha"
mkdir -p "$alpha/xml"
echo '<?xml version="1.0"?><version><version_no>6.21.alpha1</version_no></version>' > "$alpha/xml/version.xml"
git init --quiet --bare "$work/core.git"
if CK_CORE_DIR="$alpha" CK_CORE_REPO="$work/core.git" bash "$root/toolbelt/bin/ckcoretest" --fetch-only >/dev/null 2>"$work/err"; then
  fail "fetching tests for a version without a tag must refuse"
fi
grep -q 'core 6.21.alpha1 has no tag' "$work/err" || fail "the refusal must name the untagged version: $(cat "$work/err")"
grep -q 'CIVICRM_SOURCE=git' "$work/err" || fail "the refusal must point at git-source images: $(cat "$work/err")"
[[ ! -e "$alpha/tests" ]] || fail "a refused fetch must not touch the core tree"

echo "ckcoretest: ok"
