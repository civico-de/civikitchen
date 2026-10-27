#!/usr/bin/env bash
# Clone every entry of CK_SIBLING_REPO into .civikitchen-siblings/<extension
# key> and write the directory list to $GITHUB_OUTPUT as `paths=`.
#
# An entry is `owner/repo` (default branch) or `owner/repo@ref`, where ref is
# a tag, a branch or a full 40-hex commit.
#
# The directory is named after the extension KEY, not the repo: that is the
# name CiviCRM registers the extension under, the name `cv ext:enable`
# expects, and the path phpstan's ArchitectureTest probes
# (<ext>/.civikitchen-siblings/<key>). The key is only known once the clone's
# info.xml can be read, so the clone lands in a staging directory named after
# owner AND repo — two siblings may share a basename — and is moved afterwards.
#
# Lives next to action.yml rather than in .github/scripts/: the reusable
# workflows check this repository out with `sparse-checkout: .github/actions`.
set -euo pipefail

# No globbing anywhere below: an entry is a clone target and a path, never a
# pattern, and an unquoted expansion is what splits the list.
set -f

# Every path below is workspace-relative, and the calling job may run its own
# steps from somewhere else entirely.
cd "${GITHUB_WORKSPACE:-.}"

root=.civikitchen-siblings

# The calling workflow checks its own helpers out under the same parent. A
# sibling claiming one of those names would have the workflow delete it.
reserved="ci policy"

# Every entry has to be owner/repo, optionally @ref — it becomes a clone
# target and a path. Validated here as well as in the calling job: this is the
# only place that turns the value into a command. A ref may not start with a
# dash: git would read it as an option.
entry_re='^[A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*(@[A-Za-z0-9][A-Za-z0-9._/-]*)?$'
for entry in ${CK_SIBLING_REPO//,/ }; do
  [[ "$entry" =~ $entry_re ]] \
    || { echo "invalid sibling_repo entry (expected owner/repo or owner/repo@ref): $entry" >&2; exit 1; }
done

# `git -c` keeps the credential in this process: unlike a token in the clone
# URL it never reaches the sibling's .git/config. Never echoed either — base64
# defeats the runner's secret masking.
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
    # A commit is not a ref the remote advertises, so it cannot be cloned by
    # name: fetch it into an empty repository and check out what came back.
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
  # Parsed as XML, not with a tag-shaped regex: an attribute order or a root
  # element split over two lines is legal and would silently yield an empty
  # key. Same reader as the toolbelt's ck_xml_field — inline, because this
  # runs on the bare runner where the toolbelt is not on PATH. php is
  # preinstalled on the GitHub-hosted images.
  key=$(php -r '
    libxml_use_internal_errors(TRUE);
    $xml = simplexml_load_file($argv[1]);
    if ($xml === FALSE) { fwrite(STDERR, "cannot parse {$argv[1]}\n"); exit(2); }
    echo trim((string) $xml["key"]);
  ' "$info")
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
