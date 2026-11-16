#!/usr/bin/env bash
# Publish a release to a second git repository whose tags an extension
# directory reads: the source branch as it is at the release, and the release
# archive's contents as one commit on top of it, tagged with the bare version.
# That tag carries what the archive carries (vendor/, build output), so an
# archive of it installs.
#
#   release-mirror.sh <archive.zip> <version> <branch>
#
# Run in a full clone of the source repository with the released commit
# checked out. CK_MIRROR_URL names the repository; CK_MIRROR_TOKEN (a token
# that may write to it) and CK_SOURCE_TOKEN (one that may read the source's
# LFS objects) are sent as HTTP headers, never placed in a URL or an argument.
set -euo pipefail

zip="${1:?usage: release-mirror.sh <archive.zip> <version> <branch>}"
version="${2:?usage: release-mirror.sh <archive.zip> <version> <branch>}"
branch="${3:?usage: release-mirror.sh <archive.zip> <version> <branch>}"
url="${CK_MIRROR_URL:?CK_MIRROR_URL names the repository that receives the release}"
remote=ck-release-mirror

[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] \
  || { echo "not a release version: '$version'" >&2; exit 1; }
[ -f "$zip" ] || { echo "no archive at $zip" >&2; exit 1; }
if [ "$(git rev-parse --is-shallow-repository)" != false ]; then
  echo "the source clone is shallow: the mirror receives the branch with its history" >&2
  exit 1
fi

headers=()
basic() { printf 'Authorization: Basic %s' "$(printf '%s' "$1" | base64 | tr -d '\n')"; }
if [ -n "${CK_MIRROR_TOKEN:-}" ]; then
  headers+=("http.${url}.extraheader" "$(basic "oauth2:${CK_MIRROR_TOKEN}")")
fi
if [ -n "${CK_SOURCE_TOKEN:-}" ]; then
  headers+=("http.$(git remote get-url origin).extraheader" "$(basic "x-access-token:${CK_SOURCE_TOKEN}")")
fi
export GIT_CONFIG_COUNT=$((${#headers[@]} / 2))
for ((i = 0; i < ${#headers[@]}; i += 2)); do
  export "GIT_CONFIG_KEY_$((i / 2))=${headers[i]}" "GIT_CONFIG_VALUE_$((i / 2))=${headers[i + 1]}"
done
export GIT_TERMINAL_PROMPT=0

work="$(mktemp -d)"
cleanup() {
  rm -rf "$work"
  git remote remove "$remote" 2>/dev/null || true
}
trap cleanup EXIT
git remote remove "$remote" 2>/dev/null || true
git remote add "$remote" "$url"

source_commit="$(git rev-parse 'HEAD^{commit}')"
git ls-remote "$remote" > "$work/refs"
ref() { awk -v ref="$1" '$2 == ref { print $1 }' "$work/refs"; }

# The archive holds one directory named after the extension key.
mkdir "$work/archive"
unzip -q "$zip" -d "$work/archive"
shopt -s nullglob dotglob
tops=("$work/archive"/*)
shopt -u nullglob dotglob
if [ "${#tops[@]}" -ne 1 ] || [ ! -f "${tops[0]}/info.xml" ]; then
  echo "${zip##*/} does not hold one extension directory with an info.xml" >&2
  exit 1
fi

# Built without a checkout: the archive's files become the tree, as they are.
# The author and dates are the released commit's, so a re-run builds the same
# commit and pushes nothing new.
tree="$(
  export GIT_DIR GIT_WORK_TREE="${tops[0]}" GIT_INDEX_FILE="$work/index"
  GIT_DIR="$(git rev-parse --absolute-git-dir)"
  git -C "$GIT_WORK_TREE" -c core.autocrlf=false -c core.safecrlf=false \
    -c filter.lfs.clean= -c filter.lfs.smudge= -c filter.lfs.process= -c filter.lfs.required=false \
    add --all --force . >&2
  git write-tree
)"
release_commit="$(
  export GIT_AUTHOR_NAME GIT_AUTHOR_EMAIL GIT_AUTHOR_DATE GIT_COMMITTER_NAME GIT_COMMITTER_EMAIL GIT_COMMITTER_DATE
  GIT_AUTHOR_NAME="$(git show -s --format=%an "$source_commit")"
  GIT_AUTHOR_EMAIL="$(git show -s --format=%ae "$source_commit")"
  GIT_AUTHOR_DATE="$(git show -s --format=%aI "$source_commit")"
  GIT_COMMITTER_NAME="$GIT_AUTHOR_NAME" GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL" GIT_COMMITTER_DATE="$GIT_AUTHOR_DATE"
  git commit-tree "$tree" -p "$source_commit" -m "Release $version"
)"

published="$(ref "refs/tags/$version")"
if [ -n "$published" ] && [ "$published" != "$release_commit" ]; then
  echo "the mirror's tag $version is $published, this release builds $release_commit: a published tag does not move" >&2
  exit 1
fi

# The mirror refuses a commit whose LFS objects it does not hold.
if git grep -q 'filter=lfs' "$source_commit" -- .gitattributes '*/.gitattributes' 2>/dev/null; then
  git lfs version >/dev/null 2>&1 \
    || { echo "the source tracks files with Git LFS, but git-lfs is not installed" >&2; exit 1; }
  git lfs fetch --all origin
  git lfs push --all "$remote"
fi

refspecs=("$release_commit:refs/tags/$version")
mirrored="$(ref "refs/heads/$branch")"
if [ -z "$mirrored" ] || { git cat-file -e "$mirrored^{commit}" 2>/dev/null && git merge-base --is-ancestor "$mirrored" "$source_commit"; }; then
  refspecs+=("$source_commit:refs/heads/$branch")
else
  echo "the mirror's $branch is not behind this release: leaving it, publishing the tag only"
fi
git push --atomic "$remote" "${refspecs[@]}"
echo "published $version to $url"
