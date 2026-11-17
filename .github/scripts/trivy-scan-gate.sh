#!/usr/bin/env bash
# The gating trivy run over $IMAGE_REF for $PLATFORM: fixable HIGH/CRITICAL
# CVEs only, exceptions in trivyignore.yaml.
set -euo pipefail

"$RUNNER_TEMP/trivy" image \
  --image-src remote \
  --platform "$PLATFORM" \
  --scanners vuln \
  --ignore-unfixed \
  --severity HIGH,CRITICAL \
  --ignorefile trivyignore.yaml \
  --exit-code 1 \
  --no-progress \
  "$IMAGE_REF"
