#!/usr/bin/env bash
# Attach the $TAG tags (and $MAJOR's if $TAG is newest on its line) to every
# digest in digests.txt. --prefer-index=false keeps Buildx from wrapping a
# manifest in a fresh one-item index, so the tag lands on the verified digest.
set -euo pipefail

newest=$("$(dirname "$0")/release-newest-tag.sh")
with_major=1
if [ "${newest}" != "${TAG}" ]; then
  echo "::notice::${newest} is newer than ${TAG}; the ${MAJOR} image tags stay where they are"
  with_major=0
fi

while IFS=$'\t' read -r flavor digest; do
  for name in "${flavor}-" ''; do
    # The unprefixed tags alias standalone, the image the template pins.
    [ -n "${name}" ] || [ "${flavor}" = standalone ] || continue
    tags=(-t "${IMAGE_PREFIX}:${name}${TAG}")
    [ "${with_major}" = 0 ] || tags+=(-t "${IMAGE_PREFIX}:${name}${MAJOR}")
    echo "tagging ${flavor} (${digest}) as ${tags[*]}"
    docker buildx imagetools create \
      --prefer-index=false \
      "${tags[@]}" \
      "${IMAGE_PREFIX}@${digest}"
  done
done < digests.txt
