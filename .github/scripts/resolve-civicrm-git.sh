#!/usr/bin/env bash
# Resolve the refs the git-built standalone images track: master and the newest
# X.Y release branch, present in both civicrm-core and civicrm-packages.
# Emits git_refs, one {ref, tag} entry per image tag: the caller's matrix.
set -euo pipefail

CORE=https://github.com/civicrm/civicrm-core.git
PACKAGES=https://github.com/civicrm/civicrm-packages.git

branches() { # $1 = repository URL; prints one branch name per line
  local heads
  heads=$(git ls-remote --heads "$1") || { echo "could not list the branches of $1" >&2; return 1; }
  awk '{ sub(/^refs\/heads\//, "", $2); print $2 }' <<<"${heads}"
}

core_branches=$(branches "${CORE}")
packages_branches=$(branches "${PACKAGES}")

# Version order, not string order: 6.20 is newer than 6.9.
BETA=$(awk '/^[0-9]+\.[0-9]+$/' <<<"${core_branches}" | sort -t. -k1,1n -k2,2n | tail -n 1)
if [ -z "${BETA}" ]; then
  echo "no X.Y release branch in ${CORE}" >&2
  exit 1
fi
echo "newest release branch: ${BETA}"

missing=()
for ref in master "${BETA}"; do
  grep -qxF "${ref}" <<<"${core_branches}" || missing+=("${ref} in ${CORE}")
  grep -qxF "${ref}" <<<"${packages_branches}" || missing+=("${ref} in ${PACKAGES}")
done
if [ "${#missing[@]}" -gt 0 ]; then
  printf 'no branch %s\n' "${missing[@]}" >&2
  exit 1
fi

REFS="[{\"ref\":\"master\",\"tag\":\"master\"},{\"ref\":\"${BETA}\",\"tag\":\"beta\"}]"
echo "git refs: ${REFS}"
echo "git_refs=${REFS}" >> "$GITHUB_OUTPUT"
