#!/usr/bin/env bash
# Resolve the digest behind every published flavor tag of GHCR package $PKG
# (owner $OWNER) into digests.txt and verify each belongs to release
# $TAG/$RELEASE_SHA: its build commit's docker/ + toolbelt/ trees must match
# the release's, and an already-published patch tag must not move to another
# digest. standalone and standalone-demo are mandatory; a CMS flavor that is
# missing or drifted is a warning and stays out of digests.txt until a re-run
# catches it up. A flavor this release already tagged is left as is.
# A build commit that cannot be told counts as drift. $ALLOW_DRIFT=1 downgrades
# drift to a warning and attaches. Needs $GH_TOKEN.
set -euo pipefail

# --paginate concatenates one JSON array per page; --jq '.[]' streams the
# elements so `jq -s` can make them one array again.
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

# What decides whether a built image belongs to this release: the trees the
# Dockerfiles copy from. docker/ (image definitions, entrypoints, provisioning,
# demo profiles), toolbelt/ (the ck* tools and analysers) and packages/ (the
# profile and scenario schemas). 2>/dev/null: an older build commit missing a
# path yields an empty result, which compares unequal — the right verdict.
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

# Every published flavor. standalone leads because the unprefixed :v1 /
# :v1.2.3 tags alias it — it is the image the extension template's compose
# stacks run.
for flavor in standalone drupal10 drupal11 wordpress joomla \
              standalone-demo drupal10-demo drupal11-demo \
              wordpress-demo joomla-demo; do
  case "${flavor}" in
    standalone | standalone-demo) mandatory=1 ;;
    *) mandatory=0 ;;
  esac
  # The release tags this flavor gets; released ones exist on one digest.
  release_tags=("${flavor}-${TAG}")
  [ "${flavor}" != standalone ] || release_tags+=("${TAG}")
  released=()
  for t in "${release_tags[@]}"; do
    released+=("$(digest_of "${t}")")
  done
  # A re-run after a rebuild finds the moving tag on a newer digest. A tag
  # this release already set stays where it is.
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

  # Provenance: which commit was this digest built from, and do its docker/ +
  # toolbelt/ + packages/ trees match the release? An unknown answer counts as
  # a mismatch.
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

  # Released patch tags are immutable: a half-tagged flavor (one of its
  # release tags on another digest) must not be moved to complete it.
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
