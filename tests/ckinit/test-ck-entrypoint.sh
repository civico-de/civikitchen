#!/usr/bin/env bash
# docker/cli/entrypoint.sh: without CK_USER the command runs as started; with it,
# setpriv drops to that uid:gid plus the Docker socket's group (fake id and setpriv).
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
entry="$root/docker/cli/entrypoint.sh"
work="$(cd "$(mktemp -d)" && pwd -P)"
trap '/bin/rm -rf "$work"' EXIT

fail() { echo "ck entrypoint: $*" >&2; exit 1; }

mkdir -p "$work/bin"
printf '#!/bin/sh\necho "${FAKE_UID:-0}"\n' > "$work/bin/id"
printf '#!/bin/sh\necho "setpriv $*"\n' > "$work/bin/setpriv"
chmod +x "$work/bin/id" "$work/bin/setpriv"
export PATH="$work/bin:$PATH" CK_DOCKER_SOCKET="$work/docker.sock"

# Without CK_USER the command runs unchanged, socket or not.
out="$(env -u CK_USER sh "$entry" echo hello)"
[ "$out" = hello ] || fail "without CK_USER: '$out'"

# A missing socket, a malformed CK_USER or a non-root start are errors.
if CK_USER=1000:1000 sh "$entry" true 2> "$work/err"; then fail "a missing socket must fail"; fi
grep -qF "no Docker socket at $work/docker.sock" "$work/err" || fail "missing socket: $(cat "$work/err")"
php -r '$s = stream_socket_server("unix://" . $argv[1]); if ($s === false) { exit(1); }' "$work/docker.sock"
group="$(stat -c %g "$work/docker.sock")"
for bad in 1000 1000: :1000 1000:1000:1 abc:1000 "1000 :1000"; do
  if CK_USER="$bad" sh "$entry" true 2> "$work/err"; then fail "CK_USER='$bad' must fail"; fi
  grep -qF 'CK_USER must be <uid>:<gid>' "$work/err" || fail "CK_USER='$bad': $(cat "$work/err")"
done
if FAKE_UID=1000 CK_USER=1000:1000 sh "$entry" true 2> "$work/err"; then fail "a non-root start must fail"; fi
grep -qF 'started as root' "$work/err" || fail "non-root start: $(cat "$work/err")"

# As root with CK_USER: setpriv gets the uid, the gid and the socket's group, then the command.
out="$(CK_USER=1234:5678 sh "$entry" php ck run 'a b')"
[ "$out" = "setpriv --reuid 1234 --regid 5678 --groups $group --inh-caps=-all -- php ck run a b" ] \
  || fail "setpriv call: '$out'"

echo "ck entrypoint: ok"
