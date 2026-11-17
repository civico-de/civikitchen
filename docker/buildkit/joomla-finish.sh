#!/bin/bash
# Finish civibuild's incomplete joomla-demo install (its "#fixme" leaves the
# Joomla plugins unregistered, so option=com_civicrm 404s). Run by bake.sh and
# after the dev image's reinstall, as buildkit; idempotent, a no-op off Joomla.
set -euo pipefail

export PATH="/home/buildkit/buildkit/bin:${PATH}"

# This script ships in the shared dir (baked in the Dockerfile base stage so it
# is present both at build time and at runtime); its helpers sit alongside it.
SHARE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# The civibuild Joomla web root. Same path during the bake and at runtime.
SITE_WEB="${SITE_WEB:-/home/buildkit/buildkit/build/site/web}"
SITE_ROOT="${SITE_WEB%/web}"

cd "${SITE_WEB}"

# Only a Joomla site; a cv failure must not read as "not Joomla".
if ! UF="$(cv ev 'echo CIVICRM_UF;' 2>&1)"; then
    echo "joomla-finish: ERROR: cv cannot boot the site — cannot tell whether this is Joomla:" >&2
    echo "${UF}" >&2
    exit 1
fi
if [ "${UF}" != "Joomla" ]; then
    echo "joomla-finish: site UF is '${UF}', not Joomla — nothing to do."
    exit 0
fi

echo "==> joomla-finish: completing civibuild's joomla-demo install"

# (a) Register and enable CiviCRM's Joomla plugins. The component install
#     script fails on civibuild's layout; joomla-enable-plugins.php handles that.
php cli/joomla.php extension:discover
cv scr "${SHARE_DIR}/joomla-enable-plugins.php"

# (b) The component extensions the other demo types ship, one at a time and
#     deps first: a batched enable cannot autoload a just-enabled dependency.
for ext in org.civicrm.search_kit org.civicrm.afform civiimport civi_member civi_event civi_case civi_campaign civi_pledge civi_report civi_mail; do
    cv ext:enable "${ext}"
done

# (c) Joomla's unused brick/math 0.8.17 shadows CiviCRM's and breaks Money::of();
#     replace it with CiviCRM's on every run, as a reinstall restores it.
jbm="${SITE_WEB}/libraries/vendor/brick/math"
cbm="${SITE_ROOT}/src/civicrm/admin/civicrm/vendor/brick/math"
if [ -d "${jbm}" ] && [ -d "${cbm}" ]; then
    rm -rf "${jbm}" && cp -a "${cbm}" "${jbm}"
fi

# (d) ckjoomlaidentity: loads the Joomla identity of the authx-authenticated
#     user, so headless requests do not run as guest.
ext_dst="${SITE_WEB}/media/civicrm/ext/ckjoomlaidentity"
mkdir -p "${ext_dst}"
cp -a "${SHARE_DIR}/ext/ckjoomlaidentity/." "${ext_dst}/"
cv ext:enable ckjoomlaidentity

cv flush
echo "==> joomla-finish: done"
