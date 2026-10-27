#!/bin/bash
# A new extension passes its own CI gates: real civix (ckcreate), the
# template's dev stack (ckup) and every in-container gate (ckx ck ci).
#
# Usage: bash tests/ckcreate/e2e-ckcreate.sh <image>
# CK_E2E_KEEP=1 leaves the extension and its stack for debugging.
set -euo pipefail

image="${1:?usage: $0 <image>}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# Bind mounts must sit where Docker's VM can see them: in the checkout, not in the
# temp dir or the toolchain container's HOME. ckcreate mounts from below HOME.
work="$root/.cache/e2e-ckcreate.$$"
ext="$work/ckprobe"
mkdir -p "$work/conf" "$work/home"

cleanup() {
  if [ "${CK_E2E_KEEP:-0}" = "1" ]; then
    echo "e2e-ckcreate: kept $ext and its stack"
    return
  fi
  if [ -f "$ext/.docker/docker-compose.yml" ]; then
    docker compose -f "$ext/.docker/docker-compose.yml" down -v --remove-orphans >/dev/null 2>&1 || true
  fi
  /bin/rm -rf "$work"
}
trap cleanup EXIT

export CIVIKITCHEN_IMAGE="$image" CKCREATE_IMAGE="$image" XDG_CONFIG_HOME="$work/conf" HOME="$work/home"
"$root/scaffold/ckcreate" ckprobe --dir "$ext" \
  --author "Example Maintainer" --email dev@example.org --copyright "Example Org"

git -C "$ext" add -A
git -C "$ext" -c user.name=e2e -c user.email=e2e@example.org commit -qm "Scaffold"

# --pull missing overrides the template's pull_policy: always, so the image
# under test is used as built. app and its db are all the gates need.
(cd "$ext" && "$root/scaffold/ckup" --pull missing --wait --wait-timeout 900 app)

(cd "$ext" && "$root/scaffold/ckx" ck ci)
echo "e2e-ckcreate: ok"
