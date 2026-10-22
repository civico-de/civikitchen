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
#   "registry": true      cv ext:download (packaged release incl. built assets); its <requires>
#                         take the mounted extensions' pins and CK_DEP_ARCHIVE_DIR's staged releases
#   neither               bundled with core (e.g. flexmailer), enable only
# `ck internal provision-profile` fetches and enables them.
# The full, canonical profile.json shape is packages/civicrm-profile-schema/
# profile.schema.json (draft 2020-12, enforced in CI; published as
# @civico-de/civicrm-profile-schema).
#
#   apply.sh <profile-dir> [all|deps|content]   # e.g. /opt/civikitchen/docker/profiles/verein
set -euo pipefail

# provision-profile appends each packaged extension it swaps for a Git pin to REPLACED_STATE (target<TAB>backup).
# A failure restores the entries past REPLACED_KEPT; success keeps them for content in deps, else discards them.
PROFILE_SUCCEEDED=0
PHASE=''
REPLACED_STATE=''
REPLACED_KEPT=0
ck_profile_cleanup() {
    local target backup line=0
    [ -f "${REPLACED_STATE}" ] || return 0
    if [ "${PROFILE_SUCCEEDED}" = 1 ] && [ "${PHASE}" = deps ]; then
        return 0
    fi
    while IFS=$'\t' read -r target backup; do
        line=$((line + 1))
        if [ "${PROFILE_SUCCEEDED}" = 1 ]; then
            rm -rf "${backup}"
        elif [ "${line}" -gt "${REPLACED_KEPT}" ] && [ -e "${backup}" ]; then
            rm -rf "${target}"
            mv "${backup}" "${target}"
            echo "  restored packaged extension after failed profile: ${target}" >&2
        fi
    done < "${REPLACED_STATE}"
    # A failed deps run hands the earlier runs' swaps on to content.
    if [ "${PHASE}" = deps ] && [ "${REPLACED_KEPT}" -gt 0 ]; then
        head -n "${REPLACED_KEPT}" "${REPLACED_STATE}" > "${REPLACED_STATE}.kept"
        mv "${REPLACED_STATE}.kept" "${REPLACED_STATE}"
    else
        rm -f "${REPLACED_STATE}"
    fi
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
# An earlier deps run's entries are not this run's to restore, unless this run is the content they wait for.
if [ "${PHASE}" != content ] && [ -f "${REPLACED_STATE}" ]; then
    REPLACED_KEPT=$(($(wc -l < "${REPLACED_STATE}")))
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

    # Clone, composer, registry download, then the ordered enable; a registry
    # dependency's <requires> take the pins of the extensions mounted into the site.
    declare -a MOUNTED=()
    while IFS= read -r mounted_dir; do
        [ -z "${mounted_dir}" ] || MOUNTED+=(--mounted "${mounted_dir}")
    done <<<"${CK_SEED_EXTENSION_DIRS:-}"
    "${CK_PROFILE_CLI}" internal provision-profile --ext-dir "${EXT_DIR}" --archive-dir "${CK_DEP_ARCHIVE_DIR:-}" \
        "${MOUNTED[@]}" --replaced-state "${REPLACED_STATE}" "${JSON}" "${UF}"
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
