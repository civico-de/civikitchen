#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -eq 0 ]; then
  echo "lint-shell-portability: no shell files supplied" >&2
  exit 2
fi

# BSD sed needs an empty suffix after -i, GNU sed takes it as the program;
# portable scripts rewrite through a temporary file.
readonly bsd_sed_in_place="(^|[;&|()[:space:]])sed[[:space:]]+-i[[:space:]]+(''|\"\")"
status=0

for file in "$@"; do
  matches=$(grep -nHE -- "$bsd_sed_in_place" "$file" || true)
  if [ -n "$matches" ]; then
    printf '%s\n' "$matches" >&2
    status=1
  fi
done

if [ "$status" -ne 0 ]; then
  echo "shell portability: BSD-only sed -i with an empty suffix is forbidden; rewrite through a temporary file" >&2
  exit "$status"
fi

echo "shell portability clean ($# files)"
