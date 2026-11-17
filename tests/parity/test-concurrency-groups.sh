#!/usr/bin/env bash
# Concurrency-group suite: every group in the shared extension CI names the
# caller workflow and the image; the fixture legs prove the checker catches misses.
set -euo pipefail

cd "$(dirname "$0")/../.."
CHECK="tests/parity/concurrency-groups.php"
fail() { echo "FAIL: $*" >&2; exit 1; }

php "$CHECK" .github/workflows/extension-ci.yml \
  || fail "an extension CI concurrency group lets other callers, images or legs cancel the job"

out=$(php "$CHECK" tests/parity/fixtures/workflow.concurrency-groups.yml 2>&1) \
  && fail "checker passed groups that miss the caller workflow, the image or the legs"
while read -r job context; do
  grep -qF "job '$job' concurrency group does not name $context" <<<"$out" \
    || fail "checker did not report $context missing from job '$job' (got: $out)"
done <<'EXPECTED'
no-workflow github.workflow
no-image inputs.image
literal github.workflow
literal inputs.image
quoted github.workflow
quoted inputs.image
near-miss github.workflow
near-miss inputs.image
legs matrix.image
include-legs matrix
EXPECTED
grep -qF "jobs 'copy-a', 'copy-b' share one concurrency group" <<<"$out" \
  || fail "checker did not report two jobs sharing one group (got: $out)"
for job in good good-legs no-group; do
  if grep -qF "'$job'" <<<"$out"; then fail "checker flagged job '$job' (got: $out)"; fi
done

out=$(php "$CHECK" tests/parity/fixtures/workflow.no-concurrency.yml 2>&1) \
  && fail "checker passed a workflow without any concurrency group"
grep -qF 'no job has a concurrency group' <<<"$out" \
  || fail "checker failed a workflow without groups for another reason (got: $out)"

echo "concurrency groups suite OK"
