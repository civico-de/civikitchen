#!/usr/bin/env bash
# The image installs its dev tools from the committed root composer.json +
# composer.lock. Two things have to hold, and neither is visible in a passing
# build on a current image:
#
#   1. The lock matches its composer.json — an edited constraint that was never
#      re-locked installs the OLD version, silently, until someone looks.
#   2. The lock was resolved against the PHP floor the images support, not the
#      maintainer's PHP. Resolved higher, a tree can pick a transitive that
#      refuses to start on an 8.1/8.2 image: composer installs it, the tool
#      then dies at startup. That is how `cktaint` was broken on PHP 8.2 —
#      psalm's lock had sebastian/diff 7 (php >=8.3) in it.
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
