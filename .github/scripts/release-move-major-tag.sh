#!/usr/bin/env bash
# Move the moving major tag $MAJOR to $RELEASE_SHA — unless a newer patch than
# $TAG exists on that line, so a patch cut on an older line cannot drag the
# major tag backwards. Pushes with $RELEASE_PUSH_TOKEN, a GitHub App token.
set -euo pipefail

: "${RELEASE_PUSH_TOKEN:?RELEASE_PUSH_TOKEN is empty: the release App token was not minted}"

newest=$("$(dirname "$0")/release-newest-tag.sh")
if [ "${newest}" != "${TAG}" ]; then
  echo "${newest} is newer than ${TAG}; leaving ${MAJOR} where it is"
  exit 0
fi
git tag -f "${MAJOR}" "${RELEASE_SHA}"

# The credential lives in this process's environment only: not in argv, not in
# .git/config.
basic=$(printf 'x-access-token:%s' "${RELEASE_PUSH_TOKEN}" | base64 | tr -d '\n')
GIT_CONFIG_COUNT=1 \
  GIT_CONFIG_KEY_0='http.https://github.com/.extraheader' \
  GIT_CONFIG_VALUE_0="AUTHORIZATION: basic ${basic}" \
  git push -f origin "refs/tags/${MAJOR}"
echo "${MAJOR} -> ${RELEASE_SHA}"
