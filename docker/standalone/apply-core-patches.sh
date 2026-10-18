#!/bin/bash
# Applies the core patches in <patch-dir> to a CiviCRM core tree, in name order.
# A patch the release already contains is skipped; any other mismatch fails the build.
# A `Since: X.Y.Z` header skips a patch on a core older than that version.
#
# Usage: apply-core-patches.sh <core-dir> <patch-dir>
set -euo pipefail

core="${1:?usage: apply-core-patches.sh <core-dir> <patch-dir>}"
patches="${2:?usage: apply-core-patches.sh <core-dir> <patch-dir>}"
[ -d "${core}" ] || { echo "apply-core-patches: no core directory ${core}" >&2; exit 1; }

# The image copies this script and the toolbelt it needs in their checkout layout.
# shellcheck source=../../toolbelt/lib/ckcommon.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/toolbelt/lib/ckcommon.sh"

# Prints a CiviCRM version (6.19.1, 6.20.beta1, 6.21.alpha1) as five sortable
# numbers; a pre-release sorts before the X.Y.0 it leads to.
version_key() {
    local stage
    if [[ "$1" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
        echo "${BASH_REMATCH[1]} ${BASH_REMATCH[2]} ${BASH_REMATCH[3]} 3 0"
    elif [[ "$1" =~ ^([0-9]+)\.([0-9]+)\.(alpha|beta|rc)([0-9]+)$ ]]; then
        case "${BASH_REMATCH[3]}" in alpha) stage=0 ;; beta) stage=1 ;; rc) stage=2 ;; esac
        echo "${BASH_REMATCH[1]} ${BASH_REMATCH[2]} 0 ${stage} ${BASH_REMATCH[4]}"
    else
        return 1
    fi
}

# True when version $1 is older than version $2.
version_lt() {
    local -a a b
    local i
    read -ra a <<<"$(version_key "$1")"
    read -ra b <<<"$(version_key "$2")"
    for i in 0 1 2 3 4; do
        [ "${a[i]}" -lt "${b[i]}" ] && return 0
        [ "${a[i]}" -gt "${b[i]}" ] && return 1
    done
    return 1
}

core_version=""
for file in "${patches}"/*.patch; do
    [ -e "${file}" ] || continue
    name="$(basename "${file}")"
    grep -q '^Upstream: https://' "${file}" \
        || { echo "apply-core-patches: ${name} names no Upstream: link" >&2; exit 1; }
    since="$(sed -n 's/^Since: *//p' "${file}" | head -n 1)"
    if [ -n "${since}" ]; then
        version_key "${since}" >/dev/null \
            || { echo "apply-core-patches: ${name} has an invalid Since: ${since}" >&2; exit 1; }
        if [ -z "${core_version}" ]; then
            core_version="$(ck_xml_field "${core}/xml/version.xml" version_no)" \
                || { echo "apply-core-patches: cannot read ${core}/xml/version.xml" >&2; exit 1; }
            version_key "${core_version}" >/dev/null \
                || { echo "apply-core-patches: no valid <version_no> in ${core}/xml/version.xml" >&2; exit 1; }
        fi
        if version_lt "${core_version}" "${since}"; then
            echo "not-applicable ${name} (core ${core_version} < ${since})"
            continue
        fi
    fi
    if patch -d "${core}" -p1 --forward --dry-run -s < "${file}" >/dev/null 2>&1; then
        patch -d "${core}" -p1 --forward -s < "${file}"
        echo "applied ${name}"
    elif patch -d "${core}" -p1 --reverse --dry-run -s < "${file}" >/dev/null 2>&1; then
        echo "contained ${name}"
    else
        echo "apply-core-patches: ${name} neither applies to nor is contained in ${core}" >&2
        exit 1
    fi
done
