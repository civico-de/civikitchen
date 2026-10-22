#!/usr/bin/env bash
# The entrypoint enables what the mount table shows bind-mounted into the ext dir, ordered by
# CIVIKITCHEN_ENABLE_EXTENSIONS; the real ck runs against a fake cv that records every call.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/ext/alpha" "$work/ext/beta-dir" "$work/ext/downloaded" "$work/ext/no key" "$work/ext/workspace/mine"
fail() { echo "FAIL: $*" >&2; exit 1; }

cat > "$work/bin/cv" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CV_LOG"
case "$1" in
  api4) [[ -z "${CV_LIST_FAILS:-}" ]] || exit 1; echo '[]' ;;
  ev)
    if [[ "$2" == *CRM_Extension_Info::loadFromString* ]]; then
      php -r 'foreach (simplexml_load_string(stream_get_contents(STDIN))->requires->ext ?? [] as $e) { echo $e, "\n"; }'
    fi
    ;;
esac
FAKE
chmod +x "$work/bin/cv"
export PATH="$work/bin:$root/toolbelt/bin:$PATH"
export CV_LOG="$work/cv.log"
ck_as_web() { "$@"; }
export CK_EXT_DIR="$work/ext"
export CK_MOUNTINFO="$work/mountinfo"
export CIVIKITCHEN_ENABLE_EXTENSIONS=""
# shellcheck source=../../docker/runtime/provision.sh
. "$root/docker/runtime/provision.sh"

write_info() {
  cat > "$1/info.xml" <<INFO
<?xml version="1.0"?>
<extension key="$2" type="module">
  <file>$2</file>
  ${3:-}
</extension>
INFO
}
write_info "$work/ext/alpha" alpha
write_info "$work/ext/beta-dir" org.example.beta
write_info "$work/ext/downloaded" downloaded
printf '<extension' > "$work/ext/no key/info.xml"
# A folder of extensions: CiviCRM's scanner finds what is below it.
write_info "$work/ext/workspace/mine" mine
# Field 5 is the mount point; a space in it is escaped as \040. The ext dir
# itself and a nested mount below an extension are not extensions.
cat > "$work/mountinfo" <<MOUNTS
100 90 0:37 /host/a $work/ext/alpha rw,relatime - fakeowner /dev/x rw
101 90 0:37 /host/b $work/ext/beta-dir rw,relatime - fakeowner /dev/x rw
102 90 0:37 /host/n $work/ext/no\\040key rw,relatime - fakeowner /dev/x rw
105 90 0:37 /host/ws $work/ext/workspace rw,relatime - fakeowner /dev/x rw
103 90 0:37 /host/root $work/ext rw,relatime - fakeowner /dev/x rw
104 90 0:37 /host/nested $work/ext/alpha/vendor rw,relatime - fakeowner /dev/x rw
MOUNTS

expect_log() {
  local actual
  actual="$( (grep -v -e '^api4 Extension.get' -e '^ev echo implode' "$CV_LOG" || true) | tr '\n' ';')"
  [[ "$actual" == "$1" ]] || fail "$2 — expected '$1', got '$actual'"
}

# Mounts are enabled by their info.xml key, in name order; the download, the
# key-less mount and the folder of extensions are not.
: > "$CV_LOG"
ck_enable_extensions >"$work/out" 2>"$work/err"
expect_log 'ext:enable alpha;ext:enable org.example.beta;' 'mounted extensions'
grep -qx '\[civikitchen\] Enabling extensions: alpha,org.example.beta' "$work/out" || fail "the enabled keys are not listed: $(cat "$work/out")"
grep -q 'no key is mounted into the ext dir but has no readable info.xml key' "$work/err" || fail "a mount without a key should be reported"
grep -q 'workspace' "$work/err" && fail "a mount without info.xml is no extension and no warning"

# The explicit list goes first, in its order, and is not repeated.
: > "$CV_LOG"
CIVIKITCHEN_ENABLE_EXTENSIONS="org.example.beta, downloaded" ck_enable_extensions >/dev/null 2>&1
expect_log 'ext:enable org.example.beta;ext:enable downloaded;ext:enable alpha;' 'explicit list first'

# Nothing mounted and nothing listed: nothing to do, no output.
: > "$CV_LOG"
CK_MOUNTINFO="$work/none" ck_enable_extensions >"$work/out"
expect_log '' 'nothing to enable'
[ ! -s "$work/out" ] || fail "nothing to enable still printed: $(cat "$work/out")"

# An extension attached via CIVIKITCHEN_EXTENSION_PATH (a CI job that runs
# inside the image has no bind mount) is enabled like a mount.
mkdir -p "$work/src/attached"
write_info "$work/src/attached" org.example.attached
attach_and_enable() {
  ck_attach_scenario_extension
  CK_MOUNTINFO="$work/none" ck_enable_extensions >/dev/null
}
: > "$CV_LOG"
CIVIKITCHEN_EXTENSION_PATH="$work/src/attached" CIVIKITCHEN_EXTENSION_KEY=org.example.attached attach_and_enable
/bin/rm "$work/ext/org.example.attached"
expect_log 'ext:enable org.example.attached;' 'attached extension'
# ckboot attaches it under info.xml's <file>, where the compose stacks mount it.
: > "$CV_LOG"
CIVIKITCHEN_EXTENSION_PATH="$work/src/attached" CIVIKITCHEN_EXTENSION_KEY=org.example.attached CIVIKITCHEN_EXTENSION_DIR=attached attach_and_enable
[ -L "$work/ext/attached" ] || fail "not linked under its <file> name"
/bin/rm "$work/ext/attached"
expect_log 'ext:enable org.example.attached;' 'attached extension under its file name'

# A failure is the step's failure, named, and nothing after it is enabled.
write_info "$work/ext/alpha" alpha '<requires><ext>org.example.dep</ext></requires>'
: > "$CV_LOG"
if CV_LIST_FAILS=1 ck_enable_extensions >/dev/null 2>"$work/err"; then
  fail "a failed extension query must fail ck_enable_extensions"
fi
grep -qx '\[civikitchen\] ERROR: could not query the extension list for org.example.dep' "$work/err" \
  || fail "a failed provisioning must say why: $(cat "$work/err")"
expect_log '' 'enabled despite a failed extension query'

echo "provision mounts: ok"
