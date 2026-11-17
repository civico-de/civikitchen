# shellcheck shell=bash
# Shared tail of the buildkit entrypoints, sourced once the database is up:
# docker/runtime/provision.sh for the civibuild site, then the readiness marker.

SITE_WEB="/home/buildkit/buildkit/build/site/web"
if [[ "${CIVICRM_SITE_TYPE:-}" == joomla* ]]; then
    export CIVICRM_SETTINGS="${SITE_WEB}/administrator/components/com_civicrm/civicrm.settings.php"
fi

# Run as buildkit from the docroot. Under root, printf %q carries PATH, SITE_WEB
# and each argument's quoting through `su -c`.
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
    # No ck_setup_test_db: civibuild provisions its own TEST_DB_DSN.
    ck_post_install_provision
    echo "[civikitchen] Provisioning complete."
fi

# Heal any root-owned files left in the site tree (runs every boot, cheap).
if [[ "$(id -u)" == 0 ]]; then
    ck_heal_perms
fi
