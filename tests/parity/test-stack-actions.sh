#!/usr/bin/env bash
# The stack actions and the e2e job's network, run as written: stack-up merges
# its files in order and boots through a fake docker; stack-down tears down by
# project label; the e2e override publishes site and maildev on 127.0.0.1,
# merged over the template CI compose. Needs jq and the docker CLI, not a daemon.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
actions="$root/.github/actions"
workflow="$root/.github/workflows/extension-ci.yml"
template="$root/scaffold/template/extension/.docker/docker-compose.ci.yml"
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
[ "$(wc -l <<<"$jobs")" -ge 8 ] || fail "fewer stack-up jobs than expected: $jobs"
for job in $jobs; do
  down=$(value "$workflow" jobs "$job" steps | jq -c '[.[] | select((.uses // "") | endswith("/actions/stack-down"))]')
  [ "$(jq length <<<"$down")" = 1 ] || fail "job $job has no single stack-down step"
  jq -e '.[0].if | contains("always()")' <<<"$down" > /dev/null || fail "job $job tears down without always()"
  [ "$(jq -r '.[0].with.logs' <<<"$down")" = "\${{ job.status == 'failure' }}" ] || fail "job $job: stack-down logs is $(jq -r '.[0].with.logs' <<<"$down")"
  checkout=$(value "$workflow" jobs "$job" steps | jq -c '[.[] | select(.with.path? == ".civikitchen-siblings/ci")]')
  [ "$(jq -r '.[0].if // ""' <<<"$checkout")" = "" ] || fail "job $job checks civikitchen out conditionally"
done

# --- the e2e override, merged over the template CI compose --------------------
php "$root/tests/parity/workflow-step.php" "$workflow" e2e 'Compose override — maildev, site on a free localhost port' > "$work/step.sh"
: > "$work/github_env"
(env RUNNER_TEMP="$work/tmp" GITHUB_ENV="$work/github_env" bash --noprofile --norc -eo pipefail "$work/step.sh") \
  || fail "the e2e override step failed"
exported() { sed -n "s/^$1=//p" "$work/github_env"; }
base_url=$(exported BASE_URL)
[[ "$base_url" =~ ^http://localhost:[0-9]+$ ]] || fail "BASE_URL: '$base_url'"
json=$(DOCKER_HOST=unix:///nonexistent CIVIKITCHEN_IMAGE=x docker compose -f "$template" -f "$(exported CK_E2E_OVERRIDE)" config --format json) \
  || fail "compose rejects the template plus the e2e override"
ports() { jq -c --arg s "$1" '[.services[$s].ports[]? | {host_ip, published, target}]' <<<"$json"; }
[ "$(ports app)" = "[{\"host_ip\":\"127.0.0.1\",\"published\":\"${base_url##*:}\",\"target\":80}]" ] || fail "app publishes $(ports app)"
# maildev's host port is Docker's pick, so no probe can race for it.
[ "$(ports maildev)" = '[{"host_ip":"127.0.0.1","published":null,"target":1080}]' ] || fail "maildev publishes $(ports maildev)"
[ "$(jq -r '.services.app.environment.CIVIKITCHEN_SITE_URL' <<<"$json")" = "$base_url" ] || fail "CIVIKITCHEN_SITE_URL is not BASE_URL"
[ "$(jq -r '.services.app.environment.CIVIKITCHEN_SMTP_HOST' <<<"$json")" = maildev ] || fail "the site does not mail to maildev"

# --- the suite list: validated entries, evidence per suite directory, every suite run ---
mkdir -p "$work/ext/tests/e2e/a" "$work/ext/tests/e2e/b"
printf 'echo ran-a; exit 3\n' > "$work/ext/tests/e2e/a/run.sh"
printf 'echo ran-b\n' > "$work/ext/tests/e2e/b/run.sh"
php "$root/tests/parity/workflow-step.php" "$workflow" e2e 'Validate inputs' > "$work/validate.sh"
validate() {
  : > "$work/output"
  (cd "$work/ext" && env CK_EXT_KEY=demo CK_WORKDIR=sub CK_E2E="$1" CK_E2E_COMPOSE="${2-}" GITHUB_OUTPUT="$work/output" \
    bash --noprofile --norc -eo pipefail "$work/validate.sh") 2> /dev/null
}
validate $'tests/e2e/a/run.sh\n  tests/e2e/b/run.sh' || fail "the validation step refused two valid suites"
[ "$(cat "$work/output")" = $'evidence<<CK_EVIDENCE\nsub/tests/e2e/a/**/out/\nsub/tests/e2e/b/**/out/\nCK_EVIDENCE\ncompose<<CK_COMPOSE\nCK_COMPOSE' ] \
  || fail "evidence output: $(cat "$work/output")"
for bad in '../x.sh' '/etc/passwd' 'tests/e2e/missing.sh' 'tests/e2e/a/run.sh tests/$(id).sh'; do
  validate "$bad" && fail "the validation step accepted '$bad'"
done
# e2e_compose: repository files, passed on in order, under the same path rules.
mkdir -p "$work/ext/.docker"
touch "$work/ext/.docker/one.yml" "$work/ext/.docker/two.yml"
validate tests/e2e/a/run.sh $'.docker/two.yml\n .docker/one.yml' || fail "the validation step refused two compose files"
[ "$(sed -n '/^compose<<CK_COMPOSE$/,/^CK_COMPOSE$/p' "$work/output")" = $'compose<<CK_COMPOSE\n.docker/two.yml\n.docker/one.yml\nCK_COMPOSE' ] \
  || fail "compose output: $(cat "$work/output")"
for bad in '../x.yml' '/etc/hosts' '.docker/missing.yml' '.docker/one.yml $(id).yml'; do
  validate tests/e2e/a/run.sh "$bad" && fail "the validation step accepted e2e_compose '$bad'"
done
# Repository files go over the job's own override; siblings come last, as in every stack job.
[ "$(value "$workflow" jobs e2e steps 'Start CiviCRM stack' with overrides)" \
  = $'${{ env.CK_E2E_OVERRIDE }}\n${{ steps.suites.outputs.compose }}\n${{ steps.deps.outputs.sibling_override }}' ] \
  || fail "e2e stack-up overrides: $(value "$workflow" jobs e2e steps 'Start CiviCRM stack' with overrides)"
php "$root/tests/parity/workflow-step.php" "$workflow" e2e 'e2e suites' > "$work/suites.sh"
if out=$(cd "$work/ext" && CK_E2E='tests/e2e/a/run.sh tests/e2e/b/run.sh' bash --noprofile --norc "$work/suites.sh" 2>&1); then
  fail "the suites step passed with a failing suite"
fi
grep -q ran-b <<<"$out" || fail "a failing suite stopped the next one: $out"

# The suite reaches the stack through the boot's files, not the dev compose under .docker/.
[ "$(value "$workflow" jobs e2e steps 'e2e suites' env COMPOSE_FILE)" = '${{ steps.stack.outputs.compose_file }}' ] \
  || fail "the e2e suite step does not get the boot's COMPOSE_FILE"

echo "stack actions suite OK"
