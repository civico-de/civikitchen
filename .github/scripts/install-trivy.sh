#!/usr/bin/env bash
# Install the pinned Trivy into $RUNNER_TEMP, verified against the release's
# checksums. Only the two pinned Linux architectures are accepted.
set -euo pipefail

readonly version=0.72.0

case "$(uname -s)/$(uname -m)" in
  Linux/x86_64)
    asset=Linux-64bit
    sha=bbb64b9695866ce4a7a8f5c9592002c5961cab378577fa3f8a040df362b9b2ea
    ;;
  Linux/aarch64|Linux/arm64)
    asset=Linux-ARM64
    sha=2ca2c023109c2db6b2b77366b6717291452d4531167377d95c79547f0c8e3467
    ;;
  *)
    echo "no pinned Trivy build for $(uname -s)/$(uname -m) — this installer supports Linux x86_64 and arm64 runners" >&2
    exit 1
    ;;
esac

tarball="$RUNNER_TEMP/trivy_${version}_${asset}.tar.gz"
curl -fsSL --retry 3 --retry-all-errors -o "$tarball" \
  "https://github.com/aquasecurity/trivy/releases/download/v${version}/trivy_${version}_${asset}.tar.gz"
echo "$sha  $tarball" | sha256sum -c -
tar -xzf "$tarball" -C "$RUNNER_TEMP" trivy
"$RUNNER_TEMP/trivy" --version
