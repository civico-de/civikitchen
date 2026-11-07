#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
check="$root/toolbelt/bin/ckchangelog"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

valid="$work/valid.md"
cat > "$valid" <<'MD'
# Changelog

Prose that the grammar ignores.

## [Unreleased]

## [1.2.0] - 2026-01-05

### Added

- A thing.

### Fixed

- Another thing.

## [1.1.0] - 2026-01-01

### Changed

- An older thing.

[Unreleased]: https://example.org/compare/v1.2.0...HEAD
[1.2.0]: https://example.org/compare/v1.1.0...v1.2.0
[1.1.0]: https://example.org/releases/tag/v1.1.0
MD

# Each case: a description, the sed-free fixture body, and the expected message.
expect_pass() {
  local label="$1" f="$2"; shift 2
  if ! "$check" --file "$f" "$@" >"$work/out" 2>&1; then
    echo "expected pass: $label" >&2; cat "$work/out" >&2; exit 1
  fi
}

expect_fail() {
  local label="$1" f="$2" needle="$3"; shift 3
  if "$check" --file "$f" "$@" >"$work/out" 2>&1; then
    echo "expected failure: $label" >&2; cat "$work/out" >&2; exit 1
  fi
  if ! grep -q "$needle" "$work/out"; then
    echo "wrong message for: $label" >&2; cat "$work/out" >&2; exit 1
  fi
}

expect_pass "valid file lints" "$valid" lint
expect_pass "valid file releases" "$valid" release 1.2.0
expect_pass "a v-prefixed version is accepted" "$valid" release v1.2.0

expect_fail "missing version section" "$valid" \
  "version 9.9.9 has no changelog section" release 9.9.9

future="$work/future.md"
sed 's/2026-01-05/2999-01-05/' "$valid" > "$future"
expect_fail "a future date is not releasable" "$future" \
  "in the future" release 1.2.0

leftover="$work/leftover.md"
awk '{ print } /^## \[Unreleased\]$/ { print ""; print "### Added"; print ""; print "- Not moved yet." }' \
  "$valid" > "$leftover"
expect_pass "leftover unreleased entries still lint" "$leftover" lint
expect_fail "leftover unreleased entries block a release" "$leftover" \
  "still has entries" release 1.2.0

# Content the grammar does not name is an error, so nothing hides in Unreleased.
for line in '* Pending change.' 'Prose under the heading.' '  Indented without a bullet.'; do
  awk -v l="$line" '{ print } /^## \[Unreleased\]$/ { print ""; print l }' "$valid" > "$work/stray.md"
  expect_fail "a stray line in Unreleased: $line" "$work/stray.md" "unexpected line" lint
done
awk '{ print } /^## \[Unreleased\]$/ { print ""; print "- Not under a section." }' "$valid" > "$work/loose.md"
expect_fail "an entry outside a ### section" "$work/loose.md" "entry outside a ### section" lint
wrapped="$work/wrapped.md"
sed 's/^- A thing\.$/- A thing\n  that wraps.\n  - and a nested bullet./' "$valid" > "$wrapped"
expect_pass "wrapped and nested entry lines" "$wrapped" lint
for date in 2026-00-10 2026-13-01 2026-02-30 2025-02-29; do
  sed "s/^## \[1\.1\.0\] - 2026-01-01$/## [1.1.0] - $date/" "$valid" > "$work/date.md"
  expect_fail "an impossible date: $date" "$work/date.md" "bad version heading" lint
done
sed 's/^## \[1\.1\.0\] - 2026-01-01$/## [1.1.0] - 2024-02-29/' "$valid" > "$work/leap.md"
expect_pass "a leap day" "$work/leap.md" lint

# The link references close the file: nothing after them can be cut from the release notes.
awk '{ print } /^\[Unreleased\]:/ { print "- Hidden from the notes." }' "$valid" > "$work/after.md"
expect_fail "content after the link references" "$work/after.md" "content after the link references" lint
awk '{ print } /^\[Unreleased\]:/ { print "## [0.1.0] - 2025-01-01" }' "$valid" > "$work/afterheading.md"
expect_fail "a heading after the link references" "$work/afterheading.md" "content after the link references" lint
awk '{ print } /^\[1\.1\.0\]:/ { print "[1.1.0]: https://example.org/stale" }' "$valid" > "$work/dupref.md"
expect_fail "a duplicate link reference" "$work/dupref.md" "duplicate link reference \\[1.1.0\\]" lint

unknown="$work/unknown.md"
sed 's/^### Added$/### Improved/' "$valid" > "$unknown"
expect_fail "unknown subsection" "$unknown" "unknown subsection: Improved" lint

order="$work/order.md"
sed -e 's/^## \[1\.2\.0\] - 2026-01-05$/## [1.0.0] - 2026-01-05/' \
    -e 's/^\[1\.2\.0\]:/[1.0.0]:/' "$valid" > "$order"
expect_fail "out-of-order versions" "$order" "out of descending order" lint

noref="$work/noref.md"
grep -v '^\[1\.1\.0\]:' "$valid" > "$noref"
expect_fail "missing link reference" "$noref" "no link reference for \[1.1.0\]" lint
grep -v '^\[Unreleased\]:' "$valid" > "$work/nounreleased.md"
expect_fail "a released changelog links Unreleased" "$work/nounreleased.md" "no link reference for \[Unreleased\]" lint

empty="$work/empty.md"
sed '/^- An older thing\.$/d' "$valid" > "$empty"
expect_fail "empty version section" "$empty" "has no entries" lint

twice="$work/twice.md"
awk '{ print } /^## \[Unreleased\]$/ && !done { print ""; print "## [Unreleased]"; done = 1 }' \
  "$valid" > "$twice"
expect_fail "two Unreleased headings" "$twice" "exactly one" lint

"$check" --file "$valid" section 1.1.0 > "$work/section"
cat > "$work/section.expected" <<'MD'
### Changed

- An older thing.
MD
diff -u "$work/section.expected" "$work/section"

expect_fail "no section for an unknown version" "$valid" \
  "no section for version 9.9.9" section 9.9.9

# This repository's own changelog must pass the grammar too.
(cd "$root" && "$check" lint >/dev/null)

fresh="$work/fresh.md"
cat > "$fresh" <<'MD'
# Changelog

## [Unreleased]

### Added

- The first feature.
MD
expect_pass "an extension without a release needs no link reference" "$fresh" lint
expect_fail "but it cannot release" "$fresh" "has no changelog section" release 0.1.0

expect_fail "a non-semver version is refused" "$valid" "not a SemVer version" release 1.2
expect_fail "a missing file is an error" "$work/absent.md" "does not exist" lint

pre="$work/pre.md"
sed -e 's/^## \[1\.2\.0\] - 2026-01-05$/## [1.2.0-alpha.10] - 2026-01-05/' \
    -e 's/^\[1\.2\.0\]:/[1.2.0-alpha.10]:/' "$valid" > "$pre"
expect_pass "a pre-release version lints" "$pre" lint
expect_pass "and releases" "$pre" release v1.2.0-alpha.10
prebad="$work/prebad.md"
sed -e 's/^## \[1\.1\.0\] - 2026-01-01$/## [1.2.0-alpha.9] - 2026-01-01/' \
    -e 's/^\[1\.1\.0\]:/[1.2.0-alpha.9]:/' "$pre" > "$prebad"
expect_pass "alpha.10 outranks alpha.9" "$prebad" lint
rc="$work/rc.md"
sed -e 's/^## \[1\.1\.0\] - 2026-01-01$/## [1.2.0] - 2026-01-01/' \
    -e 's/^## \[1\.2\.0\] - 2026-01-05$/## [1.2.0-rc.1] - 2026-01-05/' \
    -e 's/^\[1\.2\.0\]:/[1.2.0-rc.1]:/' -e 's/^\[1\.1\.0\]:/[1.2.0]:/' "$valid" > "$rc"
expect_fail "a pre-release does not sort above its release" "$rc" "out of descending order" lint

echo "ckchangelog: ok"
