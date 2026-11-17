#!/bin/bash
set -e

# civikitchen standalone entrypoint: xdebug toggle, `cv core:install` when
# CIVICRM_AUTO_INSTALL=1, shared provisioning, then docker-php-entrypoint.

# ---------------------------------------------------------------------------
# Xdebug toggle (shared with buildkit image).
. /usr/local/share/civikitchen/xdebug-toggle.sh

# ---------------------------------------------------------------------------
# CIVIKITCHEN_* are this image's knobs; CIVICRM_* belong to the upstream image
# contract, the CiviCRM target and CiviCRM itself.

# ---------------------------------------------------------------------------
# Shared provisioning library; ck_as_web drops to www-data when started as root.
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
# Extra OS packages, comma- or space-separated, installed once per container;
# needs `user: root`.
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
    # Must match the host:port the browser opens: Civi bakes it into every asset URL.
    CIVIKITCHEN_SITE_URL="${CIVIKITCHEN_SITE_URL:-http://localhost}"

    # All standard components by default; an empty value keeps cv's core-only default.
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
    # -K keeps existing tables when the settings file is gone but the DB volume
    # is not. A failed install leaves a settings file behind; remove it to retry.
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
# Marker-gated bundles, outside the install block so a failed step re-runs on
# the next boot.
if [[ "${CIVICRM_AUTO_INSTALL}" == "1" && -f "${SETTINGS_FILE}" ]]; then
    ck_post_install_config
    ck_post_install_provision
fi

# ---------------------------------------------------------------------------
# Only root can hand root-owned data files back to www-data.
if [[ "$(id -u)" == 0 ]]; then
    ck_heal_perms
fi

exec docker-php-entrypoint "$@"
