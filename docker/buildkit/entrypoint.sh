#!/bin/bash
set -e

export PATH="/home/buildkit/buildkit/bin:${PATH}"

# Xdebug toggle (shared with standalone image).
. /usr/local/share/civikitchen/xdebug-toggle.sh

# CIVICRM_DB_ROOT_PASSWORD is the admin password: civibuild creates the
# per-site users.
export CIVICRM_DB_HOST="${CIVICRM_DB_HOST:-db}"
export CIVICRM_DB_PORT="${CIVICRM_DB_PORT:-3306}"
export CIVICRM_DB_ROOT_PASSWORD="${CIVICRM_DB_ROOT_PASSWORD:-root}"
# The build arg DEFAULT_SITE_TYPE, overridable with CIVICRM_SITE_TYPE.
CIVICRM_SITE_TYPE="${CIVICRM_SITE_TYPE:-${CIVICRM_SITE_TYPE_DEFAULT:-drupal10-demo}}"

# Legacy name: SITE_URL was renamed to CIVIKITCHEN_SITE_URL (kitchen-owned
# behavior knob); the old spelling keeps working with a warning.
if [[ -z "${CIVIKITCHEN_SITE_URL+x}" && -n "${SITE_URL+x}" ]]; then
    echo "[civikitchen] WARN: SITE_URL is deprecated - use CIVIKITCHEN_SITE_URL" >&2
    export CIVIKITCHEN_SITE_URL="${SITE_URL}"
fi

# Must match the published host port: -p 8080:80 → http://localhost:8080.
if [[ -z "${CIVIKITCHEN_SITE_URL}" ]]; then
    HTTPD_DOMAIN="${HTTPD_DOMAIN:-localhost}"
    HTTPD_PORT="${HTTPD_PORT:-80}"
    if [[ "${HTTPD_PORT}" == "80" ]]; then
        CIVIKITCHEN_SITE_URL="http://${HTTPD_DOMAIN}"
    else
        CIVIKITCHEN_SITE_URL="http://${HTTPD_DOMAIN}:${HTTPD_PORT}"
    fi
fi

MARKER_FILE="/home/buildkit/.site-installed"

echo "CiviCRM Dev Image (${CIVICRM_SITE_TYPE})"
echo "=========================================="
echo "Site URL: ${CIVIKITCHEN_SITE_URL}"

# Probe through PHP mysqli, which does not enforce TLS like newer mariadb-client.
echo "Waiting for database at ${CIVICRM_DB_HOST}:${CIVICRM_DB_PORT}..."
attempt=0
until ck internal database-ready root 2>/dev/null; do
    attempt=$((attempt + 1))
    if [[ "${attempt}" -ge 60 ]]; then
        echo "ERROR: database not reachable after 120s" >&2
        exit 1
    fi
    sleep 2
done
echo "Database is ready."

# First boot: `civibuild reinstall` (~60s) of the baked site against the external DB.
if [[ ! -f "${MARKER_FILE}" ]]; then
    # A recreated container loses the marker but not the database volume, and
    # the reinstall drops the site databases: a sentinel row refuses that.
    if [[ "${CIVIKITCHEN_REINSTALL:-0}" != "1" ]]; then
        sentinel_rc=0
        ck internal database-sentinel-exists || sentinel_rc=$?
        case "${sentinel_rc}" in
            0)
                echo "ERROR: this container is fresh, but the database at ${CIVICRM_DB_HOST} already holds a civikitchen site." >&2
                echo "       Rebuilding the site would DROP those databases (your dev data)." >&2
                echo "       Either set CIVIKITCHEN_REINSTALL=1 to rebuild anyway (drops the site DBs)," >&2
                echo "       or remove the DB volume for a clean start (docker compose down -v)." >&2
                exit 1 ;;
            1) ;;
            *)
                # "Could not tell" must not fall through to a reinstall that drops databases.
                echo "ERROR: could not verify whether ${CIVICRM_DB_HOST} already holds a civikitchen site (exit ${sentinel_rc})." >&2
                echo "       Fix the root credentials or grants first; CIVIKITCHEN_REINSTALL=1 skips this check." >&2
                exit 1 ;;
        esac
    fi

    echo "First run: installing ${CIVICRM_SITE_TYPE} site against ${CIVICRM_DB_HOST}..."

    # The image runs as buildkit; `docker run -u root` still gets there via su.
    bk() { if [[ "$(id -u)" == 0 ]]; then su -s /bin/bash buildkit -c "$1"; else bash -c "$1"; fi; }

    # Point amp/civibuild at the external DB (root creds for GRANT-level access:
    # reinstall recreates the per-site databases + users).
    cat > /home/buildkit/.my.cnf <<MYCNF
[client]
host=${CIVICRM_DB_HOST}
port=${CIVICRM_DB_PORT}
user=root
password=${CIVICRM_DB_ROOT_PASSWORD}
MYCNF
    chown buildkit:buildkit /home/buildkit/.my.cnf
    chmod 600 /home/buildkit/.my.cnf

    bk "export PATH=$(printf '%q' "${PATH}") && amp config:set --mysql_type=mycnf --httpd_type=none --perm_type=none"

    # The build config and amp registry still point at the bake's 127.0.0.1;
    # repoint them at the external host, escaping sed metacharacters (/, &, \).
    DB_HOST_SED=$(printf '%s' "${CIVICRM_DB_HOST}" | sed 's/[\/&\\]/\\&/g')
    bk "sed -i 's/127\.0\.0\.1/${DB_HOST_SED}/g' \
        /home/buildkit/buildkit/build/*.sh \
        /home/buildkit/.amp/instances.yml \
        /home/buildkit/.amp/my.cnf.d/* 2>/dev/null || true"

    # reinstall (not create): reuse the baked codebase, recreate the DBs on the
    # external host, regenerate settings for ${CIVIKITCHEN_SITE_URL}.
    bk "export PATH=$(printf '%q' "${PATH}") && civibuild reinstall site --url $(printf '%q' "${CIVIKITCHEN_SITE_URL}")"

    # The reinstall above recreates civibuild's incomplete Joomla install.
    if [[ "${CIVICRM_SITE_TYPE}" == joomla* ]]; then
        bk "export PATH=$(printf '%q' "${PATH}") && bash /usr/local/share/civikitchen/joomla-finish.sh"
    fi

    # Sentinel for the fresh-container guard above: mark the external DB as
    # holding an installed site (survives container recreation).
    ck internal database-sentinel-write \
        || echo "WARNING: could not record the install sentinel in civikitchen_state" >&2

    touch "${MARKER_FILE}"
    echo "Site installed."
else
    echo "Site already installed (skipping)."
fi

# Shared first-boot provisioning of the dev and demo images.
. /usr/local/share/civikitchen/entrypoint-common.sh

# Docker lets the image user bind port 80 (ip_unprivileged_port_start=0).
echo "Starting Apache..."
echo "Access: ${CIVIKITCHEN_SITE_URL}"
echo "Login: admin / admin"
apachectl -D FOREGROUND
