#!/usr/bin/env bash
# Attach $TAG, and $MAJOR if $TAG is the newest on its line, to the digest behind
# $IMAGE_PREFIX:latest (package $PKG, owner $OWNER). Every path the cli target
# copies must match $RELEASE_SHA in its build commit unless $ALLOW_DRIFT=1. A
# published $TAG is the release's image and stays where it is. Needs $GH_TOKEN.
set -euo pipefail

dockerfile=docker/cli/Dockerfile

gh api "/orgs/${OWNER}/packages/container/${PKG}/versions?per_page=100" \
    --paginate --jq '.[]' | jq -s '.' > cli-versions.json
digest_of() {
  jq -r --arg t "$1" 'map(select(.metadata.container.tags | index($t))) | .[0].name // ""' cli-versions.json
}

released="$(digest_of "${TAG}")"
if [ -n "${released}" ]; then
  echo "::notice::${PKG}:${TAG} is already released on ${released}, left as is"
  exit 0
fi

# The sources of the cli stage's context COPYs, read from the release's Dockerfile.
inputs="$(git show "${RELEASE_SHA}:${dockerfile}" | awk '
  /^FROM / { cli = ($NF == "cli") }
  cli && /^COPY / && $2 !~ /^--from=/ { for (i = 2; i < NF; i++) print $i }')"
[ -n "${inputs}" ] || { echo "no COPY sources in the cli stage of ${dockerfile} at ${RELEASE_SHA}" >&2; exit 1; }
for path in ${inputs}; do
  git cat-file -e "${RELEASE_SHA}:${path}" 2>/dev/null || { echo "${dockerfile} copies ${path}, which is not a path at ${RELEASE_SHA}" >&2; exit 1; }
done
tree_of() {
  local path
  for path in ${inputs} "${dockerfile}"; do printf '%s ' "$(git rev-parse "$1:${path}" 2>/dev/null)"; done
}

digest="$(digest_of latest)"
[ -n "${digest}" ] || { echo "no published image behind ${PKG}:latest" >&2; exit 1; }
build_sha="$(jq -r --arg d "${digest}" 'map(select(.name == $d)) | .[0].metadata.container.tags
  | map(select(test("^latest-[0-9a-f]{40}$"))) | .[0] // "" | ltrimstr("latest-")' cli-versions.json)"
if [ -z "${build_sha}" ] || ! git cat-file -e "${build_sha}^{commit}" 2>/dev/null \
    || [ "$(tree_of "${build_sha}")" != "$(tree_of "${RELEASE_SHA}")" ]; then
  problem="${PKG}:latest was not built from a commit whose cli inputs match ${TAG} (build commit: '${build_sha}')"
  [ "${ALLOW_DRIFT}" = 1 ] || { echo "image drift: ${problem}" >&2; exit 1; }
  echo "::warning::${problem}; attached anyway (allow_image_drift)"
fi

tags=(-t "${IMAGE_PREFIX}:${TAG}")
[ "$("$(dirname "$0")/release-newest-tag.sh")" != "${TAG}" ] || tags+=(-t "${IMAGE_PREFIX}:${MAJOR}")
echo "tagging ${PKG} (${digest}) as ${tags[*]}"
docker buildx imagetools create --prefer-index=false "${tags[@]}" "${IMAGE_PREFIX}@${digest}"
