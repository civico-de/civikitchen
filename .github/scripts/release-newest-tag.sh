#!/usr/bin/env bash
# Print the newest vX.Y.Z git tag on the $MAJOR line. A moving major tag, git
# or image, follows only that release, so an older patch never drags it back.
set -euo pipefail

# Origin's tags, not the checkout's snapshot: a release cut since this job
# started is newer.
git fetch -q --tags --force origin
git tag -l "${MAJOR}.*" | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | tail -1
