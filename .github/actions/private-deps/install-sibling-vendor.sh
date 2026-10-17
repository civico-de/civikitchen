#!/usr/bin/env bash
# Build vendor/ in every sibling whose committed composer.lock carries runtime
# packages. Siblings are mounted read-only and as is, so a sibling that loads
# its own autoloader would not boot without it. --no-dev: only the runtime tree
# a release ships; the sibling's own tests and tools never run here.
set -euo pipefail
set -f
cd "${GITHUB_WORKSPACE:-.}"

for dir in ${CK_SIBLING_DIRS:-}; do
  lock="$dir/composer.lock"
  [ -f "$lock" ] || continue
  # 0: runtime packages, 1: none; anything else is an unreadable lock and fails.
  rc=0
  php -r 'exit(count(json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR)["packages"] ?? []) > 0 ? 0 : 1);' "$lock" || rc=$?
  case $rc in
    0) ;;
    1) continue ;;
    *) echo "unreadable $lock" >&2; exit 1 ;;
  esac
  echo "composer install --no-dev in sibling $dir"
  (cd "$dir" && composer install --no-dev --no-interaction --no-progress)
done
