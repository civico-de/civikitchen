#!/usr/bin/env bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
test -L "$root/toolbelt/bin/ckcoverage"
test "$(readlink "$root/toolbelt/bin/ckcoverage")" = ck
php -l "$root/toolbelt/lib/php/src/Cli/CoverageCommand.php" >/dev/null
"$root/toolbelt/bin/ckcoverage" --help >/dev/null 2>&1 || true

work=$(mktemp -d)
trap '/bin/rm -rf "$work"' EXIT
unset PHP_INI_SCAN_DIR
mkdir -p "$work/bin" "$work/ext" "$work/tmp"
cat > "$work/ext/info.xml" <<'EOF'
<extension key="fixture" type="module"><file>fixture</file></extension>
EOF
cat > "$work/ext/phpunit.xml.dist" <<'EOF'
<phpunit><coverage/></phpunit>
EOF
# Stands in for phpunit: writes clover metrics plus a junit log holding
# CK_FIXTURE_TESTS run and CK_FIXTURE_SKIPPED skipped cases, and exits 0 the way
# phpunit does on an empty or entirely skipped run. The LAST --log-junit wins,
# as in phpunit.
cat > "$work/bin/ckphpunit" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
clover=
junit=
while [ "$#" -gt 0 ]; do
  case "$1" in
    --coverage-clover) shift; clover=$1 ;;
    --coverage-clover=*) clover=${1#*=} ;;
    --log-junit) shift; junit=$1 ;;
    --log-junit=*) junit=${1#*=} ;;
  esac
  shift
done
test -n "$clover"
test -n "$junit"
# Like pcov: covers only if pcov.directory (ini, else ./src|lib|app, else cwd) is the
# cwd. One scan dir follows CK_FIXTURE_SCAN_HEAD; "none" is empty (proc_open drops '').
head=${CK_FIXTURE_SCAN_HEAD:-:}
if [ "$head" = none ]; then head=; fi
scan=${PHP_INI_SCAN_DIR-}
case "$scan" in
  "$head"*) ;;
  *) echo "PHP_INI_SCAN_DIR lost its head: $scan" >&2; exit 1 ;;
esac
ini_dir=${scan#"$head"}
case "$ini_dir" in *:*|'') echo "PHP_INI_SCAN_DIR has no single directory after its head: $scan" >&2; exit 1 ;; esac
directory=$(sed -n 's/^pcov\.directory="\(.*\)"$/\1/p' "$ini_dir"/*.ini 2>/dev/null | tail -n 1)
if [ -z "$directory" ]; then
  directory=$(pwd -P)
  for guess in src lib app; do
    if [ -d "$guess" ]; then directory=$(pwd -P)/$guess; break; fi
  done
fi
covered=0
if [ "$directory" = "$(pwd -P)" ]; then covered=6; fi
if [ -n "${CK_FIXTURE_FILTER_WARNING:-}" ]; then
  echo "Warning:       ${CK_FIXTURE_FILTER_WARNING}, code coverage will not be processed"
else
  printf '<coverage><project><metrics statements="8" coveredstatements="%s"/></project></coverage>\n' \
    "$covered" > "$clover"
fi
{
  echo '<testsuites>'
  i=0
  while [ "$i" -lt "${CK_FIXTURE_TESTS:-3}" ]; do
    echo "  <testsuite name=\"fixture\"><testcase name=\"t$i\"/></testsuite>"
    i=$((i + 1))
  done
  i=0
  while [ "$i" -lt "${CK_FIXTURE_SKIPPED:-0}" ]; do
    echo "  <testsuite name=\"fixture\"><testcase name=\"s$i\"><skipped/></testcase></testsuite>"
    i=$((i + 1))
  done
  echo '</testsuites>'
} > "$junit"
exit 0
EOF
chmod +x "$work/bin/ckphpunit"
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" TMPDIR="$work/tmp" "$root/toolbelt/bin/ckcoverage")
grep -q '75.00% line coverage (6/8 statements)' <<<"$out"
grep -q 'reporting only' <<<"$out"
test -z "$(ls -A "$work/tmp")"

echo 'ok   ckcoverage uses the shared PHP CLI and leaves nothing in TMPDIR'

# pcov guesses its directory as ./src when one exists, which in an extension
# with a JavaScript src/ is no PHP at all: ckcoverage points it at the root.
mkdir "$work/ext/src"
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" TMPDIR="$work/tmp" "$root/toolbelt/bin/ckcoverage")
grep -q '75.00% line coverage (6/8 statements)' <<<"$out"
# The caller's scan directories stay in front; an empty variable scans nothing.
# ckcoverage itself runs on one ini holding everything the host loads, since
# the fixture values would strip its own extensions (simplexml on Debian).
php -r 'foreach (array_merge([php_ini_loaded_file()], array_map("trim", explode(",", (string) php_ini_scanned_files()))) as $f) { if ($f) { readfile($f); echo "\n"; } }' >"$work/host.ini"
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" TMPDIR="$work/tmp" PHP_INI_SCAN_DIR=/caller/ini \
  CK_FIXTURE_SCAN_HEAD=/caller/ini: php -c "$work/host.ini" "$root/toolbelt/bin/ckcoverage")
grep -q '75.00% line coverage (6/8 statements)' <<<"$out"
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" TMPDIR="$work/tmp" PHP_INI_SCAN_DIR='' \
  CK_FIXTURE_SCAN_HEAD=none php -c "$work/host.ini" "$root/toolbelt/bin/ckcoverage")
grep -q '75.00% line coverage (6/8 statements)' <<<"$out"
rmdir "$work/ext/src"
test -z "$(ls -A "$work/tmp")"

echo 'ok   ckcoverage points pcov at the extension root and keeps the caller'"'"'s ini directories'

# A quote or $ in the path would break or expand the ini value.
mkdir "$work/q\"x"
cp "$work/ext/info.xml" "$work/ext/phpunit.xml.dist" "$work/q\"x/"
status=0
out=$(cd "$work/q\"x" && PATH="$work/bin:$PATH" TMPDIR="$work/tmp" "$root/toolbelt/bin/ckcoverage" 2>&1) || status=$?
test "$status" -ne 0
grep -q 'must not contain' <<<"$out"
test -z "$(ls -A "$work/tmp")"

echo 'ok   ckcoverage refuses an extension path pcov.directory cannot hold'

# PHPUnit 9 reads the include list from <coverage> or the legacy
# <filter><whitelist>. It ignores <source>; a commented-out section is no section.
cat > "$work/ext/phpunit.xml.dist" <<'EOF'
<phpunit><filter><whitelist><directory>src</directory></whitelist></filter></phpunit>
EOF
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" "$root/toolbelt/bin/ckcoverage")
grep -q 'line coverage' <<<"$out"
for config in '<phpunit/>' '<phpunit><!-- <coverage/> --></phpunit>' \
  '<phpunit><source><include><directory>src</directory></include></source></phpunit>'; do
  printf '%s\n' "$config" > "$work/ext/phpunit.xml.dist"
  status=0
  out=$(cd "$work/ext" && PATH="$work/bin:$PATH" "$root/toolbelt/bin/ckcoverage" 2>&1) || status=$?
  test "$status" -ne 0
  grep -q 'no <coverage> section' <<<"$out"
done
grep -q 'PHPUnit 9 ignores <source>' <<<"$out"
printf '<phpunit><coverage>\n' > "$work/ext/phpunit.xml.dist"
status=0
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" "$root/toolbelt/bin/ckcoverage" 2>&1) || status=$?
test "$status" -ne 0
grep -q 'not well-formed XML' <<<"$out"
cat > "$work/ext/phpunit.xml.dist" <<'EOF'
<phpunit><coverage/></phpunit>
EOF
# Without sources, or with paths matching no file, phpunit warns and writes no
# clover report; the message names that rather than the coverage driver.
for case in 'No filter is configured:no coverage filter' \
  'Incorrect filter configuration:matches no file'; do
  status=0
  out=$(cd "$work/ext" && PATH="$work/bin:$PATH" CK_FIXTURE_FILTER_WARNING="${case%%:*}" \
    "$root/toolbelt/bin/ckcoverage" 2>&1) || status=$?
  test "$status" -ne 0
  grep -q "${case#*:}" <<<"$out"
  if grep -q 'pcov/xdebug' <<<"$out"; then exit 1; fi
done

echo 'ok   ckcoverage reads the include list where PHPUnit 9 does and names a filter that measures nothing'

# A configured floor of 0 is a floor, not an unset key: the run must report it
# as met instead of falling back to "reporting only".
cat > "$work/ext/civikitchen.yaml" <<'EOF'
version: 1
policy:
  coverage:
    minimum: 0
EOF
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" "$root/toolbelt/bin/ckcoverage")
grep -q 'floor 0% met' <<<"$out"
if grep -q 'reporting only' <<<"$out"; then exit 1; fi

echo 'ok   ckcoverage reads policy.coverage.minimum 0 as a configured floor'

# phpunit exits 0 on "No tests executed!": the empty suite must fail on its own,
# whatever the percentage says.
status=0
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" CK_FIXTURE_TESTS=0 \
  "$root/toolbelt/bin/ckcoverage" 2>&1) || status=$?
test "$status" -ne 0
grep -q 'no test was executed' <<<"$out"

echo 'ok   ckcoverage fails when no test was executed'

# A suite that skipped every test exits 0 too, and is not a passing suite; the
# message has to name the skipping rather than claim an empty suite.
status=0
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" CK_FIXTURE_TESTS=0 CK_FIXTURE_SKIPPED=2 \
  "$root/toolbelt/bin/ckcoverage" 2>&1) || status=$?
test "$status" -ne 0
grep -q 'every test was skipped' <<<"$out"

# One test that ran among skipped ones is a run.
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" CK_FIXTURE_TESTS=1 CK_FIXTURE_SKIPPED=2 \
  "$root/toolbelt/bin/ckcoverage")
grep -q 'line coverage' <<<"$out"

echo 'ok   ckcoverage fails when every test was skipped and passes a mixed run'

# A caller's own --log-junit wins in phpunit, so the count must come from THAT
# file - in both spellings - and the file must survive the run.
caller="$work/caller-junit.xml"
for form in separate joined; do
  if [ "$form" = separate ]; then
    set -- --log-junit "$caller"
  else
    set -- "--log-junit=$caller"
  fi
  /bin/rm -f "$caller"
  status=0
  out=$(cd "$work/ext" && PATH="$work/bin:$PATH" CK_FIXTURE_TESTS=0 \
    "$root/toolbelt/bin/ckcoverage" "$@" 2>&1) || status=$?
  test "$status" -ne 0
  grep -q 'no test was executed' <<<"$out"
  test -s "$caller"
  out=$(cd "$work/ext" && PATH="$work/bin:$PATH" "$root/toolbelt/bin/ckcoverage" "$@")
  grep -q 'line coverage' <<<"$out"
  test -s "$caller"
done

echo 'ok   ckcoverage reads the caller-supplied --log-junit in both spellings'

# The same empty suite in a repo that declares tests optional is not an error,
# even though the phpunit config exists.
cat > "$work/ext/civikitchen.yaml" <<'EOF'
version: 1
policy:
  tests:
    mode: optional
    reason: fixture extension has no PHP
EOF
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" CK_FIXTURE_TESTS=0 \
  "$root/toolbelt/bin/ckcoverage")
grep -q 'policy.tests declares tests optional' <<<"$out"

out=$(cd "$work/ext" && PATH="$work/bin:$PATH" CK_FIXTURE_TESTS=0 CK_FIXTURE_SKIPPED=2 \
  "$root/toolbelt/bin/ckcoverage")
grep -q 'every test was skipped, and policy.tests' <<<"$out"

echo 'ok   ckcoverage honours policy.tests=optional with a phpunit config present'

# A phpunit config without a <coverage> section is likewise nothing to measure
# rather than a failure once tests are declared optional.
cat > "$work/ext/phpunit.xml.dist" <<'EOF'
<phpunit/>
EOF
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" "$root/toolbelt/bin/ckcoverage")
grep -q 'nothing to measure' <<<"$out"
cat > "$work/ext/phpunit.xml.dist" <<'EOF'
<phpunit><coverage/></phpunit>
EOF

echo 'ok   ckcoverage skips a config without <coverage> when tests are optional'

# No phpunit config at all: policy.tests=optional must still be recognised
# once ckconform renders it with its mandatory reason, not just the bare word.
rm "$work/ext/phpunit.xml.dist"
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" "$root/toolbelt/bin/ckcoverage")
grep -q 'nothing to measure' <<<"$out"

echo 'ok   ckcoverage accepts policy.tests=optional with its reason attached'

# No phpunit config and no opt-out: an unrelated policy value must not be
# mistaken for the declaration, and the command must still fail.
cat > "$work/ext/civikitchen.yaml" <<'EOF'
version: 1
policy:
  copyright: Fixture Inc.
EOF
status=0
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" "$root/toolbelt/bin/ckcoverage" 2>&1) || status=$?
test "$status" -ne 0
grep -q 'no phpunit config' <<<"$out"

echo 'ok   ckcoverage still fails without phpunit config and no tests opt-out'
