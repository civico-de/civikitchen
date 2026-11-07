#!/usr/bin/env bash
# The stack actions, run as written: stack-up merges its files in order and
# boots through a fake docker; stack-down tears down by project label; every
# stack job tears down under always(). Needs jq and the docker CLI, not a daemon.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
actions="$root/.github/actions"
workflow="$root/.github/workflows/extension-ci.yml"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
value() { php "$root/tests/parity/workflow-value.php" "$@"; }

mkdir "$work/bin" "$work/project" "$work/tmp"
cat > "$work/bin/docker" <<'SH'
#!/bin/sh
printf 'docker %s | image=%s files=%s\n' "$*" "${CIVIKITCHEN_IMAGE-}" "${COMPOSE_FILE-}" >> "$CALL_LOG"
SH
chmod +x "$work/bin/docker"
run() {
  : > "$work/calls.log"
  : > "$work/output"
  (cd "$work/project" && env PATH="$work/bin:$PATH" CALL_LOG="$work/calls.log" RUNNER_TEMP="$work/tmp" \
    GITHUB_OUTPUT="$work/output" CK_COMPOSE_FILE=.docker/ci.yml CIVIKITCHEN_IMAGE=run:img \
    COMPOSE_PROJECT_NAME=ck-1-x "$@" bash --noprofile --norc -eo pipefail "$work/step.sh") || fail "step failed: $*"
}

# --- stack-up: files in order, empty lines skipped, relative ones anchored ---
value "$actions/stack-up/action.yml" runs steps 'Start CiviCRM stack' run > "$work/step.sh"
run CK_OVERRIDES=$'/abs/one.yml\n\nrel/two.yml\n' CK_SEED_STORE=true CK_VENDOR_IMAGE=
p="$work/project"
files="$p/.docker/ci.yml:/abs/one.yml:$p/rel/two.yml:$work/tmp/ck-seed-override.yml"
[ "$(cat "$work/output")" = "compose_file=$files" ] || fail "stack-up output: $(cat "$work/output")"
expected="docker compose down -v --remove-orphans | image=run:img files=$files
docker compose run --rm --no-deps -T -u $(id -u):$(id -g) -e HOME=/tmp --entrypoint sh app -c exec ck internal composer-install /var/www/html/ext/*/ | image=run:img files=$files
docker compose up -d --wait --quiet-pull | image=run:img files=$files"
[ "$(cat "$work/calls.log")" = "$expected" ] || fail "stack-up runs:"$'\n'"$(cat "$work/calls.log")"
grep -q -- "- $work/tmp/ck-seed-store:/var/lib/civikitchen/snapshots" "$work/tmp/ck-seed-override.yml" \
  || fail "the seed override does not mount the store"
[ "$(stat -c %a "$work/tmp/ck-seed-store" 2>/dev/null || stat -f %Lp "$work/tmp/ck-seed-store")" = 777 ] \
  || fail "the seed store is not writable by every user"

# No overrides and no seed store: the CI file alone. A vendor image applies to the install only.
run CK_OVERRIDES= CK_SEED_STORE=false CK_VENDOR_IMAGE=new:img
[ "$(cat "$work/output")" = "compose_file=$p/.docker/ci.yml" ] || fail "bare stack-up output: $(cat "$work/output")"
images=$(sed 's/.*image=\([^ ]*\) .*/\1/' "$work/calls.log" | paste -sd, -)
[ "$images" = "run:img,new:img,run:img" ] || fail "images per call: $images"

# --- stack-down: logs only when asked, teardown always, by project label -----
value "$actions/stack-down/action.yml" runs steps 'Tear down the stack' run > "$work/step.sh"
run
grep -q 'label=com.docker.compose.project=ck-1-x' "$work/calls.log" || fail "teardown does not filter by project: $(cat "$work/calls.log")"
[ "$(value "$actions/stack-down/action.yml" runs steps 'Stack logs' if)" = "\${{ inputs.logs == 'true' }}" ] \
  || fail "the logs step is not gated on the logs input"
[ "$(value "$actions/stack-down/action.yml" runs steps 'Tear down the stack' if)" = '${{ always() }}' ] \
  || fail "the teardown is skipped when the log step fails"

# --- every stack job: stack-down under always(), logs on failure --------------
jobs=$(value "$workflow" jobs | jq -r 'to_entries[] | select([.value.steps[]?.uses // "" | endswith("/actions/stack-up")] | any) | .key')
[ "$(wc -l <<<"$jobs")" -ge 7 ] || fail "fewer stack-up jobs than expected: $jobs"
for job in $jobs; do
  down=$(value "$workflow" jobs "$job" steps | jq -c '[.[] | select((.uses // "") | endswith("/actions/stack-down"))]')
  [ "$(jq length <<<"$down")" = 1 ] || fail "job $job has no single stack-down step"
  jq -e '.[0].if | contains("always()")' <<<"$down" > /dev/null || fail "job $job tears down without always()"
  [ "$(jq -r '.[0].with.logs' <<<"$down")" = "\${{ job.status == 'failure' }}" ] || fail "job $job: stack-down logs is $(jq -r '.[0].with.logs' <<<"$down")"
  checkout=$(value "$workflow" jobs "$job" steps | jq -c '[.[] | select(.with.path? == ".civikitchen-siblings/ci")]')
  [ "$(jq -r '.[0].if // ""' <<<"$checkout")" = "" ] || fail "job $job checks civikitchen out conditionally"
done

echo "stack actions suite OK"
