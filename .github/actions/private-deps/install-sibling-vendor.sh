#!/usr/bin/env bash
# Build vendor/ (--no-dev) in every sibling whose composer.lock carries runtime
# packages: siblings are mounted read-only and load their own autoloader.
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
