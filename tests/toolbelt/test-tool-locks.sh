#!/usr/bin/env bash
# The dev-tool lock matches its composer.json and was resolved against the
# images' PHP floor, or a transitive can refuse to start on an older image.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# The floor is a decision about which images keep their tools, see
# docs/building.md. Raising it drops the older CiviCRM lines.
FLOOR=8.1.31
fail() { echo "FAIL: $*" >&2; exit 1; }

r=composer.json
dir="$root"
[ -f "$dir/composer.json" ] || fail "$r: no composer.json"
[ -f "$dir/composer.lock" ] || fail "$r: no composer.lock — the image installs from it"

got=$(php -r '$c = json_decode(file_get_contents($argv[1]), TRUE);
    echo $c["config"]["platform"]["php"] ?? "";' "$dir/composer.json")
[ "$got" = "$FLOOR" ] || fail "$r: config.platform.php is '${got:-unset}', expected $FLOOR"

got=$(php -r '$c = json_decode(file_get_contents($argv[1]), TRUE);
    echo $c["platform-overrides"]["php"] ?? "";' "$dir/composer.lock")
[ "$got" = "$FLOOR" ] || fail "$r: lock was resolved against '${got:-no override}', expected $FLOOR — re-lock it"

composer validate --working-dir="$dir" --no-check-publish --no-check-all --quiet \
    || fail "$r: composer.json and composer.lock disagree — run composer update"

echo "tool lock suite OK (floor $FLOOR)"
