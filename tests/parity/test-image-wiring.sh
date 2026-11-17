#!/usr/bin/env bash
# Image-wiring suite: no job of the shared workflows hands inputs.image to a
# stack past the policy.civicrm resolver.
set -euo pipefail

cd "$(dirname "$0")/../.."
CHECK="tests/parity/image-wiring.php"
fail() { echo "FAIL: $*" >&2; exit 1; }

php "$CHECK" .github/workflows/extension-ci.yml .github/workflows/extension-release.yml \
  || fail "a job reads inputs.image past the policy.civicrm resolver"

out=$(php "$CHECK" tests/parity/fixtures/workflow.image-unwired.yml 2>&1) \
  && fail "checker passed a workflow that reads inputs.image directly"
grep -q "'direct'" <<<"$out" || fail "checker did not name job 'direct' (got: $out)"
grep -q "'stepwise'" <<<"$out" || fail "checker did not name job 'stepwise' (got: $out)"
grep -q "'resolved'" <<<"$out" && fail "checker flagged job 'resolved', which uses the resolved image"

echo "image wiring suite OK"
