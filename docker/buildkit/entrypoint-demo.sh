#!/bin/bash
# Entrypoint for the single-container DEMO images (civikitchen:*-demo).
# Unlike docker/buildkit/entrypoint.sh (external DB + fast `civibuild
# reinstall` on first boot), the demo image carries an EMBEDDED MariaDB whose
# data dir was baked at build time (the civibuild `-demo` site). So first boot
# is just: start MariaDB on the baked data → run the shared opt-in
# provisioning (incl. an optional CIVIKITCHEN_PROFILE apply, e.g. common) →
# start Apache. No external DB, no reinstall, no 127.0.0.1->host rewrite (the
# DB host is 127.0.0.1 at both bake and run time, so the baked grants stay
# valid).
set -e

export PATH="/home/buildkit/buildkit/bin:${PATH}"

# Xdebug toggle (shared with the dev images).
. /usr/local/share/civikitchen/xdebug-toggle.sh

CIVICRM_SITE_TYPE="${CIVICRM_SITE_TYPE:-${CIVICRM_SITE_TYPE_DEFAULT:-drupal10-demo}}"

# The site was baked at --url http://localhost (port 80). Without
# CIVIKITCHEN_SITE_URL the demo expects `-p 80:80`; with it, the baked base
# URL is rewritten below (after the DB is up), so any host port works —
# e.g. `-p 8080:80` + CIVIKITCHEN_SITE_URL=http://localhost:8080.
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
# Every absolute URL CiviCRM composes (asset paths, redirects, WordPress's
# siteurl/home) carries the bake-time base URL; on a different host port the
# UI breaks (assets 404). Rewrite the baked base to CIVIKITCHEN_SITE_URL in
# the flavor's civicrm.settings.php file(s) and — WordPress only — the DB
# options. The currently applied URL is recorded in a marker file, so a
# changed env var on a later boot re-applies cleanly (old -> new) and an
# unchanged boot is a no-op. Runs after MariaDB (WP options + cv flush need
# the DB) and before provisioning (profiles print URLs to the logs).
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
    # WordPress additionally stores the base URL in the database. If that
    # update fails, do NOT record the new URL — the marker would claim success
    # and every later boot would no-op while WordPress 301s to the stale base.
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

# Shared first-boot provisioning (SMTP, CIVIKITCHEN_PROFILE,
# extension knobs, init.d hooks, readiness marker) — identical for the dev and
# demo images, so it lives in entrypoint-common.sh. For a demo the base site is
# already baked, so these are mostly opt-in knobs; a CIVIKITCHEN_PROFILE apply
# (git clones + seeds) makes first boot take minutes instead of seconds.
. /usr/local/share/civikitchen/entrypoint-common.sh

# Start Apache (needs root for port 80).
echo "Starting Apache..."
apachectl -D FOREGROUND
