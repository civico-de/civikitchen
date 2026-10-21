#!/usr/bin/env bash
# Point each moving tag given as an argument at $IMAGE_PREFIX@$DIGEST: the
# digest the scan resolved and passed, never a re-read of the candidate tag.
# Digest-qualified and --prefer-index=false, as in release-attach-tags.sh.
set -euo pipefail

[[ "${DIGEST:-}" =~ ^sha256:[a-f0-9]{64}$ ]] \
  || { echo "no scanned digest to promote (got: '${DIGEST:-}')" >&2; exit 1; }
[ "$#" -gt 0 ] || { echo "usage: promote-digest.sh <tag>..." >&2; exit 2; }

tags=()
for t in "$@"; do tags+=(-t "${IMAGE_PREFIX}:${t}"); done
echo "promoting ${IMAGE_PREFIX}@${DIGEST} as $*"
docker buildx imagetools create --prefer-index=false "${tags[@]}" "${IMAGE_PREFIX}@${DIGEST}"
