#!/usr/bin/env bash
# ck_setup_test_db creates, grants and seeds <db>_test as the database root user.
# Fake mysql / mysqldump record what is issued and can be told to fail.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/home"
fail() { echo "FAIL: $*" >&2; exit 1; }

cat > "$work/bin/mysql" <<'FAKE'
#!/usr/bin/env bash
printf 'mysql %s\n' "$*" >> "$MYSQL_LOG"
cat >> "$MYSQL_LOG"
if [[ -n "${MYSQL_FAIL:-}" ]]; then
  echo "ERROR 1045 (28000): Access denied for user 'civicrm'@'%'" >&2
  exit 1
fi
echo "mysql: [Warning] Using a password on the command line interface can be insecure." >&2
FAKE
cat > "$work/bin/mysqldump" <<'FAKE'
#!/usr/bin/env bash
printf 'mysqldump %s\n' "$*" >> "$MYSQL_LOG"
if [[ -n "${DUMP_FAIL:-}" ]]; then
  echo "mysqldump: Got error: 1044: Access denied" >&2
  exit 2
fi
echo "-- dump"
FAKE
printf '#!/usr/bin/env bash\nexit 0\n' > "$work/bin/chown"
printf '#!/usr/bin/env bash\nexit 0\n' > "$work/bin/php"
chmod +x "$work/bin/mysql" "$work/bin/mysqldump" "$work/bin/chown" "$work/bin/php"
export PATH="$work/bin:$PATH"
export MYSQL_LOG="$work/mysql.log"

export CIVICRM_DB_HOST=db CIVICRM_DB_PORT=3306 CIVICRM_DB_NAME=civicrm \
       CIVICRM_DB_USER=civicrm CIVICRM_DB_PASSWORD=civicrm
export CK_WEB_USER_HOME="$work/home" CK_BOOT_STUB=""
ck_as_web() { "$@"; }
# shellcheck source=../../docker/runtime/provision.sh
. "$root/docker/runtime/provision.sh"

# ck_setup_test_db aborts the whole step when the DB work fails, so the boot
# fails instead of marking an unusable test DB as configured.
grep -q 'ck_provision_test_db "${test_db_name}" || return 1' "$root/docker/runtime/provision.sh" \
  || fail "ck_setup_test_db must abort when ck_provision_test_db fails"

# The install itself creates triggers and functions, so the server-level
# trust is set as root before `cv core:install`, not only for the test DB.
grep -B1 -- 'ck_as_web cv core:install' "$root/docker/standalone/entrypoint.sh" | grep -q 'ck_trust_function_creators' \
  || fail "the standalone entrypoint must call ck_trust_function_creators before cv core:install"
: > "$MYSQL_LOG"
ck_trust_function_creators >/dev/null 2>"$work/err.log" || fail "ck_trust_function_creators failed on the happy path"
grep -q -- '-u root' "$MYSQL_LOG" || fail "log_bin_trust_function_creators must be set as root"
: > "$MYSQL_LOG"
if MYSQL_FAIL=1 ck_trust_function_creators >/dev/null 2>"$work/err.log"; then
  fail "a refused root connection must fail ck_trust_function_creators"
fi
grep -q 'WARNING' "$work/err.log" || fail "a refused root connection must warn: $(cat "$work/err.log")"

# Happy path: root creates the scratch DB, grants the app user on it, and the
# seed copy runs as root (triggers and routines carry a DEFINER).
: > "$MYSQL_LOG"
ck_provision_test_db civicrm_test >/dev/null 2>"$work/err.log"
grep -q -- '-u root' "$MYSQL_LOG" || fail "the privileged statements must run as root: $(cat "$MYSQL_LOG")"
grep -q 'DROP DATABASE IF EXISTS `civicrm_test`; CREATE DATABASE `civicrm_test`;' "$MYSQL_LOG" \
  || fail "civicrm_test must be recreated, not reused: $(cat "$MYSQL_LOG")"
grep -q "GRANT ALL PRIVILEGES ON \`civicrm\\\\_test\`.\* TO 'civicrm'@'%'" "$MYSQL_LOG" \
  || fail "app user not granted on civicrm_test (escaped): $(cat "$MYSQL_LOG")"
grep -q 'SET GLOBAL log_bin_trust_function_creators = 1' "$MYSQL_LOG" \
  || fail "trigger creation by the app user is not unblocked at server level"
grep -q "GRANT SUPER ON \*\.\* TO 'civicrm'@'%'" "$MYSQL_LOG" \
  || fail "SUPER (Civi\\Test\\Schema's SET global) not granted"
grep -q 'mysqldump .*-u root' "$MYSQL_LOG" || fail "the seed dump must run as root"
! grep -q 'Using a password' "$work/err.log" || fail "the password notice must not be surfaced"

# A refused privileged connection is loud, quotes the server error, and fails
# the boot instead of leaving an unusable scratch DB behind.
: > "$MYSQL_LOG"
if MYSQL_FAIL=1 ck_provision_test_db civicrm_test >/dev/null 2>"$work/err.log"; then
  fail "a refused root connection must fail ck_provision_test_db"
fi
grep -q 'ERROR 1045' "$work/err.log" || fail "the mysql error text must be printed: $(cat "$work/err.log")"
grep -q 'CIVICRM_DB_ROOT_PASSWORD' "$work/err.log" || fail "the error must name the way out"

# A failing dump is an error, not a silently empty test DB.
: > "$MYSQL_LOG"
if DUMP_FAIL=1 ck_provision_test_db civicrm_test >/dev/null 2>"$work/err.log"; then
  fail "a failing seed dump must fail ck_provision_test_db"
fi
grep -q '1044' "$work/err.log" || fail "the mysqldump error text must be printed: $(cat "$work/err.log")"

# Opting out touches no database at all.
: > "$MYSQL_LOG"
CIVIKITCHEN_TEST_DB=0 ck_setup_test_db >/dev/null 2>&1
[[ ! -s "$MYSQL_LOG" ]] || fail "CIVIKITCHEN_TEST_DB=0 must not touch the database"

echo "provision test DB: ok"
