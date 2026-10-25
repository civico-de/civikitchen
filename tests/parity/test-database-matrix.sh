#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
workflow="$root/.github/workflows/build-dev-images.yml"
# Any run, a push included, can carry a release: every one covers the whole
# matrix, split into the promotion gate and the MySQL boot after it.
grep -qF 'database: ["mariadb:11.4", "mariadb:10.11", "mariadb:12.2"]' "$workflow" \
  || { echo "database promotion gate is not the full MariaDB list on every run" >&2; exit 1; }
grep -qF 'database: ["mysql:8.0"]' "$workflow" \
  || { echo "database matrix does not boot MySQL on every run" >&2; exit 1; }
grep -Eq 'needs: \[.*database-compat-extended.*\]' "$workflow" \
  || { echo "a failed MySQL boot in the scheduled build opens no issue" >&2; exit 1; }
grep -Eq 'needs: \[.*database-compat-standalone.*\]' "$workflow" \
  || { echo "standalone promotion is not gated by database compatibility" >&2; exit 1; }
grep -Eq 'needs: \[.*scenario-test-standalone.*\]' "$workflow" \
  || { echo "standalone promotion is not gated by the generated scenario E2E" >&2; exit 1; }
grep -q 'CK_DATABASE_IMAGE:' "$workflow" \
  || { echo "database matrix does not pass its image into the boot test" >&2; exit 1; }
grep -q 'SELECT DATABASE()' "$root/tests/images/boot-test-standalone.sh" \
  || { echo "database matrix does not prove the UnitTests database name" >&2; exit 1; }
grep -q 'ck_test_db_canary' "$root/tests/images/boot-test-standalone.sh" \
  || { echo "database matrix has no main-database isolation canary" >&2; exit 1; }
grep -q 'docker rm -fv' "$root/tests/images/boot-test-standalone.sh" \
  || { echo "database matrix leaks anonymous database volumes" >&2; exit 1; }

schema="$root/packages/civikitchen-scenario-schema/scenario.schema.json"
release="$root/.github/workflows/extension-release.yml"
gitlab="$root/ci/gitlab/extension-ci.yml"
php "$root/tests/parity/database-policy.php" "$schema" "$workflow" "$release" "$gitlab" \
  || { echo "policy.database is not wired to the verified matrix and the release smoke test" >&2; exit 1; }

# The checker is silent on success: each broken copy below must fail it.
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
expect_failure() {
  local name=$1 needle=$2 out
  if out=$(php "$root/tests/parity/database-policy.php" "$3" "$4" "$5" "$6" 2>&1); then
    echo "database-policy checker passed the $name copy" >&2; exit 1
  fi
  grep -qF "$needle" <<<"$out" || { echo "database-policy checker did not report the $name copy (got: $out)" >&2; exit 1; }
}
sed 's|"enum": \["mariadb:10.11", |"enum": ["mariadb:10.6", "mariadb:10.11", |' "$schema" > "$work/schema.json"
expect_failure unverified 'offers mariadb:10.6' "$work/schema.json" "$workflow" "$release" "$gitlab"
sed 's|${database:-mariadb:11.4}|${database:-mariadb:10.11}|' "$release" > "$work/fallback.yml"
expect_failure fallback 'fallback is mariadb:10.11' "$schema" "$workflow" "$work/fallback.yml" "$gitlab"
sed 's|"CIVIKITCHEN_DB_IMAGE=${database:-mariadb:11.4}"|"CIVIKITCHEN_DB_IMAGE=$database"|' "$release" > "$work/unset.yml"
expect_failure unset 'not exported as ${database:-<default>}' "$schema" "$workflow" "$work/unset.yml" "$gitlab"
sed 's|^          database=$(php .*|          # database=$(php ckconform --policy database)|' "$release" > "$work/commented.yml"
expect_failure commented 'no step reads database' "$schema" "$workflow" "$work/commented.yml" "$gitlab"
sed 's|image: ${CIVIKITCHEN_DB_IMAGE}|image: mariadb:11.4|' "$release" > "$work/smoke.yml"
expect_failure smoke "db image is 'mariadb:11.4'" "$schema" "$workflow" "$work/smoke.yml" "$gitlab"
sed 's|CK_DB_IMAGE: mariadb:11.4|CK_DB_IMAGE: mariadb:10.11|' "$gitlab" > "$work/gitlab.yml"
expect_failure gitlab "CK_DB_IMAGE defaults to 'mariadb:10.11'" "$schema" "$workflow" "$release" "$work/gitlab.yml"

echo "database matrix parity OK"
