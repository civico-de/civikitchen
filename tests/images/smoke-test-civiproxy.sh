#!/bin/bash
# Smoke test for the CiviProxy image: refuses to start without config.php, and
# with the mounted fixture config relays an APIv4 call over plain http to a stub.
#
# Usage: bash tests/images/smoke-test-civiproxy.sh <image>
set -euo pipefail
. "$(dirname "$0")/lib.sh"

IMAGE="${1:?usage: smoke-test-civiproxy.sh <image>}"
FIXTURE="$(cd "$(dirname "$0")/fixtures/civiproxy" && pwd)"
SLUG="cptest-$(echo "${IMAGE}" | cksum | cut -d' ' -f1)"
NET="${SLUG}-net"
PROXY="${SLUG}-proxy"
TARGET="${SLUG}-target"

cleanup() {
    docker rm -f "${PROXY}" "${TARGET}" "${SLUG}-bare" >/dev/null 2>&1 || true
    docker network rm "${NET}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "==> smoke-test (civiproxy) ${IMAGE}"
docker run -d --name "${SLUG}-bare" "${IMAGE}" >/dev/null
for _ in $(seq 20); do
    [ "$(docker inspect -f '{{.State.Status}}' "${SLUG}-bare")" != exited ] || break
    sleep 1
done
bare_rc=$(docker inspect -f '{{if eq .State.Status "exited"}}{{.State.ExitCode}}{{else}}running{{end}}' "${SLUG}-bare")
named=$(docker logs "${SLUG}-bare" 2>&1 | grep -c 'no /var/www/html/config.php' || true)
check "exits non-zero without config.php (exit ${bare_rc})" "[ '${bare_rc}' != running ] && [ '${bare_rc}' != 0 ]"
check "names the missing config.php" "[ '${named}' -gt 0 ]"

docker network create "${NET}" >/dev/null
docker run -d --name "${TARGET}" --network "${NET}" --network-alias target \
    -v "${FIXTURE}/target.php:/stub/target.php:ro" \
    --entrypoint php "${IMAGE}" -S 0.0.0.0:8000 /stub/target.php >/dev/null
docker run -d --name "${PROXY}" --network "${NET}" \
    -v "${FIXTURE}/config.php:/var/www/html/config.php:ro" "${IMAGE}" >/dev/null
wait_healthy "${PROXY}" 60 || exit 1

page=$(docker exec "${PROXY}" curl -s http://localhost/ | grep -c 'CiviProxy Version' || true)
check "/ serves the CiviProxy page" "[ '${page}' -gt 0 ]"
check "the bundled target.pem is removed" "! docker exec '${PROXY}' test -e /var/www/html/target.pem"

# The rewrite to rest4.php, HTTPS=on over plain http, the key mapping and the relay.
relayed=$(docker exec "${PROXY}" curl -s -G http://localhost/civicrm/ajax/api4/Contact/get \
    --data-urlencode api_key=proxy-key --data-urlencode 'params={"limit":1}' || true)
echo "    relayed: ${relayed}"
path=$(jq -r .path <<<"${relayed}" 2>/dev/null || true)
auth=$(jq -r .auth <<<"${relayed}" 2>/dev/null || true)
params=$(jq -c .params <<<"${relayed}" 2>/dev/null || true)
check "the APIv4 call reaches the target path" "[ '${path}' = /civicrm/ajax/api4/Contact/get ]"
check "the mapped API key reaches the target" "[ '${auth}' = 'Bearer target-key' ]"
check "the whitelisted parameter reaches the target" "[ '${params}' = '{\"limit\":1}' ]"

verdict "civiproxy ${IMAGE}"
