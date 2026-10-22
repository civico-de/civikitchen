#!/usr/bin/env bash
# provision.sh hands extras and mounted extensions to `ck internal provision-*` as the web user;
# what the provisioner then does is tests/shared-php/ExtensionProvisionerTest.php.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
mkdir -p "$work/ext/alpha" "$work/ext/beta"

# Listed out of name order: the pins of the first mount by name win.
cat > "$work/mountinfo" <<MOUNTS
100 90 0:37 /host/b $work/ext/beta rw,relatime - fakeowner /dev/x rw
101 90 0:37 /host/a $work/ext/alpha rw,relatime - fakeowner /dev/x rw
MOUNTS
export PATH="$root/toolbelt/bin:$PATH"
export CK_EXT_DIR="$work/ext" CK_MOUNTINFO="$work/mountinfo"
# shellcheck source=../../docker/runtime/provision.sh
. "$root/docker/runtime/provision.sh"
ck_as_web() {
  printf '%s\n' as-web "$@" > "$work/call"
  return "${CK_CALL_STATUS:-0}"
}
expect_call() {
  [[ "$(cat "$work/call")" == "$(printf '%s\n' "$@")" ]] \
    || fail "expected '$*', got '$(tr '\n' ' ' < "$work/call")'"
}
cli="$root/toolbelt/bin/ck"

# The extras as configured, spaces and empty entries included: one parser, in ck.
export CIVIKITCHEN_EXTRA_EXTENSIONS=' org.example.one, ,org.example.two@https://example.org/two.zip'
export CK_DEP_ARCHIVE_DIR="$work/staged"
ck_extra_extensions
expect_call as-web "$cli" internal provision-extras --ext-dir "$work/ext" --archive-dir "$work/staged" \
  --mounted "$work/ext/alpha" --mounted "$work/ext/beta" "$CIVIKITCHEN_EXTRA_EXTENSIONS"

# No staging directory is an empty archive dir, never a guessed one.
unset CK_DEP_ARCHIVE_DIR
export CIVIKITCHEN_ENABLE_EXTENSIONS='org.example.beta, downloaded'
ck_enable_extensions
expect_call as-web "$cli" internal provision-enable --ext-dir "$work/ext" --archive-dir '' \
  --mounted "$work/ext/alpha" --mounted "$work/ext/beta" 'org.example.beta, downloaded'

# Nothing mounted, nothing configured: the call still goes out and decides that.
unset CIVIKITCHEN_EXTRA_EXTENSIONS CIVIKITCHEN_ENABLE_EXTENSIONS
CK_MOUNTINFO="$work/none" ck_extra_extensions
expect_call as-web "$cli" internal provision-extras --ext-dir "$work/ext" --archive-dir '' ''
CK_MOUNTINFO="$work/none" ck_enable_extensions
expect_call as-web "$cli" internal provision-enable --ext-dir "$work/ext" --archive-dir '' ''

# A failed provisioning fails the step, so the provisioned marker is not written.
if CK_CALL_STATUS=1 ck_extra_extensions; then fail "a failed provision-extras was taken as success"; fi
if CK_CALL_STATUS=1 ck_enable_extensions; then fail "a failed provision-enable was taken as success"; fi

# The boot test runs this provision.sh on a published image with the ck library and profile
# driver its hand-offs call; docker records each run and reports the app exited.
mkdir -p "$work/bin"
cat > "$work/bin/docker" <<'FAKE'
#!/usr/bin/env bash
case "$1" in
  run) runs=$(($(cat "$DOCKER_LOG/runs" 2>/dev/null || echo 0) + 1)); echo "$runs" > "$DOCKER_LOG/runs"; printf '%s\0' "$@" > "$DOCKER_LOG/run-$runs" ;;
  inspect) echo exited ;;
esac
FAKE
chmod +x "$work/bin/docker"
if (cd "$root" && PATH="$work/bin:$PATH" DOCKER_LOG="$work" CK_PROVISION_OVERRIDE=docker/runtime/provision.sh \
  bash tests/images/boot-test-standalone.sh example.org/civikitchen:standalone >/dev/null 2>&1); then
  fail "the boot test passed against a fake docker"
fi
mounts=()
mapfile -d '' run < "$work/run-2"
for ((i = 1; i < ${#run[@]}; i++)); do
  if [ "${run[i - 1]}" = -v ]; then mounts+=("${run[i]}"); fi
done
for mount in "$root/docker/runtime/provision.sh:/usr/local/share/civikitchen/provision.sh:ro" \
  "$root/toolbelt/lib/php:/opt/civikitchen/toolbelt/lib/php:ro" \
  "$root/docker/profiles/apply.sh:/opt/civikitchen/docker/profiles/apply.sh:ro"; do
  found=0
  for actual in "${mounts[@]}"; do
    if [ "$actual" = "$mount" ]; then found=1; fi
  done
  [ "$found" = 1 ] || fail "the provision.sh override does not mount $mount"
done

echo "provision requires: ok"
