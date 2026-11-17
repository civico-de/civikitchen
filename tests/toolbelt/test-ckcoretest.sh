#!/usr/bin/env bash
# ckcoretest: --e2e runs @group e2e against the dev site without the test-DB gate,
# headless runs keep it; untagged cores stop before fetching; mounts keep their tests. Fakes record calls.
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

# tests/ from a checkout mounted into a release tarball: the phpunit config and the seed
# SQL still come from the tag, and the mounted tests stay as they are.
git init --quiet --initial-branch=main "$work/tagged"
mkdir -p "$work/tagged/tests/phpunit" "$work/tagged/sql"
echo '<phpunit bootstrap="tests/phpunit/CiviTest/bootstrap.php"/>' > "$work/tagged/phpunit.xml.dist"
echo 'of the tag' > "$work/tagged/tests/phpunit/FromTagTest.php"
echo 'seed' > "$work/tagged/sql/test_data.mysql"
echo 'second' > "$work/tagged/sql/test_data_second_domain.mysql"
mkdir -p "$work/tagged/ext/afform/mock/tests" "$work/tagged/ext/search_kit/tests"
echo 'of the tag' > "$work/tagged/ext/afform/mock/tests/FromTagTest.php"
echo 'of the tag' > "$work/tagged/ext/search_kit/tests/FromTagTest.php"
git -C "$work/tagged" add --all
git -C "$work/tagged" -c user.name=Test -c user.email=test@example.org -c commit.gpgsign=false commit --quiet --message release
git -C "$work/tagged" tag 6.20.1
mounted="$work/mounted"
mkdir -p "$mounted/xml" "$mounted/sql" "$mounted/tests/phpunit"
echo '<?xml version="1.0"?><version><version_no>6.20.1</version_no></version>' > "$mounted/xml/version.xml"
echo 'of the checkout' > "$mounted/tests/phpunit/ReproducerTest.php"
echo 'of the checkout' > "$mounted/sql/test_data.mysql"
CK_CORE_DIR="$mounted" CK_CORE_REPO="$work/tagged" bash "$root/toolbelt/bin/ckcoretest" --fetch-only >"$work/out" 2>&1 \
  || fail "a mounted tests/ without phpunit.xml.dist must fetch the config: $(cat "$work/out")"
grep -q 'bootstrap=' "$mounted/phpunit.xml.dist" || fail "phpunit.xml.dist was not fetched from the tag: $(cat "$work/out")"
[ "$(cat "$mounted/sql/test_data_second_domain.mysql")" = second ] || fail "the missing seed SQL was not fetched from the tag"
[ "$(cat "$mounted/sql/test_data.mysql")" = 'of the checkout' ] || fail "seed SQL that is there, as in a mounted sql/, must stay as it is"
[[ "$(cat "$mounted/tests/phpunit/ReproducerTest.php")" == 'of the checkout' && ! -e "$mounted/tests/phpunit/FromTagTest.php" \
  && ! -e "$mounted/tests/.ckcoretest-version" ]] || fail "a mounted tests/ must stay as it is: $(ls -A "$mounted/tests" "$mounted/tests/phpunit")"
# With the config in place the tree is complete, and nothing is fetched again.
CK_CORE_DIR="$mounted" CK_CORE_REPO="$work/nowhere" bash "$root/toolbelt/bin/ckcoretest" --fetch-only >"$work/out" 2>&1 \
  || fail "a complete tree must not fetch: $(cat "$work/out")"
grep -q 'not touching it' "$work/out" || fail "a complete tree must be left alone: $(cat "$work/out")"

# A fetch that stops halfway leaves no config behind, so the next run fetches again.
partial="$work/partial"
mkdir -p "$partial/xml" "$partial/tests/phpunit"
echo '<?xml version="1.0"?><version><version_no>6.20.1</version_no></version>' > "$partial/xml/version.xml"
echo 'in the way' > "$partial/sql"
if CK_CORE_DIR="$partial" CK_CORE_REPO="$work/tagged" bash "$root/toolbelt/bin/ckcoretest" --fetch-only >"$work/out" 2>&1; then
  fail "seed SQL that cannot be written must fail the fetch: $(cat "$work/out")"
fi
[[ ! -e "$partial/phpunit.xml.dist" ]] || fail "a fetch that failed on the seed SQL must not leave the config that ends fetching"

# A release image with an extension checkout mounted: the tag's tests fill core and the
# other extensions, while the mounted extension keeps its own.
tarball="$work/tarball"
mkdir -p "$tarball/xml" "$tarball/sql" "$tarball/ext/afform/mock/tests" "$tarball/ext/search_kit"
echo '<?xml version="1.0"?><version><version_no>6.20.1</version_no></version>' > "$tarball/xml/version.xml"
echo 'of the checkout' > "$tarball/ext/afform/mock/tests/ReproducerTest.php"
printf '36 25 0:31 / / rw - overlay overlay rw\n90 36 0:52 /src/afform %s/ext/afform rw - virtiofs mount0 rw\n' "$tarball" > "$work/mountinfo"
CK_MOUNTINFO="$work/mountinfo" CK_CORE_DIR="$tarball" CK_CORE_REPO="$work/tagged" bash "$root/toolbelt/bin/ckcoretest" --fetch-only >"$work/out" 2>&1 \
  || fail "a release tree with a mounted extension must fetch: $(cat "$work/out")"
[[ -e "$tarball/tests/phpunit/FromTagTest.php" && -e "$tarball/ext/search_kit/tests/FromTagTest.php" ]] \
  || fail "core and unmounted extensions must get the tag's tests: $(cat "$work/out")"
[[ "$(cat "$tarball/ext/afform/mock/tests/ReproducerTest.php" 2>/dev/null)" == 'of the checkout' \
  && ! -e "$tarball/ext/afform/mock/tests/FromTagTest.php" ]] || fail "a mounted extension's tests must stay as they are: $(cat "$work/out")"

# A mounted tests/ without tests/phpunit is refused, not emptied.
emptymount="$work/emptymount"
mkdir -p "$emptymount/xml" "$emptymount/sql" "$emptymount/tests"
echo '<?xml version="1.0"?><version><version_no>6.20.1</version_no></version>' > "$emptymount/xml/version.xml"
echo 'of the checkout' > "$emptymount/tests/README"
printf '90 36 0:52 /src/tests %s/tests rw - virtiofs mount0 rw\n' "$emptymount" > "$work/mountinfo"
if CK_MOUNTINFO="$work/mountinfo" CK_CORE_DIR="$emptymount" CK_CORE_REPO="$work/tagged" bash "$root/toolbelt/bin/ckcoretest" --fetch-only >"$work/out" 2>&1; then
  fail "a mounted tests/ without tests/phpunit must refuse: $(cat "$work/out")"
fi
[ "$(cat "$emptymount/tests/README")" = 'of the checkout' ] || fail "a refused fetch must leave the mounted tests/ alone"

echo "ckcoretest: ok"
