# shellcheck shell=bash
# Shared tail of the buildkit entrypoints (dev entrypoint.sh + demo
# entrypoint-demo.sh) — sourced AFTER the site's database is reachable (dev:
# external DB + `civibuild reinstall`; demo: embedded MariaDB started). It is
# buildkit-image-specific (hardcodes the buildkit user + civibuild site paths),
# which is why it lives here and not in docker/runtime/ next to the files the
# standalone image shares.
#
# Wires up docker/runtime/provision.sh for the civibuild site and runs the shared
# first-boot provisioning: SMTP, profiles (CIVIKITCHEN_PROFILE), extension
# knobs, /civikitchen-init.d hooks, and the readiness marker the HEALTHCHECK
# looks for.

SITE_WEB="/home/buildkit/buildkit/build/site/web"
if [[ "${CIVICRM_SITE_TYPE:-}" == joomla* ]]; then
    export CIVICRM_SETTINGS="${SITE_WEB}/administrator/components/com_civicrm/civicrm.settings.php"
fi

# Run a command as the buildkit user from the site docroot so cv auto-detects
# the civibuild site. Under root (the demo image), PATH and SITE_WEB are
# expanded in THIS shell and printf %q carries them and each argument's quoting
# through `su -c`; single-quoting PATH would hand the inner shell a literal ${PATH}.
ck_as_web() {
    if [[ "$(id -u)" != 0 ]]; then
        (cd "${SITE_WEB}" && "$@")
        return
    fi
    su -s /bin/bash buildkit -c "export PATH=$(printf '%q' "${PATH}") && cd $(printf '%q' "${SITE_WEB}") && $(printf '%q ' "$@")"
}

# provision.sh parameters for this civibuild site (override standalone defaults).
export CK_WEB_USER=buildkit
export CK_WEB_GROUP=buildkit
export CK_WEB_USER_HOME=/home/buildkit
export CK_DATA_DIRS="/home/buildkit/buildkit/build/site"
export CK_PROVISIONED_MARKER=/home/buildkit/.civikitchen-provisioned
# civibuild loads app/civicrm.settings.d/*.php into civicrm.settings.php;
# ck_smtp removes the 100-mail.php mail-to-/dev/null heuristic there.
export CK_SETTINGS_D=/home/buildkit/buildkit/app/civicrm.settings.d
# Discover the extension dir from cv (CMS-agnostic; Joomla uses CIVICRM_SETTINGS above).
CK_EXT_DIR="$(ck_as_web cv ev 'echo rtrim(CRM_Core_Config::singleton()->extensionsDir, "/");' 2>/dev/null || true)"
if [[ -z "${CK_EXT_DIR}" ]]; then
    # Without it every provisioning step below would skip quietly and the
    # container would still report healthy — with none of the extensions in.
    echo "[civikitchen] ERROR: could not discover the extension dir from cv; refusing to provision blind" >&2
    exit 1
fi
export CK_EXT_DIR

. /usr/local/share/civikitchen/provision.sh

ck_attach_scenario_extension
ck_warn_removed_auto_composer

if [[ ! -f "${CK_PROVISIONED_MARKER}" ]]; then
    echo "[civikitchen] First-boot provisioning (${CIVICRM_SITE_TYPE})..."
    ck_smtp
    # No ck_setup_test_db here: civibuild already provisions an isolated
    # TEST_DB_DSN (its own sitetest_* build) — unlike standalone, which has
    # no civibuild and sets its own.
    ck_post_install_provision
    echo "[civikitchen] Provisioning complete."
fi

# Heal any root-owned files left in the site tree (runs every boot, cheap).
if [[ "$(id -u)" == 0 ]]; then
    ck_heal_perms
fi
