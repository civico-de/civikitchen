#!/usr/bin/env bash
# Compose-project isolation suite: the real workflows must give every
# stack-booting job its own compose project, and the checker must actually
# catch one that does not (the fixture leg — the check is silent on success).
set -euo pipefail

cd "$(dirname "$0")/../.."
CHECK="tests/parity/compose-project-isolation.php"
fail() { echo "FAIL: $*" >&2; exit 1; }

php "$CHECK" .github/workflows/*.yml \
  scaffold/template/extension/.github/workflows/*.yml \
  || fail "a workflow job boots a compose stack without its own project name"

# The fixture shares one project across two jobs on a configurable runner; the
# checker must go red and name both.
out=$(php "$CHECK" tests/parity/fixtures/workflow.shared-compose-project.yml 2>&1) \
  && fail "checker passed a workflow with a shared compose project"
for job in ci compat; do
  grep -q "'$job'" <<<"$out" \
    || fail "checker failed but did not name job '$job' (got: $out)"
done

# The GitHub-hosted job in the same fixture must NOT be reported, or the gate
# would demand a project name where no collision is possible.
grep -q "'hosted'" <<<"$out" \
  && fail "checker flagged a job pinned to a GitHub-hosted runner"

# Comments that name what the job does not do: the gate reads the `runs-on`
# key and the jobs' scalars, so neither bait changes the verdict.
out=$(php "$CHECK" tests/parity/fixtures/workflow.compose-project-comment-bait.yml 2>&1) \
  && fail "checker passed a shared compose project excused only by comments"
for job in bait hosted-in-a-comment; do
  grep -q "'$job'" <<<"$out" \
    || fail "checker failed but did not name job '$job' (got: $out)"
done

# A job-scoped project nobody removes: the checker names the job without an
# always() teardown and passes the one that has it behind a condition.
out=$(php "$CHECK" tests/parity/fixtures/workflow.compose-project-leak.yml 2>&1) \
  && fail "checker passed a job that never removes its compose project"
grep -q "'leak'" <<<"$out" || fail "checker failed but did not name job 'leak' (got: $out)"
grep -q "'smoke'" <<<"$out" && fail "checker flagged a job whose teardown runs under always() && …"

echo "compose project isolation suite OK"
