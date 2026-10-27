#!/usr/bin/env bash
# A file named like a target must not satisfy it: every named target in Make's
# own database is .PHONY, also behind another makefile, whose targets stay its own.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
make="${MAKE:-make}"

# Names that are no path, each followed by whether Make holds it phony. One
# paragraph per file; target-specific variables repeat its name above the rule.
database() {
  "$make" -C "$root" -pnq "$@" help > "$work/db" 2>/dev/null || true
  awk 'BEGIN { RS = "" } /(^|\n)# Files$/ { files = 1; next }
    files && match($0, /(^|\n)[a-z][a-z0-9-]*:/) {
      name = substr($0, RSTART, RLENGTH); gsub(/[\n:]/, "", name); print name, ($0 ~ /Phony target/)
    }' "$work/db"
}

check() { # label, makefile options
  local label="$1" stray
  shift
  rows=$(database "$@")
  [ "$(wc -l <<<"$rows")" -gt 50 ] || fail "$label: the database names only: $rows"
  stray=$(awk '$1 != "stamp" && $2 == 0 { print $1 }' <<<"$rows")
  [ -z "$stray" ] || fail "$label: not phony: $stray"
}
check 'plain'
check 'behind an empty makefile' -f /dev/null -f Makefile
printf 'stamp:\n\ttouch $@\n' > "$work/wrapper.mk"
check 'behind a wrapper' -f "$work/wrapper.mk" -f Makefile
grep -qx 'stamp 0' <<<"$rows" || fail "the wrapper's file target became phony"

echo "make phony suite OK"
