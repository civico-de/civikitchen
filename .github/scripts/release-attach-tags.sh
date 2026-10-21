#!/usr/bin/env bash
# Attach the $TAG release tags, and $MAJOR's if $TAG is the newest on its line,
# to every digest in digests.txt (written by release-verify-images.sh). The
# source is deliberately digest-qualified; --prefer-index=false prevents Buildx from wrapping a source manifest in a
# fresh one-item index when the registry does not expose its media type during
# resolution. That keeps the release tag on the exact verified digest while
# preserving multi-platform indexes as indexes.
set -euo pipefail

# The moving $MAJOR tags follow the newest vX.Y.Z only, as the git one does: a
# re-run of an older release catches up its patch tags and nothing else.
newest=$("$(dirname "$0")/release-newest-tag.sh")
with_major=1
if [ "${newest}" != "${TAG}" ]; then
  echo "::notice::${newest} is newer than ${TAG}; the ${MAJOR} image tags stay where they are"
  with_major=0
fi

while IFS=$'\t' read -r flavor digest; do
  for name in "${flavor}-" ''; do
    # The unprefixed tags alias standalone: the contract image the extension
    # template pins.
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
