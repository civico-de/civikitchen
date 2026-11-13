#!/bin/sh
# Runs the command as $CK_USER (uid:gid) plus the Docker socket's group as the daemon
# sees it; without CK_USER, as the user the container was started as.
set -eu

die() { echo "ck: $*" >&2; exit 2; }

[ -n "${CK_USER:-}" ] || exec "$@"
case "$CK_USER" in
  *[!0-9:]* | :* | *: | *:*:*) die "CK_USER must be <uid>:<gid>, e.g. -e CK_USER=\"\$(id -u):\$(id -g)\"" ;;
  *:*) ;;
  *) die "CK_USER must be <uid>:<gid>, e.g. -e CK_USER=\"\$(id -u):\$(id -g)\"" ;;
esac
[ "$(id -u)" = 0 ] || die "CK_USER needs the container started as root (no -u)"
socket="${CK_DOCKER_SOCKET:-/var/run/docker.sock}"
[ -S "$socket" ] || die "no Docker socket at $socket: mount it with -v $socket:$socket"
group="$(stat -c %g "$socket")" || die "cannot read the group of $socket"
exec setpriv --reuid "${CK_USER%:*}" --regid "${CK_USER#*:}" --groups "$group" --inh-caps=-all -- "$@"
