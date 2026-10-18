#!/usr/bin/env bash
# Pre-checkout working-directory suite: no workflow step runs in a workspace
# subdirectory before the checkout — plus the fixture leg, because the check
# is silent on success.
set -euo pipefail

cd "$(dirname "$0")/../.."
CHECK="tests/parity/pre-checkout-workdir.php"
fail() { echo "FAIL: $*" >&2; exit 1; }

php "$CHECK" .github/workflows/*.yml \
  scaffold/template/extension/.github/workflows/*.yml \
  || fail "a workflow step runs in a subdirectory before the checkout"

out=$(php "$CHECK" tests/parity/fixtures/workflow.pre-checkout-workdir.yml 2>&1) \
  && fail "checker passed a workflow whose steps run before the checkout"
grep -q "'early'" <<<"$out" || fail "checker did not name job 'early' (got: $out)"
grep -q "'inherited'" <<<"$out" || fail "checker did not name job 'inherited' (got: $out)"
grep -q "'root'" <<<"$out" && fail "checker flagged job 'root', which runs in the workspace root"
grep -q "'after'" <<<"$out" && fail "checker flagged job 'after', which runs after the checkout"

echo "pre-checkout working directory suite OK"
