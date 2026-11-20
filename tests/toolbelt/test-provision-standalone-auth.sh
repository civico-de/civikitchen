#!/usr/bin/env bash
# standaloneusers enabled without its default roles (an interrupted install)
# fails the first boot; with both roles it passes. Fake cv reads the role list.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
mkdir -p "$work/bin"
fail() { echo "FAIL: $*" >&2; exit 1; }

cat > "$work/bin/cv" <<'FAKE'
#!/usr/bin/env bash
if [[ "$1" == ev ]]; then
    [[ "$2" == *'array_diff(["admin", "everyone"], \Civi\Api4\Role::get(FALSE)'* ]] || exit 3
    printf '%s' "$MISSING_ROLES"
fi
FAKE
chmod +x "$work/bin/cv"
export PATH="$work/bin:$PATH"

ck_as_web() { "$@"; }
# shellcheck source=../../docker/runtime/provision.sh
. "$root/docker/runtime/provision.sh"

MISSING_ROLES="" ck_standalone_auth >/dev/null 2>"$work/err" \
    || fail "both roles present must pass: $(cat "$work/err")"

if MISSING_ROLES="admin everyone" ck_standalone_auth >/dev/null 2>"$work/err"; then
    fail "missing roles must fail the boot"
fi
grep -q 'role(s) admin everyone are missing: the database holds an interrupted first install' "$work/err" \
    || fail "wrong message: $(cat "$work/err")"

echo "PASS: standaloneusers without its default roles fails the first boot"
