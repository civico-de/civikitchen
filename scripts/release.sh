#!/usr/bin/env bash
# Pre-flight for cutting a release tag: everything release.yml would reject,
# checked locally before the tag exists. --apply creates and pushes the tag.
set -euo pipefail

usage() { echo "usage: release.sh X.Y.Z [--apply]" >&2; exit 2; }

version="${1:-}"
apply="${2:-}"
[ "$#" -ge 1 ] && [ "$#" -le 2 ] || usage
grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$' <<<"$version" || usage
[ -z "$apply" ] || [ "$apply" = --apply ] || usage

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"
tag="v${version}"
ok() { echo "ok: $*"; }
die() { echo "release: $*" >&2; exit 1; }

branch=$(git rev-parse --abbrev-ref HEAD)
[ "$branch" = main ] || die "branch is ${branch}, not main"
[ -z "$(git status --porcelain)" ] || die "working tree is not clean"
git fetch -q origin
head=$(git rev-parse HEAD)
[ "$head" = "$(git rev-parse origin/main)" ] || die "HEAD is not origin/main"
ok "main, clean, in sync with origin at ${head}"

# origin first: the fetch above pulls a remote tag into the local refs too.
[ -z "$(git ls-remote --tags origin "refs/tags/${tag}")" ] || die "tag ${tag} exists on origin"
if git rev-parse -q --verify "refs/tags/${tag}" >/dev/null; then die "tag ${tag} exists locally"; fi
ok "${tag} is free locally and on origin"

bash .github/scripts/changelog-check.sh --release "$version" >/dev/null || die "changelog gate failed"
ok "changelog has a [${version}] section and an empty [Unreleased]"
scaffold/ck make test-ckinit >/dev/null || die "ckinit suite failed"
ok "ckinit suite passed"

# The image gate: the trigger paths are read out of the workflow itself, so the
# two cannot drift apart.
paths=()
while IFS= read -r p; do paths+=("$p"); done < <(scaffold/ck make -s release-image-paths)
[ "${#paths[@]}" -gt 0 ] || die "no trigger paths found in build-dev-images.yml"
image_sha=$(git log -1 --format=%H HEAD -- "${paths[@]}")
[ -n "$image_sha" ] || die "no commit touching the image trigger paths"
# Repo from origin: no script in this repo hardwires the slug.
repo=$(git config --get remote.origin.url | sed -E 's#^(https://github\.com/|git@github\.com:)##; s#\.git$##')
# Any run on a commit from the image commit up to HEAD counts, whatever its
# event: this workflow builds exactly the images that gate a release; the CMS
# flavors build in build-cms-images.yml and catch up later (docs/releases.md).
# One line per run, newest last: reruns mean a commit can have several runs.
run=$(gh run list -R "$repo" --workflow build-dev-images.yml \
  --branch main --limit 100 --json status,conclusion,updatedAt,databaseId,event,headSha \
  --jq '.[] | "\(.updatedAt) \(.databaseId) \(.event) \(.status) \(.conclusion) \(.headSha)"' | sort |
  while read -r line; do
    sha=${line##* }
    if git merge-base --is-ancestor "$image_sha" "$sha" 2>/dev/null &&
      git merge-base --is-ancestor "$sha" HEAD 2>/dev/null; then echo "$line"; fi
  done | tail -1)
[ -n "$run" ] || die "no Build Dev Images run for image commit ${image_sha}: push it or start one with 'gh workflow run build-dev-images.yml -R ${repo}'"
read -r _ run_id run_event run_status run_conclusion _ <<<"$run"
[ "$run_status" = completed ] || die "newest Build Dev Images run ${run_id} for ${image_sha} is ${run_status}"
[ "$run_conclusion" = success ] || die "newest Build Dev Images run ${run_id} for ${image_sha} concluded ${run_conclusion}"
ok "Build Dev Images run ${run_id} (${run_event}) is green for image commit ${image_sha}"

if [ "$apply" != --apply ]; then
  echo "dry run: would tag ${head} as ${tag} and push it to origin (pass --apply)"
  exit 0
fi

git tag -a "$tag" -m "$tag"
git push origin "$tag"
echo "pushed ${tag} -> ${head}"
