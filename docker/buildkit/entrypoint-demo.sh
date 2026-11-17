#!/bin/bash
# Entrypoint of the demo images: start the embedded MariaDB on the baked data,
# run the shared provisioning, start Apache.
set -e

export PATH="/home/buildkit/buildkit/bin:${PATH}"

# Xdebug toggle (shared with the dev images).
. /usr/local/share/civikitchen/xdebug-toggle.sh

CIVICRM_SITE_TYPE="${CIVICRM_SITE_TYPE:-${CIVICRM_SITE_TYPE_DEFAULT:-drupal10-demo}}"

# Baked at http://localhost; CIVIKITCHEN_SITE_URL rewrites it for another port.
if [[ -z "${CIVIKITCHEN_SITE_URL:-}" ]]; then
    CIVIKITCHEN_SITE_URL="http://localhost"
fi

echo "CiviCRM Demo Image (${CIVICRM_SITE_TYPE})"
echo "=========================================="
echo "Access: ${CIVIKITCHEN_SITE_URL}"
echo "Login:  admin / admin"

# Start the embedded MariaDB on the baked data dir and wait for it.
echo "Starting embedded MariaDB..."
service mariadb start
attempt=0
until mysqladmin --user=root --password=root ping >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [[ "${attempt}" -ge 60 ]]; then
        echo "ERROR: embedded MariaDB not ready after 60s" >&2
        exit 1
    fi
    sleep 1
done
echo "Database is ready."

# --- Apply CIVIKITCHEN_SITE_URL --------------------------------------------
# Rewrite the baked base URL in the settings files and WordPress's options; the
# applied URL is kept in a marker, so a changed value re-applies old -> new.
CK_SITE_URL_MARKER="/home/buildkit/.civikitchen-site-url"
SITE_ROOT="/home/buildkit/buildkit/build/site"
ck_demo_apply_site_url() {
    local new="${CIVIKITCHEN_SITE_URL%/}" old f
    old="$(cat "${CK_SITE_URL_MARKER}" 2>/dev/null || echo "http://localhost")"
    [[ "${new}" == "${old}" ]] && return 0
    if ! [[ "${new}" =~ ^https?://[A-Za-z0-9._-]+(:[0-9]+)?$ ]]; then
        echo "WARNING: CIVIKITCHEN_SITE_URL '${new}' is not a plain http(s)://host[:port] base URL — keeping '${old}'." >&2
        return 0
    fi
    echo "Rewriting site base URL: ${old} -> ${new}"
    # One settings location per flavor (Joomla has two); sed only touches the
    # file(s) this flavor actually has.
    for f in \
        web/private/civicrm.settings.php \
        web/sites/default/civicrm.settings.php \
        web/wp-content/uploads/civicrm/civicrm.settings.php \
        src/civicrm/site/civicrm.settings.php \
        src/civicrm/admin/civicrm.settings.php; do
        [[ -f "${SITE_ROOT}/${f}" ]] && sed -i "s@${old}@${new}@g" "${SITE_ROOT}/${f}"
    done
    # WordPress keeps the base URL in the database too; on failure the marker
    # must not record the new URL.
    if [[ -f "${SITE_ROOT}/web/wp-load.php" ]]; then
        if ! su -s /bin/bash buildkit -c "cd '${SITE_ROOT}/web' && /home/buildkit/buildkit/bin/cv ev \"update_option('siteurl', '${new}'); update_option('home', '${new}');\""; then
            echo "WARNING: could not update WordPress siteurl/home — will retry on next boot" >&2
            return 0
        fi
    fi
    # Drop caches that may hold URLs composed from the old base.
    su -s /bin/bash buildkit -c "cd '${SITE_ROOT}/web' && /home/buildkit/buildkit/bin/cv flush" \
        || echo "WARNING: cv flush after URL rewrite failed" >&2
    echo "${new}" > "${CK_SITE_URL_MARKER}"
}
ck_demo_apply_site_url

# Shared first-boot provisioning of the dev and demo images.
. /usr/local/share/civikitchen/entrypoint-common.sh

# Start Apache (needs root for port 80).
echo "Starting Apache..."
apachectl -D FOREGROUND
