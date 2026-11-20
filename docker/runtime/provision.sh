#!/bin/bash
# civikitchen first-boot provisioning shared by both entrypoints, sourced once
# the CiviCRM site exists. CK_* defaults are the standalone layout.
#
# The caller defines `ck_as_web CMD...` first: run CMD as the web user in a
# directory where cv auto-detects the site.

# Accept legacy CIVICRM_-spelled kitchen vars with a deprecation warning.
_ck_legacy() {
    local new="$1" legacy="$2"
    if [[ -z "${!new+x}" && -n "${!legacy+x}" ]]; then
        echo "[civikitchen] WARN: ${legacy} is deprecated - use ${new}" >&2
        export "${new}=${!legacy}"
    fi
}
_ck_legacy CIVIKITCHEN_SMTP_HOST         CIVICRM_SMTP_HOST
_ck_legacy CIVIKITCHEN_SMTP_PORT         CIVICRM_SMTP_PORT
_ck_legacy CIVIKITCHEN_EXTRA_EXTENSIONS  CIVICRM_EXTRA_EXTENSIONS
_ck_legacy CIVIKITCHEN_ENABLE_EXTENSIONS CIVICRM_ENABLE_EXTENSIONS

# --- parameters (defaults = standalone layout) -----------------------------
: "${CK_WEB_USER:=www-data}"
: "${CK_WEB_GROUP:=www-data}"
: "${CK_WEB_USER_HOME:=/var/www}"
: "${CK_ROOT_HOME:=/root}"
: "${CK_EXT_DIR:=/var/www/html/ext}"
: "${CK_DATA_DIRS:=/var/www/html/private /var/www/html/public}"
: "${CK_PROVISIONED_MARKER:=/var/www/html/private/.civikitchen-provisioned}"
# init.d hooks seed the database, so their run is marked in it: a recreated
# container with the old database volume must not run them twice.
: "${CK_PROVISIONED_SETTING:=civikitchen_provisioned}"
# Marker of ck_post_install_config, apart from CK_PROVISIONED_MARKER so the two
# retry independently.
: "${CK_CONFIGURED_MARKER:=/var/www/html/private/.civikitchen-configured}"
: "${CK_INIT_D:=/civikitchen-init.d}"
# ~/.cv.json site key under which TEST_DB_DSN is stored. Standalone keys by its
# bootstrap file; other CMSes key the site differently (resolved per image).
: "${CK_TEST_DB_CV_KEY:=/var/www/html/civicrm.standalone.php}"
# Boot stub ck_wire_test_db_boot patches (patch-test-db-boot.php); empty or
# missing = skip, as buildkit flavors boot through their CMS.
: "${CK_BOOT_STUB:=/var/www/html/civicrm.standalone.php}"
# Standalone's settings file. Missing file = buildkit flavor, whose CMS owns it.
: "${CK_SETTINGS_FILE:=/var/www/html/private/civicrm.settings.php}"
# Where named profiles (docker/profiles/<name>/) ship inside the image.
: "${CK_PROFILE_DIR:=/opt/civikitchen/docker/profiles}"
# civibuild's settings.d dir; only the buildkit entrypoint sets it.
: "${CK_SETTINGS_D:=}"
# Mount table used to tell bind-mounted extensions from downloaded ones.
: "${CK_MOUNTINFO:=/proc/self/mountinfo}"
# Where the core language archives are fetched from.
: "${CK_L10N_BASE_URL:=https://download.civicrm.org}"
# Where ck_listen_on_site_port writes its Apache config (rewritten on every boot).
: "${CK_APACHE_CONF:=/etc/apache2/conf-enabled/civikitchen-site-port.conf}"

# --- functions -------------------------------------------------------------

ck_runtime_cli() {
    command -v ck 2>/dev/null || {
        echo "[civikitchen] ERROR: no ck on PATH." >&2
        return 1
    }
}

# Link the scenario's flavor-neutral extension mount into this image's ext dir.
# CIVIKITCHEN_EXTENSION_DIR names the link (default: the key).
ck_attached_extension_link() {
    echo "${CK_EXT_DIR}/${CIVIKITCHEN_EXTENSION_DIR:-${CIVIKITCHEN_EXTENSION_KEY:-}}"
}

ck_attach_scenario_extension() {
    [[ -n "${CIVIKITCHEN_EXTENSION_PATH:-}" ]] || return 0
    local source="${CIVIKITCHEN_EXTENSION_PATH}" key="${CIVIKITCHEN_EXTENSION_KEY:-}"
    local declared target existing
    [[ -d "${source}" && -f "${source}/info.xml" ]] || {
        echo "[civikitchen] ERROR: scenario extension path is not a readable extension: ${source}" >&2
        return 1
    }
    declared="$(ck_extension_key "${source}")"
    [[ -n "${key}" && "${declared}" == "${key}" ]] || {
        echo "[civikitchen] ERROR: scenario extension key '${key}' does not match info.xml key '${declared}'" >&2
        return 1
    }
    mkdir -p "${CK_EXT_DIR}"
    target="$(ck_attached_extension_link)"
    if [[ -L "${target}" ]]; then
        existing="$(readlink "${target}")"
        [[ "${existing}" == "${source}" ]] || {
            echo "[civikitchen] ERROR: ${target} already links to ${existing}, not ${source}" >&2
            return 1
        }
    elif [[ -e "${target}" ]]; then
        echo "[civikitchen] ERROR: cannot attach scenario extension; ${target} already exists" >&2
        return 1
    else
        ln -s "${source}" "${target}"
    fi
}

# vendor/ comes from the checkout's owner before the stack starts (ckup, the CI
# workflows): written from here it would belong to the image user, not the host.
ck_warn_removed_auto_composer() {
    if [[ -n "${CIVIKITCHEN_AUTO_COMPOSER+x}${CIVICRM_AUTO_COMPOSER+x}" ]]; then
        echo "[civikitchen] WARN: CIVIKITCHEN_AUTO_COMPOSER is gone; ckup and the CI workflows install vendor/ before the stack starts" >&2
    fi
}

# Dev-mode defaults.
ck_dev_settings() {
    ck_as_web cv setting:set environment=Development >/dev/null
    ck_as_web cv setting:set debug_enabled=1 >/dev/null
    echo "[civikitchen] Dev settings applied (environment=Development, debug_enabled=1)."
}

# Point Civi's mail backend at an SMTP host (e.g. the maildev sidecar).
ck_smtp() {
    [[ -n "${CIVIKITCHEN_SMTP_HOST}" ]] || return 0
    local smtp_port="${CIVIKITCHEN_SMTP_PORT:-1025}"
    echo "[civikitchen] Configuring mail backend → ${CIVIKITCHEN_SMTP_HOST}:${smtp_port}..."
    ck_as_web env SMTP_HOST="${CIVIKITCHEN_SMTP_HOST}" SMTP_PORT="${smtp_port}" \
        cv ev '\Civi::settings()->set("mailing_backend", ["outBound_option" => 0, "smtpServer" => getenv("SMTP_HOST"), "smtpPort" => (int) getenv("SMTP_PORT"), "smtpAuth" => 0, "smtpUsername" => "", "smtpPassword" => ""]);'
    # buildkit's 100-mail.php ignores the DB setting above and sets
    # CIVICRM_MAIL_LOG=/dev/null, which discards every mail. `if`, not `&&`:
    # as the last statement a false test would fail the caller under set -e.
    if [[ -n "${CK_SETTINGS_D}" ]]; then
        rm -f "${CK_SETTINGS_D}/100-mail.php"
    fi
}

# Enable standaloneusers, the Standalone auth provider; `cv core:install` does
# not install it on every version (absent on standalone-6.12). Standalone only.
ck_standalone_auth() {
    echo "[civikitchen] Ensuring standaloneusers (Standalone auth provider) is enabled..."
    if ! ck_as_web cv ext:enable standaloneusers; then
        echo "[civikitchen] ERROR: could not enable standaloneusers (Standalone auth provider)." >&2
        return 1
    fi
    # Its postInstall creates these roles; an install killed before that step
    # leaves the extension marked installed, so enabling it again adds none.
    local missing
    missing="$(ck_as_web cv ev 'echo implode(" ", array_diff(["admin", "everyone"], \Civi\Api4\Role::get(FALSE)->addSelect("name")->execute()->column("name")));')" || return 1
    if [[ -n "${missing}" ]]; then
        echo "[civikitchen] ERROR: standaloneusers is installed but its role(s) ${missing} are missing: the database holds an interrupted first install. Remove the database volume (docker compose down -v) and start again." >&2
        return 1
    fi
}

# Opt-in demo login user (CIVIKITCHEN_DEMO_USER); needs ck_standalone_auth first.
# Standalone only.
ck_demo_user() {
    [[ -n "${CIVIKITCHEN_DEMO_USER}" ]] || return 0
    local demo_user="${CIVIKITCHEN_DEMO_USER}"
    local demo_pass="${CIVIKITCHEN_DEMO_PASS:-admin}"
    local demo_email="${CIVIKITCHEN_DEMO_EMAIL:-admin@example.org}"
    echo "[civikitchen] Creating demo user '${demo_user}'..."
    ck_as_web env DEMO_USER="${demo_user}" DEMO_PASS="${demo_pass}" DEMO_EMAIL="${demo_email}" \
        cv scr /usr/local/share/civikitchen/demo-user.php
}

# Run one SQL batch (stdin) as the database root user and echo its error text.
# mysql's password notice is not an error and would mask the real one.
ck_mysql_root() {
    local err rc=0
    err=$(mktemp)
    mysql -h "${CIVICRM_DB_HOST}" -P "${CIVICRM_DB_PORT}" -u root -p"${CIVICRM_DB_ROOT_PASSWORD:-root}" "$@" 2>"${err}" || rc=$?
    grep -v "Using a password" "${err}" >&2 || true
    rm -f "${err}"
    return "${rc}"
}

# With binary logging on (mysql:8.0 default) only SUPER may create the triggers
# and functions `cv core:install` creates, unless this server setting is on.
ck_trust_function_creators() {
    if ! echo "SET GLOBAL log_bin_trust_function_creators = 1;" | ck_mysql_root; then
        echo "[civikitchen] WARNING: could not set log_bin_trust_function_creators as database root; on a binlog-enabled server the install and the headless test DB need it (CIVICRM_DB_ROOT_PASSWORD)." >&2
        return 1
    fi
}

# Create, grant and seed the <db>_test scratch DB as the database root user:
# the app user holds rights on its own database only, so it can neither create
# the scratch DB nor copy triggers and routines into it.
ck_provision_test_db() {
    local test_db_name="$1" sql
    # `_` is a GRANT wildcard even inside backticks.
    local grant_db="${test_db_name//_/\\_}"
    # Recreated, not reused: a seed over old \Civi\Test state (civitest_revs) skips the
    # schema rebuild, and core suites then run on the site's schema instead of core's.
    sql="DROP DATABASE IF EXISTS \`${test_db_name}\`; CREATE DATABASE \`${test_db_name}\`;"
    if [[ "${CIVICRM_DB_USER}" != "root" ]]; then
        sql+="GRANT ALL PRIVILEGES ON \`${grant_db}\`.* TO '${CIVICRM_DB_USER}'@'%';"
        # Civi\Test\Schema::setStrict runs `SET global
        # innodb_flush_log_at_trx_commit`; on MariaDB 10.11 no privilege
        # narrower than SUPER permits it (verified against 10.11.19).
        sql+="GRANT SUPER ON *.* TO '${CIVICRM_DB_USER}'@'%';"
        sql+="FLUSH PRIVILEGES;"
    fi
    ck_trust_function_creators || return 1
    if ! printf '%s\n' "${sql}" | ck_mysql_root; then
        echo "[civikitchen] ERROR: could not create ${test_db_name} as database root. Headless tests need it; set CIVICRM_DB_ROOT_PASSWORD to the db service's root password, or CIVIKITCHEN_TEST_DB=0 to manage TEST_DB_DSN yourself." >&2
        return 1
    fi
    # The headless harness boots against TEST_DB_DSN before \Civi\Test builds a
    # schema, so an empty test DB is unusable.
    echo "[civikitchen] Seeding ${test_db_name} from ${CIVICRM_DB_NAME}..."
    # pipefail: a failing mysqldump must not pass as an empty, successful seed.
    local dump_err
    dump_err=$(mktemp)
    if ! (set -o pipefail; mysqldump -h "${CIVICRM_DB_HOST}" -P "${CIVICRM_DB_PORT}" -u root -p"${CIVICRM_DB_ROOT_PASSWORD:-root}" \
            --single-transaction --routines --triggers "${CIVICRM_DB_NAME}" 2>>"${dump_err}" \
        | ck_mysql_root "${test_db_name}"); then
        grep -v "Using a password" "${dump_err}" >&2 || true
        rm -f "${dump_err}"
        echo "[civikitchen] ERROR: could not seed ${test_db_name} from ${CIVICRM_DB_NAME}; headless tests would run against an empty schema." >&2
        return 1
    fi
    rm -f "${dump_err}"
}

# Isolated headless-test database: create, grant and seed the <db>_test scratch
# DB that ck_wire_test_db_boot points UnitTests boots at. Opt out: CIVIKITCHEN_TEST_DB=0.
ck_setup_test_db() {
    [[ "${CIVIKITCHEN_TEST_DB:-1}" == "1" ]] || return 0
    local test_db_name="${CIVICRM_DB_NAME}_test"
    echo "[civikitchen] Creating isolated test DB → ${test_db_name}..."
    ck_provision_test_db "${test_db_name}" || return 1
}

# "key": "value" with the value JSON-escaped (a DB password may contain \ or ").
ck_json_member() {
    local value="${2//\\/\\\\}"
    printf '"%s": "%s"' "$1" "${value//\"/\\\"}"
}

# Serve the site on the port of a localhost site URL as well, so a browser in this
# container's network (the compose browser service) opens the URL CiviCRM generates.
ck_listen_on_site_port() {
    # The image creates the file for the web user, who cannot write the directory.
    : > "${CK_APACHE_CONF}"
    [[ "${CIVIKITCHEN_SITE_URL:-}" =~ ^http://(localhost|127\.0\.0\.1):([0-9]+)(/|$) ]] || return 0
    local port="${BASH_REMATCH[2]}"
    [[ "${port}" != "80" ]] || return 0
    printf 'Listen %s\n<VirtualHost *:%s>\n    DocumentRoot /var/www/html\n</VirtualHost>\n' \
        "${port}" "${port}" > "${CK_APACHE_CONF}"
}

# Route CIVICRM_UF=UnitTests boots at <db>_test: TEST_DB_DSN in ~/.cv.json plus
# the patched boot stub. Both live outside private/, so this runs on every boot.
# The opt-out skips only the DSN: the stub reads one set by hand too.
ck_wire_test_db_boot() {
    # Core composes CIVICRM_DSN from the CIVICRM_DB_* env vars before the settings
    # file's TEST_DB_DSN branch can fire; the patched stub routes around that.
    if [[ -n "${CK_BOOT_STUB}" ]]; then
        php /usr/local/share/civikitchen/patch-test-db-boot.php "${CK_BOOT_STUB}"
    fi
    [[ "${CIVIKITCHEN_TEST_DB:-1}" == "1" ]] || return 0
    local test_db_dsn="mysql://${CIVICRM_DB_USER}:${CIVICRM_DB_PASSWORD}@${CIVICRM_DB_HOST}:${CIVICRM_DB_PORT}/${CIVICRM_DB_NAME}_test?new_link=true"
    # civicrm.settings.php reads TEST_DB_DSN from ~/.cv.json under UnitTests.
    # Written for the web user and root, never over an existing file.
    local site_json
    site_json="$(ck_json_member TEST_DB_DSN "${test_db_dsn}")"
    # Civi\Test\EndToEndInterface names an admin and a demo user; the one demo user, an admin, serves as both
    if [[ -n "${CIVIKITCHEN_DEMO_USER:-}" ]]; then
        local role
        for role in ADMIN DEMO; do
            site_json+=",
      $(ck_json_member "${role}_USER" "${CIVIKITCHEN_DEMO_USER}"),
      $(ck_json_member "${role}_PASS" "${CIVIKITCHEN_DEMO_PASS:-admin}"),
      $(ck_json_member "${role}_EMAIL" "${CIVIKITCHEN_DEMO_EMAIL:-admin@example.org}")"
        done
    fi
    local cv_json
    cv_json=$(printf '{\n  "sites": {\n    "%s": {\n      %s\n    }\n  }\n}' "${CK_TEST_DB_CV_KEY}" "${site_json}")
    if [[ -w "${CK_ROOT_HOME}" && ! -f "${CK_ROOT_HOME}/.cv.json" ]]; then
        printf '%s\n' "${cv_json}" > "${CK_ROOT_HOME}/.cv.json"
        chmod 600 "${CK_ROOT_HOME}/.cv.json"
    fi
    if [[ ! -f "${CK_WEB_USER_HOME}/.cv.json" ]]; then
        printf '%s\n' "${cv_json}" > "${CK_WEB_USER_HOME}/.cv.json"
        chown "${CK_WEB_USER}:${CK_WEB_GROUP}" "${CK_WEB_USER_HOME}/.cv.json"
        chmod 600 "${CK_WEB_USER_HOME}/.cv.json"
    fi
}

# Run `ck internal provision-OPERATION` as the web user, pinned by the extension
# under test, then the mounted extensions, installing staged releases from CK_DEP_ARCHIVE_DIR.
ck_provision() {
    local operation="$1" cli ext_dir
    local -a mounted=()
    shift
    cli="$(ck_runtime_cli)" || return 1
    if [[ -n "${CIVIKITCHEN_EXTENSION_UNDER_TEST:-}" ]]; then
        mounted+=(--under-test "${CK_EXT_DIR}/${CIVIKITCHEN_EXTENSION_UNDER_TEST}")
    fi
    while IFS= read -r ext_dir; do
        if [[ -n "${ext_dir}" ]]; then mounted+=(--mounted "${ext_dir}"); fi
    done < <(ck_mounted_extension_dirs)
    ck_as_web "${cli}" internal "provision-${operation}" \
        --ext-dir "${CK_EXT_DIR}" --archive-dir "${CK_DEP_ARCHIVE_DIR:-}" "${mounted[@]}" "$@"
}

# Download and enable CIVIKITCHEN_EXTRA_EXTENSIONS (comma-separated keys or
# key@URL) in order, each after its <requires>; a bare key takes a mounted extension's pin.
ck_extra_extensions() {
    ck_provision extras "${CIVIKITCHEN_EXTRA_EXTENSIONS:-}"
}

# Directories bind-mounted directly into the ext dir, one per line, from the
# mount table; the linked scenario extension counts as mounted too.
ck_mounted_extension_dirs() {
    [[ -n "${CK_EXT_DIR}" ]] || return 0
    local mount_point
    {
        if [[ -r "${CK_MOUNTINFO}" ]]; then
            while read -r _ _ _ _ mount_point _; do
                # Mount points escape space, tab and newline as \040 \011 \012.
                mount_point="$(printf '%b' "${mount_point}")"
                if [[ "${mount_point}" == "${CK_EXT_DIR}"/* && "${mount_point#"${CK_EXT_DIR}"/}" != */* ]]; then
                    echo "${mount_point}"
                fi
            done < "${CK_MOUNTINFO}"
        fi
        if [[ -n "${CIVIKITCHEN_EXTENSION_PATH:-}" && -L "$(ck_attached_extension_link)" ]]; then
            ck_attached_extension_link
        fi
    } | sort -u
}

# The extension key an info.xml declares, or nothing when it cannot be read.
ck_extension_key() {
    ck internal extension-key "$1/info.xml"
}

# Enable the mounted extensions and CIVIKITCHEN_ENABLE_EXTENSIONS (listed ones
# first, in their order), each after its <requires>.
ck_enable_extensions() {
    ck_provision enable "${CIVIKITCHEN_ENABLE_EXTENSIONS:-}"
}

# Core language files for CIVIKITCHEN_LOCALES=de_DE[,fr_FR,...] into
# [civicrm.l10n]; CIVIKITCHEN_DEFAULT_LOCALE, one of them, sets lcMessages.
ck_locales() {
    local out version l10n_dir
    local -a locales=()
    out="$(ck_requested_locales)" || return 1
    [[ -n "${out}" ]] || return 0
    mapfile -t locales <<< "${out}"
    version="$(ck_as_web cv ev 'echo CRM_Utils_System::version();')"
    l10n_dir="$(ck_as_web cv path -d '[civicrm.l10n]')"
    ck_fetch_locales "${version}" "${l10n_dir}" "${locales[@]}" || return 1
    if [[ -n "${CIVIKITCHEN_DEFAULT_LOCALE:-}" ]]; then
        ck_as_web cv setting:set "lcMessages=${CIVIKITCHEN_DEFAULT_LOCALE}" >/dev/null
        echo "[civikitchen] Default locale set to ${CIVIKITCHEN_DEFAULT_LOCALE} (lcMessages)."
    fi
}

# The same files before any site exists, for `cv core:install --lang`: the
# installer renders its seed labels through ts(). $1 = core source dir.
ck_locales_before_install() {
    local src="$1" l10n_dir="$2" out version
    local -a locales=()
    out="$(ck_requested_locales)" || return 1
    [[ -n "${out}" ]] || return 0
    mapfile -t locales <<< "${out}"
    version="$(ck internal xml-field "${src}/xml/version.xml" version_no)" || return 1
    ck_fetch_locales "${version}" "${l10n_dir}" "${locales[@]}"
}

# Validate CIVIKITCHEN_LOCALES / CIVIKITCHEN_DEFAULT_LOCALE and print each
# requested locale, one per line.
ck_requested_locales() {
    if [[ -z "${CIVIKITCHEN_LOCALES:-}" ]]; then
        if [[ -n "${CIVIKITCHEN_DEFAULT_LOCALE:-}" ]]; then
            echo "[civikitchen] ERROR: CIVIKITCHEN_DEFAULT_LOCALE=${CIVIKITCHEN_DEFAULT_LOCALE} needs its language files — list it in CIVIKITCHEN_LOCALES" >&2
            return 1
        fi
        return 0
    fi
    local locale
    local -a locales=() requested=()
    IFS=',' read -ra locales <<< "${CIVIKITCHEN_LOCALES}"
    for locale in "${locales[@]}"; do
        locale="${locale// /}"
        [[ -z "${locale}" ]] && continue
        if [[ ! "${locale}" =~ ^[a-z]{2,3}_[A-Z]{2}$ ]]; then
            echo "[civikitchen] ERROR: '${locale}' is not a CiviCRM locale (expected e.g. de_DE)" >&2
            return 1
        fi
        requested+=("${locale}")
    done
    [[ ${#requested[@]} -gt 0 ]] || return 0
    if [[ -n "${CIVIKITCHEN_DEFAULT_LOCALE:-}" && " ${requested[*]} " != *" ${CIVIKITCHEN_DEFAULT_LOCALE} "* ]]; then
        echo "[civikitchen] ERROR: CIVIKITCHEN_DEFAULT_LOCALE=${CIVIKITCHEN_DEFAULT_LOCALE} is not in CIVIKITCHEN_LOCALES=${CIVIKITCHEN_LOCALES}" >&2
        return 1
    fi
    printf '%s\n' "${requested[@]}"
}

# $1 = CiviCRM version, $2 = l10n dir, then locales. A locale whose core
# catalogue is already in the dir is not fetched again.
ck_fetch_locales() {
    local version="$1" l10n_dir="$2" locale archive prefix unpack
    shift 2
    local -a missing=()
    for locale in "$@"; do
        [[ -s "${l10n_dir}/${locale}/LC_MESSAGES/civicrm.mo" ]] || missing+=("${locale}")
    done
    if [[ ${#missing[@]} -eq 0 ]]; then
        echo "[civikitchen] Core language files ${CIVIKITCHEN_LOCALES} already in ${l10n_dir}."
        return 0
    fi
    # A release has its own archive. An alpha or beta core (master, or a release
    # branch before its release) has none and takes the daily catalogue.
    if [[ "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        archive="${CK_L10N_BASE_URL}/civicrm-${version}-l10n.tar.gz" prefix=civicrm/l10n
    elif [[ "${version}" =~ ^[0-9]+\.[0-9]+\.(alpha|beta)[0-9]+$ ]]; then
        archive="${CK_L10N_BASE_URL}/civicrm-l10n-core/archives/civicrm-l10n-daily.tar.gz" prefix=l10n
    else
        echo "[civikitchen] ERROR: no core language files for CiviCRM version '${version}' (expected X.Y.Z, X.Y.alphaN or X.Y.betaN)" >&2
        return 1
    fi
    echo "[civikitchen] Installing core language files ${missing[*]} from ${archive} into ${l10n_dir}..."
    unpack="$(mktemp -d)"
    # Saved before unpacking so a stalled transfer is retried whole; tar fails
    # on a locale missing from the archive.
    if ! curl -fsSL --connect-timeout 20 --speed-limit 10240 --speed-time 30 \
            --retry 3 --retry-all-errors -o "${unpack}/l10n.tar.gz" "${archive}" \
        || ! tar -xzf "${unpack}/l10n.tar.gz" -C "${unpack}" "${missing[@]/#/${prefix}/}"; then
        rm -rf "${unpack}"
        echo "[civikitchen] ERROR: could not fetch ${missing[*]} from ${archive}" >&2
        return 1
    fi
    mkdir -p "${l10n_dir}"
    cp -R "${unpack}/${prefix}/." "${l10n_dir}/"
    rm -rf "${unpack}"
    chown -R "${CK_WEB_USER}:${CK_WEB_GROUP}" "${l10n_dir}"
}

# Resolve one profile name across CIVIKITCHEN_PROFILE_PATH and the bundled
# root; a name found twice is an error, not an override.
ck_resolve_profile() {
    local name="$1" root candidate
    local -a roots=() matches=()
    [[ "${name}" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || {
        echo "[civikitchen] ERROR: invalid profile name '${name}'." >&2
        return 1
    }
    if [[ -n "${CIVIKITCHEN_PROFILE_PATH:-}" ]]; then
        IFS=':' read -ra roots <<< "${CIVIKITCHEN_PROFILE_PATH}"
    fi
    roots+=("${CK_PROFILE_DIR}")
    for root in "${roots[@]}"; do
        [[ -n "${root}" ]] || continue
        candidate="${root%/}/${name}"
        [[ -f "${candidate}/profile.json" ]] && matches+=("${candidate}")
    done
    if [[ ${#matches[@]} -eq 0 ]]; then
        echo "[civikitchen] ERROR: unknown profile '${name}' in CIVIKITCHEN_PROFILE_PATH or ${CK_PROFILE_DIR}." >&2
        return 1
    fi
    if [[ ${#matches[@]} -gt 1 ]]; then
        echo "[civikitchen] ERROR: profile '${name}' is ambiguous: ${matches[*]}" >&2
        return 1
    fi
    printf '%s' "${matches[0]}"
}

# Appends a profile to CK_EXPANDED_PROFILES after its includes, depth-first; each
# profile lands once, and a cycle is an error. $2 is the include chain so far.
ck_expand_profile() {
    local name="$1" chain="$2" dir seen includes include
    if [[ ",${chain}," == *",${name},"* ]]; then
        echo "[civikitchen] ERROR: profile include cycle: ${chain//,/ -> } -> ${name}" >&2
        return 1
    fi
    dir="$(ck_resolve_profile "${name}")" || return 1
    for seen in "${CK_EXPANDED_PROFILES[@]}"; do
        [[ "${seen}" == "${dir}" ]] && return 0
    done
    ck_validate_profile "${dir}" || return 1
    includes="$("$(ck_runtime_cli)" internal profile-includes "${dir}/profile.json")" || return 1
    while IFS= read -r include; do
        [[ -n "${include}" ]] || continue
        ck_expand_profile "${include}" "${chain:+${chain},}${name}" || return 1
    done <<< "${includes}"
    CK_EXPANDED_PROFILES+=("${dir}")
}

# Schema plus what the driver needs from the directory (scripts present, no seeds/).
ck_validate_profile() {
    "$(ck_runtime_cli)" profile validate "$1" >/dev/null
}

ck_profile_is_bundled() {
    local dir="$1" bundled candidate
    bundled="$(cd "${CK_PROFILE_DIR}" && pwd -P)" || return 1
    candidate="$(cd "${dir}" && pwd -P)" || return 1
    [[ "${candidate}" == "${bundled}"/* ]]
}

# External profiles can carry apply.sh and PHP scripts, so they run only with
# explicit trust; schema validation is no sandbox.
ck_require_profile_trust() {
    local dir="$1"
    ck_profile_is_bundled "${dir}" && return 0
    if [[ "${CIVIKITCHEN_TRUST_EXTERNAL_PROFILES:-0}" != "1" ]]; then
        echo "[civikitchen] ERROR: external profile ${dir} is executable code; set CIVIKITCHEN_TRUST_EXTERNAL_PROFILES=1 only for a fully trusted root." >&2
        return 1
    fi
}

# The one AuthX header policy the selected profiles agree on; empty means
# AuthX's default (JWT + API key).
ck_resolve_authx_policy() {
    local dir policy resolved=""
    for dir in "$@"; do
        policy="$("$(ck_runtime_cli)" internal profile-authx-policy "${dir}/profile.json" --sort)" || return 1
        [[ -z "${policy}" ]] && continue
        if [[ -n "${resolved}" && "${resolved}" != "${policy}" ]]; then
            echo "[civikitchen] ERROR: selected profiles declare conflicting AuthX header policies (${resolved} versus ${policy})." >&2
            return 1
        fi
        resolved="${policy}"
    done
    printf '%s' "${resolved}"
}

# Apply CIVIKITCHEN_PROFILE=<name>[,<name>...] left to right, each after its
# includes; every layer converges, so profiles combine. Phase `deps` installs
# dependencies, `content` the rest once mounted extensions are enabled; a
# custom apply.sh runs only in the content phase.
ck_apply_profile() {
    local phase="$1"
    [[ -n "${CIVIKITCHEN_PROFILE:-}" ]] || return 0
    local name dir root apply want_cms authx_policy merged
    local -a names resolved phase_arg
    IFS=',' read -ra names <<< "${CIVIKITCHEN_PROFILE}"
    # Validate the whole list before applying anything.
    CK_EXPANDED_PROFILES=()
    for name in "${names[@]}"; do
        name="${name// /}"
        [[ -z "${name}" ]] && continue
        ck_expand_profile "${name}" "" || return 1
    done
    resolved=("${CK_EXPANDED_PROFILES[@]}")
    for dir in "${resolved[@]}"; do
        ck_require_profile_trust "${dir}" || return 1
    done
    authx_policy="$(ck_resolve_authx_policy "${resolved[@]}")" || return 1
    for dir in "${resolved[@]}"; do
        name="$(basename "${dir}")"
        root="$(dirname "${dir}")"
        # Driver: the profile's apply.sh, else its root's, else the bundled one.
        apply="${CK_PROFILE_DIR}/apply.sh"
        phase_arg=("${phase}")
        [[ -f "${root}/apply.sh" ]] && apply="${root}/apply.sh"
        [[ -f "${dir}/apply.sh" ]] && apply="${dir}/apply.sh"
        # The CMS profile.json requires is a prefix of the site type (drupal10-demo).
        want_cms="$("$(ck_runtime_cli)" internal profile-cms "${dir}/profile.json" 2>/dev/null || true)"
        if [[ -n "${want_cms}" && "${CIVICRM_SITE_TYPE:-}" != "${want_cms}"* ]]; then
            echo "[civikitchen] ERROR: profile '${name}' requires a ${want_cms} site; this site is '${CIVICRM_SITE_TYPE:-unknown}'." >&2
            return 1
        fi
        if [[ "${apply}" != "${CK_PROFILE_DIR}/apply.sh" ]]; then
            [[ "${phase}" == content ]] || continue
            phase_arg=()
        fi
        echo "[civikitchen] Applying profile '${name}', ${phase} (needs network; this can take several minutes)..."
        # CK_SEED_*: what a seed snapshot is keyed on (docs/seed-snapshots.md).
        ck_as_web env \
            CK_DEP_ARCHIVE_DIR="${CK_DEP_ARCHIVE_DIR:-}" \
            CK_CREDENTIALS_FILE="${CK_CREDENTIALS_FILE:-}" \
            CK_CREDENTIALS_OUTPUT="${CK_CREDENTIALS_OUTPUT:-file}" \
            CK_AUTHX_HEADER_CRED="${authx_policy}" \
            CK_DEFER_API_USERS=1 \
            CK_SEED_FRESH="${CK_SEED_FRESH:-0}" \
            CK_SEED_SNAPSHOTS="${CK_SEED_SNAPSHOTS:-0}" \
            CK_SEED_PROFILES="$(printf '%s\n' "${resolved[@]}")" \
            CK_SEED_EXTENSION_DIRS="$(ck_mounted_extension_dirs)" \
            CK_SEED_SIZE="${CK_SEED_SIZE:-}" \
            CK_SEED_TODAY="${CK_SEED_TODAY:-}" \
            CK_SEED_STORE="${CK_SEED_STORE:-}" \
            bash "${apply}" "${dir}" "${phase_arg[@]}"
    done
    [[ "${phase}" == content ]] || return 0
    # API users once from the merged set, so a role shared across profiles gets
    # the union of its permissions.
    merged="$(mktemp)"
    if ! "$(ck_runtime_cli)" internal profiles-merge "${merged}" "${authx_policy}" \
        "${resolved[@]/%//profile.json}"; then
        rm -f "${merged}"
        return 1
    fi
    chmod 0644 "${merged}"
    if ! ck_as_web env \
        CK_PROFILE_JSON="${merged}" \
        CK_CREDENTIALS_FILE="${CK_CREDENTIALS_FILE:-}" \
        CK_CREDENTIALS_OUTPUT="${CK_CREDENTIALS_OUTPUT:-file}" \
        CK_AUTHX_HEADER_CRED="${authx_policy}" \
        CK_RECONCILE_API_USERS=1 \
        cv scr "${CK_PROFILE_DIR}/configure-api-users.php"; then
        rm -f "${merged}"
        return 1
    fi
    rm -f "${merged}"
}

# First-boot provisioning hooks mounted into CK_INIT_D, run in lexical order:
# *.sh via bash (as the container user, whose ~/.local/bin is on PATH; system setup needs `user: root`),
# *.php via `cv scr` (as the web user, for Civi settings / seed data). A failing hook aborts the boot.
ck_run_init_hooks() {
    [[ -d "${CK_INIT_D}" ]] || return 0
    local hook
    for hook in "${CK_INIT_D}"/*; do
        [[ -e "${hook}" ]] || continue
        case "${hook}" in
            *.sh)
                echo "[civikitchen] init hook (bash): ${hook}"
                bash "${hook}"
                ;;
            *.php)
                echo "[civikitchen] init hook (cv scr): ${hook}"
                ck_as_web cv scr "${hook}"
                ;;
            *)
                echo "[civikitchen] init hook skipped (expects *.sh or *.php): ${hook}" >&2
                ;;
        esac
    done
}

# Give root-owned files in the data dirs back to the web user. -xdev: an
# extension bind-mounted below a data dir belongs to the host user.
ck_heal_perms() {
    local dirs
    read -ra dirs <<<"${CK_DATA_DIRS}"
    find "${dirs[@]}" -xdev ! -user "${CK_WEB_USER}" \
        -exec chown -h "${CK_WEB_USER}:${CK_WEB_GROUP}" {} + 2>/dev/null || true
}

# Standalone post-install config, once; the marker is written only on success.
# The test DB comes before the auth steps, which can fail on every boot.
ck_post_install_config() {
    if [[ -f "${CK_CONFIGURED_MARKER}" ]]; then
        echo "[civikitchen] Already configured (${CK_CONFIGURED_MARKER}) — first-boot config knobs (SMTP, test DB, demo user) are not re-applied; remove the marker to re-run."
        return 0
    fi
    ck_dev_settings
    ck_smtp
    ck_setup_test_db
    ck_standalone_auth
    ck_demo_user
    touch "${CK_CONFIGURED_MARKER}"
}

# Keep core's status check from calling civicrm.org: without egress it stalls the
# first admin page by 60 s. Only a boolean FALSE disables the extension feed; newer
# cores take it from settings files only. `cv ext:*` sets its feed under the same
# key before the file runs, so `??=` leaves it in place.
ck_no_phone_home() {
    local line="\$civicrm_setting['Extension Preferences']['ext_repo_url'] ??= FALSE;"
    ck_as_web cv api4 Job.update +w api_action=version_check +v is_active=0 >/dev/null
    if [[ -n "${CK_SETTINGS_D}" ]]; then
        # civibuild requires these files inside a function, hence the global.
        printf '<?php\nglobal $civicrm_setting;\n%s\n' "${line}" \
            | ck_as_web tee "${CK_SETTINGS_D}/150-civikitchen-no-phone-home.php" >/dev/null
    elif [[ -f "${CK_SETTINGS_FILE}" ]]; then
        grep -qxF "${line}" "${CK_SETTINGS_FILE}" \
            || printf '\n%s\n' "${line}" | ck_as_web tee -a "${CK_SETTINGS_FILE}" >/dev/null
    else
        echo "[civikitchen] ERROR: no ${CK_SETTINGS_FILE} and no CK_SETTINGS_D to turn the extension feed off in" >&2
        return 1
    fi
    echo "[civikitchen] civicrm.org calls off (version_check job inactive, ext_repo_url=false)."
}

# Post-install provisioning, once; the marker is written only on success. Profile
# dependencies precede the mounted extensions, profile content follows them.
ck_post_install_provision() {
    if [[ -f "${CK_PROVISIONED_MARKER}" ]]; then
        echo "[civikitchen] Already provisioned (${CK_PROVISIONED_MARKER}) — CIVIKITCHEN_PROFILE / *_EXTENSIONS / init.d changes are not re-applied; remove the marker to re-run."
        return 0
    fi
    # Profiles re-apply idempotently and restore what a new container lost
    # (downloaded extensions, l10n files, credentials); init.d hooks run once per database.
    local seeded
    seeded="$(ck_as_web cv ev "echo \\Civi::settings()->get('${CK_PROVISIONED_SETTING}') ? 'yes' : 'no';")"
    # A seed snapshot restore replaces the whole database: only on a fresh one.
    CK_SEED_FRESH=0
    [[ "${seeded}" == "yes" ]] || CK_SEED_FRESH=1
    # One generation day for every profile of this boot, also across midnight.
    CK_SEED_TODAY="${CK_SEED_TODAY:-$(date +%F)}"
    ck_no_phone_home
    ck_locales
    ck_apply_profile deps
    ck_extra_extensions
    ck_enable_extensions
    ck_apply_profile content
    if [[ "${seeded}" == "yes" ]]; then
        echo "[civikitchen] init.d hooks already ran on this database (setting ${CK_PROVISIONED_SETTING}) — not re-run; re-provision with docker compose down -v."
    else
        ck_run_init_hooks
        ck_as_web cv ev "\\Civi::settings()->set('${CK_PROVISIONED_SETTING}', TRUE);" >/dev/null
    fi
    touch "${CK_PROVISIONED_MARKER}"
}
