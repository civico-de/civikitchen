#!/usr/bin/env bash
# ckup writes free host ports into .docker/.env once (existing values are
# kept), installs vendor/ as the calling user, then runs docker compose up -d.
# Fake docker records the calls; the port probe reads a list of "taken" ports.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/repo/.docker/sub"
fail() { echo "FAIL: $*" >&2; exit 1; }

: > "$work/repo/.docker/docker-compose.yml"
cat > "$work/bin/docker" <<'FAKE'
#!/usr/bin/env bash
printf '%s|%s\n' "$PWD" "$*" >> "$DOCKER_LOG"
printf '%s|%s\n' "${CIVIKITCHEN_IMAGE:-}" "$*" >> "$IMAGE_LOG"
[[ "${DOCKER_FAIL_RUN:-}" != 1 || "$2" != run ]]
FAKE
chmod +x "$work/bin/docker"
export PATH="$work/bin:$PATH" DOCKER_LOG="$work/docker.log" IMAGE_LOG="$work/image.log"
unset CIVIKITCHEN_IMAGE
# The real ckconform of this checkout reads civikitchen.yaml, on the host.
cat > "$work/bin/policy" <<FAKE
#!/usr/bin/env bash
exec php "$root/toolbelt/bin/ckconform" --policy civicrm
FAKE
chmod +x "$work/bin/policy"
export CK_POLICY_READ="$work/bin/policy"
# The port probe is a script: ports listed in TAKEN_PORTS are "taken".
cat > "$work/bin/probe" <<'FAKE'
#!/usr/bin/env bash
[[ ",${TAKEN_PORTS}," != *",$1,"* ]]
FAKE
chmod +x "$work/bin/probe"
export CK_PORT_CHECK="$work/bin/probe" TAKEN_PORTS="8080,8081"

# From a subdirectory: the repo is found upwards, ports skip the taken ones and
# each other, compose runs in .docker with the arguments passed through.
(cd "$work/repo/.docker/sub" && "$root/scaffold/ckup" --build >/dev/null)
[[ "$(cat "$work/repo/.docker/.env")" == $'CK_HTTP_PORT=8082\nCK_MAILDEV_PORT=1080\nCK_SMTP_PORT=1025\nCK_PMA_PORT=8083' ]] \
  || fail "unexpected .env: $(cat "$work/repo/.docker/.env")"
expected="$work/repo/.docker|compose run --rm --no-deps -T -u $(id -u):$(id -g) -e HOME=/tmp --entrypoint sh app -c exec ck internal composer-install /var/www/html/ext/*/
$work/repo/.docker|compose up -d --build"
[[ "$(cat "$DOCKER_LOG")" == "$expected" ]] || fail "unexpected docker calls: $(cat "$DOCKER_LOG")"

# A failing composer install stops before the stack starts.
: > "$DOCKER_LOG"
if (cd "$work/repo" && DOCKER_FAIL_RUN=1 "$root/scaffold/ckup" >/dev/null 2>&1); then fail "a failed composer install must fail ckup"; fi
! grep -q 'compose up' "$DOCKER_LOG" || fail "the stack must not start after a failed composer install"

# A second run keeps the file as it is, even though its ports are now "taken".
export TAKEN_PORTS="8082,1080,1025,8083"
(cd "$work/repo" && "$root/scaffold/ckup" >/dev/null)
[[ "$(grep -c . "$work/repo/.docker/.env")" == 4 ]] || fail ".env must not grow on rerun"

# A partial .env: the existing value is kept and not handed out again, even
# though nothing listens on it right now.
mkdir -p "$work/partial/.docker"; : > "$work/partial/.docker/docker-compose.yml"
printf 'CK_HTTP_PORT=8081\n' > "$work/partial/.docker/.env"
export TAKEN_PORTS=""
(cd "$work/partial" && "$root/scaffold/ckup" >/dev/null)
[[ "$(cat "$work/partial/.docker/.env")" == $'CK_HTTP_PORT=8081\nCK_MAILDEV_PORT=1080\nCK_SMTP_PORT=1025\nCK_PMA_PORT=8082' ]] \
  || fail "existing port reused: $(cat "$work/partial/.docker/.env")"

# Without policy.civicrm the compose file's default image stays: nothing exported.
grep -q '^|compose up' "$IMAGE_LOG" || fail "an image was exported without policy.civicrm: $(cat "$IMAGE_LOG")"

# policy.civicrm picks the image CI runs, for the composer install and the stack.
: > "$IMAGE_LOG"
printf 'version: 1\npolicy:\n  civicrm: "6.17"\n' > "$work/repo/civikitchen.yaml"
out="$(cd "$work/repo" && "$root/scaffold/ckup")"
[[ "$(cut -d'|' -f1 "$IMAGE_LOG" | sort -u)" == ghcr.io/civico-de/civikitchen:standalone-6.17 ]] \
  || fail "policy.civicrm must set the image of every compose call: $(cat "$IMAGE_LOG")"
grep -q 'image ghcr.io/civico-de/civikitchen:standalone-6.17 (policy.civicrm 6.17)' <<<"$out" || fail "no image line: $out"

# An image set in the environment or in .docker/.env wins over the policy.
: > "$IMAGE_LOG"
(cd "$work/repo" && CIVIKITCHEN_IMAGE=ghcr.io/acme/img:1 "$root/scaffold/ckup" >/dev/null)
[[ "$(cut -d'|' -f1 "$IMAGE_LOG" | sort -u)" == ghcr.io/acme/img:1 ]] || fail "environment image overridden: $(cat "$IMAGE_LOG")"
: > "$IMAGE_LOG"
printf 'CIVIKITCHEN_IMAGE=ghcr.io/acme/img:2\n' >> "$work/repo/.docker/.env"
(cd "$work/repo" && "$root/scaffold/ckup" >/dev/null)
grep -q '^|compose up' "$IMAGE_LOG" || fail ".env image overridden by the policy: $(cat "$IMAGE_LOG")"
sed -i.bak '/^CIVIKITCHEN_IMAGE=/d' "$work/repo/.docker/.env"

# A policy.civicrm that is no minor stops before compose runs.
: > "$DOCKER_LOG"
printf 'version: 1\npolicy:\n  civicrm: latest\n' > "$work/repo/civikitchen.yaml"
if (cd "$work/repo" && "$root/scaffold/ckup" >/dev/null 2>&1); then fail "an invalid policy.civicrm must fail ckup"; fi
[[ ! -s "$DOCKER_LOG" ]] || fail "compose must not run with an invalid policy.civicrm"
/bin/rm "$work/repo/civikitchen.yaml"

# Outside a repo: a clear error, no compose call.
: > "$DOCKER_LOG"
if (cd "$work" && "$root/scaffold/ckup" 2>/dev/null); then fail "no compose file must fail"; fi
[[ ! -s "$DOCKER_LOG" ]] || fail "compose must not run without a compose file"

echo "ckup: ok"
