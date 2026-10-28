#!/usr/bin/env bash
# The private-deps sibling checkout: one directory per extension KEY, no
# collision between repos that share a basename, and nothing written outside
# the siblings it was asked for. `git` is stubbed — the script is the unit.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
script="$root/.github/actions/private-deps/checkout-siblings.sh"
real_git="$(command -v git)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/bin"
# The stub takes the clone's last two arguments (URL, target) and stamps an
# info.xml whose key comes from FAKE_KEYS ("owner/repo=key ..."). A repo absent
# from FAKE_KEYS gets no info.xml at all.
cat > "$work/bin/git" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
url="${*: -2:1}"
target="${*: -1}"
repo="${url#https://github.com/}"
mkdir -p "$target"
for pair in $FAKE_KEYS; do
  if [ "${pair%%=*}" = "$repo" ]; then
    printf '<extension key="%s" type="module"/>\n' "${pair#*=}" > "$target/info.xml"
  fi
done
SH
chmod +x "$work/bin/git"

fail() { echo "FAIL: $*" >&2; exit 1; }

# Runs the script in a fresh workspace; $out holds stdout+stderr, $ws the tree.
run() {
  ws="$work/ws-$1"
  shift
  mkdir -p "$ws"
  out="$ws/out"
  rc=0
  (
    cd "$ws"
    PATH="$work/bin:$PATH" \
    CK_SIBLING_REPO="$1" \
    CK_SIBLING_TOKEN=token \
    FAKE_KEYS="$2" \
    GITHUB_WORKSPACE="$ws" \
    GITHUB_OUTPUT="$ws/github_output" \
      "$script"
  ) > "$out" 2>&1 || rc=$?
}

# Two siblings whose repo basenames are identical: the old basename-derived
# directory made the second clone overwrite the first.
run collision 'orgA/shared,orgB/shared' 'orgA/shared=de.example.alpha orgB/shared=de.example.beta'
[ "$rc" = 0 ] || fail "same-basename siblings rejected (rc=$rc): $(cat "$out")"
[ -f "$ws/.civikitchen-siblings/de.example.alpha/info.xml" ] \
  || fail "first sibling missing; got: $(ls -A "$ws/.civikitchen-siblings")"
[ -f "$ws/.civikitchen-siblings/de.example.beta/info.xml" ] \
  || fail "second sibling missing; got: $(ls -A "$ws/.civikitchen-siblings")"
grep -qx 'paths=.civikitchen-siblings/de.example.alpha .civikitchen-siblings/de.example.beta' "$ws/github_output" \
  || fail "paths output not the key directories: $(cat "$ws/github_output")"
leftovers=("$ws"/.civikitchen-siblings/.staging-*)
if [ -e "${leftovers[0]}" ]; then
  fail "staging directory left behind: ${leftovers[*]}"
fi

# A sibling named like the workflow's own helper checkouts must not be able to
# claim — and the old code, rm -rf — their directories.
for name in ci policy; do
  run "reserved-$name" "org/$name" "org/$name=$name"
  [ "$rc" = 1 ] || fail "reserved key '$name' accepted (rc=$rc)"
  grep -q "reserved" "$out" || fail "reserved key '$name': unclear message: $(cat "$out")"
done

# Two repos, one key: refuse rather than silently mount one of them twice.
run duplicate 'orgA/one,orgB/two' 'orgA/one=de.example.same orgB/two=de.example.same'
[ "$rc" = 1 ] || fail "duplicate extension key accepted (rc=$rc)"
grep -q "cannot share a key" "$out" || fail "duplicate key: unclear message: $(cat "$out")"

# A glob in an entry stays literal and is rejected by the owner/repo check,
# even when the workspace holds a path it would otherwise expand to.
mkdir -p "$work/ws-glob/orgA/shared"
run glob 'orgA/*' 'orgA/shared=de.example.alpha'
[ "$rc" = 1 ] || fail "glob entry accepted (rc=$rc): $(cat "$out")"
grep -q "invalid sibling_repo entry" "$out" || fail "glob entry: unclear message: $(cat "$out")"

# Not an extension.
run no-info 'org/plain' ''
[ "$rc" = 1 ] || fail "repo without info.xml accepted (rc=$rc)"
grep -q "not a CiviCRM extension" "$out" || fail "missing info.xml: unclear message: $(cat "$out")"

# A ref that is neither a branch, a tag nor a full commit.
i=0
for entry in 'org/repo@' 'org/repo@-x' 'org/repo@a@b' 'org/repo@ref;id'; do
  i=$((i + 1))
  run "badref-$i" "$entry" ''
  [ "$rc" = 1 ] || fail "invalid entry '$entry' accepted (rc=$rc): $(cat "$out")"
  grep -q "invalid sibling_repo entry" "$out" || fail "entry '$entry': unclear message: $(cat "$out")"
done

# --- owner/repo@ref against real git ---------------------------------------
#
# The stub above is enough for the script's bookkeeping; the pinned forms have
# to prove the git commands themselves. This leg runs REAL git against a local
# file:// repository through a shim that maps https://github.com/<owner>/<repo>
# onto it — the only thing about github.com a local fixture cannot be.
remotes="$work/remotes"
mkdir -p "$remotes/org" "$work/bin-real"
cat > "$work/bin-real/git" <<SHIM
#!/usr/bin/env bash
set -euo pipefail
args=()
for arg in "\$@"; do
  case "\$arg" in
    https://github.com/*) args+=("file://$remotes/\${arg#https://github.com/}") ;;
    *) args+=("\$arg") ;;
  esac
done
exec "$real_git" "\${args[@]}"
SHIM
chmod +x "$work/bin-real/git"

fixture="$remotes/org/pinned"
git init --quiet -b main "$fixture"
git -C "$fixture" config user.email test@example.org
git -C "$fixture" config user.name 'CiviKitchen test'
# Fetching a bare commit needs the server to serve it; github.com does.
git -C "$fixture" config uploadpack.allowAnySHA1InWant true
printf '<extension key="de.example.pinned" type="module"/>\n' > "$fixture/info.xml"
echo tagged > "$fixture/MARKER"
git -C "$fixture" add info.xml MARKER
git -C "$fixture" commit --quiet -m 'first'
git -C "$fixture" tag v1.0.0
pinned_sha=$(git -C "$fixture" rev-parse HEAD)
git -C "$fixture" checkout --quiet -b topic
echo branched > "$fixture/MARKER"
git -C "$fixture" commit --quiet -am 'branch'
git -C "$fixture" checkout --quiet main
echo moved-on > "$fixture/MARKER"
git -C "$fixture" commit --quiet -am 'default branch moves on'

# Same runner as above, with real git behind the shim instead of the stub.
run_real() {
  ws="$work/ws-$1"
  mkdir -p "$ws"
  out="$ws/out"
  rc=0
  (
    cd "$ws"
    PATH="$work/bin-real:$PATH" \
    CK_SIBLING_REPO="$2" \
    CK_SIBLING_TOKEN=token \
    GITHUB_WORKSPACE="$ws" \
    GITHUB_OUTPUT="$ws/github_output" \
      "$script"
  ) > "$out" 2>&1 || rc=$?
}

dir=.civikitchen-siblings/de.example.pinned
for pin in "v1.0.0=tagged" "topic=branched" "$pinned_sha=tagged" "=moved-on"; do
  ref="${pin%%=*}"
  want="${pin#*=}"
  run_real "pin-${want}-${#ref}" "org/pinned${ref:+@$ref}"
  [ "$rc" = 0 ] || fail "sibling pinned at '$ref' failed (rc=$rc): $(cat "$out")"
  got=$(cat "$ws/$dir/MARKER")
  [ "$got" = "$want" ] || fail "sibling pinned at '$ref' checked out '$got', expected '$want'"
  grep -qx "paths=$dir" "$ws/github_output" \
    || fail "pinned sibling paths output wrong: $(cat "$ws/github_output")"
done

# A commit the remote cannot serve: git's own "couldn't find remote ref" says
# nothing about which input was wrong.
run_real unreachable-sha "org/pinned@$(printf 'd%.0s' {1..40})"
[ "$rc" = 1 ] || fail "unfetchable pinned commit accepted (rc=$rc): $(cat "$out")"
grep -q "is not reachable from any branch or tag" "$out" \
  || fail "unfetchable pinned commit: unclear message: $(cat "$out")"

# Called from a job whose steps run somewhere else (playwright-e2e sets a
# working directory): the checkout still belongs to the workspace root.
ws="$work/ws-workdir"
mkdir -p "$ws/tests/e2e"
out="$ws/out"
rc=0
(
  cd "$ws/tests/e2e"
  PATH="$work/bin-real:$PATH" \
  CK_SIBLING_REPO=org/pinned \
  CK_SIBLING_TOKEN=token \
  GITHUB_WORKSPACE="$ws" \
  GITHUB_OUTPUT="$ws/github_output" \
    "$script"
) > "$out" 2>&1 || rc=$?
[ "$rc" = 0 ] || fail "checkout from a nested working directory failed: $(cat "$out")"
[ -f "$ws/$dir/info.xml" ] || fail "sibling not checked out at the workspace root: $(cat "$out")"

# A sibling with files in Git LFS, on a runner whose git-lfs filter is
# installed but may not fetch them: the remote here holds no LFS object at all.
command -v git-lfs >/dev/null || fail "git-lfs is required for the LFS leg"
export GIT_CONFIG_GLOBAL="$work/gitconfig"
git lfs install --skip-repo >/dev/null
media="$remotes/org/media"
git init --quiet -b main "$media"
git -C "$media" config user.email test@example.org
git -C "$media" config user.name 'CiviKitchen test'
printf '<extension key="de.example.media" type="module"/>\n' > "$media/info.xml"
echo '*.png filter=lfs diff=lfs merge=lfs -text' > "$media/.gitattributes"
pointer=$(printf 'version https://git-lfs.github.com/spec/v1\noid sha256:%064d\nsize 1' 0)
echo "$pointer" > "$media/shot.png"
git -C "$media" add info.xml .gitattributes shot.png
git -C "$media" commit --quiet -m 'media'
run_real lfs org/media
[ "$rc" = 0 ] || fail "sibling with an unfetchable LFS file failed (rc=$rc): $(cat "$out")"
[ "$(cat "$ws/.civikitchen-siblings/de.example.media/shot.png")" = "$pointer" ] \
  || fail "LFS file not left as its pointer"

echo "sibling checkout suite OK"
