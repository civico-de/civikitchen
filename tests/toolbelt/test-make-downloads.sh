#!/usr/bin/env bash
# A download that fails its checksum must leave no file under the tool's name:
# Make takes an existing target as fetched, so the next run would use it unchecked.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
make="${MAKE:-make}"

# A curl that delivers something other than the pinned release.
mkdir "$work/bin"
cat > "$work/bin/curl" <<'SH'
#!/bin/sh
while [ $# -gt 0 ]; do
  case "$1" in -*o) out="$2"; shift ;; esac
  shift
done
echo 'not the release' > "$out"
SH
chmod +x "$work/bin/curl"

for tool in COMPOSER PHPUNIT; do
  target=$("$make" -s -C "$root" CACHE="$work/cache" --eval "ck-print: ; @echo \$($tool)" ck-print)
  case "$target" in "$work"/cache/*) ;; *) fail "$tool resolves outside the test cache: $target" ;; esac
  for attempt in 1 2; do
    if PATH="$work/bin:$PATH" "$make" -s -C "$root" CACHE="$work/cache" "$target" > /dev/null 2>&1; then
      fail "$tool: attempt $attempt accepted a download that fails its checksum"
    fi
    [ ! -e "$target" ] || fail "$tool: attempt $attempt left the unverified download at $target"
  done
done

echo "make downloads suite OK"
