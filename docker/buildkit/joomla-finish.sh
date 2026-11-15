#!/bin/bash
# Finish civicrm-buildkit's deliberately-incomplete joomla-demo install.
#
# civibuild's joomla-demo install.sh ends with a commented-out
# "#fixme joomla extension:install . civicrm": it downloads + links CiviCRM but
# leaves the Joomla component/plugins unregistered and enables only the
# civi_contribute component extension. Left like that, CiviCRM-on-Joomla has no
# HTTP request hook — the `option=com_civicrm` route 404s, so the admin UI and
# the api_key API are dead — and the demo profiles' APIv4 entities are missing.
# This script completes the install so Joomla matches the Drupal/WordPress demos.
#
# It runs in TWO places (hence its own script — single source of truth):
#   - bake.sh (builder stage), right after `civibuild create`: bakes the
#     finished state into the demo image's embedded DB.
#   - entrypoint.sh (dev image), right after the first-boot `civibuild
#     reinstall`: the dev image rebuilds the site from civibuild's incomplete
#     install on every first boot and discards the baked DB, so the finish must
#     re-run there or the dev `:joomla` image ships half-broken.
#
# Fully idempotent and Joomla-guarded (a no-op on every other flavor), so it is
# safe to call unconditionally and to re-run. Must run as the buildkit user
# (the site owner); cv relies on the joomla-cli-bootstrap.php settings.d shim.
set -euo pipefail

export PATH="/home/buildkit/buildkit/bin:${PATH}"

# This script ships in the shared dir (baked in the Dockerfile base stage so it
# is present both at build time and at runtime); its helpers sit alongside it.
SHARE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# The civibuild Joomla web root. Same path during the bake and at runtime.
SITE_WEB="${SITE_WEB:-/home/buildkit/buildkit/build/site/web}"
SITE_ROOT="${SITE_WEB%/web}"

cd "${SITE_WEB}"

# Joomla guard: only finish a Joomla site. cv boots here (the DB is up), so ask
# CiviCRM directly — CMS-agnostic, and a clean no-op on drupal*/wp/standalone.
# A cv failure must NOT read as "not Joomla": on a real Joomla site that would
# silently ship a dev image with a dead option=com_civicrm route.
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

# (a) Register + enable CiviCRM's Joomla plugins (civibuild's #fixme step): this
#     gives CiviCRM its HTTP request hook, so the option=com_civicrm route (and
#     thus the api_key API) works. The com_civicrm *component* install script
#     fails on civibuild's path layout (harmless — the component is registered
#     by then); joomla-enable-plugins.php catches that and forces the rows
#     enabled (state=0, so Joomla's dispatcher routes option=com_civicrm).
php cli/joomla.php extension:discover
cv scr "${SHARE_DIR}/joomla-enable-plugins.php"

# (b) Enable the standard component extensions the Drupal/WP demo types ship
#     (joomla-demo ships only civi_contribute) so the profiles' APIv4 entities
#     (MembershipType, …) exist, plus civiimport, which core requires and the
#     import routes need. One at a time, deps first (afform needs search_kit;
#     civi_case and civiimport need afform) — a batched enable can't autoload a
#     just-enabled dependency in the same process. ext:enable is idempotent.
for ext in org.civicrm.search_kit org.civicrm.afform civiimport civi_member civi_event civi_case civi_campaign civi_pledge civi_report civi_mail; do
    cv ext:enable "${ext}"
done

# (c) Joomla bundles an ancient brick/math (0.8.17) that nothing in Joomla uses
#     but which shadows CiviCRM's at runtime (both autoload in one process,
#     Joomla's wins) — breaking every CiviCRM Money operation (Money::of() type
#     error → contributions, membership fees, SEPA seeds). Align Joomla's copy
#     to CiviCRM's bundled version. Re-applied on every run because `civibuild
#     reinstall` can restore Joomla's original copy.
jbm="${SITE_WEB}/libraries/vendor/brick/math"
cbm="${SITE_ROOT}/src/civicrm/admin/civicrm/vendor/brick/math"
if [ -d "${jbm}" ] && [ -d "${cbm}" ]; then
    rm -rf "${jbm}" && cp -a "${cbm}" "${jbm}"
fi

# (d) Ship + enable the ckjoomlaidentity extension. Copied from the shipped
#     source (not the site tree) so this stays self-contained even after a
#     reinstall recreated the site's ext dir. On Joomla it loads the Joomla
#     identity matching the authx-authenticated CiviCRM user, so permission-
#     checked operations work on headless requests (api_key writes, cv --user
#     seeds) — which cv/authx otherwise leave running as guest. ext:enable
#     auto-enables its hard authx dependency.
ext_dst="${SITE_WEB}/media/civicrm/ext/ckjoomlaidentity"
mkdir -p "${ext_dst}"
cp -a "${SHARE_DIR}/ext/ckjoomlaidentity/." "${ext_dst}/"
cv ext:enable ckjoomlaidentity

# (e) Keep PHP warnings out of the pages, as the Drupal and WordPress demos do. On a
#     localhost URL core's Smarty raises error_reporting to E_ALL, so only display_errors
#     hides them; Joomla's 'simple'/'maximum' still turn it on.
printf 'php_flag display_errors off\n' > "${SITE_WEB}/.htaccess"

cv flush
echo "==> joomla-finish: done"
