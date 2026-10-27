#!/usr/bin/env bash
# Dist-build-wiring suite: every job of the shared extension CI workflow that
# runs the suite builds the policy.dist.build output first — plus the fixture
# leg, because the check is silent on success.
set -euo pipefail

cd "$(dirname "$0")/../.."
CHECK="tests/parity/dist-build-wiring.php"
fail() { echo "FAIL: $*" >&2; exit 1; }

php "$CHECK" .github/workflows/extension-ci.yml \
  || fail "a job runs the suite without building the declared release output"

out=$(php "$CHECK" tests/parity/fixtures/workflow.dist-build-unwired.yml 2>&1) \
  && fail "checker passed a workflow whose jobs run the suite unbuilt"
grep -q "'bare'" <<<"$out" || fail "checker did not name job 'bare' (got: $out)"
grep -q "'late'" <<<"$out" || fail "checker did not name job 'late' (got: $out)"
grep -q "'built'" <<<"$out" && fail "checker flagged job 'built', which builds first"
grep -q "'imaged'" <<<"$out" && fail "checker flagged job 'imaged', which builds first in the Playwright image"

echo "dist build wiring suite OK"
