#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
flags="$root/.github/scripts/release-publish-flags.sh"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# Newest release v0.10.0 by numeric order; the major, four-part and
# leading-zero tags must not count as versions.
cat > "$work/tags" <<'TAGS'
refs/tags/v0.0.0
refs/tags/v0.3.0
refs/tags/v0.9.0
refs/tags/v0.10.0
refs/tags/v0.10.0-rc.1
refs/tags/v0.11.0-alpha3
refs/tags/v1
refs/tags/v2.2.7.1
refs/tags/v09.0.0
latest-build
TAGS

expect() {
  local label="$1" tag="$2" want="$3"
  if ! "$flags" "$tag" < "$work/tags" > "$work/out" 2>&1; then
    echo "expected flags for: $label" >&2; cat "$work/out" >&2; exit 1
  fi
  if [ "$(cat "$work/out")" != "$(printf '%b' "$want")" ]; then
    echo "wrong flags for: $label" >&2
    printf 'want:\n%b\ngot:\n' "$want" >&2; cat "$work/out" >&2; exit 1
  fi
}

reject() {
  local label="$1" tag="$2"
  if "$flags" "$tag" < "$work/tags" > "$work/out" 2>&1; then
    echo "expected rejection: $label" >&2; cat "$work/out" >&2; exit 1
  fi
  grep -q "not a release tag: '$tag'" "$work/out" \
    || { echo "wrong message for: $label" >&2; cat "$work/out" >&2; exit 1; }
}

expect "the highest plain tag takes Latest" v0.10.0 'prerelease=false\nlatest=true'
expect "a late release of an older version does not" v0.0.0 'prerelease=false\nlatest=false'
expect "lexically larger, numerically smaller" v0.9.0 'prerelease=false\nlatest=false'
expect "a new highest version not yet in the list" v0.12.0 'prerelease=false\nlatest=true'
expect "an older version not in the list" v0.3.1 'prerelease=false\nlatest=false'
expect "a pre-release is never Latest" v0.10.0-rc.1 'prerelease=true\nlatest=false'
expect "nor above the newest plain tag" v0.11.0-alpha3 'prerelease=true\nlatest=false'
expect "a pre-release of a new major" v3.0.0-beta.2 'prerelease=true\nlatest=false'
expect "a highest tag above an unrelated pre-release" v0.11.0 'prerelease=false\nlatest=true'

reject "four components" v2.2.7.1
reject "moving major tag" v1
reject "no v prefix" 1.2.3
reject "leading zero" v01.2.3
reject "leading zero in a numeric pre-release identifier" v1.2.3-rc.01
reject "empty pre-release identifier" v1.2.3-rc..1
reject "build metadata" v1.2.3+build.5
reject "empty pre-release" v1.2.3-
reject "not a version" latest-build

# Tag names without the refs/tags/ prefix count the same.
printf 'v9.0.0\nv1.0.0\n' | "$flags" v1.0.0 > "$work/out"
[ "$(cat "$work/out")" = "$(printf 'prerelease=false\nlatest=false')" ] \
  || { echo "an unprefixed higher tag must keep Latest" >&2; cat "$work/out" >&2; exit 1; }

# No tag list at all (a first release) is still decidable.
"$flags" v0.1.0 < /dev/null > "$work/out"
[ "$(cat "$work/out")" = "$(printf 'prerelease=false\nlatest=true')" ] \
  || { echo "a first release must take Latest" >&2; cat "$work/out" >&2; exit 1; }

echo "release publish flags: ok"
