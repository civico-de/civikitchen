#!/bin/bash
set -e

# civikitchen standalone entrypoint.
#
# 1. If XDEBUG_MODE is set, activate xdebug for this container.
# 2. If CIVICRM_AUTO_INSTALL=1 and CiviCRM is not yet installed, wait for the
#    database and run `cv core:install`.
# 3. Run the shared first-boot provisioning (docker/runtime/provision.sh).
# 4. Hand off to docker-php-entrypoint (the php-apache base's own).
#
# Why runtime install (not a build-time bake like the buildkit demo images)?
# The demo images bake an embedded MariaDB into the same container as CiviCRM,
# so the baked DB sits on localhost at both build and run time. This image
# instead points at an external MariaDB whose host/credentials are only known
# at runtime, so baking the DB would require regenerating civicrm.settings.php
# on first start anyway. The saving wasn't worth the extra build complexity.

# ---------------------------------------------------------------------------
# Xdebug toggle (shared with buildkit image).
. /usr/local/share/civikitchen/xdebug-toggle.sh

# ---------------------------------------------------------------------------
# Env-var convention: CIVIKITCHEN_* are this image's own behavior knobs.
# CIVICRM_* is reserved for the upstream image contract (CIVICRM_AUTO_INSTALL,
# CIVICRM_DB_*), for describing the CiviCRM target (CIVICRM_VERSION,
# CIVICRM_SITE_TYPE) and for CiviCRM's own variables (CIVICRM_UF, ...).

# ---------------------------------------------------------------------------
# Shared provisioning library. ck_as_web runs a command as the web user
# (www-data) against the standalone CiviCRM site: the image's own user, or a
# drop from root when a stack starts it with `user: root`. The CK_* parameters
# default to the standalone layout, so nothing else needs overriding here.
ck_as_web() {
    if [[ "$(id -u)" == 0 ]]; then runuser -u www-data -- "$@"; else "$@"; fi
}
. /usr/local/share/civikitchen/provision.sh
# Legacy CIVICRM_-spelled kitchen vars keep working with a deprecation warning.
_ck_legacy CIVIKITCHEN_COMPONENTS        CIVICRM_COMPONENTS
_ck_legacy CIVIKITCHEN_DEMO_USER         CIVICRM_DEMO_USER
_ck_legacy CIVIKITCHEN_DEMO_PASS         CIVICRM_DEMO_PASS
_ck_legacy CIVIKITCHEN_DEMO_EMAIL        CIVICRM_DEMO_EMAIL
_ck_legacy CIVIKITCHEN_SITE_URL          SITE_URL

# ---------------------------------------------------------------------------
# Extra OS packages (e.g. libreoffice-writer + unoconv for CiviOffice
# rendering) without baking them into the image. Comma- or space-separated.
# Installed once per container; restarts skip already-present packages.
# apt needs root, so the stack runs this service with `user: root`.
if [[ -n "${CIVIKITCHEN_EXTRA_PACKAGES}" ]]; then
    if [[ "$(id -u)" != 0 ]]; then
        echo "[civikitchen] ERROR: CIVIKITCHEN_EXTRA_PACKAGES needs the container to start as root (compose: user: root)" >&2
        exit 1
    fi
    _ck_missing=()
    for pkg in ${CIVIKITCHEN_EXTRA_PACKAGES//,/ }; do
        dpkg -s "${pkg}" >/dev/null 2>&1 || _ck_missing+=("${pkg}")
    done
    if [[ ${#_ck_missing[@]} -gt 0 ]]; then
        echo "[civikitchen] Installing extra packages: ${_ck_missing[*]}"
        apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${_ck_missing[@]}"
        rm -rf /var/lib/apt/lists/*
    fi
fi

# ---------------------------------------------------------------------------
ck_attach_scenario_extension
ck_warn_removed_auto_composer

# ---------------------------------------------------------------------------
# Auto-install.
export CIVICRM_DB_HOST="${CIVICRM_DB_HOST:-db}"
export CIVICRM_DB_PORT="${CIVICRM_DB_PORT:-3306}"
export CIVICRM_DB_NAME="${CIVICRM_DB_NAME:-civicrm}"
export CIVICRM_DB_USER="${CIVICRM_DB_USER:-civicrm}"
export CIVICRM_DB_PASSWORD="${CIVICRM_DB_PASSWORD:-civicrm}"

CIVICRM_AUTO_INSTALL="${CIVICRM_AUTO_INSTALL:-0}"
SETTINGS_FILE="/var/www/html/private/civicrm.settings.php"

if [[ "${CIVICRM_AUTO_INSTALL}" == "1" && ! -f "${SETTINGS_FILE}" ]]; then
    echo "[civikitchen] CIVICRM_AUTO_INSTALL=1, settings not present"
    echo "[civikitchen] Waiting for database at ${CIVICRM_DB_HOST}:${CIVICRM_DB_PORT}..."

    attempt=0
    until ck internal database-ready configured 2>/dev/null; do
        attempt=$((attempt + 1))
        if [[ "${attempt}" -ge 30 ]]; then
            echo "[civikitchen] ERROR: database not reachable after 60s" >&2
            exit 1
        fi
        sleep 2
    done

    DB_URL="mysql://${CIVICRM_DB_USER}:${CIVICRM_DB_PASSWORD}@${CIVICRM_DB_HOST}:${CIVICRM_DB_PORT}/${CIVICRM_DB_NAME}"
    # CIVIKITCHEN_SITE_URL is the URL the browser uses. It MUST match the host:port the
    # user opens — Civi bakes it into every JS/CSS asset URL, so a mismatch
    # silently breaks the Angular login form because asset fetches fail.
    CIVIKITCHEN_SITE_URL="${CIVIKITCHEN_SITE_URL:-http://localhost}"

    # cv core:install --comp accepts a comma-separated list. cv's own default
    # enables only the core component, which is wrong for a dev image —
    # extensions assuming CiviContribute/CiviCase/etc. would silently fail.
    # Default to all standard components; user can override (or pass an
    # empty string to fall back to cv's core-only default).
    CIVIKITCHEN_COMPONENTS="${CIVIKITCHEN_COMPONENTS-CiviEvent,CiviContribute,CiviMember,CiviMail,CiviPledge,CiviCase,CiviReport,CiviCampaign}"
    INSTALL_OPTS=()
    if [[ -n "${CIVIKITCHEN_COMPONENTS}" ]]; then
        INSTALL_OPTS+=(--comp="${CIVIKITCHEN_COMPONENTS}")
    fi
    # A default locale installs the site in that language: seed labels are
    # translated, machine names stay English. [civicrm.l10n] is private/l10n.
    if [[ -n "${CIVIKITCHEN_DEFAULT_LOCALE:-}" ]]; then
        ck_locales_before_install /var/www/html/core /var/www/html/private/l10n
        INSTALL_OPTS+=(--lang="${CIVIKITCHEN_DEFAULT_LOCALE}")
    fi

    echo "[civikitchen] Running cv core:install (cmsBaseUrl=${CIVIKITCHEN_SITE_URL}${CIVIKITCHEN_COMPONENTS:+, components=${CIVIKITCHEN_COMPONENTS}}${CIVIKITCHEN_DEFAULT_LOCALE:+, lang=${CIVIKITCHEN_DEFAULT_LOCALE}})..."
    # cv --url is the documented flag for setting cmsBaseUrl during install.
    # It populates the model BEFORE init plugins run, so every
    # $civicrm_paths[*]['url'] is derived from CIVIKITCHEN_SITE_URL (cms.root,
    # civicrm.root, civicrm.files, civicrm.vendor — all of them).
    #
    # -K keeps existing tables — survives `docker compose down` (without -v)
    # where settings file is lost but DB volume persists.
    # Run as www-data: /var/www/html/private/ is owned by www-data, and the
    # install creates settings files + cache dirs there. Running as root
    # leaves them root-owned and apache can't later write to the cache dir.
    # A half-finished install writes the settings file before it dies; the
    # next boot would then skip the install (settings exist) and every later
    # step would fail against the half-installed DB with nothing self-healing.
    # Remove the settings file on failure so the next start retries cleanly.
    ck_trust_function_creators || true
    if ! ck_as_web cv core:install -n -K --url="${CIVIKITCHEN_SITE_URL}" --db="${DB_URL}" "${INSTALL_OPTS[@]}"; then
        echo "[civikitchen] ERROR: cv core:install failed — removing the partial settings file so the next start retries" >&2
        rm -f "${SETTINGS_FILE}"
        exit 1
    fi
    echo "[civikitchen] CiviCRM installed."
fi

# ---------------------------------------------------------------------------
# ~/.cv.json, the boot stub and the Apache port live in the container while the
# config marker persists in private/, so wire them on every boot, not in the bundle below.
ck_wire_test_db_boot
ck_listen_on_site_port

# ---------------------------------------------------------------------------
# Post-install configuration + provisioning. Both bundles are marker-gated (each
# writes its marker only on success) so a failed step exits the boot loudly AND
# retries on the next start, instead of being silently skipped because the
# settings file already exists.
#
# They run HERE (settings file present) — not inside the install block above,
# which runs once and never again. That is what makes config retry-safe: a step
# that hard-fails under set -e (the standaloneusers enable or the demo-user
# creation in ck_post_install_config) re-runs next boot instead of being
# stranded. In particular ck_post_install_config creates and seeds the test DB
# BEFORE its auth/demo-user steps, so a demo-user failure cannot leave it missing.
#   ck_post_install_config:    dev settings, SMTP, test DB, auth, demo user
#                              (standalone-only — see docker/runtime/provision.sh).
#   ck_post_install_provision: profile, extra/mounted extensions, init.d hooks.
if [[ "${CIVICRM_AUTO_INSTALL}" == "1" && -f "${SETTINGS_FILE}" ]]; then
    ck_post_install_config
    ck_post_install_provision
fi

# ---------------------------------------------------------------------------
# Heal root-owned files a root start left in the CiviCRM data dirs so the
# www-data web workers can write caches/locks/uploads. Only root can chown.
if [[ "$(id -u)" == 0 ]]; then
    ck_heal_perms
fi

exec docker-php-entrypoint "$@"
