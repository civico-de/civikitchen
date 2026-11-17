#!/usr/bin/env bash
# Every shipped compose file passes `docker compose config` and names an existing
# build context and Dockerfile. Needs the docker CLI only.
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"
rc=0
while IFS= read -r file; do
  if ! json=$(DOCKER_HOST=unix:///nonexistent docker compose -f "$file" config --format json 2>&1); then
    echo "FAIL: $file: $json" >&2
    rc=1
    continue
  fi
  while IFS=$'\t' read -r service context dockerfile; do
    if [[ ! -d "$context" ]]; then
      echo "FAIL: $file: $service: build context $context does not exist" >&2
      rc=1
    elif [[ ! -f "$context/$dockerfile" ]]; then
      echo "FAIL: $file: $service: $dockerfile not found in $context" >&2
      rc=1
    fi
  done < <(jq -r '.services | to_entries[] | select(.value.build) | [.key, .value.build.context, (.value.build.dockerfile // "Dockerfile")] | @tsv' <<<"$json")
done < <(git ls-files | grep -E '(^|/)docker-compose[^/]*\.ya?ml$')
[[ "$rc" -eq 0 ]] || exit 1
echo "compose config: ok"
