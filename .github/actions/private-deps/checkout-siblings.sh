#!/usr/bin/env bash
# Clone every entry of CK_SIBLING_REPO into .civikitchen-siblings/<extension
# key> and write the directory list to $GITHUB_OUTPUT as `paths=`.
# An entry is `owner/repo` or `owner/repo@ref` (tag, branch or 40-hex commit).
# Named by key, which CiviCRM and phpstan's ArchitectureTest expect; cloned to
# an owner-repo staging dir first, since the key is read from the clone.
set -euo pipefail

# Entries are split by unquoted expansion and must never glob.
set -f

# LFS files stay pointers, as with actions/checkout's default.
export GIT_LFS_SKIP_SMUDGE=1

# The toolbelt is checked out beside .github/actions, as build-siblings.sh needs it too.
ck="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)/toolbelt/bin/ck"

# Every path below is workspace-relative.
cd "${GITHUB_WORKSPACE:-.}"

root=.civikitchen-siblings

# The calling workflow's own checkouts under the same parent.
reserved="ci policy"

# Validated here, where the value becomes a command. A ref may not start with
# a dash: git would read it as an option.
entry_re='^[A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*(@[A-Za-z0-9][A-Za-z0-9._/-]*)?$'
for entry in ${CK_SIBLING_REPO//,/ }; do
  [[ "$entry" =~ $entry_re ]] \
    || { echo "invalid sibling_repo entry (expected owner/repo or owner/repo@ref): $entry" >&2; exit 1; }
done

# `git -c` keeps the token out of .git/config. Never echo it: base64 defeats
# the runner's secret masking.
header="AUTHORIZATION: basic $(printf 'x-access-token:%s' "$CK_SIBLING_TOKEN" | base64 | tr -d '\n')"

mkdir -p "$root"
paths=""
for entry in ${CK_SIBLING_REPO//,/ }; do
  repo="${entry%%@*}"
  ref=""
  if [ "$entry" != "$repo" ]; then
    ref="${entry#*@}"
  fi
  url="https://github.com/$repo"
  staging="$root/.staging-${repo//\//-}"
  rm -rf "$staging"
  if [ -z "$ref" ]; then
    git -c "http.https://github.com/.extraheader=$header" \
      clone --quiet --depth 1 "$url" "$staging"
  elif [[ "$ref" =~ ^[0-9a-fA-F]{40}$ ]]; then
    # A commit cannot be cloned by name: fetch it into an empty repository.
    git -c init.defaultBranch=main init --quiet "$staging"
    git -C "$staging" remote add origin "$url"
    if ! git -C "$staging" -c "http.https://github.com/.extraheader=$header" \
      fetch --quiet --depth 1 origin "$ref"; then
      echo "sibling_repo: commit $ref of $repo is not reachable from any branch or tag (only reachable commits can be fetched)" >&2
      exit 1
    fi
    git -C "$staging" checkout --quiet FETCH_HEAD
  else
    git -c "http.https://github.com/.extraheader=$header" \
      clone --quiet --depth 1 --branch "$ref" "$url" "$staging"
  fi

  info="$staging/info.xml"
  if [ ! -f "$info" ]; then
    echo "sibling_repo: no info.xml in $repo — that repo is not a CiviCRM extension" >&2
    exit 1
  fi
  key=$(php "$ck" internal xml-field "$info" key)
  if ! [[ "$key" =~ ^[A-Za-z][A-Za-z0-9._-]*$ ]]; then
    echo "sibling_repo: could not read a usable extension key from $repo's info.xml (got: '$key')" >&2
    exit 1
  fi
  case " $reserved " in
    *" $key "*)
      echo "sibling_repo: $repo declares the extension key '$key', which is reserved for the workflow's own checkout under $root/" >&2
      exit 1
      ;;
  esac

  dir="$root/$key"
  if [ -e "$dir" ]; then
    echo "sibling_repo: $repo declares the extension key '$key', but $dir already exists — two entries cannot share a key" >&2
    exit 1
  fi
  mv "$staging" "$dir"

  echo "sibling extension: $key (from $entry)"
  paths="${paths:+$paths }$dir"
done

echo "checked out siblings: $paths"
echo "paths=$paths" >> "$GITHUB_OUTPUT"
