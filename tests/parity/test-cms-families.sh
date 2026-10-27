#!/usr/bin/env bash
# cms-families.sh: the CMS families build-cms-images.yml builds, minus `skip`,
# and no job of that workflow lists a family past it.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

families() {
  : > "$work/out"
  (cd "$root" && GITHUB_OUTPUT="$work/out" SKIP="$1" bash .github/scripts/cms-families.sh)
}
output() { sed -n "s/^$1=//p" "$work/out"; }

families ''
[ "$(output families)" = '["drupal10","drupal11","wordpress","joomla","backdrop"]' ] || fail "default families: $(output families)"
[ "$(jq length <<<"$(output demo_smoke)")" = 25 ] || fail "every demo family smokes every profile"
while IFS= read -r compose; do
  [ -f "$root/$compose" ] || fail "e2e names a missing compose file: $compose"
done < <(jq -r '.[].compose' <<<"$(output e2e)")

families ' joomla , wordpress'
[ "$(output families)" = '["drupal10","drupal11","backdrop"]' ] || fail "skip kept: $(output families)"
if grep -q 'joomla\|wordpress' "$work/out"; then
  fail "a skipped family is still in an output: $(cat "$work/out")"
fi

if out=$(families 'joomla,bogus' 2>&1); then fail "an unknown family was accepted"; fi
grep -qF 'unknown CMS families: ["bogus"]' <<<"$out" || fail "the unknown family was not named: $out"
if families 'drupal10,drupal11,wordpress,joomla,backdrop' >/dev/null 2>&1; then fail "skipping every family was accepted"; fi

# A family a job lists itself would build even when skipped.
php -r '
  require $argv[1];
  foreach (ck_jobs_parsed($argv[2]) as $name => $job) {
    if (preg_match("/drupal10|drupal11|wordpress|joomla|backdrop/", ck_job_text($job)) === 1) {
      fwrite(STDERR, "job $name lists a CMS family past cms-families.sh\n");
      exit(1);
    }
  }
' "$root/tests/parity/workflow-jobs.php" "$root/.github/workflows/build-cms-images.yml" \
  || fail "build-cms-images.yml lists a CMS family outside cms-families.sh"

echo "cms families suite OK"
