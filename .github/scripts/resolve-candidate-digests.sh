#!/usr/bin/env bash
# Resolve this run's candidate `$IMAGE_PREFIX:<$PREFIX><tag>-$SHA` for every tag
# in $TAGS (JSON array) to its digest, once. Emits `digests`, a JSON object
# tag -> digest: the scan reads exactly these and promote publishes them.
set -euo pipefail

digests='{}'
while IFS= read -r tag; do
  ref="${IMAGE_PREFIX}:${PREFIX}${tag}-${SHA}"
  digest=$(docker buildx imagetools inspect "$ref" --format '{{.Manifest.Digest}}')
  [[ "$digest" =~ ^sha256:[a-f0-9]{64}$ ]] \
    || { echo "could not resolve a digest for $ref (got: '$digest')" >&2; exit 1; }
  echo "$ref -> $digest"
  digests=$(jq -c --arg t "$tag" --arg d "$digest" '. + {($t): $d}' <<<"$digests")
done < <(jq -r '.[]' <<<"$TAGS")
[ "$digests" != '{}' ] || { echo "no tags to resolve in TAGS='$TAGS'" >&2; exit 1; }
echo "digests=$digests" >> "$GITHUB_OUTPUT"
