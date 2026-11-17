#!/bin/bash
# Build-time `civibuild create` against a MariaDB in this layer (Dockerfile
# `builder` stage); the demo target ships this database. CIVICRM_BUILD_VERSION
# may be a minor branch (6.15) while CIVICRM_VERSION labels the patch (6.15.4).
set -euxo pipefail

CIVICRM_CREATE_VERSION="${CIVICRM_BUILD_VERSION:-${CIVICRM_VERSION}}"

service mariadb start
for i in $(seq 1 60); do
    mysqladmin ping >/dev/null 2>&1 && break
    [ "$i" -eq 60 ] && { echo "bake.sh: MariaDB never became ready" >&2; exit 1; }
    sleep 1
done
# Keep unix_socket next to the password: Debian's /etc/mysql/debian-start
# connects as root over the socket without one.
mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA unix_socket OR mysql_native_password USING PASSWORD('root'); FLUSH PRIVILEGES;"

# Drupal 11.4+ installs Navigation instead of Toolbar, so buildkit's grant of
# 'access toolbar' fails `civibuild create`; grant whichever exists.
sed -i 's@^  drush8 -y role-add-perm demoadmin "access toolbar"$@  drush8 -y role-add-perm demoadmin "access toolbar" || drush8 -y role-add-perm demoadmin "access navigation" || true@' \
    /home/buildkit/buildkit/src/civibuild.lib.sh
grep -q 'access navigation' /home/buildkit/buildkit/src/civibuild.lib.sh \
    || echo "bake.sh: NOTE: upstream 'access toolbar' line changed; Drupal 11.4 toolbar-perm patch no longer applies (probably fixed upstream)" >&2

# robo 5.2.1-5.2.3 need Symfony Console 7.4 (consolidation/robo#1197); Drupal 10
# ships 6.4, so drush dies. Drop once drush requires a fixed robo.
sed -i 's@^  composer require drush/drush$@&\n  composer require "consolidation/robo:<5.2.1 || >5.2.3"@' \
    "/home/buildkit/buildkit/app/config/drupal10-demo/download.sh"
grep -q 'consolidation/robo' /home/buildkit/buildkit/app/config/drupal10-demo/download.sh \
    || echo "bake.sh: NOTE: upstream drupal10-demo 'composer require drush/drush' line changed; robo 5.2.1-5.2.3 exclusion no longer applies" >&2

# drupal10-demo clones CiviRules and CiviDiscount below vendor/, where their
# assets answer 404; move them into files/civicrm/ext.
sed -i 's@^  cv api extension.refresh local=1 remote=0$@  for ext in org.civicoop.civirules cividiscount; do\n    if [ -d "$CIVI_CORE/tools/extensions/$ext" ]; then mv "$CIVI_CORE/tools/extensions/$ext" files/civicrm/ext/; fi\n  done\n&@' \
    "/home/buildkit/buildkit/app/config/drupal10-demo/install.sh"
grep -q 'for ext in org.civicoop.civirules cividiscount' /home/buildkit/buildkit/app/config/drupal10-demo/install.sh \
    || echo "bake.sh: NOTE: upstream drupal10-demo 'cv api extension.refresh' line changed; the extension move no longer applies" >&2

# civibuild has no drupal11-demo type: install ours over a copy of drupal10-demo,
# unless upstream ships one.
BK_CONFIG=/home/buildkit/buildkit/app/config
if [ ! -d "${BK_CONFIG}/drupal11-demo" ]; then
    cp -r "${BK_CONFIG}/drupal10-demo" "${BK_CONFIG}/drupal11-demo"
    cp /usr/local/share/civikitchen/site-types/drupal11-demo/download.sh \
       /usr/local/share/civikitchen/site-types/drupal11-demo/install.sh \
       "${BK_CONFIG}/drupal11-demo/"
    chown -R buildkit:buildkit "${BK_CONFIG}/drupal11-demo"
fi

# A wrong site type must fail here, not in the network retry loop below.
if [ ! -f "${BK_CONFIG}/${DEFAULT_SITE_TYPE}/download.sh" ]; then
    echo "bake.sh: unknown or incomplete civibuild site type '${DEFAULT_SITE_TYPE}' (missing ${BK_CONFIG}/${DEFAULT_SITE_TYPE}/download.sh)" >&2
    exit 1
fi

# Run civibuild as buildkit. This shell expands the heredoc; escaped \$ expand
# inside the buildkit shell.
su -s /bin/bash buildkit <<BK
set -eo pipefail
export PATH=/home/buildkit/buildkit/bin:\$PATH
printf '[client]\nhost=127.0.0.1\nport=3306\nuser=root\npassword=root\n' > /home/buildkit/.my.cnf
amp config:set --mysql_type=mycnf --httpd_type=none --perm_type=none
if [ '${DEFAULT_SITE_TYPE}' = 'joomla-demo' ]; then
  # Upstream buildkit's joomla-demo currently downloads Joomla to
  # \$WEB_ROOT/web but links CiviCRM into \$WEB_ROOT/joomla. Its install step
  # also calls joomlaxml.php without DM_TMPDIR, which makes CiviCRM 6.15 write
  # XML files below /com_civicrm. Finally, setup.sh can leave the install canary
  # table behind after a successful Joomla install; drop it before snapshotting.
  sed -i 's|pushd "\$WEB_ROOT/joomla"|pushd "\$WEB_ROOT/web"|' /home/buildkit/buildkit/app/config/joomla-demo/download.sh
  sed -i \
    -e 's|cvutil_mkdir "\$TMPDIR/\$SITE_NAME"{,/joomlaxml,/joomlaxml/admin}|cvutil_mkdir "\$TMPDIR/\$SITE_NAME"{,/com_civicrm,/com_civicrm/admin}|' \
    -e 's|php "\$CIVI_CORE/distmaker/utils/joomlaxml.php"|DM_TMPDIR="\$TMPDIR/\$SITE_NAME" php "\$CIVI_CORE/distmaker/utils/joomlaxml.php"|' \
    -e 's|"\$TMPDIR/\$SITE_NAME/joomlaxml/civicrm.xml"|"\$TMPDIR/\$SITE_NAME/com_civicrm/civicrm.xml"|' \
    -e 's|"\$TMPDIR/\$SITE_NAME/joomlaxml/admin/access.xml"|"\$TMPDIR/\$SITE_NAME/com_civicrm/admin/access.xml"|' \
    /home/buildkit/buildkit/app/config/joomla-demo/install.sh
  sed -i '/^civicrm_install$/a\
echo "DROP TABLE IF EXISTS civicrm_install_canary;" | cvutil_php_nodbg amp sql -Ncivi --root="\$CMS_ROOT" || true' \
    /home/buildkit/buildkit/app/config/joomla-demo/install.sh
  sed -i '/pushd "\$WEB_ROOT" >> \/dev\/null/a\
  [ -f web/configuration.php ] && rm -f web/configuration.php\
  [ ! -d web/installation ] && [ -f web/installation.zip ] && unzip -q web/installation.zip -d web' \
    /home/buildkit/buildkit/app/config/joomla-demo/uninstall.sh
fi
# civibuild create downloads the CMS + civicrm-core, including dozens of bundled
# JS assets the composer-downloads-plugin fetches from github.com. A single
# transient 5xx on ANY one of them (after composer's own retries) makes composer
# — and thus the whole multi-arch image build — fail, with civibuild's bash
# then dying noisily ("pop_var_context: ... not a function context"). Retry the
# create so a transient blip doesn't sink the build; the composer cache (cleared only at the
# end of this heredoc) means a retry re-fetches just the asset that failed, not
# the whole tree. </dev/null on both keeps a stray prompt from hanging the
# non-interactive build; destroy resets partial state between attempts, and the
# cache purge below makes a retry actually re-fetch.
CREATE_LOG=/home/buildkit/civibuild-create.log
for attempt in 1 2 3; do
  if civibuild create site --type '${DEFAULT_SITE_TYPE}' --civi-ver '${CIVICRM_CREATE_VERSION}' --url http://localhost --admin-pass admin </dev/null 2>&1 | tee "\$CREATE_LOG"; then
    break
  fi
  # composer failures carry no [[Downloading ...]] line; an empty match must not end the script under pipefail.
  FAILED_HOST=\$(grep -o '\[\[Downloading https\?://[^/]*' "\$CREATE_LOG" | tail -1 | sed 's|.*//||' || true)
  if [ "\$attempt" = 3 ]; then
    echo "bake.sh: civibuild create failed after 3 attempts (last download host: \${FAILED_HOST:-unknown})" >&2
    exit 1
  fi
  echo "bake.sh: civibuild create attempt \$attempt failed (last download host: \${FAILED_HOST:-unknown}); clearing bad downloads + retrying..." >&2
  # extract-url caches by md5(url) and never checks the HTTP status, so a failed
  # attempt leaves an error page where the archive belongs; a retry would reuse it.
  find /home/buildkit/buildkit/app/tmp -maxdepth 1 -type f \\( -name '*.zip' -o -name '*.tar.gz' -o -name '*.tgz' \\) 2>/dev/null | while read -r cached; do
    case "\$cached" in
      *.zip) unzip -tqq "\$cached" >/dev/null 2>&1 && continue ;;
      *) gzip -t "\$cached" >/dev/null 2>&1 && continue ;;
    esac
    echo "bake.sh: dropping unusable download cache \$cached" >&2
    rm -f "\$cached"
  done || true
  civibuild destroy site </dev/null >/dev/null 2>&1 || true
  sleep \$((attempt * 15))
done
# brick/money 0.12+ renamed ISOCurrencyProvider; CiviCRM still references the old
# name. Pin compatible (non-fatal — only matters for some CMS/Civi combos).
{ cd /home/buildkit/buildkit/build/site && [ -f composer.json ] && composer require 'brick/money:<0.12' -W --no-interaction; } || true
if [ '${DEFAULT_SITE_TYPE}' = 'joomla-demo' ]; then
  # civibuild's joomla-demo install is deliberately incomplete (it leaves the
  # Joomla component registration as a commented-out "#fixme" and enables only
  # civi_contribute). Finish it so the demo matches the Drupal/WordPress ones.
  # Same script the dev image re-runs after its first-boot reinstall (see
  # joomla-finish.sh) — kept out of line here so there is one source of truth.
  bash /usr/local/share/civikitchen/joomla-finish.sh
fi
# Drop build-time download caches (composer + npm, ~800MB). The final image
# COPYs ~buildkit wholesale, so clearing them here keeps them out. The runtime
# \`civibuild reinstall\` reuses the baked vendor/, so nothing is re-downloaded.
rm -rf /home/buildkit/.composer/cache /home/buildkit/.npm /home/buildkit/.cache
BK

# Strip the ~550MB of git history civibuild cloned unless KEEP_GIT=1.
if [ "${KEEP_GIT:-0}" != "1" ]; then
    echo "bake.sh: stripping git history from the baked site (--build-arg KEEP_GIT=1 keeps it)"
    # Backdrop's uninstall, run by every `civibuild reinstall`, restores settings.php and
    # files/ with `git checkout`: keep a one-commit repo holding just their pristine state.
    web=/home/buildkit/buildkit/build/site/web
    pristine=/home/buildkit/backdrop-pristine
    if [[ "${DEFAULT_SITE_TYPE}" == backdrop* ]]; then
        su -s /bin/bash buildkit -c "mkdir ${pristine} && git -C ${web} archive HEAD settings.php files | tar -x -C ${pristine}"
    fi
    find /home/buildkit/buildkit/build/site -type d -name .git -prune -exec rm -rf {} +
    if [[ "${DEFAULT_SITE_TYPE}" == backdrop* ]]; then
        su -s /bin/bash buildkit -c "set -e; git init -q ${web}
            git -C ${web} --work-tree=${pristine} add settings.php files
            git -C ${web} -c user.name=civikitchen -c user.email=civikitchen@localhost commit -q -m 'Pristine Backdrop settings'
            rm -rf ${pristine}"
    fi
fi

# The demo ships this /var/lib/mysql, so wait for the server process to exit;
# the socket file can linger and proves nothing.
mysqladmin -uroot -proot shutdown
for i in $(seq 1 60); do
    pgrep -x mariadbd >/dev/null || pgrep -x mysqld >/dev/null || break
    [ "$i" -eq 60 ] && { echo "bake.sh: MariaDB did not shut down cleanly" >&2; exit 1; }
    sleep 1
done
