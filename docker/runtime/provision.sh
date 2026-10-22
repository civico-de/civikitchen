#!/bin/bash
# civikitchen shared provisioning library.
#
# Sourced by both entrypoints AFTER their CiviCRM site exists (standalone:
# `cv core:install`; buildkit: `civibuild create site`). It holds the
# CMS-agnostic first-boot provisioning so the standalone and buildkit images
# behave the same: dev settings, SMTP backend, an isolated test DB, no calls
# to civicrm.org, core language files, registry + mounted extensions with
# their <requires> (`ck internal provision-*`), named profiles
# (CIVIKITCHEN_PROFILE), and /civikitchen-init.d hooks.
#
# Caller contract — define this BEFORE calling any ck_* function:
#
#   ck_as_web CMD...   Run CMD as the image's web user, against its CiviCRM
#                      site. cv must auto-detect the site.
#                        standalone: the command itself, runuser from root.
#                        buildkit:   cd into the site docroot, su from root.
#
# The CK_* parameters below default to the standalone image's layout; the
# buildkit entrypoint overrides them before sourcing.

# --- parameters (defaults = standalone layout) -----------------------------
: "${CK_WEB_USER:=www-data}"
: "${CK_WEB_GROUP:=www-data}"
: "${CK_WEB_USER_HOME:=/var/www}"
: "${CK_ROOT_HOME:=/root}"
: "${CK_EXT_DIR:=/var/www/html/ext}"
: "${CK_DATA_DIRS:=/var/www/html/private /var/www/html/public}"
: "${CK_PROVISIONED_MARKER:=/var/www/html/private/.civikitchen-provisioned}"
# init.d hooks seed the database, so their run is marked there: a recreated
# container (private/ gone, database volume kept) must not run them twice.
: "${CK_PROVISIONED_SETTING:=civikitchen_provisioned}"
# Separate marker for the standalone post-install CONFIG bundle (ck_post_install_config:
# dev settings / SMTP / test DB / auth / demo user). Kept distinct from
# CK_PROVISIONED_MARKER so config and provisioning retry independently.
: "${CK_CONFIGURED_MARKER:=/var/www/html/private/.civikitchen-configured}"
: "${CK_INIT_D:=/civikitchen-init.d}"
# ~/.cv.json site key under which TEST_DB_DSN is stored. Standalone keys by its
# bootstrap file; other CMSes key the site differently (resolved per image).
: "${CK_TEST_DB_CV_KEY:=/var/www/html/civicrm.standalone.php}"
# Boot stub patched by ck_wire_test_db_boot so CIVICRM_UF=UnitTests boots define
# the test DSN before core's env-based DSN composition (see
# patch-test-db-boot.php). Empty or missing file = skip (buildkit flavors
# boot through their CMS, not a stub).
: "${CK_BOOT_STUB:=/var/www/html/civicrm.standalone.php}"
# Where named profiles (docker/profiles/<name>/) ship inside the image.
: "${CK_PROFILE_DIR:=/opt/civikitchen/docker/profiles}"
# Optional civibuild-style settings.d dir (loaded into civicrm.settings.php).
# Only the buildkit entrypoint sets this; see ck_smtp.
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

# Attach the scenario's flavor-neutral source mount to the extension directory
# discovered/configured by the current image. This keeps generated scenarios
# portable across Standalone, Drupal, WordPress and Joomla layouts without
# teaching the generator each CMS's internal paths.
# CIVIKITCHEN_EXTENSION_DIR names the link (default: the key); ckboot uses
# info.xml's <file>, where the compose stacks mount the extension.
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

# Dev-mode defaults — there's no reason a dev image would want them off.
ck_dev_settings() {
    ck_as_web cv setting:set environment=Development >/dev/null
    ck_as_web cv setting:set debug_enabled=1 >/dev/null
    echo "[civikitchen] Dev settings applied (environment=Development, debug_enabled=1)."
}

# Point Civi's mail backend at an SMTP host (e.g. the maildev sidecar).
# Without this, Civi defaults to PHP mail() and outbound mail goes nowhere.
ck_smtp() {
    [[ -n "${CIVIKITCHEN_SMTP_HOST}" ]] || return 0
    local smtp_port="${CIVIKITCHEN_SMTP_PORT:-1025}"
    echo "[civikitchen] Configuring mail backend → ${CIVIKITCHEN_SMTP_HOST}:${smtp_port}..."
    ck_as_web env SMTP_HOST="${CIVIKITCHEN_SMTP_HOST}" SMTP_PORT="${smtp_port}" \
        cv ev '\Civi::settings()->set("mailing_backend", ["outBound_option" => 0, "smtpServer" => getenv("SMTP_HOST"), "smtpPort" => (int) getenv("SMTP_PORT"), "smtpAuth" => 0, "smtpUsername" => "", "smtpPassword" => ""]);'
    # buildkit only: its app/civicrm.settings.d/100-mail.php cannot see the
    # DB-level setting above (it only checks the $civicrm_setting override)
    # and so defines CIVICRM_MAIL_LOG=/dev/null — send() returns TRUE while
    # every outbound mail silently vanishes. With an explicit SMTP host there
    # is nothing left for that heuristic to decide: drop it.
    # NOT `[[ ... ]] && rm`: as the function's last statement it returns 1
    # when CK_SETTINGS_D is empty (standalone), and the entrypoints run set -e.
    if [[ -n "${CK_SETTINGS_D}" ]]; then
        rm -f "${CK_SETTINGS_D}/100-mail.php"
    fi
}

# Enable standaloneusers — the Standalone auth provider. It backs the
# /civicrm/login route and supplies the \Civi\Api4\User entity. `cv core:install`
# does NOT reliably install it across CiviCRM versions (it was absent on
# standalone-6.12), which leaves the login route with no auth provider behind it.
# Enable it explicitly so every Standalone boot has a working auth provider —
# independent of CIVIKITCHEN_DEMO_USER, since a site without a demo user still
# needs the provider. Idempotent: standaloneusers ships bundled with civicrm-core,
# so this just enables the already-present extension. Standalone-only — buildkit
# flavors authenticate through their CMS (Drupal/WordPress/Joomla) and must NOT
# call this (their entrypoints don't).
ck_standalone_auth() {
    echo "[civikitchen] Ensuring standaloneusers (Standalone auth provider) is enabled..."
    if ! ck_as_web cv ext:enable standaloneusers; then
        echo "[civikitchen] ERROR: could not enable standaloneusers (Standalone auth provider)." >&2
        return 1
    fi
}

# Demo login user. Opt-in via CIVIKITCHEN_DEMO_USER. Needs the standaloneusers
# \Civi\Api4\User entity, which ck_standalone_auth enables (it runs first in the
# standalone config bundle, ck_post_install_config). Standalone-only: buildkit flavors get their CMS login
# from civibuild and must NOT call this (their entrypoints don't).
ck_demo_user() {
    [[ -n "${CIVIKITCHEN_DEMO_USER}" ]] || return 0
    local demo_user="${CIVIKITCHEN_DEMO_USER}"
    local demo_pass="${CIVIKITCHEN_DEMO_PASS:-admin}"
    local demo_email="${CIVIKITCHEN_DEMO_EMAIL:-admin@example.org}"
    echo "[civikitchen] Creating demo user '${demo_user}'..."
    # Pass env explicitly via `env` rather than relying on preserve-environment.
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

# With binary logging on (mysql:8.0 default) a user without SUPER may not
# create triggers or functions, and `cv core:install` creates both. Trusting
# non-SUPER creators is a server setting, so it is set as root. Best effort:
# MariaDB and binlog-less servers need nothing, and root may be unreachable.
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
    # `_` is a GRANT wildcard even inside backticks — escape it so the grant
    # covers <db>_test and nothing else.
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
    # An EMPTY test DB is unusable: the headless harness boots CiviCRM against
    # TEST_DB_DSN before \Civi\Test can (re)build any schema, and that boot dies
    # on a schema-less database. civibuild does the same main→test copy.
    echo "[civikitchen] Seeding ${test_db_name} from ${CIVICRM_DB_NAME}..."
    # pipefail in a subshell: without it a failing mysqldump feeds mysql empty
    # input, the pipeline exits 0, and the marker gets written over a
    # schema-less test DB.
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
    # cv merges ~/.cv.json into $GLOBALS['_CV'], keyed by the site bootstrap
    # path; civicrm.settings.php reads _CV['TEST_DB_DSN'] under
    # CIVICRM_UF=UnitTests. Write it for the web user, and for root when the
    # container started as root. Don't clobber an existing ~/.cv.json (a project may have set its own).
    # JSON-escape the DSN (the DB password may contain \ or ") and write both
    # files from this shell — interpolating the payload into a `bash -c` string would
    # let a password containing quotes execute as shell.
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

# Run `ck internal provision-OPERATION` as the web user, pinned by the mounted
# extensions and installing staged releases from CK_DEP_ARCHIVE_DIR.
ck_provision() {
    local operation="$1" cli ext_dir
    local -a mounted=()
    shift
    cli="$(ck_runtime_cli)" || return 1
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

# Directories bind-mounted directly into the ext dir, one per line — the
# extensions a developer put there, as opposed to downloaded ones. Read from
# the mount table (field 5 is the mount point), so nothing has to be declared;
# the extension ck_attach_scenario_extension linked in counts as mounted too.
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

# Core language files. Opt-in via CIVIKITCHEN_LOCALES=de_DE[,fr_FR,...]: the
# version-matching civicrm-<version>-l10n.tar.gz is streamed once and only
# the requested locales land in [civicrm.l10n], the directory core reads
# translations from; an extension's own l10n/ catalogue translates without
# them. CIVIKITCHEN_DEFAULT_LOCALE=<locale> additionally
# sets lcMessages; it has to be one of the installed locales.
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
    # Streamed, not saved: the archive carries every locale and only a few MB
    # of it are wanted. tar exits non-zero for a member that is not in the
    # archive, so an unknown locale fails here instead of silently leaving
    # English behind.
    if ! (set -o pipefail; curl -fsSL "${archive}" | tar -xz -C "${unpack}" "${missing[@]/#/${prefix}/}"); then
        rm -rf "${unpack}"
        echo "[civikitchen] ERROR: could not fetch ${missing[*]} from ${archive}" >&2
        return 1
    fi
    mkdir -p "${l10n_dir}"
    cp -R "${unpack}/${prefix}/." "${l10n_dir}/"
    rm -rf "${unpack}"
    chown -R "${CK_WEB_USER}:${CK_WEB_GROUP}" "${l10n_dir}"
}

# Resolve one profile name across explicit external roots plus the bundled root.
# Ambiguity is an error rather than an implicit override: selecting `verein`
# must identify the same scenario on every host regardless of mount order.
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

# External profiles can carry a root/profile apply.sh and script PHP. Schema
# validation proves their data shape; it is not a code sandbox. Requiring an
# explicit trust decision prevents a read-only mount from being mistaken for a
# data-only security boundary.
ck_require_profile_trust() {
    local dir="$1"
    ck_profile_is_bundled "${dir}" && return 0
    if [[ "${CIVIKITCHEN_TRUST_EXTERNAL_PROFILES:-0}" != "1" ]]; then
        echo "[civikitchen] ERROR: external profile ${dir} is executable code; set CIVIKITCHEN_TRUST_EXTERNAL_PROFILES=1 only for a fully trusted root." >&2
        return 1
    fi
}

# One instance has one global AuthX header policy. Missing policy is the AuthX
# upstream-safe default (JWT + API key), never password. Multiple selected
# profiles must agree before the first one mutates the site.
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

# Apply named profiles (extensions + config + seed + scripts + API users) at first boot.
# Opt-in via CIVIKITCHEN_PROFILE=<name>[,<name>...] — a comma-separated list
# is applied left to right, each profile after its `include`s; profiles ship in CK_PROFILE_DIR (see
# docker/profiles/). Combining works because every layer converges instead of
# colliding: apply.sh skips extensions the site already has, config converges,
# seed records are created once per anchor, and configure-api-users.php upserts users/roles
# (merging role permissions), so a name shared across profiles ends up with
# the union. Needs network (git clones) and can take several minutes.
# Runs inside ck_post_install_provision, so it is marker-gated: it applies
# once, and a failure aborts the boot (no marker) and re-runs on next start.
# Phase `deps` fetches and enables profile dependencies; `content` (config, seed,
# scripts, API users) follows once the mounted extensions are enabled. A custom
# apply.sh runs once, in the content phase.
ck_apply_profile() {
    local phase="$1"
    [[ -n "${CIVIKITCHEN_PROFILE:-}" ]] || return 0
    local name dir root apply want_cms authx_policy merged
    local -a names resolved phase_arg
    IFS=',' read -ra names <<< "${CIVIKITCHEN_PROFILE}"
    # Expand and validate the whole list before applying anything — a typo in
    # the last name must not leave a half-provisioned site behind the failed boot.
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
        # An external root can share its own driver; otherwise use the bundled
        # driver. A profile-local apply.sh is the narrowest override.
        apply="${CK_PROFILE_DIR}/apply.sh"
        phase_arg=("${phase}")
        [[ -f "${root}/apply.sh" ]] && apply="${root}/apply.sh"
        [[ -f "${dir}/apply.sh" ]] && apply="${dir}/apply.sh"
        # CMS gate: profile.json declares the CMS family it needs (e.g.
        # "drupal10"); match it as a prefix of the civibuild site type
        # (drupal10-demo, ...).
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
        # Pass CK_CREDENTIALS_FILE through explicitly (like ck_smtp/ck_demo_user
        # above) so a profile's configure-api-users.php + scripts resolve it
        # deterministically rather than relying on runuser's preserve-env. Empty
        # when unset -> the script's `?:` falls back to $HOME/api-credentials.txt
        # (no change for profiles that don't set it).
        # CK_SEED_*: what a seed snapshot is keyed on and may restore over
        # (docker/profiles/apply.sh, docs/seed-snapshots.md).
        # CK_DEP_ARCHIVE_DIR and CK_SEED_EXTENSION_DIRS: the staged releases and the pins a dependency's <requires> take.
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
    # Configure identities once from the complete selected set. This turns
    # same-role declarations into an exact union and lets the CMS adapters
    # remove stale CiviKitchen-owned permissions/users safely.
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

# Heal root-owned files in the CiviCRM data dirs that root-run steps may have
# left behind — the web workers can't write them otherwise. Cheap no-op when
# ownership is already correct. -h: change symlinks, never their targets.
# -xdev: an extension bind-mounted below a data dir (the buildkit flavors put
# the CMS ext dir inside the site tree) belongs to the host user, and chowning
# it to the web user locks that user — a CI runner — out of its own checkout.
ck_heal_perms() {
    # shellcheck disable=SC2086 # CK_DATA_DIRS is a space-separated path list.
    find ${CK_DATA_DIRS} -xdev ! -user "${CK_WEB_USER}" \
        -exec chown -h "${CK_WEB_USER}:${CK_WEB_GROUP}" {} + 2>/dev/null || true
}

# Marker-gated STANDALONE post-install CONFIG bundle, run once: the steps that
# must follow `cv core:install` — dev settings, SMTP backend, isolated test DB,
# the Standalone auth provider, and an optional demo user. The marker is written
# only on success, so a step that hard-fails (the standaloneusers enable or the
# demo-user creation) exits the boot loudly AND re-runs the WHOLE — idempotent —
# sequence on the next start, instead of being silently skipped because the
# settings file already exists.
#
# ck_setup_test_db is ordered BEFORE the auth/demo-user steps deliberately: those
# can hard-fail (return 1 under the entrypoint's set -e), and the test DB must be
# created and seeded regardless, even if a demo-user step fails on every boot.
#
# Standalone-only — buildkit gets its demo user + isolated test DB from civibuild
# and calls ck_smtp directly, so it must NOT call this bundle (its entrypoint
# doesn't).
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

# Keep core's status check from calling civicrm.org (version pingback,
# extension feed): without egress it stalls the first admin page by 60 s.
# Only a boolean FALSE disables the feed; `cv ext:download` sets its own.
ck_no_phone_home() {
    ck_as_web cv api4 Job.update +w api_action=version_check +v is_active=0 >/dev/null
    echo '{"ext_repo_url":false}' | ck_as_web cv setting:set --in=json >/dev/null
    echo "[civikitchen] civicrm.org calls off (version_check job inactive, ext_repo_url=false)."
}

# Marker-gated post-install provisioning bundle: civicrm.org calls off, core
# locales, profile, registry + mounted extensions, and init.d hooks, run once.
# The marker is written only on success so a failed step re-runs on the next
# start instead of being silently skipped. Profile dependencies come first, so the
# mounted extension can rely on them; profile content follows the mounted
# extension, so its seed and scripts can use it; init.d hooks run last.
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
