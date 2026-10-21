#!/usr/bin/env bash
# Promote-scanned suite: every promote job of the image pipelines waits for the
# scan of its own candidates — plus the fixture leg, because the check is
# silent on success.
set -euo pipefail

cd "$(dirname "$0")/../.."
CHECK="tests/parity/promote-scanned.php"
fail() { echo "FAIL: $*" >&2; exit 1; }

php "$CHECK" .github/workflows/build-dev-images.yml .github/workflows/build-cms-images.yml \
  .github/workflows/build-demo-images.yml \
  || fail "a promote job does not wait for the scan of its candidates"

out=$(php "$CHECK" tests/parity/fixtures/workflow.promote-unscanned.yml 2>&1) \
  && fail "checker passed a promote job without a scan"
for job in promote-unscanned promote-other-family promote-unrelated-scan promote-always \
           promote-not-cancelled promote-candidate-tag promote-wider-matrix; do
  grep -q "'${job}'" <<<"$out" || fail "checker did not name job '${job}' (got: $out)"
done
grep -q "'promote-scanned'" <<<"$out" && fail "checker flagged job 'promote-scanned', which promotes its own scanned digests"

echo "promote-scanned suite OK"
