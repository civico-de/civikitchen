#!/usr/bin/env bash
# Create or update the GitLab release of the mirror's bare-version tag, with
# the version's changelog section as its description.
#
#   release-mirror-notes.sh <version> <notes.md>
#
# CK_MIRROR_URL is the mirror's HTTPS clone address; CK_MIRROR_TOKEN needs the
# api scope and reaches curl only through a header file.
set -euo pipefail

version="${1:?usage: release-mirror-notes.sh <version> <notes.md>}"
notes="${2:?usage: release-mirror-notes.sh <version> <notes.md>}"
url="${CK_MIRROR_URL:?CK_MIRROR_URL names the repository that receives the release}"
token="${CK_MIRROR_TOKEN:?CK_MIRROR_TOKEN may write releases on the mirror}"

[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] \
  || { echo "not a release version: '$version'" >&2; exit 1; }
[ -f "$notes" ] || { echo "no release notes at $notes" >&2; exit 1; }
[[ "$url" =~ ^(https://[^/]+)/(.+[^/])$ ]] \
  || { echo "the mirror is not an HTTPS project address: $url" >&2; exit 1; }
host="${BASH_REMATCH[1]}"
project="${BASH_REMATCH[2]%.git}"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
(umask 077 && printf 'PRIVATE-TOKEN: %s\n' "$token" > "$work/auth")

encode() { php -r 'echo rawurlencode($argv[1]);' "$1"; }
api="$host/api/v4/projects/$(encode "$project")/releases"
# One JSON encoder: the description is the notes file verbatim.
php -r 'echo json_encode(["tag_name" => $argv[1], "name" => $argv[1], "description" => file_get_contents($argv[2])], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);' \
  "$version" "$notes" > "$work/body.json"

request() { # <method> <url> [body file]; prints the HTTP status, the body goes to $work/response
  local args=(--silent --show-error --header "@$work/auth" --output "$work/response" --write-out '%{http_code}' --request "$1")
  [ -z "${3:-}" ] || args+=(--header 'Content-Type: application/json' --data-binary "@$3")
  curl "${args[@]}" "$2"
}

refused() { # <reading|writing>; GitLab's answer names the missing scope or role
  echo "$1 the mirror's release $version returned HTTP $status: $(head -c 500 "$work/response")" >&2
  exit 1
}

status="$(request GET "$api/$(encode "$version")")"
case "$status" in
  200) method=PUT target="$api/$(encode "$version")" outcome=updated ;;
  404) method=POST target="$api" outcome=created ;;
  *) refused reading ;;
esac
status="$(request "$method" "$target" "$work/body.json")"
case "$status" in
  200 | 201) echo "$outcome the mirror's release $version" ;;
  *) refused writing ;;
esac
