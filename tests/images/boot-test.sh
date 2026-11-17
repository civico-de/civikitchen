#!/bin/bash
# Boot test for the buildkit-backed CMS dev images: the real first boot against
# an external MariaDB, including the repoint of the build-time DB host (127.0.0.1).
#
# Usage:
#   bash tests/images/boot-test.sh <image> <site_type>
#   bash tests/images/boot-test.sh civikitchen:drupal10  drupal10-demo
#   bash tests/images/boot-test.sh civikitchen:drupal11  drupal11-demo
#   bash tests/images/boot-test.sh civikitchen:wordpress wp-demo
#   bash tests/images/boot-test.sh civikitchen:joomla    joomla-demo
#   bash tests/images/boot-test.sh civikitchen:backdrop  backdrop-demo
set -euo pipefail
. "$(dirname "$0")/lib.sh"

IMAGE="${1:?usage: boot-test.sh <image> <site_type>}"
SITE_TYPE="${2:?usage: boot-test.sh <image> <site_type>}"

# Unique per run; the DB name is a DNS label (<63 chars) and CI tags carry a
# 40-char sha, hence prefix plus checksum.
RAW="$(echo "${IMAGE}_${SITE_TYPE}" | tr -c 'a-z0-9' '-')"
SLUG="boottest-$(echo "${RAW}" | cut -c1-32)$(echo "${RAW}" | cksum | cut -d' ' -f1)"
NET="${SLUG}-net"
DB="${SLUG}-db"
APP="${SLUG}-app"
HEALTH_TIMEOUT=600   # seconds; fast reinstall path is ~60s, allow slack

cleanup() {
    docker rm -fv "${APP}" "${DB}" >/dev/null 2>&1 || true
    docker network rm "${NET}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "==> boot-test ${IMAGE} (${SITE_TYPE})"
docker network create "${NET}" >/dev/null
docker run -d --name "${DB}" --network "${NET}" \
    -e MYSQL_ROOT_PASSWORD=root mariadb:11.4 >/dev/null
docker run -d --name "${APP}" --network "${NET}" \
    -e CIVICRM_DB_HOST="${DB}" \
    -e CIVICRM_DB_ROOT_PASSWORD=root \
    -e CIVIKITCHEN_SITE_URL=http://localhost \
    -e CIVICRM_SITE_TYPE="${SITE_TYPE}" \
    -v "$(cd "$(dirname "$0")/fixtures/init.d" && pwd):/civikitchen-init.d:ro" \
    "${IMAGE}" >/dev/null

echo "==> waiting for healthy (first-boot reinstall + provisioning)..."
wait_healthy "${APP}" "${HEALTH_TIMEOUT}" || exit 1


# 1) HTTP: the CMS home page serves 200 (checked inside the container, no host port).
code=$(docker exec "${APP}" curl -s -o /dev/null -w '%{http_code}' -L http://localhost/ 2>/dev/null || echo 000)
check "home page serves HTTP 200 (got ${code})" "[ '${code}' = '200' ]"

# 2) CiviCRM is live against the external db; on Joomla cv boots through the
# civicrm.settings.d shim joomla-cli-bootstrap.php.
ver=$(docker exec -u buildkit -w /home/buildkit/buildkit/build/site/web "${APP}" \
    bash -lc 'export PATH=/home/buildkit/buildkit/bin:$PATH; cv api4 Domain.get +s version 2>/dev/null' \
    | tr -d '[:space:]' || true)
check "CiviCRM responds via cv (Domain version: ${ver:-none})" "echo '${ver}' | grep -q 'version'"

# 2b) No calls to civicrm.org from the admin status check.
offline=$(docker exec -u buildkit -w /home/buildkit/buildkit/build/site/web "${APP}" \
    bash -lc 'export PATH=/home/buildkit/buildkit/bin:$PATH; cv api4 Job.get +w api_action=version_check +s is_active --out=json-strict 2>/dev/null; cv vget ext_repo_url --out=json-strict 2>/dev/null' \
    | tr -d '[:space:]' || true)
check "version_check job inactive, ext_repo_url false (got ${offline:-none})" \
    "echo '${offline}' | grep -q '\"is_active\":false' && echo '${offline}' | grep -q '\"value\":false'"

# 2c) A *.sh init hook runs as buildkit; the tool it installs into
# ~/.local/bin is on PATH for later hooks and for docker exec.
check "init.d: a later hook finds the installed tool" "docker logs '${APP}' 2>&1 | grep -F 'init.d hook found: ckinittool-ok' >/dev/null"
tool=$(docker exec "${APP}" ckinittool 2>&1 || true)
check "init.d: docker exec finds the installed tool (got ${tool:-absent})" "[ '${tool}' = 'ckinittool-ok' ]"

# 3) Joomla only: joomla-finish.sh registers the com_civicrm component, which
# cv's shim does not need; unregistered, Joomla 404s the route.
# Gated on SITE_TYPE, not a cv-derived UF, so it runs even when cv cannot boot.
if [[ "${SITE_TYPE}" == joomla* ]]; then
    jcode=$(docker exec "${APP}" curl -s -o /dev/null -w '%{http_code}' \
        'http://localhost/index.php?option=com_civicrm' 2>/dev/null || echo 000)
    check "Joomla com_civicrm route is registered (HTTP ${jcode}, not 404)" \
        "[ '${jcode}' != '404' ] && [ '${jcode}' != '000' ]"
fi

# 4) Backdrop only: /civicrm/<path> must reach the front controller. Backdrop keeps
# civicrm.settings.php in the web root, so Apache MultiViews would answer it with a 404.
if [[ "${SITE_TYPE}" == backdrop* ]]; then
    bcode=$(docker exec "${APP}" curl -s -o /dev/null -w '%{http_code}' \
        'http://localhost/civicrm/dashboard' 2>/dev/null || echo 000)
    check "Backdrop civicrm/ route reaches the CMS (HTTP ${bcode}, not 404)" \
        "[ '${bcode}' != '404' ] && [ '${bcode}' != '000' ]"
fi

verdict "${IMAGE}"
