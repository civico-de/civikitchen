#!/bin/bash
# Boots a Standalone dev image with a MariaDB sidecar and the given profiles,
# then runs their browser suites (tests/e2e/profiles) against it.
# CK_CHECKOUT_PROFILES=1 mounts this checkout's docker/profiles and schema.
#
# Usage:
#   bash tests/images/e2e-profiles-standalone.sh <image> <profile[,profile...]>
#   bash tests/images/e2e-profiles-standalone.sh ghcr.io/civico-de/civikitchen:standalone-beta common,dach
set -euo pipefail
. "$(dirname "$0")/lib.sh"

IMAGE="${1:?usage: e2e-profiles-standalone.sh <image> <profile[,profile...]>}"
PROFILE="${2:?usage: e2e-profiles-standalone.sh <image> <profile[,profile...]>}"
DATABASE_IMAGE="${CK_DATABASE_IMAGE:-mariadb:11.4}"

RAW="$(echo "${IMAGE}-${PROFILE}" | tr -c 'a-z0-9' '-')"
SLUG="pe2e-$(echo "${RAW}" | cut -c1-32)$(echo "${RAW}" | cksum | cut -d' ' -f1)"
NET="${SLUG}-net"
DB="${SLUG}-db"
APP="${SLUG}-app"

extra=()
if [ "${CK_CHECKOUT_PROFILES:-0}" = 1 ]; then
    root="$(cd "$(dirname "$0")/../.." && pwd)"
    extra+=(-v "${root}/docker/profiles:/opt/civikitchen/docker/profiles:ro"
        -v "${root}/packages/civicrm-profile-schema:/opt/civikitchen/packages/civicrm-profile-schema:ro")
    echo "==> docker/profiles and the profile schema mounted from the checkout"
fi

cleanup() {
    docker rm -fv "${APP}" "${DB}" >/dev/null 2>&1 || true
    docker network rm "${NET}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "==> profile e2e (standalone) ${IMAGE} profile=${PROFILE}"
docker network create "${NET}" >/dev/null
docker run -d --name "${DB}" --network "${NET}" \
    -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=civicrm \
    -e MYSQL_USER=civicrm -e MYSQL_PASSWORD=civicrm \
    "${DATABASE_IMAGE}" >/dev/null
docker run -d --name "${APP}" --network "${NET}" \
    -e CIVICRM_AUTO_INSTALL=1 \
    -e CIVICRM_DB_HOST="${DB}" \
    -e CIVIKITCHEN_SITE_URL=http://localhost \
    -e CIVIKITCHEN_DEMO_USER=admin \
    -e CIVIKITCHEN_PROFILE="${PROFILE}" \
    "${extra[@]}" \
    "${IMAGE}" >/dev/null

echo "==> waiting for healthy (install + profile apply)..."
wait_healthy "${APP}" 1200 || exit 1
docker exec -u www-data "${APP}" cv api4 Domain.get +s version 2>/dev/null | tr -d '[:space:]'
echo

# The browser shares the app's network namespace, so http://localhost is the site.
if (cd "$(dirname "$0")/../.." && CIVICRM_BASE_URL=http://localhost CIVIKITCHEN_E2E_CONTAINER="${APP}" \
    scaffold/ck playwright --network "container:${APP}" -- playwright test tests/e2e/profiles); then
    echo "  ✓ profile e2e suite passed"
else
    echo "  ✗ profile e2e suite failed"; fail=1
fi

verdict "${IMAGE} ${PROFILE}"
