#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
mirror="$root/.github/scripts/release-mirror.sh"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null
export GIT_AUTHOR_NAME=Test GIT_AUTHOR_EMAIL=test@example.org
export GIT_COMMITTER_NAME=Test GIT_COMMITTER_EMAIL=test@example.org

fail() { echo "FAIL: $*" >&2; exit 1; }

# A source repository two commits long that ignores vendor/, its origin, and
# an empty mirror.
git init -q -b main "$work/src"
cd "$work/src"
printf 'vendor/\n' > .gitignore
printf '<extension key="demo"><version>1.2.3</version></extension>\n' > info.xml
git add -A && git commit -qm 'First'
echo code > demo.php
git add -A && git commit -qm 'Release 1.2.3'
git clone -q --bare "$work/src" "$work/origin.git"
git remote add origin "$work/origin.git"
git init -q --bare -b main "$work/mirror.git"
released="$(git rev-parse HEAD)"

# The archive: the tracked files without the development layer, plus vendor/.
archive() {
  local version="$1" dir="$work/zip-$1/demo"
  mkdir -p "$dir/vendor"
  cp info.xml demo.php "$dir/"
  echo '<?php' > "$dir/vendor/autoload.php"
  (cd "$work/zip-$version" && zip -qr "$work/demo-$version.zip" demo)
}
archive 1.2.3

run() { CK_MIRROR_URL="$work/mirror.git" bash "$mirror" "$@" > "$work/out" 2>&1; }
in_mirror() { git -C "$work/mirror.git" "$@"; }

run "$work/demo-1.2.3.zip" 1.2.3 main || { cat "$work/out" >&2; fail "the first publish failed"; }
[ "$(in_mirror rev-parse refs/heads/main)" = "$released" ] || fail "the mirror's main is not the released commit"
[ "$(in_mirror rev-parse '1.2.3^')" = "$released" ] || fail "the tag does not sit on the released commit"
[ "$(in_mirror ls-tree -r --name-only 1.2.3 | sort | tr '\n' ' ')" = 'demo.php info.xml vendor/autoload.php ' ] \
  || fail "the tag does not carry the archive's files: $(in_mirror ls-tree -r --name-only 1.2.3 | tr '\n' ' ')"
[ -z "$(in_mirror tag --list 'v*')" ] || fail "a v-prefixed tag reached the mirror"
[ -z "$(git remote | grep -v '^origin$' || true)" ] || fail "the script left its remote behind"
tagged="$(in_mirror rev-parse 1.2.3)"

# A re-run builds the same commit and changes nothing.
run "$work/demo-1.2.3.zip" 1.2.3 main || { cat "$work/out" >&2; fail "the re-run failed"; }
[ "$(in_mirror rev-parse 1.2.3)" = "$tagged" ] || fail "the re-run moved the tag"

# Another archive under a published version is refused.
echo changed >> "$work/zip-1.2.3/demo/demo.php"
(cd "$work/zip-1.2.3" && zip -qr "$work/other.zip" demo)
if run "$work/other.zip" 1.2.3 main; then fail "a published tag was moved"; fi
grep -q 'a published tag does not move' "$work/out" || { cat "$work/out" >&2; fail "wrong message for a moved tag"; }
[ "$(in_mirror rev-parse 1.2.3)" = "$tagged" ] || fail "the refused run moved the tag"

# A later release moves main forward and adds its tag.
echo more >> demo.php
git commit -qam 'Release 1.3.0'
archive 1.3.0
run "$work/demo-1.3.0.zip" 1.3.0 main || { cat "$work/out" >&2; fail "the second release failed"; }
[ "$(in_mirror rev-parse refs/heads/main)" = "$(git rev-parse HEAD)" ] || fail "main did not follow the second release"
newest="$(git rev-parse HEAD)"

# A release from an older line publishes its tag and leaves main alone.
git checkout -q -b maintenance "$released"
echo fix >> demo.php
git commit -qam 'Release 1.2.4'
archive 1.2.4
run "$work/demo-1.2.4.zip" 1.2.4 main || { cat "$work/out" >&2; fail "the maintenance release failed"; }
grep -q 'publishing the tag only' "$work/out" || { cat "$work/out" >&2; fail "the older line was not reported"; }
[ "$(in_mirror rev-parse refs/heads/main)" = "$newest" ] || fail "an older line moved the mirror's main"
in_mirror rev-parse --verify -q 1.2.4 >/dev/null || fail "the maintenance tag is missing"

# Refused inputs.
if run "$work/demo-1.2.4.zip" v1.2.4 main; then fail "a v-prefixed version was accepted"; fi
grep -q "not a release version: 'v1.2.4'" "$work/out" || fail "wrong message for a v-prefixed version"
mkdir -p "$work/two/a" "$work/two/b" && touch "$work/two/a/info.xml" "$work/two/b/info.xml"
(cd "$work/two" && zip -qr "$work/two.zip" a b)
if run "$work/two.zip" 2.0.0 main; then fail "an archive of two directories was accepted"; fi
grep -q 'does not hold one extension directory' "$work/out" || fail "wrong message for two directories"
git clone -q --depth 1 "file://$work/origin.git" "$work/shallow"
if (cd "$work/shallow" && run "$work/demo-1.2.3.zip" 1.2.3 main); then fail "a shallow clone was accepted"; fi
grep -q 'the source clone is shallow' "$work/out" || fail "wrong message for a shallow clone"

# The token travels as a header for the mirror's URL, never in an argument.
cat > "$work/git" <<'WRAP'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CK_TEST_ARGV"
[ "${1:-}" = ls-remote ] && env | grep '^GIT_CONFIG_' >> "$CK_TEST_ENV"
exec "$CK_TEST_GIT" "$@"
WRAP
chmod +x "$work/git"
git checkout -q main
CK_TEST_GIT="$(command -v git)" CK_TEST_ARGV="$work/argv" CK_TEST_ENV="$work/env" \
  CK_MIRROR_TOKEN=s3cret PATH="$work:$PATH" CK_MIRROR_URL="$work/mirror.git" \
  bash "$mirror" "$work/demo-1.3.0.zip" 1.3.0 main > "$work/out" 2>&1 || { cat "$work/out" >&2; fail "the run with a token failed"; }
grep -q "^GIT_CONFIG_KEY_0=http.$work/mirror.git.extraheader\$" "$work/env" || fail "no header for the mirror's URL"
grep -q "^GIT_CONFIG_VALUE_0=Authorization: Basic $(printf 'oauth2:s3cret' | base64)\$" "$work/env" || fail "the header does not carry the token"
if grep -q 's3cret\|'"$(printf 'oauth2:s3cret' | base64)" "$work/argv" "$work/out"; then fail "the token reached an argument or the output"; fi

# A source that tracks media with Git LFS: the mirror gets the objects before
# the commits that point at them, and the tag carries the image itself.
git lfs version >/dev/null 2>&1 || fail "git-lfs is needed for the LFS case"
git init -q -b main "$work/lfs-src"
cd "$work/lfs-src"
git lfs install --local >/dev/null
printf '*.png filter=lfs diff=lfs merge=lfs -text\n' > .gitattributes
printf '<extension key="demo"><version>2.0.0</version></extension>\n' > info.xml
head -c 4096 /dev/urandom > cover.png
git add .gitattributes info.xml cover.png
git commit -qm 'Release 2.0.0'
git clone -q --bare "$work/lfs-src" "$work/lfs-origin.git"
git remote add origin "$work/lfs-origin.git"
git lfs push --all origin >/dev/null 2>&1
git init -q --bare -b main "$work/lfs-mirror.git"
mkdir -p "$work/zip-lfs/demo" && cp info.xml cover.png "$work/zip-lfs/demo/"
(cd "$work/zip-lfs" && zip -qr "$work/demo-2.0.0.zip" demo)
oid="$(git lfs ls-files --long | cut -d' ' -f1)"
rm -rf .git/lfs/objects
CK_MIRROR_URL="$work/lfs-mirror.git" bash "$mirror" "$work/demo-2.0.0.zip" 2.0.0 main > "$work/out" 2>&1 \
  || { cat "$work/out" >&2; fail "the LFS release failed"; }
[ -f "$work/lfs-mirror.git/lfs/objects/${oid:0:2}/${oid:2:2}/$oid" ] || fail "the mirror did not receive the LFS object"
git -C "$work/lfs-mirror.git" cat-file -p main:cover.png | grep -q '^oid sha256:' || fail "the source branch does not hold the LFS pointer"
git -C "$work/lfs-mirror.git" cat-file -p 2.0.0:cover.png | cmp -s - cover.png || fail "the tag does not carry the image itself"

echo "release mirror suite OK"
