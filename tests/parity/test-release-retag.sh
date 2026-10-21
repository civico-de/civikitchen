#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }

# The script runs from a copy of .github/scripts, as in the release job; a
# docker shim records every call, one per line.
mkdir -p "${work}/bin" "${work}/scripts"
cp "${root}/.github/scripts/release-attach-tags.sh" "${root}/.github/scripts/release-newest-tag.sh" "${work}/scripts/"
cat > "${work}/bin/docker" <<SHIM
#!/bin/bash
printf '%s\n' "\$*" >> "${work}/calls"
SHIM
chmod +x "${work}/bin/docker"
export PATH="${work}/bin:${PATH}"
export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL="${work}/gitconfig"
git config --global user.name test
git config --global user.email test@example.org
git config --global init.defaultBranch main

# The checkout is a snapshot: v1.2.11 reaches origin after it was taken, as
# from an overlapping release.
git init -q --bare "${work}/remote.git"
git init -q "${work}/seed"
git -C "${work}/seed" commit -q --allow-empty -m one
for t in v1.2.3 v1.2.10 v2.0.0 v1; do git -C "${work}/seed" tag "$t"; done
git -C "${work}/seed" push -q "${work}/remote.git" HEAD:refs/heads/main --tags
git clone -q "${work}/remote.git" "${work}/repo"
git -C "${work}/seed" tag v1.2.11
git -C "${work}/seed" push -q "${work}/remote.git" v1.2.11
cd "${work}/repo"
[ -z "$(git tag -l v1.2.11)" ] || fail "fixture: the checkout already has v1.2.11"
printf 'standalone\tsha256:aaa\ndrupal11\tsha256:bbb\n' > digests.txt
export IMAGE_PREFIX=ghcr.io/owner/civikitchen MAJOR=v1
P="${IMAGE_PREFIX}"

attach() { : > "${work}/calls"; TAG="$1" "${work}/scripts/release-attach-tags.sh" > "${work}/out" 2>&1; }
# One call per line, its `-t <tag>` pairs in a fixed order.
norm() {
  local line
  while read -r line; do
    sed -E 's/ -t [^ ]+//g' <<<"${line}" | tr -d '\n'
    grep -oE -- '-t [^ ]+' <<<"${line}" | sort | tr '\n' ' '
    echo
  done
}
same() { [ "$(norm < "${work}/calls")" = "$(norm <<<"$1")" ]; }

# Release sources are digest-qualified. Buildx defaults to preferring an index
# and may therefore wrap a manifest whose media type was not discovered,
# changing the top-level digest despite identical image content.
# The newest patch of the line on origin (by version, not by string:
# 1.2.11 > 1.2.3) moves the major tags.
attach v1.2.11 || { cat "${work}/out" >&2; fail "attach v1.2.11"; }
expected="buildx imagetools create --prefer-index=false -t ${P}:standalone-v1.2.11 -t ${P}:standalone-v1 ${P}@sha256:aaa
buildx imagetools create --prefer-index=false -t ${P}:v1.2.11 -t ${P}:v1 ${P}@sha256:aaa
buildx imagetools create --prefer-index=false -t ${P}:drupal11-v1.2.11 -t ${P}:drupal11-v1 ${P}@sha256:bbb"
same "${expected}" || { cat "${work}/calls" >&2; fail "newest release: wrong retags"; }

# v1.2.10 was the newest in the checkout's snapshot, but not on origin.
attach v1.2.10 || { cat "${work}/out" >&2; fail "attach v1.2.10"; }
grep -qE -- '[:-]v1 ' "${work}/calls" && { cat "${work}/calls" >&2; fail "a release older than origin's newest moved a major tag"; }
grep -q '::notice::v1.2.11 is newer than v1.2.10' "${work}/out" || { cat "${work}/out" >&2; fail "no notice for v1.2.10"; }

# An older patch, e.g. a catch-up re-run, gets its patch tags only.
attach v1.2.3 || { cat "${work}/out" >&2; fail "attach v1.2.3"; }
expected="buildx imagetools create --prefer-index=false -t ${P}:standalone-v1.2.3 ${P}@sha256:aaa
buildx imagetools create --prefer-index=false -t ${P}:v1.2.3 ${P}@sha256:aaa
buildx imagetools create --prefer-index=false -t ${P}:drupal11-v1.2.3 ${P}@sha256:bbb"
same "${expected}" || { cat "${work}/calls" >&2; fail "older release moved a major tag"; }
grep -q '::notice::v1.2.11 is newer than v1.2.3' "${work}/out" || { cat "${work}/out" >&2; fail "no notice for the kept major tags"; }

echo "release retag regression test passed"
