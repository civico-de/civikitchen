#!/usr/bin/env bash
# The browser job's port: the override step, run as written, publishes a port
# the kernel hands out as free (another one while that is held) and points
# CIVIKITCHEN_SITE_URL and CIVICRM_BASE_URL at it. Merged over the template CI
# compose or the CMS example, which publishes 8080 itself, that port is the only
# one and the URL wins; the start step passes the override to every compose
# call. Needs node and the docker CLI, not a daemon.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow="$root/.github/workflows/extension-ci.yml"
template="$root/scaffold/template/extension/.docker/docker-compose.ci.yml"
cms="$root/examples/ci/docker-compose.ci-cms.yml"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
value() { php "$root/tests/parity/workflow-value.php" "$workflow" "$@"; }

override_step='Compose override — publish a free port, create the demo user'
php "$root/tests/parity/workflow-step.php" "$workflow" playwright "$override_step" > "$work/step.sh"

# --- the step as written, with the runner's temp dir and GITHUB_ENV ----------
: > "$work/github_env"
RUNNER_TEMP="$work" GITHUB_ENV="$work/github_env" bash --noprofile --norc -eo pipefail "$work/step.sh" \
  || fail "the override step failed"
base_url=$(sed -n 's/^CIVICRM_BASE_URL=//p' "$work/github_env")
[[ "$base_url" =~ ^http://localhost:([0-9]+)$ ]] || fail "CIVICRM_BASE_URL is not http://localhost:<port>: '$base_url'"
port="${BASH_REMATCH[1]}"
(( port >= 1024 && port <= 65535 )) || fail "port $port is outside 1024-65535"
override=$(sed -n 's/^CK_PW_OVERRIDE=//p' "$work/github_env")
[ "$override" = "$work/civikitchen-playwright-override.yml" ] || fail "CK_PW_OVERRIDE: '$override'"

# Released again, so compose can bind it.
node -e 'const s = require("node:net").createServer().listen(Number(process.argv[1]), () => s.close());' "$port" \
  || fail "port $port is still held after the step"

# Asked again while that port is held, the step picks another one.
mkdir "$work/held"
: > "$work/held/github_env"
node -e '
  const [port, ...command] = process.argv.slice(1);
  const held = require("node:net").createServer().listen(Number(port), () => {
    const run = require("node:child_process").spawnSync(command[0], command.slice(1), { stdio: "inherit" });
    held.close();
    process.exitCode = run.status ?? 1;
  });' "$port" env RUNNER_TEMP="$work/held" GITHUB_ENV="$work/held/github_env" \
  bash --noprofile --norc -eo pipefail "$work/step.sh" \
  || fail "the override step failed while port $port was held"
held_url=$(sed -n 's/^CIVICRM_BASE_URL=//p' "$work/held/github_env")
[[ "$held_url" =~ ^http://localhost:[0-9]+$ && "$held_url" != "$base_url" ]] \
  || fail "with port $port held, the step published '$held_url'"

# --- merged over a CI compose, as `up` sees it --------------------------------
for base in "$template" "$cms"; do
  json=$(DOCKER_HOST=unix:///nonexistent docker compose -f "$base" -f "$override" config --format json) \
    || fail "compose rejects $base plus the override"
  published=$(jq -c '[.services.app.ports[] | {published, target}]' <<<"$json")
  [ "$published" = "[{\"published\":\"$port\",\"target\":80}]" ] || fail "over $base, app publishes $published, expected only $port"
  site_url=$(jq -r '.services.app.environment.CIVIKITCHEN_SITE_URL' <<<"$json")
  [ "$site_url" = "$base_url" ] || fail "over $base, CIVIKITCHEN_SITE_URL is '$site_url', the suite opens '$base_url'"
  [ "$(jq -r '.services.app.environment.CIVIKITCHEN_DEMO_USER' <<<"$json")" = admin ] || fail "over $base, no demo user"
done

# --- the wiring around the step ------------------------------------------------
# node must be on PATH before the step runs.
steps=$(value jobs playwright steps)
node_at=$(jq '[.[].name] | index("Set up Node")' <<<"$steps")
override_at=$(jq --arg name "$override_step" '[.[].name] | index($name)' <<<"$steps")
[[ "$node_at" =~ ^[0-9]+$ && "$override_at" =~ ^[0-9]+$ ]] || fail "steps not found (node: $node_at, override: $override_at)"
(( node_at < override_at )) || fail "the override step runs before Node is set up"
# A step's own env outranks GITHUB_ENV and would send the suite to another port.
own_url=$(jq -c '[.[] | select((.env // {}) | has("CIVICRM_BASE_URL")) | .name]' <<<"$steps")
[ "$own_url" = "[]" ] || fail "steps that set their own CIVICRM_BASE_URL: $own_url"

# Every compose call of the start step carries the override, a sibling one after it,
# and the seed-store mount last; vendor/ is installed as the runner user before the stack starts.
mkdir "$work/bin"
cat > "$work/bin/docker" <<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$DOCKER_LOG"
SH
chmod +x "$work/bin/docker"
php "$root/tests/parity/workflow-step.php" "$workflow" playwright 'Start CiviCRM stack' > "$work/start.sh"
start_calls() {
  : > "$work/docker.log"
  PATH="$work/bin:$PATH" DOCKER_LOG="$work/docker.log" CK_COMPOSE_FILE=compose.ci.yml RUNNER_TEMP="$work" \
    CK_PW_OVERRIDE="$override" CK_SIBLING_OVERRIDE="$1" \
    bash --noprofile --norc -eo pipefail "$work/start.sh" || fail "the start step failed"
  cat "$work/docker.log"
}
for sibling in '' sibling.yml; do
  files="-f compose.ci.yml -f $override${sibling:+ -f $sibling} -f $work/ck-seed-override.yml"
  calls=$(start_calls "$sibling")
  expected="compose $files down -v --remove-orphans"$'\n'"compose $files run --rm --no-deps -T -u $(id -u):$(id -g) -e HOME=/tmp --entrypoint sh app -c exec ck internal composer-install /var/www/html/ext/*/"$'\n'"compose $files up -d --wait --quiet-pull"
  [ "$calls" = "$expected" ] || fail "with sibling override '$sibling', the start step runs:"$'\n'"$calls"
done

# The seed override mounts the cached snapshot store, writable by the image's web user.
grep -qx "      - $work/ck-seed-store:/var/lib/civikitchen/snapshots" "$work/ck-seed-override.yml" \
  || fail "the seed override does not mount $work/ck-seed-store: $(cat "$work/ck-seed-override.yml")"
[ "$(stat -c %a "$work/ck-seed-store" 2>/dev/null || stat -f %Lp "$work/ck-seed-store")" = 777 ] \
  || fail "the seed store is not writable by every user"

echo "playwright port suite OK"
