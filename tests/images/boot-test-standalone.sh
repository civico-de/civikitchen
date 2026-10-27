#!/bin/bash
# Boot test for the standalone dev image: the real first-boot path against an
# an external MySQL-compatible database, with the provisioning knobs a
# developer stack relies on.
# It asserts what the fast (fake-cv) suites under tests/toolbelt cannot:
#   * an extension bind-mounted into the ext dir is enabled without being
#     named in CIVIKITCHEN_ENABLE_EXTENSIONS, its <requires> in place;
#   * CIVIKITCHEN_LOCALES installs the core catalogue and gettext really
#     initialises — ts() renders German, which is the whole point of the knob;
#   * CIVIKITCHEN_DEFAULT_LOCALE installs the site in that language, so the
#     seed data labels are German while their machine names stay English;
#   * an external profile is schema-validated and applied, with generated API
#     credentials written mode 0600 and never disclosed in default logs;
#   * a profile's config and seed are written before its scripts run, and a
#     second apply changes nothing;
#   * the admin status check makes no calls to civicrm.org (version_check job
#     inactive, ext_repo_url false);
#   * word replacements reach ts(), which the standalone boot order breaks
#     without the core patch.
#   * cklifecycle's settings-metadata check names every malformed
#     pseudoconstant and passes valid ones, on a site that loads options;
#   * cklifecycle accepts managed records core keeps by cleanup policy;
#   * cksmarty compiles managed MessageTemplate bodies that mix Civi tokens
#     with Smarty, and still fails a broken one;
#   * the phpstan field check accepts every live get and create field on the
#     catalog's minor release.
#   * execute() and civicrm_api4() without an index return array rows, the
#     type ckanalyze gives them; getInfo() and addJoin() match their patches.
# The db service gets a plain MYSQL_USER and no grant script: the app user
# holds rights on its own database only, exactly as a hand-written stack.
#
# Usage:
#   bash tests/images/boot-test-standalone.sh <image>
#   CK_PROVISION_OVERRIDE=docker/runtime/provision.sh bash tests/images/boot-test-standalone.sh ghcr.io/civico-de/civikitchen:standalone
#   CK_DATABASE_IMAGE=mysql:8.0 bash tests/images/boot-test-standalone.sh ghcr.io/civico-de/civikitchen:standalone
# CK_PROVISION_OVERRIDE mounts a checkout's provision.sh over the image's, with this checkout's PHP
# library and profile driver it calls — for running the test against a published image before it is rebuilt.
set -euo pipefail
. "$(dirname "$0")/lib.sh"

IMAGE="${1:?usage: boot-test-standalone.sh <image>}"
DATABASE_IMAGE="${CK_DATABASE_IMAGE:-mariadb:11.4}"
SRC="$(cd "$(dirname "$0")/../.." && pwd)"
# The fixture is staged, not mounted from the checkout: the headless suite
# needs the TEMPLATE bootstrap next to it, and nothing may be written into
# tests/images/fixtures.
# Inside the checkout, never $TMPDIR: on macOS the Docker VM sees only $HOME.
FIXTURE="${SRC}/.cache/boot-fixture-$$"
mkdir -p "${FIXTURE}"
cp -R "$(dirname "$0")/fixtures/ckbootfixture/." "${FIXTURE}/"
mkdir -p "${FIXTURE}/tests/phpunit"
cp "${SRC}/scaffold/template/extension/tests/phpunit/bootstrap.php" \
   "${SRC}/scaffold/template/extension/tests/phpunit/ckHeadless.php" "${FIXTURE}/tests/phpunit/"
mv "${FIXTURE}/headless/CkHeadlessContractTest.php" "${FIXTURE}/tests/phpunit/"
rmdir "${FIXTURE}/headless"
sed "s/__EXTKEY__/ckbootfixture/g" "${SRC}/scaffold/template/extension/phpunit.xml.dist" \
   > "${FIXTURE}/phpunit.xml.dist"
PROFILE_FIXTURE="$(cd "$(dirname "$0")/fixtures/external-profile" && pwd)"
LIFECYCLE_FIXTURE="$(cd "$(dirname "$0")/fixtures/lifecycle" && pwd)"
INITD_FIXTURE="$(cd "$(dirname "$0")/fixtures/init.d" && pwd)"

RAW="$(echo "${IMAGE}-${DATABASE_IMAGE}" | tr -c 'a-z0-9' '-')"
SLUG="satest-$(echo "${RAW}" | cut -c1-32)$(echo "${RAW}" | cksum | cut -d' ' -f1)"
NET="${SLUG}-net"
DB="${SLUG}-db"
APP="${SLUG}-app"
HEALTH_TIMEOUT=600   # install + the ~100 MB l10n stream

cleanup() {
    docker rm -fv "${APP}" "${DB}" >/dev/null 2>&1 || true
    docker network rm "${NET}" >/dev/null 2>&1 || true
    if [ -n "${FIXTURE:-}" ]; then rm -rf "${FIXTURE}"; fi
}
trap cleanup EXIT

extra=()
if [ -n "${CK_PROVISION_OVERRIDE:-}" ]; then
    extra+=(-v "$(cd "$(dirname "${CK_PROVISION_OVERRIDE}")" && pwd)/$(basename "${CK_PROVISION_OVERRIDE}"):/usr/local/share/civikitchen/provision.sh:ro"
        -v "${SRC}/toolbelt/lib/php:/opt/civikitchen/toolbelt/lib/php:ro"
        -v "${SRC}/docker/profiles/apply.sh:/opt/civikitchen/docker/profiles/apply.sh:ro")
    echo "==> provision.sh overridden from ${CK_PROVISION_OVERRIDE}"
fi

echo "==> boot-test (standalone) ${IMAGE} database=${DATABASE_IMAGE}"
docker network create "${NET}" >/dev/null
docker run -d --name "${DB}" --network "${NET}" \
    -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=civicrm \
    -e MYSQL_USER=civicrm -e MYSQL_PASSWORD=civicrm \
    "${DATABASE_IMAGE}" >/dev/null
docker run -d --name "${APP}" --network "${NET}" \
    -e CIVICRM_AUTO_INSTALL=1 \
    -e CIVICRM_DB_HOST="${DB}" \
    -e CIVIKITCHEN_SITE_URL=http://localhost \
    -e CIVIKITCHEN_LOCALES=de_DE \
    -e CIVIKITCHEN_DEFAULT_LOCALE=de_DE \
    -e CIVIKITCHEN_DEMO_USER=admin \
    -e CIVIKITCHEN_PROFILE=minimal,content,composed \
    -e CIVIKITCHEN_PROFILE_PATH=/civikitchen-external-profiles \
    -e CIVIKITCHEN_TRUST_EXTERNAL_PROFILES=1 \
    -v "${FIXTURE}:/var/www/html/ext/ckbootfixture:ro" \
    -v "${PROFILE_FIXTURE}:/civikitchen-external-profiles:ro" \
    -v "${LIFECYCLE_FIXTURE}:/civikitchen-lifecycle-fixtures:ro" \
    -v "${INITD_FIXTURE}:/civikitchen-init.d:ro" \
    "${extra[@]}" \
    "${IMAGE}" >/dev/null

echo "==> waiting for healthy (install + provisioning)..."
wait_healthy "${APP}" "${HEALTH_TIMEOUT}" || exit 1

cv() { docker exec -u www-data "${APP}" cv "$@" 2>/dev/null; }

# 1) Site up.
code=$(docker exec "${APP}" curl -s -o /dev/null -w '%{http_code}' -L http://localhost/ 2>/dev/null || echo 000)
check "site serves HTTP 200 (got ${code})" "[ '${code}' = '200' ]"

db_version=$(docker exec "${DB}" sh -c 'mariadb -uroot -proot -Nse "SELECT CONCAT(@@version, \" \", @@version_comment)" 2>/dev/null || mysql -uroot -proot -Nse "SELECT CONCAT(@@version, \" \", @@version_comment)"' 2>/dev/null || true)
check "database responds (${db_version:-unknown})" "[ -n '${db_version}' ]"

# 1b) The phpstan field check accepts every live get and create field: a
# rejected one is a false unknownField report. The label carries the script's
# verdict, so a row on another release shows "skipped", not a silent pass.
docker cp "${SRC}/toolbelt/phpstan/tools/live-api4-drift.php" "${APP}:/tmp/" >/dev/null
docker cp "${SRC}/toolbelt/phpstan/src" "${APP}:/tmp/ck-phpstan-src" >/dev/null
drift_rc=0
drift=$(docker exec -u www-data -e CK_PHPSTAN_SRC=/tmp/ck-phpstan-src "${APP}" \
    cv scr /tmp/live-api4-drift.php 2>&1) || drift_rc=$?
while IFS= read -r line; do echo "    ${line}"; done <<<"${drift}"
verdict=$(grep '^live-api4-drift: ' <<<"${drift}" | tail -1 | sed 's/^live-api4-drift: //' || true)
check "APIv4 catalog vs live getFields: ${verdict:-no verdict}" "[ ${drift_rc} = 0 ] && [ -n \"\${verdict}\" ]"

# 1c) The row type ckanalyze gives execute() and civicrm_api4() without an
# index: the Result holds arrays keyed by field name.
rows=$(cv ev '$rows = array_merge((array) \Civi\Api4\FinancialType::get(FALSE)->execute(), (array) civicrm_api4("FinancialType", "get", ["checkPermissions" => FALSE])); echo count(array_filter($rows, fn($row) => is_array($row) && array_filter(array_keys($row), "is_int") === [])) . "/" . count($rows);' | tr -d '[:space:]"' || true)
check "execute() and civicrm_api4() return field maps (field maps/rows: ${rows:-none})" "[ '${rows%/*}' -gt 0 ] && [ '${rows%/*}' = '${rows#*/}' ]"

# 1d) The patched types: getInfo() is a string-keyed map with `type` as a list,
# and addJoin() takes NULL or a condition array as $bridge.
info=$(cv ev '$info = \Civi\Api4\Contact::getInfo(); $join = fn($bridge) => \Civi\Api4\Contact::get(FALSE)->addSelect("id")->addJoin("Email AS e", "LEFT", $bridge, ...($bridge === NULL ? [["id", "=", "e.contact_id"]] : []))->setLimit(1)->execute()->count(); echo (array_filter(array_keys($info), "is_int") === [] && is_array($info["type"]) ? "map" : "other") . "/" . $join(NULL) . "/" . $join(["id", "=", "e.contact_id"]);' | tr -d '[:space:]"' || true)
check "getInfo() and addJoin() match the patched types (${info:-none})" "[ '${info}' = 'map/1/1' ]"

# 2) The bind-mounted extension is enabled without CIVIKITCHEN_ENABLE_EXTENSIONS.
status=$(cv api4 Extension.get +w key=ckbootfixture +s status | tr -d '[:space:]' || true)
check "mounted extension enabled by the mount alone (${status:-absent})" "echo '${status}' | grep -q '\"installed\"'"

# 3) Core catalogue in place where core reads it, and the default locale set.
l10n=$(cv path -d '[civicrm.l10n]' | tr -d '[:space:]' || true)
check "de_DE catalogue under ${l10n:-?}" \
    "docker exec '${APP}' test -s '${l10n}/de_DE/LC_MESSAGES/civicrm.mo'"
lc=$(cv setting:get lcMessages --out=json-strict | tr -d '[:space:]' || true)
check "lcMessages is de_DE" "echo '${lc}' | grep -q '\"value\":\"de_DE\"'"

# 4) gettext really initialised: without the core .mo this prints "Contacts".
word=$(cv ev 'echo ts("Contacts");' | tr -d '[:space:]"' || true)
check "ts(\"Contacts\") renders German (got '${word}')" "[ '${word}' = 'Kontakte' ]"

# 4b) Installed in the default locale: an English install leaves "Donation".
donation=$(cv api4 FinancialType.get +w name=Donation +s label --out=json-strict | tr -d '[:space:]' || true)
check "financial type Donation is labelled Spende (got ${donation:-absent})" "echo '${donation}' | grep -q '\"label\":\"Spende\"'"

# 5) The compatibility leg includes the scratch DB contract, not only install.
# A green site with an empty civicrm_test DB would still wipe developer data on
# the first headless suite, so boot CiviCRM through UnitTests and read from it.
test_db=$(docker exec -e CIVICRM_UF=UnitTests -u www-data "${APP}" \
    cv ev 'CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS ck_test_db_canary (id INT PRIMARY KEY)"); echo CRM_Core_DAO::singleValueQuery("SELECT DATABASE()");' \
    2>/dev/null | tr -d '[:space:]"' || true)
check "UnitTests process is connected to civicrm_test (got ${test_db:-absent})" "[ '${test_db}' = 'civicrm_test' ]"
main_canary=$(docker exec "${DB}" sh -c 'mariadb -uroot -proot civicrm -Nse "SHOW TABLES LIKE '\''ck_test_db_canary'\''" 2>/dev/null || mysql -uroot -proot civicrm -Nse "SHOW TABLES LIKE '\''ck_test_db_canary'\''"' 2>/dev/null || true)
check "test DB canary is absent from the main civicrm database" "[ -z '${main_canary}' ]"

# 6) External profile resolution and the default secret-handling contract.
check "external profile was applied" "docker logs '${APP}' 2>&1 | grep -F '[minimal] profile applied' >/dev/null"
check "credential secrets absent from default logs" "! docker logs '${APP}' 2>&1 | grep 'API User Credentials' >/dev/null"
check "credential file exists" "docker exec '${APP}' test -s /var/www/api-credentials.txt"
cred_mode=$(docker exec "${APP}" stat -c '%a' /var/www/api-credentials.txt 2>/dev/null || true)
check "credential file mode is 0600 (got ${cred_mode:-absent})" "[ '${cred_mode}' = '600' ]"
cred_line=$(docker exec "${APP}" sed -n '1p' /var/www/api-credentials.txt 2>/dev/null || true)
check "external profile generated random password + API key" "echo '${cred_line}' | grep -Eq '^smokeapi:[0-9a-f]{48}:[0-9a-f]{32}$'"
check "password is not derived from username" "! echo '${cred_line}' | grep -q '^smokeapi:smokeapi:'"

# 6b) Declarative content: config + seed land before the profile's scripts,
# and a re-apply leaves every record alone.
check "content profile was applied" "docker logs '${APP}' 2>&1 | grep -F '[content] profile applied' >/dev/null"
order=$(docker logs "${APP}" 2>&1 | grep -E '\[content\] (asserting config|script 10-after-seed)|seed: 2 contact|content fixture script saw' | cut -c1-40 | tr '\n' '|')
check "content: config + seed before the script (got ${order})" "echo '${order}' | grep -Eq '^==> \[content\] asserting config.*\|  seed: 2 contact.*\|==> \[content\] script 10-after-seed.*\|  content fixture script saw'"
# 6b') include: the bundled core-only fundraising data loads before the profile
# that includes it, without any extension.
order=$(docker logs "${APP}" 2>&1 | grep -E '^==> \[(fundraising-data|composed)\] profile applied' | tr '\n' '|')
check "include: fundraising-data applied before composed (got ${order})" "[ '${order}' = '==> [fundraising-data] profile applied|==> [composed] profile applied|' ]"
pledged=$(cv ev 'echo \Civi\Api4\Pledge::get(FALSE)->selectRowCount()->execute()->count() > 0 && \Civi\Api4\Campaign::get(FALSE)->selectRowCount()->execute()->count() > 0 ? "yes" : "no";' | tr -d '"' || true)
check "include: core-only pledges and campaigns seeded (got ${pledged:-absent})" "[ '${pledged}' = yes ]"
check "content: a profile script sees the mounted extension enabled" "docker logs '${APP}' 2>&1 | grep -F 'content fixture script saw the mounted extension' >/dev/null"
content=$(cv ev 'echo implode("/", [\Civi\Api4\Contact::get(FALSE)->addWhere("external_identifier", "=", "acme-supplies")->addWhere("contact_type", "=", "Organization")->execute()->count(), \Civi\Api4\Contribution::get(FALSE)->addWhere("trxn_id", "=", "profile-seed-ada-2026")->execute()->count(), \Civi\Api4\MembershipType::get(FALSE)->addWhere("name", "=", "acme_annual")->addWhere("fixed_period_start_day", "=", 101)->execute()->count(), \Civi\Api4\Domain::get(FALSE)->addWhere("id", "=", CRM_Core_Config::domainID())->execute()->single()["name"]]);' | tr -d '"' || true)
check "content: organization seed, contribution, fixed membership type, domain renamed (got ${content:-absent})" "[ '${content}' = '1/1/1/Acme Association' ]"
reapply=$(docker exec -u www-data "${APP}" bash /opt/civikitchen/docker/profiles/apply.sh /civikitchen-external-profiles/content 2>&1 | grep -E '^  (config|seed):' | tr '\n' '|' || true)
check "content: a second apply changes nothing (got ${reapply:-absent})" "[ '${reapply}' = '  config: unchanged|  seed: 0 contact(s), 0 contribution(s) created|' ]"

# 6c) A *.sh init hook runs as the web user; the tool it installs into
# ~/.local/bin is on PATH for later hooks and for docker exec.
check "init.d: a later hook finds the installed tool" "docker logs '${APP}' 2>&1 | grep -F 'init.d hook found: ckinittool-ok' >/dev/null"
tool=$(docker exec -u www-data "${APP}" ckinittool 2>&1 || true)
check "init.d: docker exec finds the installed tool (got ${tool:-absent})" "[ '${tool}' = 'ckinittool-ok' ]"

# 7) No calls to civicrm.org from the admin status check.
vc=$(cv api4 Job.get +w api_action=version_check +s is_active --out=json-strict | tr -d '[:space:]' || true)
check "version_check job inactive (got ${vc:-absent})" "echo '${vc}' | grep -q '\"is_active\":false'"
repo=$(cv vget ext_repo_url --out=json-strict | tr -d '[:space:]' || true)
check "ext_repo_url is boolean false (got ${repo:-absent})" "echo '${repo}' | grep -q '\"value\":false'"

# 8) The managed headless bootstrap, twice against the SAME scratch database.
# Signing the environment drops the core foreign keys, and a warm apply()
# returns before re-adding them — only the second run shows it.
docker exec -u www-data "${APP}" bash -c 'cktestreset' >/dev/null 2>&1 || true
for round in 1 2; do
    if docker exec -u www-data -w /var/www/html/ext/ckbootfixture "${APP}" \
        ckphpunit tests/phpunit/CkHeadlessContractTest.php > "${FIXTURE}/phpunit-${round}.log" 2>&1; then
        echo "  ✓ ck_headless contract holds on the ${round}. run"
    else
        echo "  ✗ ck_headless contract fails on the ${round}. run"
        tail -30 "${FIXTURE}/phpunit-${round}.log"
        fail=1
    fi
done

# 9) Word replacements reach ts() (core patch i18n-boot-replacements). The boot
# caches the en_US list before the DSN is known, so only an en_US site shows it.
cv api4 Setting.set +v lcMessages=en_US >/dev/null || true
cv api4 WordReplacement.create +v find_word="CiviKitchen probe" +v replace_word="probe replaced" +v match_type=exactMatch >/dev/null || true
replaced=$(cv ev 'echo ts("CiviKitchen probe");' | tr -d '"' || true)
check "word replacement applies on an en_US site (got '${replaced}')" "[ '${replaced}' = 'probe replaced' ]"
check "core patch log lists the patch" \
    "docker exec '${APP}' grep -Eq '^(applied|contained) i18n-boot-replacements.patch$' /usr/local/share/civikitchen/core-patches.log"

# 10) cklifecycle's settings-metadata check and managed cleanup policies. Copied
# in, not bind-mounted, so the malformed fixture is not enabled while the steps
# above run.
docker exec "${APP}" bash -c 'cp -R /civikitchen-lifecycle-fixtures/. /var/www/html/ext/ && chown -R www-data: /var/www/html/ext/cksettings* /var/www/html/ext/ckmanagedkept /var/www/html/ext/ckmsgtpl*'
cv ext:enable cksettingsgood cksettingsbad ckmanagedkept ckmsgtplgood ckmsgtplbad >/dev/null || true
lifecycle() { docker exec -u www-data -w "/var/www/html/ext/$1" "${APP}" cklifecycle > "${FIXTURE}/lifecycle-$1.log" 2>&1; }
check "cklifecycle passes valid pseudoconstants" "lifecycle cksettingsgood"
check "cklifecycle fails malformed pseudoconstants" "! lifecycle cksettingsbad"
check "cklifecycle passes records kept by cleanup never" "lifecycle ckmanagedkept"
for name in cksettingsbad_snake cksettingsbad_table cksettingsbad_callback; do
    check "the settings check names ${name}" "grep -q 'FAILED: ${name}:' '${FIXTURE}/lifecycle-cksettingsbad.log'"
done
[ "${fail}" = 0 ] || tail -20 "${FIXTURE}"/lifecycle-*.log

# 11) cksmarty on managed MessageTemplate bodies: core strips Civi tokens
# before Smarty compiles, so a token is no Smarty error, an unclosed {if} is.
smarty() { docker exec -u www-data -w "/var/www/html/ext/$1" "${APP}" cksmarty > "${FIXTURE}/smarty-$1.log" 2>&1; }
check "cksmarty passes a body with Civi tokens" "smarty ckmsgtplgood"
check "cksmarty fails an unclosed {if}" "! smarty ckmsgtplbad"
check "cksmarty names the broken body" "grep -q 'MessageTemplate MessageTemplate_ckmsgtplbad (msg_html)' '${FIXTURE}/smarty-ckmsgtplbad.log'"
check "cksmarty passes the token-only subject" "! grep -q 'ckmsgtplbad (msg_subject)' '${FIXTURE}/smarty-ckmsgtplbad.log'"
[ "${fail}" = 0 ] || tail -20 "${FIXTURE}"/smarty-*.log

# 12) A profile set with neither API users nor an AuthX policy, like the empty
# dev profile, leaves the site's AuthX header policy as it is. Same calls as
# provision.sh: resolve the policy, merge the set, configure from the merge.
authx_get='echo json_encode(Civi::settings()->get("authx_header_cred"));'
authx_before=$(cv ev "${authx_get}" || true)
docker exec -u www-data "${APP}" sh -c '
    printf "{\"description\":\"no users\",\"dependencies\":[]}\n" > /tmp/ck-no-users.json
    policy=$(ck internal profile-authx-policy /tmp/ck-no-users.json --sort)
    ck internal profiles-merge /tmp/ck-no-users-merged.json "${policy}" /tmp/ck-no-users.json
    CK_PROFILE_JSON=/tmp/ck-no-users-merged.json CK_AUTHX_HEADER_CRED="${policy}" CK_RECONCILE_API_USERS=1 \
        cv scr /opt/civikitchen/docker/profiles/configure-api-users.php' > "${FIXTURE}/no-users.log" 2>&1 || true
authx_after=$(cv ev "${authx_get}" || true)
check "a profile without API users keeps authx_header_cred (${authx_before:-?} -> ${authx_after:-?})" \
    "[ -n '${authx_before}' ] && [ '${authx_before}' != '[]' ] && [ '${authx_before}' = '${authx_after}' ]"

verdict "${IMAGE} on ${DATABASE_IMAGE}"
