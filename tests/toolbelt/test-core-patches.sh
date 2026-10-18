#!/usr/bin/env bash
# apply-core-patches.sh over a fixture core tree: a patch applies once, a
# contained one is skipped, a mismatch or a patch without upstream link fails,
# and a Since: header skips the patch on an older core.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
apply="$root/docker/standalone/apply-core-patches.sh"

for shipped in "$root"/docker/standalone/core-patches/*.patch; do
  grep -q '^Upstream: https://' "$shipped" || fail "$(basename "$shipped") names no Upstream: link"
done

mkdir -p "$work/core/CRM" "$work/patches" "$work/bad"
printf 'one\nkeep\n' > "$work/core/CRM/A.php"
cat > "$work/patches/10-change.patch" <<'EOF'
Fixture change.

Upstream: https://example.org/issue/1

--- a/CRM/A.php
+++ b/CRM/A.php
@@ -1,2 +1,2 @@
-one
+two
 keep
EOF

out=$(bash "$apply" "$work/core" "$work/patches") || fail "a fitting patch was rejected"
[ "$out" = "applied 10-change.patch" ] || fail "expected 'applied', got: $out"
[ "$(head -1 "$work/core/CRM/A.php")" = "two" ] || fail "the patch was not applied"

out=$(bash "$apply" "$work/core" "$work/patches") || fail "a contained patch was rejected"
[ "$out" = "contained 10-change.patch" ] || fail "expected 'contained', got: $out"
[ "$(head -1 "$work/core/CRM/A.php")" = "two" ] || fail "a contained patch changed the file"

sed 's/^-one$/-three/; s/^+two$/+four/' "$work/patches/10-change.patch" > "$work/bad/20-stale.patch"
out=$(bash "$apply" "$work/core" "$work/bad" 2>&1) && fail "a stale patch passed"
grep -q '20-stale.patch neither applies' <<<"$out" || fail "stale patch not named: $out"

grep -v '^Upstream:' "$work/patches/10-change.patch" > "$work/bad/20-stale.patch"
out=$(bash "$apply" "$work/core" "$work/bad" 2>&1) && fail "a patch without upstream link passed"
grep -q 'names no Upstream: link' <<<"$out" || fail "missing link not named: $out"

mkdir "$work/empty"
out=$(bash "$apply" "$work/core" "$work/empty") || fail "an empty patch directory failed"
[ -z "$out" ] || fail "an empty patch directory printed: $out"

# Since: a fresh core tree per case, its version in xml/version.xml.
since_core() { # $1 = core version_no; resets $work/sc to the unpatched fixture
  /bin/rm -rf "$work/sc" "$work/sp"
  mkdir -p "$work/sc/CRM" "$work/sc/xml" "$work/sp"
  printf 'one\nkeep\n' > "$work/sc/CRM/A.php"
  printf '<?xml version="1.0"?>\n<version>\n  <version_no>%s</version_no>\n</version>\n' "$1" > "$work/sc/xml/version.xml"
}
since_patch() { # $1 = Since value
  sed "s/^Upstream: .*/&\nSince: $1/" "$work/patches/10-change.patch" > "$work/sp/10-change.patch"
}

since_core 6.18.2; since_patch 6.19.1
out=$(bash "$apply" "$work/sc" "$work/sp") || fail "a patch for a newer core failed the build"
[ "$out" = "not-applicable 10-change.patch (core 6.18.2 < 6.19.1)" ] || fail "expected 'not-applicable', got: $out"
[ "$(head -1 "$work/sc/CRM/A.php")" = "one" ] || fail "a not-applicable patch changed the file"

# version.xml is read as XML: whitespace around the value is no obstacle.
since_core 6.18.2; since_patch 6.19.1
printf '<?xml version="1.0"?>\n<version>\n  <version_no>\n    6.18.2\n  </version_no>\n</version>\n' > "$work/sc/xml/version.xml"
out=$(bash "$apply" "$work/sc" "$work/sp") || fail "a multi-line <version_no> failed the build"
[ "$out" = "not-applicable 10-change.patch (core 6.18.2 < 6.19.1)" ] || fail "multi-line <version_no> misread: $out"

since_core 6.19.0; since_patch 6.19.1
out=$(bash "$apply" "$work/sc" "$work/sp") || fail "a patch for a newer patch release failed the build"
[ "$out" = "not-applicable 10-change.patch (core 6.19.0 < 6.19.1)" ] || fail "6.19.0 not older than 6.19.1: $out"

for v in 6.19.1 6.19.10 6.20.0 6.20.beta1 6.21.alpha1 7.0.0; do
  since_core "$v"; since_patch 6.19.1
  out=$(bash "$apply" "$work/sc" "$work/sp") || fail "core $v rejected a Since: 6.19.1 patch"
  [ "$out" = "applied 10-change.patch" ] || fail "core $v: expected 'applied', got: $out"
  [ "$(head -1 "$work/sc/CRM/A.php")" = "two" ] || fail "core $v: the patch was not applied"
  out=$(bash "$apply" "$work/sc" "$work/sp") || fail "core $v: a contained Since patch was rejected"
  [ "$out" = "contained 10-change.patch" ] || fail "core $v: expected 'contained', got: $out"
done

# A pre-release precedes its X.Y.0, and alpha < beta < rc.
for pair in 6.20.beta1:6.20.0 6.20.alpha1:6.20.beta1 6.20.beta2:6.20.rc1 6.20.beta1:6.20.beta2 6.9.0:6.10.0; do
  since_core "${pair%%:*}"; since_patch "${pair#*:}"
  out=$(bash "$apply" "$work/sc" "$work/sp") || fail "core ${pair%%:*}, Since ${pair#*:} failed the build"
  [ "$out" = "not-applicable 10-change.patch (core ${pair%%:*} < ${pair#*:})" ] \
    || fail "core ${pair%%:*} not older than ${pair#*:}: $out"
done

since_core 6.20.0; since_patch 6.19.1
sed 's/^-one$/-three/; s/^+two$/+four/' "$work/sp/10-change.patch" > "$work/sp/20-stale.patch"
out=$(bash "$apply" "$work/sc" "$work/sp" 2>&1) && fail "a stale Since patch passed"
grep -q '20-stale.patch neither applies' <<<"$out" || fail "stale Since patch not named: $out"

since_core 6.18.2; since_patch 6.19
out=$(bash "$apply" "$work/sc" "$work/sp" 2>&1) && fail "an invalid Since: passed"
grep -q 'invalid Since: 6.19' <<<"$out" || fail "invalid Since not named: $out"

since_patch 6.19.1; /bin/rm "$work/sc/xml/version.xml"
out=$(bash "$apply" "$work/sc" "$work/sp" 2>&1) && fail "a Since patch passed without a core version"
grep -q 'cannot read .*/xml/version.xml' <<<"$out" || fail "missing core version not named: $out"

printf '<?xml version="1.0"?>\n<version><releaseDate>2026-10-07</releaseDate></version>\n' > "$work/sc/xml/version.xml"
out=$(bash "$apply" "$work/sc" "$work/sp" 2>&1) && fail "a Since patch passed without <version_no>"
grep -q 'no valid <version_no>' <<<"$out" || fail "missing <version_no> not named: $out"
/bin/rm "$work/sc/xml/version.xml"

# Without the header, the core version is neither read nor needed.
cp "$work/patches/10-change.patch" "$work/sp/10-change.patch"
out=$(bash "$apply" "$work/sc" "$work/sp") || fail "a patch without Since: needed a core version"
[ "$out" = "applied 10-change.patch" ] || fail "a patch without Since: changed behaviour: $out"

echo "core patches: OK"
