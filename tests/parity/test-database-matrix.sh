#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
workflow="$root/.github/workflows/build-dev-images.yml"
# Any run, a push included, can carry a release: every one covers the whole matrix.
grep -qF 'database: ["mariadb:11.4", "mariadb:10.11", "mariadb:12.2", "mysql:8.0"]' "$workflow" \
  || { echo "database matrix is not the full list on every run" >&2; exit 1; }
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

echo "database matrix parity OK"
