#!/usr/bin/env bash
# The browser job's network: the override step, run as written, publishes no
# port and points CIVIKITCHEN_SITE_URL and CIVICRM_BASE_URL at http://localhost,
# merged over the template CI compose or the CMS example, which publishes 8080
# itself. The suite runs through ck playwright in the app container's network
# namespace. Needs jq and the docker CLI, not a daemon.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow="$root/.github/workflows/extension-ci.yml"
template="$root/scaffold/template/extension/.docker/docker-compose.ci.yml"
cms="$root/examples/ci/docker-compose.ci-cms.yml"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
value() { php "$root/tests/parity/workflow-value.php" "$workflow" "$@"; }
step() { php "$root/tests/parity/workflow-step.php" "$workflow" playwright "$1"; }

# --- the override step as written, with the runner's temp dir and GITHUB_ENV ---
step 'Compose override — no published port, create the demo user' > "$work/override.sh"
: > "$work/github_env"
RUNNER_TEMP="$work" GITHUB_ENV="$work/github_env" \
  bash --noprofile --norc -eo pipefail "$work/override.sh" || fail "the override step failed"
exported() { sed -n "s/^$1=//p" "$work/github_env"; }
[ "$(exported CIVICRM_BASE_URL)" = http://localhost ] || fail "CIVICRM_BASE_URL: '$(exported CIVICRM_BASE_URL)'"
# tests/e2e/lib.sh falls back to the published port, and there is none.
[ "$(exported BASE_URL)" = http://localhost ] || fail "BASE_URL: '$(exported BASE_URL)'"
override=$(exported CK_PW_OVERRIDE)
[ "$override" = "$work/civikitchen-playwright-override.yml" ] || fail "CK_PW_OVERRIDE: '$override'"

# --- merged over a CI compose, as `up` sees it --------------------------------
for base in "$template" "$cms"; do
  json=$(DOCKER_HOST=unix:///nonexistent docker compose -f "$base" -f "$override" config --format json) \
    || fail "compose rejects $base plus the override"
  [ "$(jq -c '.services.app.ports // []' <<<"$json")" = "[]" ] || fail "over $base, app publishes $(jq -c '.services.app.ports' <<<"$json")"
  site_url=$(jq -r '.services.app.environment.CIVIKITCHEN_SITE_URL' <<<"$json")
  [ "$site_url" = http://localhost ] || fail "over $base, CIVIKITCHEN_SITE_URL is '$site_url', the suite opens http://localhost"
  [ "$(jq -r '.services.app.environment.CIVIKITCHEN_DEMO_USER' <<<"$json")" = admin ] || fail "over $base, no demo user"
done

# --- the wiring around the step ------------------------------------------------
steps=$(value jobs playwright steps)
# A step's own env outranks GITHUB_ENV and would send the suite elsewhere.
own_url=$(jq -c '[.[] | select((.env // {}) | has("CIVICRM_BASE_URL") or has("BASE_URL")) | .name]' <<<"$steps")
[ "$own_url" = "[]" ] || fail "steps that set their own CIVICRM_BASE_URL: $own_url"
# ck playwright is checked out on every run, with the Dockerfile it builds from.
ck=$(value jobs playwright env CK)
[ "$ck" = '${{ github.workspace }}/.civikitchen-siblings/ci/toolbelt/bin/ck' ] || fail "CK: '$ck'"
checkout=$(jq -c '[.[] | select(.with.path? == ".civikitchen-siblings/ci")]' <<<"$steps")
[ "$(jq 'length' <<<"$checkout")" = 1 ] || fail "no single civikitchen checkout at .civikitchen-siblings/ci"
[ "$(jq -r '.[0].if // ""' <<<"$checkout")" = "" ] || fail "the civikitchen checkout is conditional: $(jq -r '.[0].if' <<<"$checkout")"
for path in toolbelt/bin toolbelt/lib toolbelt/playwright; do
  jq -e --arg path "$path" '.[0].with["sparse-checkout"] | split("\n") | index($path)' <<<"$checkout" > /dev/null \
    || fail "the civikitchen checkout lacks $path"
done

# Fakes that log their arguments: docker answers `compose ps -q app`, php stands in for ck.
mkdir "$work/bin"
cat > "$work/bin/docker" <<'SH'
#!/bin/sh
printf 'docker %s\n' "$*" >> "$CALL_LOG"
case "$*" in *' ps -q app') echo f00dcafe ;; esac
SH
cat > "$work/bin/php" <<'SH'
#!/bin/sh
printf 'php %s\n' "$*" >> "$CALL_LOG"
SH
chmod +x "$work/bin/docker" "$work/bin/php"
run_step() {
  : > "$work/calls.log"
  (cd "$work/project" && PATH="$work/bin:$PATH" CALL_LOG="$work/calls.log" CK=/ck CK_COMPOSE_FILE=compose.ci.yml \
    CK_USE_BUN="$2" bash --noprofile --norc -eo pipefail "$work/$1") || fail "$1 failed (bun: $2)"
  cat "$work/calls.log"
}

# The suite runs in the app container's netns, with the repo's package manager.
mkdir "$work/project"
echo '{"scripts": {"test:e2e": "playwright test"}}' > "$work/project/package.json"
step 'Playwright (npm run test:e2e / bun run test:e2e)' > "$work/suite.sh"
for bun in false true; do
  manager=npm
  if [ "$bun" = true ]; then manager=bun; fi
  calls=$(run_step suite.sh "$bun")
  expected="docker compose -f compose.ci.yml ps -q app"$'\n'"php /ck playwright --network container:f00dcafe $manager run test:e2e"
  [ "$calls" = "$expected" ] || fail "with bun $bun, the suite step runs:"$'\n'"$calls"
done
step 'Build the declared release output (bun run build)' > "$work/build.sh"
[ "$(run_step build.sh true)" = "php /ck playwright bun run build" ] || fail "the build step runs: $(cat "$work/calls.log")"

# The app container serves the checkout: the image's tree lands there on every run, before the build.
copy='Copy the JS dependencies into the checkout'
step "$copy" > "$work/copy.sh"
[ "$(run_step copy.sh false)" = "php /ck playwright cp -R /ck/node_modules ." ] || fail "the copy step runs: $(cat "$work/calls.log")"
[ "$(jq -r --arg name "$copy" '.[] | select(.name == $name) | .if // ""' <<<"$steps")" = "" ] || fail "the copy step is conditional"
order=$(jq -c --arg name "$copy" '[.[].name] | [index($name), index("Build the declared release output (bun run build)")]' <<<"$steps")
jq -e '.[0] < .[1]' <<<"$order" > /dev/null || fail "the copy step does not run before the build: $order"
# ck playwright masks an existing node_modules with a tmpfs: the step refuses instead of copying into it.
mkdir "$work/project/node_modules"
if (cd "$work/project" && PATH="$work/bin:$PATH" CALL_LOG="$work/calls.log" CK=/ck bash --noprofile --norc -eo pipefail "$work/copy.sh" 2> /dev/null); then
  fail "the copy step ran over an existing node_modules"
fi
rmdir "$work/project/node_modules"

# A package.json without test:e2e fails the step before anything starts.
echo '{"scripts": {}}' > "$work/project/package.json"
if (cd "$work/project" && PATH="$work/bin:$PATH" CALL_LOG="$work/calls.log" CK=/ck CK_COMPOSE_FILE=compose.ci.yml CK_USE_BUN=false \
  bash --noprofile --norc -eo pipefail "$work/suite.sh" 2> /dev/null); then
  fail "the suite step passed without a test:e2e script"
fi

# Every compose call of the start step carries the override, a sibling one after it,
# and the seed-store mount last; vendor/ is installed as the runner user before the stack starts.
step 'Start CiviCRM stack' > "$work/start.sh"
start_calls() {
  : > "$work/calls.log"
  PATH="$work/bin:$PATH" CALL_LOG="$work/calls.log" CK_COMPOSE_FILE=compose.ci.yml RUNNER_TEMP="$work" \
    CK_PW_OVERRIDE="$override" CK_SIBLING_OVERRIDE="$1" \
    bash --noprofile --norc -eo pipefail "$work/start.sh" || fail "the start step failed"
  cat "$work/calls.log"
}
for sibling in '' sibling.yml; do
  files="-f compose.ci.yml -f $override${sibling:+ -f $sibling} -f $work/ck-seed-override.yml"
  calls=$(start_calls "$sibling")
  expected="docker compose $files down -v --remove-orphans"$'\n'"docker compose $files run --rm --no-deps -T -u $(id -u):$(id -g) -e HOME=/tmp --entrypoint sh app -c exec ck internal composer-install /var/www/html/ext/*/"$'\n'"docker compose $files up -d --wait --quiet-pull"
  [ "$calls" = "$expected" ] || fail "with sibling override '$sibling', the start step runs:"$'\n'"$calls"
done

# The seed override mounts the cached snapshot store, writable by the image's web user.
grep -qx "      - $work/ck-seed-store:/var/lib/civikitchen/snapshots" "$work/ck-seed-override.yml" \
  || fail "the seed override does not mount $work/ck-seed-store: $(cat "$work/ck-seed-override.yml")"
[ "$(stat -c %a "$work/ck-seed-store" 2>/dev/null || stat -f %Lp "$work/ck-seed-store")" = 777 ] \
  || fail "the seed store is not writable by every user"

echo "playwright network suite OK"
