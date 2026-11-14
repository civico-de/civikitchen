#!/bin/bash

## install.sh -- Create config files and databases; fill the databases
##
## CiviKitchen's drupal11-demo civibuild site type — drupal10-demo's install
## recipe with the two changes Drupal 11.4 requires (marked D11 below):
##
##  1. Drupal 11.4 moved the Standard profile's content types into core
##     recipes (https://www.drupal.org/node/3520751): `site-install standard`
##     no longer creates the "Basic page" node type + body field that
##     install-welcome.php builds the demo front page from. Apply the
##     core/recipes/page_content_type recipe the way core intends.
##  2. Drupal 11.4's Standard profile installs Navigation instead of Toolbar,
##     so the 'access toolbar' permission may not exist — and `drush rap` is
##     all-or-nothing, so it must not share a grant with the CiviCRM perms.
##
## Retire this dir when upstream buildkit ships its own drupal11-demo.
##
## The body below intentionally stays byte-close to upstream drupal10-demo so
## the two can be diffed. civibuild sources this file inside its own harness:
## the harness aborts on failed steps (bare pushd/popd, SC2164), and the
## CIVI_*/GENCODE_* assignments are read by the sourcing civibuild functions,
## not here (SC2034). The unquoted $CIVI_CORE mv is upstream-verbatim (SC2086).
# shellcheck disable=SC2164,SC2034,SC2086

[ -d "$WEB_ROOT/web" ] && CMS_ROOT="$WEB_ROOT/web"

###############################################################################
## Create virtual-host and databases

amp_install

###############################################################################
## Setup Drupal (config files, database tables)

drupal8_install
DRUPAL_SITE_DIR=$(_drupal_multisite_dir "$CMS_URL" "$SITE_ID")
pushd "${CMS_ROOT}/sites/${DRUPAL_SITE_DIR}" >> /dev/null
  drush8 -y updatedb

  ## (D11) Standard no longer ships the "Basic page" content type on 11.4+ —
  ## apply the core recipe that provides it (node.type.page + body field),
  ## guarded so a pre-11.4 site (where the profile still created it) skips.
  ## Applied programmatically via the Recipe API: drush 13.7 has no `recipe`
  ## command, and core's CLI entry point is mid-rename in 11.4 (scripts/drupal
  ## deprecated in favour of scripts/dr) — the API is the stable surface, and
  ## drush ev runs it inside the site's own bootstrap (multisite-safe).
  if ! drush8 config:get node.type.page >/dev/null 2>&1; then
    drush8 ev "\$recipe = \Drupal\Core\Recipe\Recipe::createFromDirectory('${CMS_ROOT}/core/recipes/page_content_type'); \Drupal\Core\Recipe\RecipeRunner::processRecipe(\$recipe); print 'applied page_content_type recipe';"
    drush8 -y cr
  fi
popd >> /dev/null

###############################################################################
## Setup CiviCRM (config files, database tables)

CIVI_DOMAIN_NAME="Demonstrators Anonymous"
CIVI_DOMAIN_EMAIL="info@example.org"
CIVI_CORE="${WEB_ROOT}/vendor/civicrm/civicrm-core"
CIVI_UF="Drupal8"
GENCODE_CONFIG_TEMPLATE="${CMS_ROOT}/modules/contrib/civicrm/civicrm.config.php.drupal"

pushd "${CMS_ROOT}/sites/${DRUPAL_SITE_DIR}" >> /dev/null
  civicrm_install_cv
popd >> /dev/null

###############################################################################
## Extra configuration
pushd "${CMS_ROOT}/sites/${DRUPAL_SITE_DIR}" >> /dev/null

  ## make sure drush functions are loaded
  drush8 cc drush -y
  drush8 en -y civicrmtheme
  drush8 config-import -y --partial --source="$SITE_CONFIG_DIR/config/"

  ## Setup CiviCRM
  if cv ev 'exit(version_compare(CRM_Utils_System::version(), "5.47.alpha", "<") ?0:1);' ; then
    echo '{"enable_components":["CiviEvent","CiviContribute","CiviMember","CiviMail","CiviReport","CiviPledge","CiviCase","CiviCampaign","CiviGrant"]}' \
      | cv api --in=json setting.create
  else
    echo '{"enable_components":["CiviEvent","CiviContribute","CiviMember","CiviMail","CiviReport","CiviPledge","CiviCase","CiviCampaign"]}' \
      | cv api --in=json setting.create
  fi
  civicrm_apply_demo_defaults
  cv ev 'return CRM_Utils_System::synchronizeUsers();'

  ## Show errors on screen
  drush8 -y config:set system.logging error_level verbose

  ## Setup demo user
  civicrm_apply_d8_perm_defaults
  drush8 -y user-create --password="$DEMO_PASS" --mail="$DEMO_EMAIL" "$DEMO_USER"
  drush8 -y user-add-role demoadmin "$DEMO_USER"
  ## (D11) The admin-bar permission differs by core version (Toolbar <= 11.3,
  ## Navigation >= 11.4) and drush rap dies on the first unknown permission —
  ## grant it separately and tolerantly, keep the CiviCRM grants hard-fail.
  drush8 -y rap demoadmin 'access navigation' || drush8 -y rap demoadmin 'access toolbar' || true
  drush8 -y rap demoadmin 'administer CiviCase,access all cases and activities,access my cases and activities,add cases,delete in CiviCase,administer CiviCampaign,manage campaign,reserve campaign contacts,release campaign contacts,interview campaign contacts,gotv campaign contacts,sign CiviCRM Petition,access CiviGrant,edit grants, delete in CiviGrant, administer CiviDiscount'

  ## Setup userprotect
  drush8 -y en userprotect
  drush8 -y rmp authenticated 'userprotect.account.edit,userprotect.mail.edit,userprotect.pass.edit'

  drush8 -y scr "$SITE_CONFIG_DIR/install-welcome.php"

  # Move extensions into web accessible areas
  if [ -d "$CIVI_CORE/tools/extensions/org.civicrm.contactlayout" ]; then
    mv $CIVI_CORE/tools/extensions/org.civicrm.contactlayout files/civicrm/ext
  fi
  # vendor/ is not served either: without the move their JS and CSS answer 404.
  for ext in org.civicoop.civirules cividiscount; do
    if [ -d "$CIVI_CORE/tools/extensions/$ext" ]; then mv "$CIVI_CORE/tools/extensions/$ext" files/civicrm/ext/; fi
  done
  cv api extension.refresh local=1 remote=0

  ## Demo sites always disable email and often disable cron
  cv api StatusPreference.create ignore_severity=critical name=checkOutboundMail
  cv api StatusPreference.create ignore_severity=critical name=checkLastCron

  export SITE_CONFIG_DIR
  ## Install theme and blocks
  drush8 scr "$SITE_CONFIG_DIR/install-theme.php"

  ## Setup CiviCRM dashboards
  INSTALL_DASHBOARD_USERS="$ADMIN_USER;$DEMO_USER" drush8 scr "$SITE_CONFIG_DIR/install-dashboard.php"

popd >> /dev/null
