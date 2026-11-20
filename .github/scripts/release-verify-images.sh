#!/usr/bin/env bash
# Write the digest behind each flavor tag of $PKG to digests.txt if its build
# commit's image trees match $RELEASE_SHA. standalone(-demo) and civiproxy are
# mandatory; other flavors warn. $ALLOW_DRIFT=1 attaches anyway. Needs $GH_TOKEN.
set -euo pipefail

gh api "/orgs/${OWNER}/packages/container/${PKG}/versions?per_page=100" \
    --paginate --jq '.[]' | jq -s '.' > versions.json

# The digest (GHCR calls it `name`) of the version carrying a tag.
digest_of() {
  jq -r --arg t "$1" \
    'map(select(.metadata.container.tags | index($t))) | .[0].name // ""' versions.json
}
# A candidate tag left on that same digest, e.g. standalone-latest-<sha>.
candidate_of() {
  jq -r --arg t "$1" '
    map(select(.metadata.container.tags | index($t)))
    | .[0].metadata.container.tags // []
    | map(select(test("-[0-9a-f]{40}$"))) | .[0] // ""' versions.json
}

# The trees the Dockerfiles copy from. A path missing in an older commit
# yields an empty hash, which compares unequal.
image_tree_of() { # $1 = commit-ish
  printf '%s %s %s' \
    "$(git rev-parse "$1:docker" 2>/dev/null)" \
    "$(git rev-parse "$1:toolbelt" 2>/dev/null)" \
    "$(git rev-parse "$1:packages" 2>/dev/null)"
}
RELEASE_TREE=$(image_tree_of "${RELEASE_SHA}")
drift=()
fatal=()
: > digests.txt

# standalone leads: the unprefixed :v1 / :v1.2.3 tags alias it.
for flavor in standalone drupal10 drupal11 wordpress joomla backdrop \
              standalone-demo drupal10-demo drupal11-demo \
              wordpress-demo joomla-demo backdrop-demo civiproxy; do
  case "${flavor}" in
    standalone | standalone-demo | civiproxy) mandatory=1 ;;
    *) mandatory=0 ;;
  esac
  # The release tags this flavor gets; released ones exist on one digest.
  release_tags=("${flavor}-${TAG}")
  [ "${flavor}" != standalone ] || release_tags+=("${TAG}")
  released=()
  for t in "${release_tags[@]}"; do
    released+=("$(digest_of "${t}")")
  done
  # A tag this release already set stays, even if the moving tag has moved on.
  if [ -n "${released[0]}" ] && [ "$(printf '%s\n' "${released[@]}" | sort -u)" = "${released[0]}" ]; then
    echo "::notice::release tag :${flavor}-${TAG} is already released on ${released[0]}, left as is"
    continue
  fi

  digest=$(digest_of "${flavor}")
  if [ -z "${digest}" ]; then
    if [ "${mandatory}" = 1 ]; then
      fatal+=("no published image behind :${flavor}")
    else
      echo "::warning::no published image behind :${flavor}; its release tags follow on a re-run once 'Build CMS Images' promotes one"
    fi
    continue
  fi

  # Provenance: an unknown build commit counts as a mismatch.
  candidate=$(candidate_of "${flavor}")
  build_sha="${candidate##*-}"
  problem=''
  if [ -z "${candidate}" ]; then
    problem="cannot tell which commit :${flavor} was built from (candidate tag already pruned)"
  elif ! git cat-file -e "${build_sha}^{commit}" 2>/dev/null; then
    problem="build commit ${build_sha} of :${flavor} is not in this checkout"
  elif [ "$(image_tree_of "${build_sha}")" != "${RELEASE_TREE}" ]; then
    problem=":${flavor} was built from ${build_sha:0:12}, whose image trees differ from ${TAG}"
  fi
  if [ -n "${problem}" ]; then
    if [ "${ALLOW_DRIFT}" = 1 ]; then
      echo "::warning::${problem}; attached anyway (allow_image_drift)"
    elif [ "${mandatory}" = 1 ]; then
      drift+=("${problem}")
    else
      echo "::warning::${problem}; its release tags follow on a re-run once 'Build CMS Images' promotes a matching image"
      continue
    fi
  fi

  # Released patch tags are immutable, even to complete a half-tagged flavor.
  for i in "${!release_tags[@]}"; do
    if [ -n "${released[i]}" ] && [ "${released[i]}" != "${digest}" ]; then
      fatal+=(":${release_tags[i]} is already published on another digest — cut a new patch version")
    fi
  done
  printf '%s\t%s\n' "${flavor}" "${digest}" >> digests.txt
done

if [ ${#drift[@]} -gt 0 ]; then
  printf 'image drift: %s\n' "${drift[@]}" >&2
  echo "Wait for a 'Build Dev Images' run (push, daily or dispatched) to promote this commit, or re-run this workflow with allow_image_drift." >&2
  exit 1
fi
if [ ${#fatal[@]} -gt 0 ]; then
  printf 'FATAL: %s\n' "${fatal[@]}" >&2
  exit 1
fi
cat digests.txt
