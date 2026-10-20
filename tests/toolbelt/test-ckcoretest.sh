#!/usr/bin/env bash
# ckcoretest --e2e runs only @group e2e against the dev site, as www-data when started as root:
# no UnitTests UF and no TEST_DB_DSN gate. Headless runs keep both and get core's startup ini.
# Fakes record the calls.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

core="$work/core"
mkdir -p "$core/xml" "$core/tests/phpunit" "$core/ext/afform/mock" "$work/bin"
echo '<?xml version="1.0"?><version><version_no>6.20.alpha1</version_no></version>' > "$core/xml/version.xml"
cat > "$work/bin/phpunit" <<'FAKE'
#!/usr/bin/env bash
printf 'dir=%s uf=%s args=%s\n' "$PWD" "${CIVICRM_UF:-}" "$*" > "$PHPUNIT_LOG"
FAKE
chmod +x "$work/bin/phpunit"
# ckcoretest itself needs php (bin/ck); only the phpunit launch is recorded.
cat > "$work/bin/php" <<FAKE
#!/usr/bin/env bash
for a in "\$@"; do
  [[ "\$a" == "$work/bin/phpunit" ]] || continue
  printf 'uf=%s args=%s\\n' "\${CIVICRM_UF:-}" "\$*" > "\$PHP_LOG"
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
[[ "$(cat "$work/php.log" 2>/dev/null)" == "uf=UnitTests args=-d sendmail_path=/bin/true -d variables_order=EGPCS $work/bin/phpunit --filter testX tests/phpunit/CRM" ]] \
  || fail "a headless run must start phpunit under php with a sendmail sink and variables_order=EGPCS: $(cat "$work/php.log" 2>/dev/null)"
[[ ! -e "$work/phpunit.log" ]] || fail "a headless run must not start phpunit without the sendmail sink"

echo "ckcoretest: ok"
