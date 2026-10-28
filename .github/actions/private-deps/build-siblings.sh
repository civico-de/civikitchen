#!/usr/bin/env bash
# Build the release output a sibling declares under policy.dist.build, before
# the read-only mount; a sibling without a declaration is left as it is.
#
#   build-siblings.sh plan    read CK_SIBLING_DIRS; write `dirs=` (siblings
#                             building with Bun) and `bun_version_file=`
#   build-siblings.sh build   build CK_SIBLING_BUILD_DIRS on the Bun set up
#                             from that file, and check every declared output
set -euo pipefail
set -f
cd "${GITHUB_WORKSPACE:-.}"

ci_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
ckconform="$ci_root/toolbelt/bin/ckconform"
ck="$ci_root/toolbelt/bin/ck"

unreadable() {
  echo "sibling $1: its $2 could not be read by this civikitchen version (the reason is above)" >&2
  exit 1
}

plan() {
  local dirs="" pin="" first="" dir tool sibling_pin parser_ready=""
  for dir in ${CK_SIBLING_DIRS:-}; do
    [ -f "$dir/civikitchen.yaml" ] || continue
    if [ -z "$parser_ready" ]; then
      [ -f "$ckconform" ] \
        || { echo "sibling builds: $ckconform is missing — check civikitchen out with toolbelt/ and packages/ beside .github/actions" >&2; exit 1; }
      composer install --no-dev --no-interaction --no-progress --classmap-authoritative \
        --working-dir="$ci_root"
      parser_ready=1
    fi
    tool=$(cd "$dir" && php "$ckconform" --policy dist_build_tool) || unreadable "$dir" civikitchen.yaml
    case "$tool" in
      '')
        echo "sibling $dir declares no release build"
        continue
        ;;
      bun) ;;
      *) echo "sibling $dir: unsupported policy.dist.build tool '$tool'" >&2; exit 1 ;;
    esac
    # Refuses an unsafe output path, an unpinned Bun or a missing bun.lock up front.
    (cd "$dir" && php "$ckconform" --dist-paths > /dev/null) \
      || { echo "sibling $dir: its policy.dist.build cannot be built as declared" >&2; exit 1; }
    sibling_pin=$(php "$ck" internal json-field "$dir/package.json" packageManager) || unreadable "$dir" package.json
    # One job installs one Bun, so every building sibling has to pin the same.
    if [ -z "$first" ]; then
      first=$dir
      pin=$sibling_pin
    elif [ "$sibling_pin" != "$pin" ]; then
      echo "sibling $dir pins $sibling_pin, sibling $first pins $pin — siblings that build with Bun must pin the same version" >&2
      exit 1
    fi
    dirs="${dirs:+$dirs }$dir"
  done
  if [ -n "$dirs" ] && [ -z "${CK_NODE_VERSION:-}" ]; then
    echo "sibling builds: node_version is required to build $dirs" >&2
    exit 1
  fi
  echo "siblings with a declared Bun build: ${dirs:-none}"
  {
    echo "dirs=$dirs"
    echo "bun_version_file=${first:+$first/package.json}"
  } >> "$GITHUB_OUTPUT"
}

build() {
  local dir staged kind path
  for dir in ${CK_SIBLING_BUILD_DIRS:-}; do
    echo "bun install --frozen-lockfile && bun run build in sibling $dir"
    if ! (cd "$dir" && bun install --frozen-lockfile && bun run build); then
      echo "sibling $dir: its declared release build (policy.dist.build) failed" >&2
      exit 1
    fi
    staged=$(cd "$dir" && php "$ckconform" --dist-paths)
    while read -r kind path; do
      [ "$kind" = stage ] || continue
      [ -e "$dir/$path" ] \
        || { echo "sibling $dir: the build did not write its declared output $path" >&2; exit 1; }
    done <<< "$staged"
  done
}

case "${1:-}" in
  plan) plan ;;
  build) build ;;
  *) echo "usage: build-siblings.sh plan|build" >&2; exit 2 ;;
esac
