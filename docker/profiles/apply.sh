#!/bin/bash
# Shared profile driver: apply a demo profile (extensions + seed data + API
# users) to a site. Runs at FIRST BOOT as the web user, invoked by
# provision.sh's ck_apply_profile when CIVIKITCHEN_PROFILE=<name> is set —
# works on every flavor (standalone, drupal10, drupal11, wordpress, joomla),
# on demo images (embedded DB) and dev images (external DB) alike. Needs network
# and takes a few minutes; it is marker-gated by the caller, so it applies
# exactly once per container.
#
# Entirely driven by profile.json, applied as dependencies -> config -> seed ->
# scripts (or a seed snapshot restore in their place), so every profile shares
# this one script; a profile may ship its own apply.sh to override it. The `deps` phase fetches and enables dependencies,
# `content` applies config, seed, scripts and API users; `all` runs both. Each dependency in profile.json names one of three sources:
#   "repo": <git url>     cloned at "version" into the site's ext dir
#   "registry": true      cv ext:download (packaged release incl. built assets)
#   neither               bundled with core (e.g. flexmailer), enable only
# The full, canonical profile.json shape is packages/civicrm-profile-schema/
# profile.schema.json (draft 2020-12, enforced in CI; published as
# @civico-de/civicrm-profile-schema).
#
#   apply.sh <profile-dir> [all|deps|content]   # e.g. /opt/civikitchen/docker/profiles/verein
set -euo pipefail

# Packaged code replaced by an immutable Git pin remains recoverable until the
# complete profile succeeds. A failed enable/content/script/user step restores the prior
# filesystem state; installed packaged extensions are refused below because a
# database upgrade cannot be rolled back safely by swapping PHP files. A successful
# deps phase hands its backups to the content phase through REPLACED_STATE.
PROFILE_SUCCEEDED=0
declare -a REPLACED_TARGETS=() REPLACED_BACKUPS=()
ck_profile_cleanup() {
    local index target backup
    if [ "${PROFILE_SUCCEEDED}" = 1 ] && [ "${PHASE:-}" = deps ]; then
        for index in "${!REPLACED_TARGETS[@]}"; do
            printf '%s\t%s\n' "${REPLACED_TARGETS[${index}]}" "${REPLACED_BACKUPS[${index}]}" >> "${REPLACED_STATE}"
        done
        return
    fi
    for index in "${!REPLACED_TARGETS[@]}"; do
        target="${REPLACED_TARGETS[${index}]}"
        backup="${REPLACED_BACKUPS[${index}]}"
        if [ "${PROFILE_SUCCEEDED}" = 1 ]; then
            rm -rf "${backup}"
        elif [ -e "${backup}" ]; then
            rm -rf "${target}"
            mv "${backup}" "${target}"
            echo "  restored packaged extension after failed profile: ${target}" >&2
        fi
    done
    [ "${PHASE:-}" = deps ] || rm -f "${REPLACED_STATE:-/nonexistent}"
}
trap ck_profile_cleanup EXIT

PROFILE_DIR="${1:?usage: apply.sh <profile-dir> [all|deps|content]}"
PHASE="${2:-all}"
case "${PHASE}" in
    all|deps|content) ;;
    *) echo "apply.sh: unknown phase '${PHASE}' (all, deps, content)" >&2; exit 1 ;;
esac
PROFILE_NAME="$(basename "${PROFILE_DIR}")"
JSON="${PROFILE_DIR}/profile.json"
CK_PROFILE_CLI="$(command -v ck 2>/dev/null)" || { echo "apply.sh: no ck on PATH" >&2; exit 1; }
# Resolved before anything is fetched or written: a missing script or a retired
# seeds/ directory fails the apply while the site is still untouched.
SCRIPTS="$("${CK_PROFILE_CLI}" internal profile-scripts "${JSON}")"
# civibuild layout if present (demo + buildkit dev images); on the standalone
# dev image cv is on the global PATH and finds the site via env, no cd needed.
SITE_WEB="/home/buildkit/buildkit/build/site/web"
if [ -d "${SITE_WEB}" ]; then
    export PATH="/home/buildkit/buildkit/bin:${PATH}"
    cd "${SITE_WEB}"
fi

# Extension dir (cv-discovered, so this stays CMS-agnostic across the tested
# profile flavors). The DB is up at this point, so cv boots.
EXT_DIR="$(cv ev 'echo rtrim(CRM_Core_Config::singleton()->extensionsDir, "/");')"
[ -n "${EXT_DIR}" ] || { echo "apply.sh: could not resolve extensionsDir" >&2; exit 1; }
mkdir -p "${EXT_DIR}"
REPLACED_STATE="${EXT_DIR}/.ck-profile-${PROFILE_NAME}.replaced"
if [ "${PHASE}" = content ] && [ -f "${REPLACED_STATE}" ]; then
    while IFS=$'\t' read -r target backup; do
        REPLACED_TARGETS+=("${target}")
        REPLACED_BACKUPS+=("${backup}")
    done < "${REPLACED_STATE}"
fi

# The UF (CMS framework) this site runs on — "Standalone", "WordPress",
# "Drupal8", ... A dependency may declare `"skipUf": ["Standalone"]` (values
# compared against CIVICRM_UF verbatim) plus an optional human "skipUfReason";
# it is then neither fetched nor enabled on that framework. This exists
# because an extension can be structurally incompatible with one flavor —
# e.g. remoteevent's `civicrm_session` table DROPs/replaces standaloneusers'
# session storage on Standalone, after which every web request fatals
# (https://github.com/systopia/de.systopia.remoteevent/issues/128).
UF="$(cv ev 'echo CIVICRM_UF;')"

if [ "${PHASE}" != content ]; then
    # Keys of every extension the site can already see, across ALL scanned paths —
    # civibuild bakes some demo extensions under civicrm-core/tools/extensions
    # (e.g. org.civicoop.civirules on drupal10-demo). Cloning a second copy of a
    # known key is fatal (PHP redeclares the module functions), so both fetch
    # steps below skip keys from this list.
    LOCAL_EXTENSIONS="$(cv ev '$c=CRM_Extension_System::singleton()->getFullContainer(); foreach ($c->getKeys() as $k) { echo $k . "\t" . $c->getPath($k) . PHP_EOL; }')"
    LOCAL_KEYS="$(cut -f1 <<<"${LOCAL_EXTENSIONS}")"
    ext_present() { grep -qx "$1" <<<"${LOCAL_KEYS}" || [ -d "${EXT_DIR}/$1" ]; }
    extension_key() {
        "${CK_PROFILE_CLI}" internal extension-key "$1/info.xml" 2>/dev/null || true
    }

    # Generated users receive passwords and API keys, but CiviKitchen does not mint
    # JWTs. Validate the effective merged policy before fetching or replacing code.
    if "${CK_PROFILE_CLI}" internal profile-api-users-present "${JSON}"; then
        AUTHX_POLICY="${CK_AUTHX_HEADER_CRED:-$("${CK_PROFILE_CLI}" internal profile-authx-policy "${JSON}")}"
        if [[ ",${AUTHX_POLICY}," != *,api_key,* && ",${AUTHX_POLICY}," != *,pass,* ]]; then
            echo "apply.sh: API users need an AuthX policy containing api_key or pass; JWT credentials are not generated" >&2
            exit 1
        fi
        if [ "${UF}" = Joomla ] && [[ ",${AUTHX_POLICY}," != *,api_key,* ]]; then
            echo "apply.sh: Joomla API users require api_key authentication" >&2
            exit 1
        fi
    fi
    "${CK_PROFILE_CLI}" internal profile-skipped "${JSON}" "${UF}"

    # Git checkouts ship no vendor/ directory, so a dependency whose composer.json
    # declares runtime requirements would fatal on enable. Resolve before enabling.
    declare -a GIT_SOURCED_TARGETS=()
    COMPOSER_HOME="${COMPOSER_HOME:-/tmp/composer}"
    export COMPOSER_HOME
    ck_resolve_composer() {
        local ext="$1" mode=install name output
        name="$(basename "${ext}")"
        [ -f "${ext}/composer.json" ] || return 0
        [ ! -d "${ext}/vendor" ] || { echo "  ${name} composer dependencies already present"; return 0; }
        # No lock file means `install` has nothing to install; the repositories'
        # pre-update-cmd hook is also what strips civicrm/civicrm-core from require.
        [ -f "${ext}/composer.lock" ] || mode=update
        command -v composer >/dev/null 2>&1 \
          || { echo "  ERROR: composer is required to resolve ${name} but is not on PATH" >&2; exit 1; }
        echo "  composer ${mode} for ${name}"
        if ! output="$(composer "${mode}" --no-dev --no-interaction --no-progress --working-dir="${ext}" 2>&1)"; then
            printf '%s\n' "${output}" >&2
            echo "  ERROR: composer ${mode} failed for ${name}" >&2
            exit 1
        fi
    }

    echo "==> [${PROFILE_NAME}] cloning extensions into ${EXT_DIR}"
    # Tab-separated so URLs/names never collide with the field separator. A failed
    # clone/checkout aborts the apply (loud) — a missing extension must not ship.
    while IFS=$'\t' read -r repo name version; do
        target="${EXT_DIR}/${name}"
        # A civibuild image can expose bundled extensions from another scanned
        # directory. Converge the discovered code in place; silently accepting it
        # would bypass the profile's immutable commit pin.
        if [ ! -d "${target}" ]; then
            discovered="$(awk -F '\t' -v key="${name}" '$1 == key {sub(/^[^\t]*\t/, ""); print; exit}' <<<"${LOCAL_EXTENSIONS}")"
            [ -z "${discovered}" ] || target="${discovered}"
        fi
        GIT_SOURCED_TARGETS+=("${target}")
        if [ -d "${target}" ]; then
            if [ ! -d "${target}/.git" ]; then
                # civibuild may bake a release archive directly into the primary
                # extension directory and then strip every .git directory. A
                # profile with a pinned Git source must still converge that code
                # to the requested commit. Only replace a directory which CiviCRM
                # already recognizes under this exact extension key; arbitrary or
                # half-created directories remain a hard error.
                existing_key="$(extension_key "${target}")"
                if ! grep -qx "${name}" <<<"${LOCAL_KEYS}" || [ "${existing_key}" != "${name}" ]; then
                    echo "  ERROR: ${target} already exists but is not a verifiable git checkout" >&2
                    exit 1
                fi
                if ! db_installed_keys="$(cv ext:list --statuses=installed,disabled --columns=key --out=list 2>/dev/null)"; then
                    echo "  ERROR: could not determine database lifecycle status for ${name}" >&2
                    exit 1
                fi
                if grep -qx "${name}" <<<"${db_installed_keys}"; then
                    echo "  ERROR: refusing to replace database-installed packaged extension ${name}; its lifecycle cannot be rolled back safely" >&2
                    exit 1
                fi
                if ! uninstalled_keys="$(cv ext:list --statuses=uninstalled --columns=key --out=list 2>/dev/null)"; then
                    echo "  ERROR: could not determine uninstalled extension status for ${name}" >&2
                    exit 1
                fi
                if ! grep -qx "${name}" <<<"${uninstalled_keys}"; then
                    echo "  ERROR: refusing to replace ${name} with unknown extension lifecycle status" >&2
                    exit 1
                fi
                echo "  replacing packaged ${name} with pinned commit ${version}"
                temporary="$(mktemp -d "${EXT_DIR}/.${name}.clone.XXXXXX")"
                if ! git clone --quiet "${repo}" "${temporary}" \
                  || ! git -C "${temporary}" checkout --quiet "${version}"; then
                    rm -rf "${temporary}"
                    echo "  ERROR: could not clone ${name} at requested ref ${version}" >&2
                    exit 1
                fi
                if [ "$(extension_key "${temporary}")" != "${name}" ]; then
                    rm -rf "${temporary}"
                    echo "  ERROR: cloned repository does not declare extension key ${name}" >&2
                    exit 1
                fi
                packaged="$(dirname "${target}")/.$(basename "${target}").packaged.$$"
                mv "${target}" "${packaged}"
                if ! mv "${temporary}" "${target}"; then
                    mv "${packaged}" "${target}"
                    rm -rf "${temporary}"
                    echo "  ERROR: could not install pinned ${name}" >&2
                    exit 1
                fi
                REPLACED_TARGETS+=("${target}")
                REPLACED_BACKUPS+=("${packaged}")
                continue
            fi
            expected="$(git -C "${target}" rev-parse --verify "${version}^{commit}" 2>/dev/null || true)"
            actual="$(git -C "${target}" rev-parse --verify HEAD 2>/dev/null || true)"
            if [ -z "${expected}" ] || [ "${actual}" != "${expected}" ]; then
                echo "  ERROR: ${target} exists at ${actual:-unknown}, not requested ref ${version}" >&2
                exit 1
            fi
            echo "  ${name} already present at ${version}"
            continue
        fi
        # A bundled/alternate extension path can satisfy this key without owning
        # EXT_DIR/name. Do not clone a duplicate copy into the primary directory.
        if grep -qx "${name}" <<<"${LOCAL_KEYS}"; then echo "  ${name} already present"; continue; fi
        echo "  cloning ${name} @ ${version}"
        temporary="$(mktemp -d "${EXT_DIR}/.${name}.clone.XXXXXX")"
        # Clone and verify away from the discovery path. If checkout fails, no
        # partial directory can make the next boot believe the dependency exists.
        if ! git clone --quiet "${repo}" "${temporary}" \
          || ! git -C "${temporary}" checkout --quiet "${version}"; then
            rm -rf "${temporary}"
            echo "  ERROR: could not clone ${name} at requested ref ${version}" >&2
            exit 1
        fi
        if [ "$(extension_key "${temporary}")" != "${name}" ]; then
            rm -rf "${temporary}"
            echo "  ERROR: cloned repository does not declare extension key ${name}" >&2
            exit 1
        fi
        mv "${temporary}" "${target}"
    done < <("${CK_PROFILE_CLI}" internal profile-dependencies "${JSON}" "${UF}" repo)

    if [ "${#GIT_SOURCED_TARGETS[@]}" -gt 0 ]; then
        echo "==> [${PROFILE_NAME}] resolving composer dependencies of git-sourced extensions"
        for git_target in "${GIT_SOURCED_TARGETS[@]}"; do
            ck_resolve_composer "${git_target}"
        done
    fi

    echo "==> [${PROFILE_NAME}] downloading registry extensions"
    "${CK_PROFILE_CLI}" internal profile-dependencies "${JSON}" "${UF}" registry \
    | while IFS= read -r name; do
        [ -n "${name}" ] || continue
        if ext_present "${name}"; then echo "  ${name} already present"; continue; fi
        echo "  downloading ${name}"
        # Core bounds the whole archive transfer by http_timeout (5 s default),
        # which a multi-MB release from a slow mirror overruns.
        cv vset http_timeout=120 >/dev/null
        cv ext:download -n --no-install "${name}"
        cv vdel http_timeout >/dev/null
    done

    echo "==> [${PROFILE_NAME}] enabling extensions"
    # One at a time, in profile.json order: a batched Extension.install does not
    # guarantee install order, and extension installers may depend on artifacts
    # (option groups etc.) created by an earlier dependency's installer. Also
    # covers bundled core extensions like flexmailer (no repo, no registry).
    "${CK_PROFILE_CLI}" internal profile-dependencies "${JSON}" "${UF}" enable \
    | while IFS= read -r name; do
        [ -n "${name}" ] || continue
        cv ext:enable "${name}"
    done
fi

# The deps phase stops here; content follows once the caller has enabled the
# extensions it mounts itself.
if [ "${PHASE}" = deps ]; then
    PROFILE_SUCCEEDED=1
    echo "==> [${PROFILE_NAME}] dependencies ready"
    exit 0
fi

# On Joomla, civibuild's install doesn't link the CMS admin to a CiviCRM contact
# (Drupal/WordPress/Standalone do at install time), so cv --user=admin below
# can't resolve a contact. Create the link the way CiviCRM does (idempotent).
if [ "${UF}" = "Joomla" ]; then
    cv scr "$(dirname "$0")/joomla-link-admin.php"
fi

# Content and scripts run as the admin CMS user. civibuild sites always have
# one; the standalone dev image only after an auto-install with a demo admin —
# fail with a hint instead of a cryptic cv error.
if ! cv ev --user=admin 'echo "ok";' >/dev/null 2>&1; then
    echo "apply.sh: no 'admin' user on this site — profiles need one." >&2
    echo "apply.sh: on the standalone dev image set CIVICRM_AUTO_INSTALL=1 and CIVIKITCHEN_DEMO_USER=admin." >&2
    exit 1
fi

# Generators date every record relative to CK_SEED_TODAY and size it by
# CK_SEED_SIZE (docs/seed-snapshots.md); set for every profile, so a script
# draws the same data with or without a snapshot.
CK_SEED_TODAY="$("${CK_PROFILE_CLI}" seed today)"
CK_SEED_SIZE="${CK_SEED_SIZE:-demo}"
export CK_SEED_TODAY CK_SEED_SIZE

# The selected profiles that have a `snapshot` block are generated once and
# stored as one snapshot; later fresh databases restore it with shifted dates
# at the first of them and skip every profile it holds. The restore replaces
# the whole database, so only on one not provisioned yet (CK_SEED_FRESH=1),
# and only on an image with a snapshot store (CK_SEED_SNAPSHOTS=1).
SNAPSHOT=apply
if [ "${CK_SEED_SNAPSHOTS:-0}" = 1 ] && [ "${CK_SEED_FRESH:-0}" = 1 ]; then
    SNAPSHOT=generate
    status=0
    "${CK_PROFILE_CLI}" seed restore "${PROFILE_DIR}" || status=$?
    case "${status}" in
        0) SNAPSHOT=restored; echo "==> [${PROFILE_NAME}] content restored from the seed snapshot" ;;
        3) ;;
        *) echo "  ERROR: seed snapshot restore failed" >&2; exit 1 ;;
    esac
elif "${CK_PROFILE_CLI}" internal profile-has-snapshot "${JSON}"; then
    if [ "${CK_SEED_SNAPSHOTS:-0}" != 1 ]; then
        echo "==> [${PROFILE_NAME}] snapshot: restored on the standalone image only; running the profile on this image"
    else
        echo "==> [${PROFILE_NAME}] snapshot: this database was provisioned before; applying the profile, nothing restored or stored"
    fi
fi

if [ "${SNAPSHOT}" != restored ]; then
    echo "==> [${PROFILE_NAME}] asserting config, creating seed records"
    # config converges on every apply; seed rows are created once, matched by their
    # anchor. A missing prerequisite (component, financial type, group) fails here.
    CK_PROFILE_JSON="${JSON}" cv scr --user=admin "$(dirname "$0")/apply-content.php"

    # Scripts are PHP run with a booted CiviCRM (cv scr) after the declarative part,
    # in profile order, as the admin CMS user — extensions like CiviSEPA re-check
    # permissions in internal API calls. A failed script aborts the apply so the
    # caller writes no provisioning marker and the next boot retries the profile.
    while IFS= read -r script; do
        [ -n "${script}" ] || continue
        echo "==> [${PROFILE_NAME}] script $(basename "${script}")"
        if ! cv scr --user=admin "${script}"; then
            echo "  ERROR: profile script failed: ${script}" >&2
            exit 1
        fi
    done <<<"${SCRIPTS}"
fi

# Stores the set after its last snapshot profile. The site is complete without
# it; a failed save only means the next fresh boot generates again.
if [ "${SNAPSHOT}" = generate ]; then
    "${CK_PROFILE_CLI}" seed save "${PROFILE_DIR}" ||
        echo "  WARNING: seed snapshot not stored; the next fresh boot generates it again" >&2
fi

if "${CK_PROFILE_CLI}" internal profile-api-users-declared "${JSON}"; then
    echo "==> [${PROFILE_NAME}] preparing API users + AuthX"
    # authx powers the api_key / basic-auth the API users rely on. It ships with
    # core but isn't enabled on every CMS build (civibuild's joomla-demo leaves
    # it uninstalled), so enable it here — in its own process, before the script
    # below uses it. Idempotent, and a no-op where it is already on.
    cv ext:enable authx
    # PHP via cv scr: cv boots CiviCRM + the host CMS, so user/role creation
    # uses the native CMS APIs on every flavor (no drush/wp-cli dependency).
    if [ "${CK_DEFER_API_USERS:-0}" != 1 ]; then
        CK_PROFILE_JSON="${JSON}" CK_AUTHX_HEADER_CRED="${CK_AUTHX_HEADER_CRED:-}" \
          cv scr "$(dirname "$0")/configure-api-users.php"
    fi
fi

cv flush
PROFILE_SUCCEEDED=1
echo "==> [${PROFILE_NAME}] profile applied"
