#!/bin/bash
# Functional test for civikitchen dev images.
#
# Exercises every bundled dev tool against real input — not just --version
# checks. Run inside a built dev image, e.g.:
#
#   docker run --rm -v "$(pwd)/tests/images:/civikitchen-test" \
#       ghcr.io/civico-de/civikitchen:standalone \
#       bash /civikitchen-test/test-dev-tools.sh
#
# Or via the workflow's smoke-test job (see .github/workflows/build-dev-images.yml).
#
# What it checks:
#   1. Every binary responds to --version
#   2. the toolbelt ships no phpcs; mago does its jobs
#   3. ck scenario parses a canonical civikitchen.yaml
#   3b. cklint's mago stage runs the CiviKitchen extension rules and its tests
#   3c. ckmodernize boots rector and previews a CiviCRM footgun rewrite
#   3d. ckrelease builds a dist zip and rejects a mismatched tag
#   3g. ckcompat rejects syntax, APIs, constants and enum cases the declared PHP
#       floor lacks, not core's polyfills
#   4. phpstan actually analyses a sample file (intentionally with type errors)
#   4c. ckanalyze runs mago analyze against core as a shadow gate
#   5. phpunit actually runs a passing assertion
#   5b. ckphpunit injects the transaction canary listener, after CiviTestListener
#   5c. cklifecycle's guards (the cycle itself needs a booted site)
#   5d. ckmutate: red on a surviving mutant with a floor, no-op without one
#   5e. cktaint: twelve source/sink fixture pairs — the flow is reported, the
#       escaped twin stays silent
#   6. composer can install a real package from packagist
#   7. civix can render help (signals the phar boots)
#   8. Xdebug toggle: php -m has no xdebug by default; setting XDEBUG_MODE
#      via the entrypoint enables it
#   9. The scaffold template, as ckinit stamps it, passes cklint and ckfmt
#   9b. The Playwright starter's config and tests pass ckeslint and ckfmt
set -euo pipefail

PASS=0
FAIL=0
FAILURES=()

ok()   { echo "  ✓ $1"; PASS=$((PASS+1)); }
fail() { echo "  ✗ $1"; FAIL=$((FAIL+1)); FAILURES+=("$1"); }

# ---------------------------------------------------------------------------
# The image runs as its web user; Docker lets that user bind port 80.
echo "== image user =="
if [ "$(id -u)" != 0 ]; then ok "runs as $(id -un), not root"; else fail "the image runs as root"; fi
if php -r 'exit(stream_socket_server("tcp://0.0.0.0:80") ? 0 : 1);' 2>/dev/null; then
    ok "the web user may bind port 80"
else
    fail "the web user cannot bind port 80"
fi

# ---------------------------------------------------------------------------
# 1. Binaries respond to --version
echo "== versions =="
for bin in composer composer-dependency-analyser node npm civix phpunit phpstan cv infection; do
    if command -v "${bin}" >/dev/null 2>&1 && "${bin}" --version >/dev/null 2>&1; then
        ok "${bin} --version"
    else
        fail "${bin} --version"
    fi
done

# Every toolbelt tool is on PATH as a link into /opt/civikitchen/toolbelt, the
# standalone-only helpers only there, and the deprecated v1 paths resolve.
TOOLBELT_BIN=/opt/civikitchen/toolbelt/bin
for expected in cktestreset ckcoretest ckboot; do
    if [ "${CIVICRM_UF:-}" = "Standalone" ] && [ ! -x "${TOOLBELT_BIN}/${expected}" ]; then
        fail "${expected} ships on standalone"
    elif [ "${CIVICRM_UF:-}" != "Standalone" ] && [ -e "${TOOLBELT_BIN}/${expected}" ]; then
        fail "${expected} is standalone-only"
    fi
done
for tool in "${TOOLBELT_BIN}"/ck*; do
    name="${tool##*/}"
    if [ "$(readlink -f "$(command -v "${name}" || true)")" = "$(readlink -f "${tool}")" ]; then
        ok "${name} on PATH"
    else
        fail "${name} on PATH resolves to ${tool}"
    fi
done
for old in ckconform/bin/ckconform rector/rector.php \
    phpstan/vendor/autoload.php phpstan-ext/composer.json phpstan-config/civicrm-disallowed.neon \
    psalm/psalm-taint.xml.dist oxlint/.oxlintrc.json oxfmt/oxfmtrc.json mago/mago.toml \
    composer-deps.php; do
    if [ -f "/opt/civikitchen-${old}" ]; then ok "deprecated /opt/civikitchen-${old}"; else fail "deprecated /opt/civikitchen-${old}"; fi
done
# A profile a stack mounts below the v1 directory has to land where provisioning looks.
if [ "$(readlink -f /usr/local/share/civikitchen/profiles)" = /opt/civikitchen/docker/profiles ]; then
    ok "deprecated /usr/local/share/civikitchen/profiles"
else
    fail "deprecated /usr/local/share/civikitchen/profiles resolves to /opt/civikitchen/docker/profiles"
fi

# The unified commands depend on files copied outside /usr/local/bin. Exercise
# them in-image so a missing schema/implementation layer cannot pass the host
# dispatcher tests and then ship broken.
# Each check keeps what the command printed and shows it on failure — a
# one-off miss on a CI runner is undiagnosable from "✗" alone.
ck_out=$(ck help 2>&1) || true
if grep -q 'ck scenario' <<<"${ck_out}"; then ok "ck dispatcher"; else fail "ck dispatcher"; echo "      ${ck_out}"; fi
ck_out=$(ck profile validate /opt/civikitchen/docker/profiles/verein 2>&1) && ck_rc=0 || ck_rc=$?
if [ "${ck_rc}" -eq 0 ]; then
    ok "ck profile validates a bundled profile"
else
    fail "ck profile validates a bundled profile"
    echo "      exit ${ck_rc}: ${ck_out}"
fi
ck_out=$(ck scenario --help 2>&1) && ck_rc=0 || ck_rc=$?
if grep -q 'validate|plan|compose' <<<"${ck_out}"; then
    ok "ck scenario implementation boots"
else
    fail "ck scenario implementation boots"
    echo "      exit ${ck_rc}: ${ck_out}"
fi

# ---------------------------------------------------------------------------
# 1b. Standalone base parity. The image stopped building FROM civicrm/civicrm
#     and mirrors its base itself; these pin the parity points a consumer
#     would notice — PHP extensions, gd's WebP support, the ini values, and
#     the boot stub patch-test-db-boot.php anchors on.
if [ "${CIVICRM_UF:-}" = "Standalone" ]; then
    echo "== standalone base parity =="
    PHP_MODULES="$(php -m)"
    for ext in imagick soap opcache gd intl mysqli pdo_mysql zip bcmath pcov; do
        name="${ext}"
        [ "${ext}" = opcache ] && name="Zend OPcache"
        if grep -qix -- "${name}" <<<"${PHP_MODULES}"; then
            ok "php extension ${ext} loaded"
        else
            fail "php extension ${ext} loaded"
        fi
    done
    if php -r 'exit(empty(gd_info()["WebP Support"]) ? 1 : 0);'; then
        ok "gd built with WebP support"
    else
        fail "gd built with WebP support"
    fi
    if [ "$(php -r 'echo ini_get("upload_max_filesize"), " ", ini_get("max_input_vars");')" = "64M 10000" ]; then
        ok "civicrm.ini values apply (upload_max_filesize, max_input_vars)"
    else
        fail "civicrm.ini values apply — got '$(php -r 'echo ini_get("upload_max_filesize"), " ", ini_get("max_input_vars");')'"
    fi
    if grep -q 'bootSettings' /var/www/html/civicrm.standalone.php; then
        ok "civicrm.standalone.php carries the bootSettings anchor (patch-test-db-boot)"
    else
        fail "civicrm.standalone.php carries the bootSettings anchor (patch-test-db-boot)"
    fi
    if [ -s /var/www/html/core/sql/test_data.mysql ] \
        && [ -s /var/www/html/core/sql/test_data_second_domain.mysql ]; then
        ok "core headless-test seed SQL is present"
    else
        fail "core headless-test seed SQL is missing from the standalone release archive"
    fi
fi

# ---------------------------------------------------------------------------
# 2. No phpcs in the toolbelt: cklint and ckcompat run on mago. buildkit's own
#    clone keeps its phpcs, which no ck tool calls.
echo "== no phpcs =="
if [ -e /opt/composer/vendor/bin/phpcs ]; then fail "the toolbelt still installs phpcs"; else ok "the toolbelt installs no phpcs"; fi

# ---------------------------------------------------------------------------
# 3. ck scenario parses a canonical civikitchen.yaml
echo "== ck scenario =="
WORKDIR="$(mktemp -d)"
mkdir -p "${WORKDIR}/scenario/extension"
cat > "${WORKDIR}/scenario/extension/info.xml" <<'XML'
<extension key="org.example.scenario" type="module"><file>scenario</file></extension>
XML
cat > "${WORKDIR}/scenario/civikitchen.yaml" <<'YAML'
version: 1
scenario:
  name: image-yaml-smoke
  image: ghcr.io/civico-de/civikitchen:v1
  database:
    image: mariadb:11.4
  extension:
    key: org.example.scenario
    path: extension
YAML
if (cd "${WORKDIR}/scenario" && ck scenario validate >/dev/null && ck scenario plan >/dev/null); then
    ok "ck scenario parses canonical civikitchen.yaml in-image"
else
    fail "ck scenario could not parse canonical civikitchen.yaml in-image"
fi
# ---------------------------------------------------------------------------
# 3b. cklint runs the CiviKitchen mago extension rules through the baseline
echo "== CiviKitchen rules + cklint =="
cat > "${WORKDIR}/Legacy.php" <<'PHP'
<?php
function myext_civicrm_managed(&$entities) {
  $entities = [];
}
function f() {
  CRM_Core_Error::debug_log_message(ts('hello'));
  return civicrm_api3('Contact', 'get', []);
}
PHP

if CKLINT_HELP="$(cklint --help 2>&1)" && grep -q "uncommitted git changes" <<<"${CKLINT_HELP}"; then
    ok "cklint --help"
else
    fail "cklint --help"
fi

# Explicit-path mode without a project config: the mago baseline's extension
# host runs the CiviKitchen rules next to mago's own strict-types.
LEGACYDIR="${WORKDIR}/legacyext"
mkdir -p "${LEGACYDIR}"
cp "${WORKDIR}/Legacy.php" "${LEGACYDIR}/"
(cd "${LEGACYDIR}" && git init -q . && git add -A) >/dev/null 2>&1
CKLINT_OUT="$( (cd "${LEGACYDIR}" && cklint Legacy.php) 2>&1 || true)"
for code in strict-types civikitchen/use-extension-ts civikitchen/use-mixins-for-standard-hooks; do
    if grep -q "${code}" <<<"${CKLINT_OUT}"; then
        ok "cklint mago stage flags ${code}"
    else
        fail "cklint mago stage didn't flag ${code} (output: ${CKLINT_OUT:0:300})"
    fi
done

# The rules find info.xml from the repo cklint runs in (CK_MAGO_WORKSPACE), not
# from mago's config directory: with the prefix known, a helper is no hook.
INFODIR="${WORKDIR}/infoext"
mkdir -p "${INFODIR}"
printf '<?xml version="1.0"?>\n<extension key="org.example.widget" type="module">\n  <file>widget</file>\n</extension>\n' > "${INFODIR}/info.xml"
cat > "${INFODIR}/widget.php" <<'PHP'
<?php

declare(strict_types = 1);

function widget_civicrm_managed(array &$entities): void {
}

function helper_civicrm_managed(array &$entities): void {
}
PHP
(cd "${INFODIR}" && git init -q . && git add -A) >/dev/null 2>&1
INFO_OUT="$( (cd "${INFODIR}" && cklint widget.php) 2>&1 || true)"
if grep -q "widget_civicrm_managed" <<<"${INFO_OUT}" && ! grep -q "helper_civicrm_managed" <<<"${INFO_OUT}"; then
    ok "cklint mago stage resolves the extension prefix from the repo's info.xml"
else
    fail "cklint mago stage ignored the repo's info.xml (output: ${INFO_OUT:0:400})"
fi

# cklint --fix inserts the missing declare, spelled so the next plain run passes.
FIXDIR="${WORKDIR}/fixext"
mkdir -p "${FIXDIR}"
printf '<?php\n\nfunction fixext_answer(): int {\n  return 42;\n}\n' > "${FIXDIR}/Answer.php"
(cd "${FIXDIR}" && git init -q . && git add -A) >/dev/null 2>&1
FIX_OUT="$( (cd "${FIXDIR}" && cklint --fix Answer.php && cklint Answer.php) 2>&1 )" || true
if grep -q "declare(strict_types = 1);" "${FIXDIR}/Answer.php" && (cd "${FIXDIR}" && cklint Answer.php) >/dev/null 2>&1; then
    ok "cklint --fix inserts declare(strict_types = 1) and the result lints clean"
else
    fail "cklint --fix left Answer.php without a clean declare ($(head -c 200 "${FIXDIR}/Answer.php") | ${FIX_OUT:0:300})"
fi

# mago lints at the repo's declared PHP floor: a trait constant (PHP 8.2) is a
# semantics error under the baseline's 8.1, and fine for a >=8.3 repo.
FLOORDIR="${WORKDIR}/floorext"
mkdir -p "${FLOORDIR}"
printf '<?php\n\ntrait Floor {\n  public const LEVEL = 1;\n}\n' > "${FLOORDIR}/Floor.php"
(cd "${FLOORDIR}" && git init -q . && git add -A) >/dev/null 2>&1
FLOOR_BASE="$( (cd "${FLOORDIR}" && cklint Floor.php) 2>&1 || true)"
printf '{"require": {"php": ">=8.3"}}\n' > "${FLOORDIR}/composer.json"
FLOOR_83="$( (cd "${FLOORDIR}" && cklint Floor.php) 2>&1 || true)"
if grep -q "Constants in traits" <<<"${FLOOR_BASE}" && ! grep -q "Constants in traits" <<<"${FLOOR_83}"; then
    ok "cklint's mago stage lints at the composer.json PHP floor"
else
    fail "cklint ignored the PHP floor (8.1: ${FLOOR_BASE:0:200} | 8.3: ${FLOOR_83:0:200})"
fi

# A positional boolean takes judgement per call site, so the baseline leaves
# name-boolean-arguments off: every level mago reports would fail the gate.
BOOLDIR="${WORKDIR}/boolext"
mkdir -p "${BOOLDIR}"
cat > "${BOOLDIR}/Bool.php" <<'PHP'
<?php

declare(strict_types = 1);

function acme_probe(): void {
  acme_helper('x', TRUE);
}
PHP
(cd "${BOOLDIR}" && git init -q . && git add -A) >/dev/null 2>&1
if BOOL_OUT="$( (cd "${BOOLDIR}" && cklint Bool.php) 2>&1 )" && ! grep -q "name-boolean-arguments" <<<"${BOOL_OUT}"; then
    ok "cklint baseline leaves name-boolean-arguments off"
else
    fail "cklint flagged or failed on a positional boolean (output: ${BOOL_OUT:0:300})"
fi

# The checks that replaced the Drupal standard's MethodScope, PropertyDeclaration
# and InlineControlStructure sniffs: one from the extension, one mago rule.
SCOPEDIR="${WORKDIR}/scopeext"
mkdir -p "${SCOPEDIR}"
cat > "${SCOPEDIR}/Scope.php" <<'PHP'
<?php

declare(strict_types = 1);

class ScopeProbe {

  var $legacy;

  function probe(int $x): int {
    if ($x) return 1;
    return 0;
  }

}
PHP
(cd "${SCOPEDIR}" && git init -q . && git add -A) >/dev/null 2>&1
SCOPE_OUT="$( (cd "${SCOPEDIR}" && cklint Scope.php) 2>&1 || true)"
for code in civikitchen/explicit-visibility block-statement; do
    if grep -q "${code}" <<<"${SCOPE_OUT}"; then
        ok "cklint flags ${code}"
    else
        fail "cklint didn't flag ${code} (output: ${SCOPE_OUT:0:300})"
    fi
done

# cklint's mago stage: a bug-pattern rule fires, a house-idiom rule
# stays disabled by the bundled baseline, and @mago-expect suppresses.
MAGODIR="${WORKDIR}/magoext"
mkdir -p "${MAGODIR}"
cat > "${MAGODIR}/Mago.php" <<'PHP'
<?php

declare(strict_types = 1);

function magoext_probe(array $params): bool {
  return !empty($params['id']) && @unlink('/tmp/nope');
}
PHP
(cd "${MAGODIR}" && git init -q . && git add -A) >/dev/null 2>&1
MAGO_OUT="$( (cd "${MAGODIR}" && cklint --all) 2>&1 || true)"
if grep -q "no-error-control-operator" <<<"${MAGO_OUT}"; then
    ok "cklint mago stage flags the error control operator"
else
    fail "cklint mago stage didn't flag @ (output: ${MAGO_OUT:0:300})"
fi
if grep -q "no-empty" <<<"${MAGO_OUT}"; then
    fail "cklint mago stage runs no-empty — the baseline should disable it"
else
    ok "cklint mago stage honors the baseline's disabled rules"
fi
cat > "${MAGODIR}/Mago.php" <<'PHP'
<?php

declare(strict_types = 1);

function magoext_probe(array $params): bool {
  // @mago-expect lint:no-error-control-operator
  return !empty($params['id']) && @unlink('/tmp/nope');
}
PHP
MAGO_OUT2="$( (cd "${MAGODIR}" && cklint --all) 2>&1 || true)"
if grep -q "no-error-control-operator" <<<"${MAGO_OUT2}"; then
    fail "@mago-expect did not suppress the finding (output: ${MAGO_OUT2:0:300})"
else
    ok "cklint mago stage honors @mago-expect"
fi

# too-many-methods and no-request-variable are path-scoped in the baseline:
# signals for production code, noise in a PHPUnit case. The per-rule `exclude`
# is the only path-aware setting in the config, so a mago upgrade dropping it
# is worth catching here.
mago_methods_class() {
    { echo "<?php"; echo; echo "declare(strict_types = 1);"; echo;
      echo "class $1 {";
      for i in $(seq 1 25); do echo "  public function m${i}(): void {}"; done
      echo "}"; } > "$2"
}
mkdir -p "${MAGODIR}/tests/phpunit" "${MAGODIR}/Civi"
rm -f "${MAGODIR}/Mago.php"
mago_methods_class BigProd "${MAGODIR}/Civi/BigProd.php"
mago_methods_class BigTest "${MAGODIR}/tests/phpunit/BigTest.php"
for f in Civi/RequestProd.php tests/phpunit/RequestTest.php; do
    printf '<?php\n\ndeclare(strict_types = 1);\n\n$_REQUEST = [];\n' > "${MAGODIR}/${f}"
done
(cd "${MAGODIR}" && git add -A) >/dev/null 2>&1
MAGO_OUT3="$( (cd "${MAGODIR}" && cklint --all) 2>&1 || true)"
# mago prints the rule code and the file path on separate lines, and the output
# names both fixtures — so look only at the lines belonging to the
# too-many-methods diagnostic itself.
TMM_CTX="$(echo "${MAGO_OUT3}" | grep -A3 'too-many-methods' || true)"
if grep -q "BigProd.php" <<<"${TMM_CTX}"; then
    ok "cklint mago stage flags too-many-methods in production code"
else
    fail "too-many-methods didn't fire outside tests/ (output: ${MAGO_OUT3:0:300})"
fi
if grep -q "BigTest.php" <<<"${TMM_CTX}"; then
    fail "too-many-methods fired under tests/ — the baseline excludes that path"
else
    ok "cklint mago stage excludes tests/ from too-many-methods"
fi

NRV_CTX="$(echo "${MAGO_OUT3}" | grep -A3 'no-request-variable' || true)"
if grep -q "RequestProd.php" <<<"${NRV_CTX}"; then
    ok "cklint mago stage flags no-request-variable in production code"
else
    fail "no-request-variable didn't fire outside tests/ (output: ${MAGO_OUT3:0:300})"
fi
if grep -q "RequestTest.php" <<<"${NRV_CTX}"; then
    fail "no-request-variable fired under tests/ — the baseline excludes that path"
else
    ok "cklint mago stage excludes tests/ from no-request-variable"
fi

# The extension's own cases (exact findings per fixture) run against the
# image's mago, from a copy since /opt is not the test's to write to.
cp -r /opt/civikitchen/toolbelt "${WORKDIR}/toolbelt"
if EXT_TESTS_OUT="$(php "${WORKDIR}/toolbelt/mago-ext/tests/run.php" --mago="$(command -v mago)" 2>&1)"; then
    ok "CiviKitchen mago extension cases"
else
    fail "CiviKitchen mago extension cases ($(grep -v '^ok ' <<<"${EXT_TESTS_OUT}" | head -40))"
fi

# ---------------------------------------------------------------------------
# 3c. ckmodernize boots rector and previews a CiviCRM-specific rewrite
echo "== ckmodernize =="
cat > "${WORKDIR}/Modernize.php" <<'PHP'
<?php
function f(array $a) {
    return CRM_Utils_Array::value('k', $a, 'd');
}
PHP

if CKMOD_HELP="$(ckmodernize --help 2>&1)" && grep -q "modernize a CiviCRM extension" <<<"${CKMOD_HELP}"; then
    ok "ckmodernize --help"
else
    fail "ckmodernize --help"
fi

CKMOD_OUT="$( (cd "${WORKDIR}" && ckmodernize Modernize.php) 2>&1 || true)"
if grep -q '\?\?' <<<"${CKMOD_OUT}"; then
    ok "ckmodernize previews CRM_Utils_Array::value rewrite"
else
    fail "ckmodernize did not preview the array-value rewrite (output: ${CKMOD_OUT:0:300})"
fi

# ---------------------------------------------------------------------------
# 3d. ckrelease builds a dist archive from a throwaway extension repo
echo "== ckrelease =="
RELDIR="${WORKDIR}/rel"
mkdir -p "${RELDIR}/tests/phpunit"
cat > "${RELDIR}/info.xml" <<'XML'
<?xml version="1.0"?>
<extension key="org.example.greeter" type="module">
  <file>greeter</file>
  <version>1.3.0</version>
</extension>
XML
echo '<?php' > "${RELDIR}/greeter.php"
echo '<?php' > "${RELDIR}/tests/phpunit/GreeterTest.php"
echo 'x' > "${RELDIR}/phpunit.xml.dist"
echo 'must-not-ship' > "${RELDIR}/.env.custom"
# Keep enough entries after info.xml to fill a pipe buffer. This reproduces the
# Composer-sized archive where `printf entries | grep -q info.xml` used to make
# printf die with SIGPIPE under pipefail, falsely reporting a missing info.xml.
mkdir -p "${RELDIR}/vendor/example/package"
for n in $(seq 1 2500); do
    printf '<?php // %04d\n' "$n" > "${RELDIR}/vendor/example/package/File${n}.php"
done
(
    cd "${RELDIR}"
    git init -q .
    git add -A
    git -c user.email=test@example.org -c user.name=test commit -qm init
) >/dev/null 2>&1

CKREL_OUT="$( (cd "${RELDIR}" && ckrelease dist --version v1.3.0) 2>&1 || true)"
if [ -f "${RELDIR}/.ckrelease/org.example.greeter-1.3.0.zip" ] \
    && [ -f "${RELDIR}/.ckrelease/org.example.greeter-1.3.0.zip.sha256" ]; then
    ok "ckrelease builds the dist zip + checksum"
else
    fail "ckrelease did not build the dist zip (output: ${CKREL_OUT:0:300})"
fi

# The whole point of the archive: dev/CI files stay out of what a site installs.
CKREL_LIST="$(unzip -Z1 "${RELDIR}/.ckrelease/org.example.greeter-1.3.0.zip" 2>/dev/null || true)"
if grep -q 'org.example.greeter/greeter.php' <<< "${CKREL_LIST}" \
    && ! grep -qE 'tests/|phpunit.xml.dist|/\.env' <<< "${CKREL_LIST}"; then
    ok "ckrelease excludes dev/CI files from the archive"
else
    fail "ckrelease archive contents wrong (${CKREL_LIST:0:300})"
fi

# A tag that disagrees with info.xml is the failure this exists to catch.
if (cd "${RELDIR}" && ckrelease check --version v9.9.9) >/dev/null 2>&1; then
    fail "ckrelease accepted a tag that disagrees with info.xml"
else
    ok "ckrelease rejects a tag that disagrees with info.xml"
fi

# ---------------------------------------------------------------------------
# 3d-bis. ckcommon.sh's readers parse XML as XML.
#
# Regression fixtures for two inputs that a tag-shaped regex gets wrong, and
# that three tools plus one CI step used to read that way. Both are legal XML
# and both were silently wrong rather than loud:
#   - a single-quoted attribute yielded an EMPTY key, so the tool aborted
#   - a commented-out root element won over the real one, so the tool ran under
#     the wrong extension name
# Tested against the shared helper because it is now the only implementation.
echo "== ckcommon xml reader =="
# shellcheck source=/dev/null
. /opt/civikitchen/toolbelt/lib/ckcommon.sh

XMLDIR="${WORKDIR}/xmlread"
mkdir -p "${XMLDIR}"

printf '%s\n' "<extension key='org.example.single' type='module'><file>single</file></extension>" \
    > "${XMLDIR}/single-quoted.xml"
if [ "$(ck_xml_field "${XMLDIR}/single-quoted.xml" key)" = "org.example.single" ]; then
    ok "ck_xml_field reads a single-quoted key attribute"
else
    fail "ck_xml_field returned '$(ck_xml_field "${XMLDIR}/single-quoted.xml" key)' for a single-quoted key"
fi

{
    printf '%s\n' '<?xml version="1.0"?>'
    printf '%s\n' '<!-- <extension key="org.example.decoy" type="module"> -->'
    printf '%s\n' '<extension key="org.example.real" type="module"><file>real</file></extension>'
} > "${XMLDIR}/commented-decoy.xml"
if [ "$(ck_xml_field "${XMLDIR}/commented-decoy.xml" key)" = "org.example.real" ]; then
    ok "ck_xml_field ignores a commented-out root element"
else
    fail "ck_xml_field returned '$(ck_xml_field "${XMLDIR}/commented-decoy.xml" key)' instead of the real key"
fi

# A child element, which is how <file>, <version> and core's <version_no> are read.
if [ "$(ck_xml_field "${XMLDIR}/commented-decoy.xml" file)" = "real" ]; then
    ok "ck_xml_field reads a child element"
else
    fail "ck_xml_field did not read <file>"
fi

# Unparseable input must be loud: exit 2, not an empty string a caller treats
# as "the field is absent".
if ! printf 'not xml at all\n' > "${XMLDIR}/broken.xml" \
    || ck_xml_field "${XMLDIR}/broken.xml" key >/dev/null 2>&1; then
    fail "ck_xml_field accepted unparseable XML instead of failing"
else
    ok "ck_xml_field fails on unparseable XML"
fi

# ---------------------------------------------------------------------------
# 3e. ckeslint: the pinned oxlint toolchain is installed and every layer of the
#     baseline fires. One finding per layer on purpose, because each can
#     go missing on its own without anything else looking wrong:
#       - oxlint's own `correctness` category (the native rules)
#       - no-unsanitized on .js AND on .ts — that plugin runs through oxlint's
#         alpha jsPlugins bridge, and if the bridge stops loading it, oxlint
#         still exits 0 over the same file
#       - a type-aware rule, which needs the tsgolint binary to be found
#       - civikitchen/api4-contract, a local jsPlugin fed the phpstan catalog
#     A silently empty gate is the failure mode this block exists to catch.
echo "== ckeslint =="
ESDIR="${WORKDIR}/eslintext"
mkdir -p "${ESDIR}/js"
cat > "${ESDIR}/info.xml" <<'XML'
<?xml version="1.0"?>
<extension key="org.example.widget" type="module">
  <file>widget</file>
</extension>
XML
cat > "${ESDIR}/tsconfig.json" <<'JSON'
{
  "compilerOptions": {
    "strict": true,
    "noEmit": true,
    "target": "ES2022",
    "module": "ESNext",
    "moduleResolution": "bundler"
  },
  "include": ["js/**/*.ts"]
}
JSON
cat > "${ESDIR}/js/widget.js" <<'JS'
function render(el, userInput) {
  var unused = 1;
  el.innerHTML = userInput;
}
CRM.api4('Contact', 'get', { select: ['display_nam'] });
JS
cat > "${ESDIR}/js/widget.ts" <<'TS'
export function render(el: HTMLElement, userInput: string): void {
  el.innerHTML = userInput;
}
async function work(): Promise<void> {}
export function boot(): void {
  work();
}
TS
# Node code outside the conventional *.config.js names: a build script and a
# Playwright spec. The baseline lints with the browser env, so without a node
# env for these paths every `require`/`process`/`__dirname` reads as no-undef —
# a gate that fires on correct code, which a repo can only silence by replacing
# the whole baseline.
mkdir -p "${ESDIR}/scripts" "${ESDIR}/tests/e2e"
cat > "${ESDIR}/scripts/build-widget.js" <<'NODEJS'
const path = require('path');
const out = path.join(__dirname, 'widget.js');
if (process.env.CK_DEBUG) {
  console.log(out);
}
NODEJS
cat > "${ESDIR}/tests/e2e/widget.spec.js" <<'NODEJS'
const { test } = require('@playwright/test');
test('boots', async ({ page }) => {
  await page.goto(process.env.CK_BASE_URL || 'http://localhost');
});
NODEJS
(cd "${ESDIR}" && git init -q . && git add -A) >/dev/null 2>&1

# --format=unix: oxlint's default reporter is the graphical one in some
# terminals and a one-liner in others, and these assertions name file AND rule.
ESLINT_OUT="$( (cd "${ESDIR}" && ckeslint --format=unix) 2>&1 || true)"
if grep -q "^js/widget.js:.*no-unsanitized(property)" <<<"${ESLINT_OUT}"; then
    ok "ckeslint flags an unsafe innerHTML assignment in .js"
else
    fail "ckeslint didn't flag innerHTML in .js (output: ${ESLINT_OUT:0:400})"
fi
if grep -q "^js/widget.ts:.*no-unsanitized(property)" <<<"${ESLINT_OUT}"; then
    ok "ckeslint flags an unsafe innerHTML assignment in .ts"
else
    fail "ckeslint didn't flag innerHTML in .ts (output: ${ESLINT_OUT:0:400})"
fi
if grep -q "^js/widget.js:.*Contact.display_nam.*civikitchen(api4-contract)" <<<"${ESLINT_OUT}"; then
    ok "ckeslint checks APIv4 field names against the image's catalog"
else
    fail "ckeslint didn't flag the misspelt APIv4 field (output: ${ESLINT_OUT:0:400})"
fi
if grep -q "no-unused-vars" <<<"${ESLINT_OUT}"; then
    ok "ckeslint applies oxlint's correctness rules"
else
    fail "ckeslint didn't report the unused variable (output: ${ESLINT_OUT:0:400})"
fi
if grep -q "no-floating-promises" <<<"${ESLINT_OUT}"; then
    ok "ckeslint runs the type-aware rules (tsgolint is wired up)"
else
    fail "ckeslint didn't report the floating promise (output: ${ESLINT_OUT:0:400})"
fi
if echo "${ESLINT_OUT}" | grep -E "^(scripts/build-widget|tests/e2e/widget.spec)\.js:.*no-undef"; then
    fail "ckeslint reported no-undef for Node globals outside *.config.js (output: ${ESLINT_OUT:0:400})"
else
    ok "ckeslint gives Node scripts and e2e specs the node env"
fi

# The type-aware rules are the half that can vanish quietly: oxlint reports the
# missing binary and exits non-zero, and the gate must not read that as a pass.
TSGO_OUT="$( (cd "${ESDIR}" && OXLINT_TSGOLINT_PATH=/nonexistent/tsgolint ckeslint --format=unix) 2>&1 || true)"
if (cd "${ESDIR}" && OXLINT_TSGOLINT_PATH=/nonexistent/tsgolint ckeslint --format=unix) >/dev/null 2>&1; then
    fail "ckeslint passed with an unusable tsgolint (output: ${TSGO_OUT:0:300})"
else
    ok "ckeslint fails when tsgolint is missing"
fi

# Without a root tsconfig there is no TypeScript program: every import is
# `any` and no-unsafe-* would bury the gate in false positives. The wrapper
# must pick the no-type-aware baseline then — while no-unsanitized stays live.
ESNOTS="${WORKDIR}/eslintnotsext"
mkdir -p "${ESNOTS}/js"
cp "${ESDIR}/info.xml" "${ESNOTS}/info.xml"
cat > "${ESNOTS}/js/loose.ts" <<'TS'
const v: any = JSON.parse('{}');
export const picked = v.thing;
export function render(el: HTMLElement, userInput: string): void {
  el.innerHTML = userInput;
}
TS
(cd "${ESNOTS}" && git init -q . && git add -A) >/dev/null 2>&1
NOTS_OUT="$( (cd "${ESNOTS}" && ckeslint --format=unix) 2>&1 || true)"
if grep -q "no-unsafe" <<<"${NOTS_OUT}"; then
    fail "ckeslint ran type-aware rules without a tsconfig (output: ${NOTS_OUT:0:300})"
else
    ok "ckeslint skips type-aware rules when the repo has no tsconfig"
fi
if grep -q "no-unsanitized(property)" <<<"${NOTS_OUT}"; then
    ok "ckeslint keeps no-unsanitized live without a tsconfig"
else
    fail "ckeslint lost no-unsanitized in the no-tsconfig baseline (output: ${NOTS_OUT:0:300})"
fi

# A repo that still ships only an eslint.config.* has to stop the gate loudly:
# ESLint is not in the image any more, and running the baseline over rules that
# repo never chose is the silent-wrong-answer case.
ESCFG="${WORKDIR}/eslintcfgext"
mkdir -p "${ESCFG}/js"
cp "${ESDIR}/info.xml" "${ESCFG}/info.xml"
cp "${ESDIR}/js/widget.js" "${ESCFG}/js/widget.js"
echo 'export default [];' > "${ESCFG}/eslint.config.mjs"
(cd "${ESCFG}" && git init -q . && git add -A) >/dev/null 2>&1
ESCFG_OUT="$( (cd "${ESCFG}" && ckeslint) 2>&1 || true)"
if (cd "${ESCFG}" && ckeslint) >/dev/null 2>&1; then
    fail "ckeslint passed a repo whose only config is an eslint.config.* (output: ${ESCFG_OUT:0:300})"
elif grep -q "the image gate is oxlint now" <<<"${ESCFG_OUT}"; then
    ok "ckeslint refuses a stale eslint.config.* with a pointer to the fix"
else
    fail "ckeslint's eslint.config.* refusal is unclear (output: ${ESCFG_OUT:0:300})"
fi

# ... and an .oxlintrc.json of the repo's own takes over from the baseline.
cat > "${ESCFG}/.oxlintrc.json" <<'JSON'
{ "rules": { "no-unused-vars": "off" } }
JSON
(cd "${ESCFG}" && git add -A) >/dev/null 2>&1
OWN_OUT="$( (cd "${ESCFG}" && ckeslint --format=unix) 2>&1 || true)"
if grep -q "own .oxlintrc.json" <<<"${OWN_OUT}"; then
    ok "ckeslint prefers the repo's own .oxlintrc.json"
else
    fail "ckeslint ignored the repo's .oxlintrc.json (output: ${OWN_OUT:0:300})"
fi

# CRM/cj/ts/_/angular must not read as undefined identifiers, or every real
# extension's frontend drowns in no-undef and the gate gets switched off.
cat > "${ESDIR}/js/globals.js" <<'JS'
export function boot() {
  return [CRM.url('civicrm/x'), cj('body'), ts('Hi'), _.size([]), angular.module('x')];
}
JS
(cd "${ESDIR}" && git add -A) >/dev/null 2>&1
GLOBALS_OUT="$( (cd "${ESDIR}" && ckeslint --format=unix js/globals.js) 2>&1 || true)"
if grep -q "no-undef" <<<"${GLOBALS_OUT}"; then
    fail "ckeslint reports CiviCRM globals as undefined (output: ${GLOBALS_OUT:0:300})"
else
    ok "ckeslint knows the CiviCRM globals"
fi

# A repo with no JS at all must PASS, and say so — the common case in this
# fleet, and the one where a silent exit 0 is indistinguishable from a broken
# tool.
NOJS="${WORKDIR}/nojsext"
mkdir -p "${NOJS}"
cp "${ESDIR}/info.xml" "${NOJS}/info.xml"
(cd "${NOJS}" && git init -q . && git add -A) >/dev/null 2>&1
if NOJS_OUT="$( (cd "${NOJS}" && ckeslint) 2>&1 )" \
    && grep -q "nothing to lint" <<<"${NOJS_OUT}"; then
    ok "ckeslint passes with a log line when there is no JS"
else
    fail "ckeslint on a JS-free repo (output: ${NOJS_OUT:0:200})"
fi

# Node globals: an e2e suite reading process.env must not drown in
# no-unsafe-* just because the repo does not carry @types/node. The image's
# copy is linked in for the run — and must be gone again afterwards, because a
# stray node_modules/ in a checkout is exactly what the gate must not leave.
ESNODE="${WORKDIR}/eslintnodeext"
mkdir -p "${ESNODE}/e2e"
cp "${ESDIR}/info.xml" "${ESNODE}/info.xml"
cat > "${ESNODE}/tsconfig.json" <<'JSON'
{
  "compilerOptions": {
    "strict": true,
    "noEmit": true,
    "target": "ES2022",
    "module": "ESNext",
    "moduleResolution": "bundler",
    "types": ["node"]
  },
  "include": ["e2e/**/*.ts"]
}
JSON
cat > "${ESNODE}/e2e/env.ts" <<'TS'
export function baseUrl(): string {
  const raw = process.env.WIDGET_BASE_URL;
  return raw ?? 'http://localhost';
}
TS
(cd "${ESNODE}" && git init -q . && git add -A) >/dev/null 2>&1
NODE_OUT="$( (cd "${ESNODE}" && ckeslint --format=unix) 2>&1 || true)"
if grep -q "no-unsafe" <<<"${NODE_OUT}"; then
    fail "ckeslint reports no-unsafe-* on process.env — the image's @types/node did not reach the program (output: ${NODE_OUT:0:400})"
else
    ok "ckeslint types process.env from the image's @types/node"
fi
if [ -e "${ESNODE}/node_modules" ]; then
    fail "ckeslint left a node_modules/ behind in the repo"
else
    ok "ckeslint removes its @types/node overlay again"
fi

# A repo that carries its own @types/node keeps it: the overlay must not
# overwrite it, and two copies in one program is the conflict to avoid.
ESOWNNODE="${WORKDIR}/eslintownnodeext"
mkdir -p "${ESOWNNODE}/e2e/../node_modules/@types"
cp "${ESNODE}/info.xml" "${ESOWNNODE}/info.xml"
cp "${ESNODE}/tsconfig.json" "${ESOWNNODE}/tsconfig.json"
cp "${ESNODE}/e2e/env.ts" "${ESOWNNODE}/e2e/env.ts"
cp -R /opt/civikitchen/toolbelt/oxlint/node_modules/@types/node "${ESOWNNODE}/node_modules/@types/node"
(cd "${ESOWNNODE}" && git init -q . && git add -A) >/dev/null 2>&1
OWNNODE_OUT="$( (cd "${ESOWNNODE}" && ckeslint --format=unix) 2>&1 || true)"
if grep -q "no-unsafe" <<<"${OWNNODE_OUT}"; then
    fail "ckeslint broke a repo that ships its own @types/node (output: ${OWNNODE_OUT:0:400})"
elif [ -L "${ESOWNNODE}/node_modules/@types/node" ]; then
    fail "ckeslint replaced the repo's own @types/node with its overlay"
else
    ok "ckeslint leaves a repo's own @types/node alone"
fi

# ---------------------------------------------------------------------------
# 3e2. ckfmt: both formatter halves fire, converge, and the mago output passes
#      the lint rules cklint enforces — the property the whole gate rests on,
#      so it is asserted here, not assumed.
echo "== ckfmt =="
FMTDIR="${WORKDIR}/fmtext"
mkdir -p "${FMTDIR}/js"
cat > "${FMTDIR}/info.xml" <<'XML'
<?xml version="1.0"?>
<extension key="org.example.acme" type="module">
  <file>acme</file>
</extension>
XML
cat > "${FMTDIR}/acme.php" <<'PHP'
<?php
declare(strict_types = 1);

function acme_greet( string $name ){
    if($name===''){ return 'nobody'; }
  return "hi " . $name;
}
PHP
cat > "${FMTDIR}/js/acme.js" <<'JS'
const acme = {a:1,   b:2};
JS
(cd "${FMTDIR}" && git init -q . && git add -A) >/dev/null 2>&1

if (cd "${FMTDIR}" && ckfmt --check) >/dev/null 2>&1; then
    fail "ckfmt --check passed on unformatted PHP + JS"
else
    ok "ckfmt --check fails on unformatted code"
fi
if FMT_OUT="$( (cd "${FMTDIR}" && ckfmt && ckfmt --check) 2>&1 )"; then
    ok "ckfmt formats and then reports clean"
else
    fail "ckfmt did not converge (output: ${FMT_OUT:0:300})"
fi
# The agreement property: formatted output is clean under the cklint gate.
if CKLINT_FMT_OUT="$( (cd "${FMTDIR}" && cklint --all) 2>&1 )"; then
    ok "ckfmt output is clean under the cklint gate"
else
    fail "cklint rejects ckfmt output (output: ${CKLINT_FMT_OUT:0:300})"
fi

# The same agreement property on overlong lines, where the formatter makes
# its largest layout decisions: whatever ckfmt does, cklint must accept.
# Each fixture is its own file so a regression names the construct.
AGREEDIR="${WORKDIR}/fmtagreeext"
mkdir -p "${AGREEDIR}"
cp "${FMTDIR}/info.xml" "${AGREEDIR}/info.xml"
cat > "${AGREEDIR}/extends.php" <<'PHP'
<?php

declare(strict_types = 1);

namespace Civi\Acme\Widget;

class AcmeWidgetSubscriptionRenewalNotificationDispatchHandler extends AbstractAcmeWidgetSubscriptionRenewalNotificationHandlerBase {

  public function handle(): void {
  }

}
PHP
cat > "${AGREEDIR}/extendsimplements.php" <<'PHP'
<?php

declare(strict_types = 1);

namespace Civi\Acme\Widget;

class AcmeWidgetSubscriptionRenewalDispatchHandler extends AbstractAcmeWidgetSubscriptionRenewalNotificationHandlerBase implements AcmeWidgetNotificationInterface {

  public function handle(): void {
  }

}
PHP
cat > "${AGREEDIR}/implements.php" <<'PHP'
<?php

declare(strict_types = 1);

namespace Civi\Acme\Widget;

class AcmeWidgetRenewalHandler implements AcmeWidgetNotificationInterface, AcmeWidgetSchedulableInterface, AcmeWidgetLoggableInterface {

  public function handle(): void {
  }

}
PHP
cat > "${AGREEDIR}/echoconcat.php" <<'PHP'
<?php

declare(strict_types = 1);

function acme_render(string $alpha, string $beta, string $gamma, string $delta): void {
  echo 'The widget ' . $alpha . ' has been renewed for ' . $beta . ' until ' . $gamma . ' and dispatched to ' . $delta . '.';
}
PHP
cat > "${AGREEDIR}/nestedarray.php" <<'PHP'
<?php

declare(strict_types = 1);

function acme_rows(): array {
  return [['id' => 1, 'label' => 'one', 'description' => 'the first widget row'], ['id' => 2, 'label' => 'two', 'description' => 'the second widget row']];
}
PHP
# A multi-line inner array literal opening as `[[`: ckfmt keeps the shared brackets.
cat > "${AGREEDIR}/nestedarrayliteral.php" <<'PHP'
<?php

declare(strict_types = 1);

function acme_grouped(): array {
  return [
    'groups' => [[
      'group_id' => 7,
      'group_title' => 'Newsletter',
      'candidates' => 12,
    ]],
  ];
}
PHP
# An array value ckfmt wraps, followed by sibling keys at the same depth.
cat > "${AGREEDIR}/arrayvaluewrap.php" <<'PHP'
<?php

declare(strict_types = 1);

function acme_columns(): array {
  return [
    'display' => [
      'settings' => [
        'columns' => [
          [
            'key' => 'flag',
            'rewrite' => '{if $flag}' . acme_label('the widget is currently switched on') . '{else}' . acme_label('the widget is currently switched off') . '{/if}',
            'css_rules' => [['font-bold', 'flag', '=', FALSE], ['font-italic', 'flag', '=', TRUE], ['font-strike', 'flag', '=', NULL]],
            'icons' => [['icon' => 'fa-flask', 'side' => 'left', 'if' => ['flag', '=', TRUE]], ['icon' => 'fa-flag', 'side' => 'left', 'if' => ['flag', '=', FALSE]]],
          ],
        ],
      ],
    ],
  ];
}
PHP
(cd "${AGREEDIR}" && git init -q . && git add -A) >/dev/null 2>&1
if AGREE_FMT_OUT="$( (cd "${AGREEDIR}" && ckfmt) 2>&1 )"; then
    ok "ckfmt formats the overlong-line fixtures"
else
    fail "ckfmt failed on the overlong-line fixtures (output: ${AGREE_FMT_OUT:0:300})"
fi
for fixture in extends extendsimplements implements echoconcat nestedarray \
    nestedarrayliteral arrayvaluewrap; do
    if AGREE_OUT="$( (cd "${AGREEDIR}" && cklint "${fixture}.php") 2>&1 )"; then
        ok "ckfmt output for ${fixture}.php is clean under cklint"
    else
        fail "cklint rejects ckfmt output for ${fixture}.php (output: ${AGREE_OUT:0:400})"
    fi
done

# No formattable files at all must PASS, and say so.
NOFMT="${WORKDIR}/nofmtext"
mkdir -p "${NOFMT}"
cp "${FMTDIR}/info.xml" "${NOFMT}/info.xml"
(cd "${NOFMT}" && git init -q . && git add -A) >/dev/null 2>&1
if NOFMT_OUT="$( (cd "${NOFMT}" && ckfmt --check) 2>&1 )" \
    && grep -q "no PHP files" <<<"${NOFMT_OUT}"; then
    ok "ckfmt passes with a log line when there is nothing to format"
else
    fail "ckfmt on an empty repo (output: ${NOFMT_OUT:0:200})"
fi

# ---------------------------------------------------------------------------
# 3f. ckschemadiff: table discovery from both schema formats, and the
#     normalisation that makes two dumps of the same schema compare equal.
#     The database half is exercised by the shared CI's schema-parity job,
#     which is the only place two CiviCRM databases exist.
echo "== ckschemadiff =="
SCHDIR="${WORKDIR}/schemaext"
mkdir -p "${SCHDIR}/schema" "${SCHDIR}/xml/schema/CRM/Widget"
cp "${ESDIR}/info.xml" "${SCHDIR}/info.xml"
cat > "${SCHDIR}/schema/Widget.entityType.php" <<'PHP'
<?php
return ['name' => 'Widget', 'table' => 'civicrm_widget', 'getFields' => fn() => []];
PHP
cat > "${SCHDIR}/xml/schema/CRM/Widget/Gadget.xml" <<'XML'
<?xml version="1.0" encoding="iso-8859-1" ?>
<table>
  <name>civicrm_gadget</name>
  <field><name>id</name></field>
</table>
XML
SCH_TABLES="$( (cd "${SCHDIR}" && ckschemadiff tables) 2>&1 || true)"
if [ "${SCH_TABLES}" = "civicrm_gadget
civicrm_widget" ]; then
    ok "ckschemadiff reads tables from both schema formats"
else
    fail "ckschemadiff table discovery (output: ${SCH_TABLES:0:200})"
fi

printf 'CREATE TABLE `civicrm_widget` (\n  `id` int NOT NULL AUTO_INCREMENT\n) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4;\n' > "${WORKDIR}/schema-a.sql"
printf '/*!40101 SET @saved_cs_client = @@character_set_client */;\nCREATE TABLE `civicrm_widget` (\n  `id` int NOT NULL AUTO_INCREMENT\n) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;\n' > "${WORKDIR}/schema-b.sql"
ckschemadiff normalize < "${WORKDIR}/schema-a.sql" > "${WORKDIR}/schema-a.norm"
ckschemadiff normalize < "${WORKDIR}/schema-b.sql" > "${WORKDIR}/schema-b.norm"
if ckschemadiff diff "${WORKDIR}/schema-a.norm" "${WORKDIR}/schema-b.norm" >/dev/null 2>&1; then
    ok "ckschemadiff normalises away the AUTO_INCREMENT counter and dump wrappers"
else
    fail "ckschemadiff reported a difference between two dumps of the same schema"
fi

printf 'CREATE TABLE `civicrm_widget` (\n  `label` varchar(64) DEFAULT NULL\n) ENGINE=InnoDB;\n' \
    | ckschemadiff normalize > "${WORKDIR}/schema-c.norm"
if ckschemadiff diff "${WORKDIR}/schema-a.norm" "${WORKDIR}/schema-c.norm" >/dev/null 2>&1; then
    fail "ckschemadiff called two genuinely different schemas equal"
else
    ok "ckschemadiff reports a real schema difference"
fi

# ---------------------------------------------------------------------------
# 3g. ckcompat: mago at the declared PHP floor — its parser for syntax the floor
#     cannot read, analyze for APIs it lacks. The image runs a single PHP 8.4,
#     so `php -l` is structurally blind to 8.4-only syntax; this is the only
#     gate that sees it.
echo "== ckcompat =="
CMPDIR="${WORKDIR}/compatext"
mkdir -p "${CMPDIR}/Civi"
echo '{"require":{"php":">=8.3"}}' > "${CMPDIR}/composer.json"
cat > "${CMPDIR}/Civi/Floor.php" <<'PHP'
<?php

declare(strict_types = 1);

class AcmeFloor {

  public function m(): int {
    return 1;
  }

}

function acme_floor_run(): int {
  return new AcmeFloor()->m();
}
PHP
CMP_OUT="$( (cd "${CMPDIR}" && ckcompat) 2>&1 || true)"
if (cd "${CMPDIR}" && ckcompat) >/dev/null 2>&1; then
    fail "ckcompat passed PHP-8.4-only syntax against an 8.3 floor (output: ${CMP_OUT:0:300})"
else
    ok "ckcompat rejects PHP-8.4-only syntax against the declared 8.3 floor"
fi
if grep -q "semantics" <<<"${CMP_OUT}"; then
    ok "ckcompat runs the mago floor-parse stage"
else
    fail "ckcompat's mago parse stage did not run (output: ${CMP_OUT:0:300})"
fi
# The same code with the floor it really needs must be silent, or the gate is
# just noise.
if (cd "${CMPDIR}" && ckcompat --php 8.4) >/dev/null 2>&1; then
    ok "ckcompat passes when the floor matches the syntax"
else
    CMP_OK_OUT="$( (cd "${CMPDIR}" && ckcompat --php 8.4) 2>&1 || true)"
    fail "ckcompat failed on code that matches its floor (output: ${CMP_OK_OUT:0:300})"
fi
# Property hooks: syntax only the parse stage names.
cat > "${CMPDIR}/Civi/Hooked.php" <<'PHP'
<?php

declare(strict_types = 1);

class AcmeHooked {

  public string $n = '' {
    get => $this->n;
  }

}
PHP
if (cd "${CMPDIR}" && ckcompat) >/dev/null 2>&1; then
    fail "ckcompat passed PHP-8.4 property hooks against an 8.3 floor"
else
    ok "ckcompat rejects PHP-8.4 property hooks against the declared floor"
fi
# array_find() is PHP 8.4, but core ships symfony/polyfill-php84: calling it on
# an 8.3 floor is fine, and flagging it would be a false alarm.
APIDIR="${WORKDIR}/compatapi"
mkdir -p "${APIDIR}/Civi"
echo '{"require":{"php":">=8.3"}}' > "${APIDIR}/composer.json"
cat > "${APIDIR}/Civi/Api.php" <<'PHP'
<?php

declare(strict_types = 1);

function acme_api_first(array $xs): mixed {
  return array_find($xs, fn($x) => $x !== NULL);
}
PHP
if API_OUT="$( (cd "${APIDIR}" && ckcompat) 2>&1)"; then
    ok "ckcompat accepts a function core polyfills"
else
    fail "ckcompat flagged a polyfilled function (output: ${API_OUT:0:400})"
fi
cat > "${APIDIR}/Civi/Dom.php" <<'PHP'
<?php

declare(strict_types = 1);

function acme_api_dom(string $name): string {
  return \Dom\HTMLDocument::createEmpty()->saveHtml() . "${name}";
}
PHP
API_OUT="$( (cd "${APIDIR}" && ckcompat) 2>&1 || true)"
if grep -q 'unavailable-method' <<<"${API_OUT}" && grep -q 'civikitchen/php-deprecation' <<<"${API_OUT}"; then
    ok "ckcompat reports an API the floor lacks and a deprecated interpolation"
else
    fail "ckcompat missed Dom\\HTMLDocument on an 8.3 floor or \"\${name}\" (output: ${API_OUT:0:400})"
fi
# Core polyfills RoundingMode, Pdo\Mysql and CURL_HTTP_VERSION_3, not
# Random\IntervalBoundary: only the last one's constant fetch may be reported.
CONSTDIR="${WORKDIR}/compatconst"
mkdir -p "${CONSTDIR}/Civi"
echo '{"require":{"php":">=8.1"}}' > "${CONSTDIR}/composer.json"
cat > "${CONSTDIR}/Civi/Polyfilled.php" <<'PHP'
<?php

declare(strict_types = 1);

function acme_const_polyfilled(): array {
  return [\RoundingMode::HalfAwayFromZero, \Pdo\Mysql::ATTR_COMPRESS, \CURL_HTTP_VERSION_3];
}
PHP
if CONST_OUT="$( (cd "${CONSTDIR}" && ckcompat) 2>&1)"; then
    ok "ckcompat accepts constants and enum cases core polyfills"
else
    fail "ckcompat flagged a polyfilled constant or enum case (output: ${CONST_OUT:0:400})"
fi
cat > "${CONSTDIR}/Civi/Boundary.php" <<'PHP'
<?php

declare(strict_types = 1);

function acme_const_boundary(): object {
  return \Random\IntervalBoundary::ClosedOpen;
}
PHP
CONST_OUT="$( (cd "${CONSTDIR}" && ckcompat) 2>&1 || true)"
if grep -q 'Random\\IntervalBoundary` is not available' <<<"${CONST_OUT}" && [ "$(grep -c 'civikitchen/unavailable-access' <<<"${CONST_OUT}")" -eq 1 ]; then
    ok "ckcompat reports an enum case of a class the floor lacks"
else
    fail "ckcompat missed Random\\IntervalBoundary::ClosedOpen on an 8.1 floor (output: ${CONST_OUT:0:400})"
fi

# ---------------------------------------------------------------------------
# 4. phpstan analyses a sample file
echo "== phpstan run =="
cat > "${WORKDIR}/typed.php" <<'PHP'
<?php
function add(int $a, int $b): int {
    return $a . $b;  // type error: returning string from int-typed function
}
PHP
cat > "${WORKDIR}/phpstan.neon" <<'NEON'
parameters:
    level: 5
    paths:
        - typed.php
NEON

PHPSTAN_OUT="$(phpstan analyse -c "${WORKDIR}/phpstan.neon" --no-progress 2>&1 || true)"
if grep -qiE "should return int|error" <<<"${PHPSTAN_OUT}"; then
    ok "phpstan reports the typed-return error"
else
    fail "phpstan didn't catch the type error (output: ${PHPSTAN_OUT:0:200})"
fi

# ---------------------------------------------------------------------------
# 4b. deprecation rules fire, and @ck-legacy exempts the scope that must call
#     a deprecated API (CiviKitchen's bundled deprecated-scope resolver).
echo "== phpstan deprecation rules + @ck-legacy =="
cat > "${WORKDIR}/plain-caller.php" <<'PHP'
<?php
/** @deprecated use Greeter */
class OldGreeter {
    public function greet(): string { return 'hi'; }
}
class PlainCaller {
    public function call(): string { return (new OldGreeter())->greet(); }
}
PHP
# Same code, only the marker differs — so a green run can only come from it.
cat > "${WORKDIR}/marked-caller.php" <<'PHP'
<?php
/** @deprecated use Greeter */
class OldGreeter {
    public function greet(): string { return 'hi'; }
}
/** @ck-legacy */
class PlainCaller {
    public function call(): string { return (new OldGreeter())->greet(); }
}
PHP

for fixture in plain-caller marked-caller; do
    cat > "${WORKDIR}/phpstan-${fixture}.neon" <<NEON
parameters:
    level: 5
    paths:
        - ${fixture}.php
NEON
done

DEPR_OUT="$(phpstan analyse -c "${WORKDIR}/phpstan-plain-caller.neon" --no-progress 2>&1 || true)"
if grep -q "deprecatedClass" <<<"${DEPR_OUT}"; then
    ok "phpstan reports the call into a deprecated class"
else
    fail "deprecation rules not active (output: ${DEPR_OUT:0:200})"
fi

MARKED_OUT="$(phpstan analyse -c "${WORKDIR}/phpstan-marked-caller.neon" --no-progress 2>&1 || true)"
if grep -q "deprecatedClass" <<<"${MARKED_OUT}"; then
    fail "@ck-legacy scope was still reported (output: ${MARKED_OUT:0:400})"
else
    ok "@ck-legacy exempts the scope from the deprecation rules"
fi

# ---------------------------------------------------------------------------
# 4c. ckanalyze: mago analyze against the image's CiviCRM core, with civix
#     code read for its symbols and APIv4 Result rows typed as field maps.
echo "== ckanalyze =="
if PARITY_OUT="$(php /opt/civikitchen/toolbelt/mago/stub-parity.php /var/www/html/core 2>&1)"; then
    ok "the mago stubs declare every member of the image's core"
else
    fail "the mago stubs drifted from the image's core (output: ${PARITY_OUT:0:600})"
fi
ANADIR="${WORKDIR}/analyzeext"
mkdir -p "${ANADIR}/Civi/Widget" "${ANADIR}/tests/phpunit"
cat > "${ANADIR}/info.xml" <<'XML'
<?xml version="1.0"?>
<extension key="org.example.widget" type="module">
  <file>widget</file>
  <civix>
    <namespace>CRM/Widget</namespace>
  </civix>
</extension>
XML
echo '{"require":{"php":">=8.1"}}' > "${ANADIR}/composer.json"
cat > "${ANADIR}/widget.civix.php" <<'PHP'
<?php

class CRM_Widget_ExtensionUtil {

  public static function ts(string $text): string {
    return $text;
  }

}
PHP
cat > "${ANADIR}/Civi/Widget/Rows.php" <<'PHP'
<?php

declare(strict_types = 1);

namespace Civi\Widget;

use CRM_Widget_ExtensionUtil as E;

final class Rows {

  public function label(): string {
    return E::ts('Widget');
  }

  public function firstId(): mixed {
    $result = \Civi\Api4\Contact::get(FALSE)->execute();
    return $result[0]['id'] ?? NULL;
  }

  public function firstRow(): string {
    foreach (\Civi\Api4\Contact::get(FALSE)->execute() as $row) {
      return $row;
    }
    return '';
  }

  public function fill(\Civi\Api4\Generic\Result $result): void {
    $result['run_id'] = 5;
  }

  public function fields(): string {
    return civicrm_api4('Contact', 'get', ['select' => ['display_name']], 0)['display_name'];
  }

  public function count(): int {
    return 'many';
  }

}
PHP
# Outside the analyzed scope, as in phpstan's main pass.
cat > "${ANADIR}/tests/phpunit/RowsTest.php" <<'PHP'
<?php

function rows_test(): int {
  return 'not analyzed';
}
PHP
(cd "${ANADIR}" && git init -q . && git add -A) >/dev/null 2>&1
ANA_OUT="$( (cd "${ANADIR}" && ckanalyze) 2>&1 || true)"
if grep -q 'invalid-return-statement' <<<"${ANA_OUT}"; then
    ok "ckanalyze reports the wrong return type"
else
    fail "ckanalyze missed the wrong return type (output: ${ANA_OUT:0:400})"
fi
if ! grep -q 'invalid-return-statement' <<<"${ANA_OUT}" \
    || grep -qE 'non-existent-(method|class)|extension-boundary|Civi\\Api4\\Generic\\Result|assignment-value|Rows::fields`: expected|RowsTest' <<<"${ANA_OUT}"; then
    fail "ckanalyze reported core, civix symbols, Result access or tests/ (output: ${ANA_OUT:0:600})"
else
    ok "ckanalyze resolves core and civix symbols, accepts Result access and writes, skips tests/"
fi
if grep -q 'Rows::firstRow`: expected `string`, but found `array<string, mixed>`' <<<"${ANA_OUT}"; then
    ok "ckanalyze types the rows execute() returns as field maps"
else
    fail "ckanalyze did not type the Result rows (output: ${ANA_OUT:0:600})"
fi
if ANA_CI_OUT="$( (cd "${ANADIR}" && ck ci --only ckanalyze) 2>&1 )" \
    && grep -qE 'ckanalyze +shadow' <<<"${ANA_CI_OUT}" \
    && grep -q 'invalid-return-statement' <<<"${ANA_CI_OUT}"; then
    ok "ck ci reports ckanalyze findings as a shadow gate and passes"
else
    fail "ck ci did not treat ckanalyze as a shadow gate (output: ${ANA_CI_OUT:0:400})"
fi

# ---------------------------------------------------------------------------
# 5. phpunit runs a passing test
echo "== phpunit run =="
mkdir -p "${WORKDIR}/tests"
cat > "${WORKDIR}/tests/SmokeTest.php" <<'PHP'
<?php
use PHPUnit\Framework\TestCase;
class SmokeTest extends TestCase {
    public function testItPasses(): void {
        $this->assertSame(4, 2 + 2);
    }
}
PHP
cat > "${WORKDIR}/phpunit.xml" <<'XML'
<?xml version="1.0"?>
<phpunit colors="false" bootstrap="vendor/autoload.php">
    <testsuites>
        <testsuite name="smoke">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML
# phpunit doesn't strictly need bootstrap if we pass tests/ directly.
if (cd "${WORKDIR}" && phpunit --no-configuration tests/SmokeTest.php >/dev/null 2>&1); then
    ok "phpunit runs a passing test"
else
    fail "phpunit failed on a trivial passing test"
fi

# ---------------------------------------------------------------------------
# 5b. ckphpunit injects the transaction canary into the repo's config.
#     The canary itself needs a booted site (the shared CI's PHPUnit step is
#     where it earns its keep); what is checkable here is the wiring — that the
#     listener is added, and added AFTER CiviTestListener, which is what puts
#     the marker inside the transaction and the check after the rollback.
echo "== ckphpunit =="
CKPU="${WORKDIR}/ckphpunit"
mkdir -p "${CKPU}/fake"
printf '<extension key="org.acme.widget" type="module"><file>widget</file></extension>\n' > "${CKPU}/info.xml"
cat > "${CKPU}/phpunit.xml.dist" <<'XML'
<?xml version="1.0"?>
<phpunit bootstrap="tests/bootstrap.php">
    <listeners>
        <listener class="Civi\Test\CiviTestListener"><arguments/></listener>
    </listeners>
</phpunit>
XML
# A stand-in for phpunit that only records the config it was handed: the real
# one would need a CiviCRM to boot.
cat > "${CKPU}/fake/phpunit" <<SH
#!/bin/bash
while [ "\$#" -gt 0 ]; do
    [ "\$1" = -c ] && { cp "\$2" "${CKPU}/handed.xml"; exit 0; }
    shift
done
exit 3
SH
chmod +x "${CKPU}/fake/phpunit"
(cd "${CKPU}" && PATH="${CKPU}/fake:${PATH}" ckphpunit tests >/dev/null 2>&1) || true
if [ -f "${CKPU}/handed.xml" ] && grep -q 'TransactionCanaryListener' "${CKPU}/handed.xml"; then
    ok "ckphpunit injects the transaction canary listener"
else
    fail "ckphpunit did not inject the canary listener"
fi
if [ -f "${CKPU}/handed.xml" ] \
    && [ "$(grep -n 'CiviTestListener' "${CKPU}/handed.xml" | cut -d: -f1)" -lt \
         "$(grep -n 'TransactionCanaryListener' "${CKPU}/handed.xml" | cut -d: -f1)" ]; then
    ok "the canary listener is registered after CiviTestListener"
else
    fail "canary listener ordering (it must come after CiviTestListener)"
fi
# The generated config lives in a temp dir, never in the (possibly read-only
# under CI's uid mismatch) repo — and its paths must survive the move.
if [ -z "$(find "${CKPU}" -maxdepth 1 -name '.ckphpunit-canary.xml' -o -name 'ckphpunit-canary-*' | head -1)" ]; then
    ok "ckphpunit writes nothing into the repo directory"
else
    fail "ckphpunit left a generated config in the repo"
fi
if [ -f "${CKPU}/handed.xml" ] && grep -q "bootstrap=\"${CKPU}/tests/bootstrap.php\"" "${CKPU}/handed.xml"; then
    ok "ckphpunit absolutizes the bootstrap path in the temp config"
else
    fail "ckphpunit did not absolutize the bootstrap path (config: $(grep -o 'bootstrap="[^"]*"' "${CKPU}/handed.xml" 2>/dev/null))"
fi
rm -f "${CKPU}/handed.xml"
(cd "${CKPU}" && PATH="${CKPU}/fake:${PATH}" CK_TX_CANARY=0 ckphpunit tests >/dev/null 2>&1) || true
if [ -f "${CKPU}/handed.xml" ]; then
    fail "CK_TX_CANARY=0 still generated a config"
else
    ok "CK_TX_CANARY=0 runs plain phpunit"
fi

# ---------------------------------------------------------------------------
# 5c. cklifecycle: the cycle itself needs a booted site, so only the guards are
#     checkable here.
echo "== cklifecycle =="
if CKLIFECYCLE_HELP="$(cklifecycle --help 2>&1)" && grep -q 'disable' <<<"${CKLIFECYCLE_HELP}"; then
    ok "cklifecycle --help describes the cycle"
else
    fail "cklifecycle --help"
fi
if (cd "${WORKDIR}" && cklifecycle >/dev/null 2>&1); then
    fail "cklifecycle ran outside an extension root"
else
    ok "cklifecycle refuses to run without info.xml"
fi
if [ -f /opt/civikitchen/toolbelt/lib/settings-metadata-check.php ]; then
    ok "cklifecycle's settings-metadata-check.php payload is baked in"
else
    fail "settings-metadata-check.php missing from the image"
fi

# ---------------------------------------------------------------------------
# 6. composer can install a real package
echo "== composer install =="
mkdir -p "${WORKDIR}/composer_test"
cat > "${WORKDIR}/composer_test/composer.json" <<'JSON'
{
    "require": { "psr/log": "^3.0" }
}
JSON
if (cd "${WORKDIR}/composer_test" && \
    composer install --no-interaction --no-progress --quiet 2>&1) \
    && [[ -f "${WORKDIR}/composer_test/vendor/autoload.php" ]]; then
    ok "composer install (psr/log)"
else
    fail "composer install failed"
fi

# ---------------------------------------------------------------------------
# 7. civix renders help (proves the phar boots and core registers commands)
echo "== civix =="
if CIVIX_LIST="$(civix list 2>&1)" && grep -q "generate:module" <<<"${CIVIX_LIST}"; then
    ok "civix list includes generate:module"
else
    fail "civix list didn't include generate:module"
fi

# ---------------------------------------------------------------------------
# 8. Xdebug toggle
# pcov should always be loaded; xdebug should only load when XDEBUG_MODE is set
# via the entrypoint.
echo "== xdebug toggle =="
DEFAULT_MODULES="$(php -m)"
if grep -qiE "^pcov$" <<<"${DEFAULT_MODULES}"; then ok "pcov enabled by default"; else fail "pcov not enabled"; fi
if grep -qiE "^xdebug$" <<<"${DEFAULT_MODULES}"; then
    fail "xdebug enabled by default (should be off until XDEBUG_MODE is set)"
else
    ok "xdebug off by default"
fi

# The entrypoints' own toggle, run as the image user: it rewrites a conf.d
# file the image hands that user, so XDEBUG_MODE works without root.
toggle() { (export XDEBUG_MODE="$1"; . /usr/local/share/civikitchen/xdebug-toggle.sh) >/dev/null 2>&1; }
if toggle develop && XDEBUG_MODULES="$(php -m 2>&1)" && grep -qi "^xdebug$" <<<"${XDEBUG_MODULES}"; then
    ok "XDEBUG_MODE enables xdebug"
else
    fail "XDEBUG_MODE didn't enable xdebug"
fi
if toggle '' && ! php -m 2>&1 | grep -qi '^xdebug$'; then
    ok "an unset XDEBUG_MODE turns xdebug off again"
else
    fail "xdebug stayed on after XDEBUG_MODE was unset"
fi

# ---------------------------------------------------------------------------
# 5d. ckmutate: the point of mutation testing in one fixture — a test that
#     COVERS a line without asserting enough to notice it changing. `$n > 10`
#     asserted only at n = 50 survives the GreaterThanOrEqual mutant, so a 100%
#     floor must go red. Without the civikitchen.yaml key the tool must be a no-op.
echo "== ckmutate =="
MUT="${WORKDIR}/ckmutate"
mkdir -p "${MUT}/Civi" "${MUT}/tests"
printf '<extension key="org.acme.widget" type="module"><file>widget</file></extension>\n' > "${MUT}/info.xml"
cat > "${MUT}/Civi/Greeter.php" <<'PHP'
<?php
namespace Civi;
class Greeter {
    public function level(int $n): string {
        return $n > 10 ? 'high' : 'low';
    }
}
PHP
cat > "${MUT}/tests/bootstrap.php" <<'PHP'
<?php
spl_autoload_register(function ($class) {
    $path = __DIR__ . '/../' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($path)) { require $path; }
});
PHP
cat > "${MUT}/tests/GreeterTest.php" <<'PHP'
<?php
use PHPUnit\Framework\TestCase;
class GreeterTest extends TestCase {
    public function testHighLevel(): void {
        $this->assertSame('high', (new Civi\Greeter())->level(50));
    }
}
PHP
cat > "${MUT}/phpunit.xml.dist" <<'XML'
<?xml version="1.0"?>
<phpunit colors="false" bootstrap="tests/bootstrap.php">
    <testsuites><testsuite name="unit"><directory>tests</directory></testsuite></testsuites>
</phpunit>
XML
MUT_OUT="$( (cd "${MUT}" && ckmutate) 2>&1 || true)"
if (cd "${MUT}" && ckmutate) >/dev/null 2>&1 && grep -q 'no policy.mutation.minimum_msi' <<<"${MUT_OUT}"; then
    ok "ckmutate is a no-op without mutation_min_msi in civikitchen.yaml"
else
    fail "ckmutate without a floor should pass and say so (output: ${MUT_OUT:0:300})"
fi

printf '%s\n' 'version: 1' 'policy:' '  mutation:' '    minimum_msi: 100' '    paths:' '      - Civi' > "${MUT}/civikitchen.yaml"
MUT_OUT="$( (cd "${MUT}" && ckmutate) 2>&1 || true)"
if (cd "${MUT}" && ckmutate) >/dev/null 2>&1; then
    fail "ckmutate passed a 100% floor although a mutant survives (output: ${MUT_OUT:0:300})"
else
    ok "ckmutate fails the floor when a mutant escapes the suite"
fi
if grep -q 'GreaterThan' <<<"${MUT_OUT}"; then
    ok "ckmutate names the escaped mutant"
else
    fail "ckmutate did not report the escaped GreaterThan mutant (output: ${MUT_OUT:0:300})"
fi
if [ -e "${MUT}/.ckmutate.json" ]; then
    fail "ckmutate left its generated infection config behind"
else
    ok "ckmutate removes its generated config"
fi

printf '%s\n' 'version: 1' 'policy:' '  mutation:' '    minimum_msi: 50' '    paths:' '      - Civi' > "${MUT}/civikitchen.yaml"
if (cd "${MUT}" && ckmutate) >/dev/null 2>&1; then
    ok "ckmutate passes a floor the suite meets"
else
    MUT_OK_OUT="$( (cd "${MUT}" && ckmutate) 2>&1 || true)"
    fail "ckmutate failed a floor it should meet (output: ${MUT_OK_OUT:0:300})"
fi

# ---------------------------------------------------------------------------
# 5e. cktaint: the stubs, proven by fixtures.
#
# Every case is a pair: a `_bad` file where request input reaches a sink and
# has to be reported, and a `_good` file with the escape (or the safe API) in
# between, which has to stay silent. A stub that stops matching core — a
# renamed method, a changed argument position — turns the pair red on the
# side that matters: the flow stops being found.
echo "== cktaint (Psalm taint analysis) =="
TAINT="$(mktemp -d)/taint"
mkdir -p "${TAINT}"

# --- PSR-7 request input (the house standard for HTTP endpoints) -> SQL
cat > "${TAINT}/01_psr7_sql_bad.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f01(ServerRequestInterface $r): void {
  $q = $r->getQueryParams();
  CRM_Core_DAO::executeQuery("SELECT id FROM civicrm_contact WHERE sort_name = '" . $q['name'] . "'");
}
PHP
cat > "${TAINT}/01_psr7_sql_good.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f01g(ServerRequestInterface $r): void {
  $q = $r->getQueryParams();
  $safe = CRM_Utils_Type::escape($q['name'], 'String');
  CRM_Core_DAO::executeQuery("SELECT id FROM civicrm_contact WHERE sort_name = '" . $safe . "'");
}
PHP
# --- the request body (webhook payload) -> SQL
cat > "${TAINT}/02_body_sql_bad.php" <<'PHP'
<?php
use Psr\Http\Message\RequestInterface;
function f02(RequestInterface $r): void {
  $body = (string) $r->getBody();
  CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_mailing WHERE name = '$body'");
}
PHP
cat > "${TAINT}/02_body_sql_good.php" <<'PHP'
<?php
use Psr\Http\Message\RequestInterface;
function f02g(RequestInterface $r): void {
  $body = CRM_Core_DAO::escapeString((string) $r->getBody());
  CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_mailing WHERE name = '$body'");
}
PHP
# --- the request URI -> Location header
cat > "${TAINT}/03_uri_header_bad.php" <<'PHP'
<?php
use Psr\Http\Message\RequestInterface;
function f03(RequestInterface $r): void {
  CRM_Utils_System::redirect($r->getUri()->getQuery());
}
PHP
cat > "${TAINT}/03_uri_header_good.php" <<'PHP'
<?php
use Psr\Http\Message\RequestInterface;
function f03g(RequestInterface $r): void {
  CRM_Utils_System::redirect(CRM_Utils_String::munge($r->getUri()->getQuery()));
}
PHP
# --- a route attribute -> filesystem path
cat > "${TAINT}/04_attr_file_bad.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f04(ServerRequestInterface $r): void {
  CRM_Utils_File::createDir('/tmp/x/' . $r->getAttribute('slug'));
}
PHP
cat > "${TAINT}/04_attr_file_good.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f04g(ServerRequestInterface $r): void {
  CRM_Utils_File::createDir('/tmp/x/' . CRM_Utils_File::makeFileName((string) $r->getAttribute('slug')));
}
PHP
# --- an upload's client filename -> moveTo()
cat > "${TAINT}/05_upload_file_bad.php" <<'PHP'
<?php
use Psr\Http\Message\UploadedFileInterface;
function f05(UploadedFileInterface $u): void {
  $u->moveTo('/var/tmp/' . $u->getClientFilename());
}
PHP
cat > "${TAINT}/05_upload_file_good.php" <<'PHP'
<?php
use Psr\Http\Message\UploadedFileInterface;
function f05g(UploadedFileInterface $u): void {
  $u->moveTo('/var/tmp/' . CRM_Utils_File::makeFileName((string) $u->getClientFilename()));
}
PHP
# --- CRM_Utils_SQL_Select: raw fragment vs. the interpolation array
cat > "${TAINT}/06_sqlselect_bad.php" <<'PHP'
<?php
function f06(): void {
  $id = CRM_Utils_Request::retrieve('cid', 'String');
  CRM_Utils_SQL_Select::from('civicrm_contact')->where('id = ' . $id)->execute();
}
PHP
cat > "${TAINT}/06_sqlselect_good.php" <<'PHP'
<?php
function f06g(): void {
  $id = CRM_Utils_Request::retrieve('cid', 'String');
  CRM_Utils_SQL_Select::from('civicrm_contact')->where('id = #cid', ['cid' => $id])->execute();
}
PHP
# --- SSRF: Guzzle (the client Civi::httpClient() hands out)
cat > "${TAINT}/07_guzzle_ssrf_bad.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f07(ServerRequestInterface $r): void {
  $client = new \GuzzleHttp\Client();
  $client->get((string) $r->getQueryParams()['url']);
}
PHP
cat > "${TAINT}/07_guzzle_ssrf_good.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f07g(ServerRequestInterface $r): void {
  $client = new \GuzzleHttp\Client();
  $client->get('https://api.example.org/v1/ping', ['json' => $r->getParsedBody()]);
}
PHP
# --- SSRF: CRM_Utils_HttpClient
cat > "${TAINT}/08_httpclient_ssrf_bad.php" <<'PHP'
<?php
function f08(): void {
  $url = CRM_Utils_Request::retrieveValue('target', 'String');
  (new CRM_Utils_HttpClient())->get($url);
}
PHP
cat > "${TAINT}/08_httpclient_ssrf_good.php" <<'PHP'
<?php
function f08g(): void {
  $token = CRM_Utils_Request::retrieveValue('token', 'String');
  (new CRM_Utils_HttpClient())->post('https://api.example.org/v1/ping', ['token' => $token]);
}
PHP
# --- a request header echoed back into a response header
cat > "${TAINT}/09_setheader_bad.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f09(ServerRequestInterface $r): void {
  CRM_Utils_System::setHttpHeader('X-Trace', (string) $r->getHeaderLine('X-Request-Id'));
}
PHP
cat > "${TAINT}/09_setheader_good.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f09g(ServerRequestInterface $r): void {
  CRM_Utils_System::setHttpHeader('X-Trace', CRM_Utils_String::munge((string) $r->getHeaderLine('X-Request-Id')));
}
PHP
# --- a request-chosen filename -> Content-Disposition
cat > "${TAINT}/10_download_bad.php" <<'PHP'
<?php
function f10(): void {
  $name = CRM_Utils_Request::retrieveValue('file', 'String');
  $buf = 'x';
  CRM_Utils_System::download($name, 'text/plain', $buf);
}
PHP
cat > "${TAINT}/10_download_good.php" <<'PHP'
<?php
function f10g(): void {
  $name = CRM_Utils_String::munge((string) CRM_Utils_Request::retrieveValue('file', 'String'));
  $buf = 'x';
  CRM_Utils_System::download($name, 'text/plain', $buf);
}
PHP
# --- shell (Psalm's own sink, reached from a PSR-7 source)
cat > "${TAINT}/11_shell_bad.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f11(ServerRequestInterface $r): void {
  exec('convert ' . (string) $r->getQueryParams()['f']);
}
PHP
cat > "${TAINT}/11_shell_good.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f11g(ServerRequestInterface $r): void {
  exec('convert ' . escapeshellarg((string) $r->getQueryParams()['f']));
}
PHP
# --- file_get_contents on a request-controlled path
cat > "${TAINT}/12_fgc_bad.php" <<'PHP'
<?php
use Psr\Http\Message\RequestInterface;
function f12(RequestInterface $r): void {
  echo strlen((string) file_get_contents((string) $r->getUri()->getPath()));
}
PHP
cat > "${TAINT}/12_fgc_good.php" <<'PHP'
<?php
use Psr\Http\Message\RequestInterface;
function f12g(RequestInterface $r): void {
  echo strlen((string) file_get_contents('/srv/data/' . CRM_Utils_File::makeFileName((string) $r->getUri()->getPath())));
}
PHP

# One run over the whole fixture set — Psalm reports one flow per source/sink
# pair, and every pair here is distinct, so all twelve findings show up
# together. --no-cache because the fixtures live in a fresh temp dir.
TAINT_RC=0
TAINT_OUT_RAW="$( (cd "${TAINT}" && cktaint --no-cache) 2>&1 )" || TAINT_RC=$?
TAINT_OUT="$(printf '%s' "${TAINT_OUT_RAW}" | sed -E 's/\x1b\[[0-9;]*m//g')"

# Blocking fixtures (TaintedSql & co.) are present, so the run must fail.
if [[ "${TAINT_RC}" -ne 0 ]]; then
    ok "cktaint exits non-zero with blocking findings present"
else
    fail "cktaint exited 0 despite blocking TaintedSql/Shell/SSRF fixtures"
fi

# A run restricted to an advisory-only fixture (header taint = INFO) must
# report the finding but keep exit 0 — that is the blocking/advisory split.
ADV_RC=0
ADV_OUT="$( (cd "${TAINT}" && cktaint --no-cache 03_uri_header_bad.php) 2>&1 )" || ADV_RC=$?
if [[ "${ADV_RC}" -eq 0 ]] && grep -q 'TaintedHeader' <<<"${ADV_OUT}"; then
    ok "cktaint reports advisory TaintedHeader without failing (exit 0)"
else
    fail "advisory-only run: expected TaintedHeader + exit 0, got exit ${ADV_RC}"
fi

# case-file -> the issue that must be reported for it
TAINT_CASES=(
    "01_psr7_sql_bad.php:TaintedSql:PSR-7 getQueryParams -> executeQuery"
    "02_body_sql_bad.php:TaintedSql:PSR-7 request body -> singleValueQuery"
    "03_uri_header_bad.php:TaintedHeader:request URI -> CRM_Utils_System::redirect"
    "04_attr_file_bad.php:TaintedFile:route attribute -> CRM_Utils_File::createDir"
    "05_upload_file_bad.php:TaintedFile:upload filename -> moveTo"
    "06_sqlselect_bad.php:TaintedSql:request input -> CRM_Utils_SQL_Select::where"
    "07_guzzle_ssrf_bad.php:TaintedSSRF:request input -> Guzzle client"
    "08_httpclient_ssrf_bad.php:TaintedSSRF:request input -> CRM_Utils_HttpClient"
    "09_setheader_bad.php:TaintedHeader:request header -> setHttpHeader"
    "10_download_bad.php:TaintedHeader:request input -> CRM_Utils_System::download"
    "11_shell_bad.php:TaintedShell:PSR-7 input -> exec"
    "12_fgc_bad.php:TaintedFile:PSR-7 input -> file_get_contents"
)
for case in "${TAINT_CASES[@]}"; do
    file="${case%%:*}"; rest="${case#*:}"; issue="${rest%%:*}"; label="${rest#*:}"
    if grep -q "${issue}.*${file}" <<<"${TAINT_OUT}"; then
        ok "cktaint reports ${issue}: ${label}"
    else
        fail "cktaint missed ${issue} in ${file} (${label})"
    fi
    good="${file/_bad.php/_good.php}"
    if grep -q "${good}" <<<"${TAINT_OUT}"; then
        fail "cktaint false positive: the escaped ${good} was reported"
    else
        ok "cktaint stays silent on the escaped ${good}"
    fi
done

# ---------------------------------------------------------------------------
# 9. The scaffold template passes civikitchen's own gates
# Consumers get these files verbatim from `ckinit --update` and then run cklint
# and ckfmt over them in their CI, so a template that trips either one breaks
# every conforming repo. Stamp a throwaway extension and hold it to the same bar.
echo "== scaffold template vs. cklint/ckfmt =="
CK_SRC="${CK_SRC:-/civikitchen-src}"
if [[ ! -x "${CK_SRC}/scaffold/ckinit.php" ]]; then
    fail "the repo source is not mounted at ${CK_SRC} — the template gate cannot run"
else
    TPL_SRC="${WORKDIR}/template_gate"
    mkdir -p "${TPL_SRC}"
    # Writable copy: ckinit reads its policy helper out of toolbelt/ and its YAML
    # parser out of packages/, both by path relative to scaffold/.
    cp -R "${CK_SRC}/scaffold" "${CK_SRC}/packages" "${CK_SRC}/toolbelt" "${TPL_SRC}/"
    TPL_EXT="${TPL_SRC}/example_ext"
    mkdir -p "${TPL_EXT}"
    printf '%s\n' '<extension key="org.acme.example_ext" type="module"><file>example_ext</file><license>AGPL-3.0</license></extension>' \
        > "${TPL_EXT}/info.xml"
    # ckinit's YAML parser lives in a gitignored vendor/, so the checkout CI
    # mounts here usually has none.
    TPL_SCHEMA="${TPL_SRC}/packages/civikitchen-scenario-schema"
    if [[ ! -f "${TPL_SCHEMA}/vendor/autoload.php" ]]; then
        composer install --no-interaction --no-progress --quiet \
            --working-dir "${TPL_SCHEMA}" >/dev/null 2>&1 || true
    fi
    # extra.strauss opts the extension into the files ckinit writes only on request.
    if TPL_OUT="$("${TPL_SRC}/scaffold/ckinit.php" "${TPL_EXT}" 2>&1)" \
        && php -r '$f = $argv[1]; $j = json_decode(file_get_contents($f), true); $j["extra"]["strauss"] = ["packages" => []];
            file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");' "${TPL_EXT}/composer.json" \
        && TPL_OUT="$("${TPL_SRC}/scaffold/ckinit.php" --update "${TPL_EXT}" 2>&1)" \
        && { test -f "${TPL_EXT}/.civikitchen/strauss.php" \
            || { TPL_OUT="--update wrote no .civikitchen/strauss.php: ${TPL_OUT}"; false; }; } \
        && git -C "${TPL_EXT}" init -q \
        && git -C "${TPL_EXT}" add -A; then
        ok "ckinit stamps the template into a fresh extension"
        if TPL_LINT="$(cd "${TPL_EXT}" && cklint --all 2>&1)"; then
            ok "stamped template passes cklint --all"
        else
            fail "stamped template fails cklint --all"
            echo "${TPL_LINT}"
        fi
        if TPL_FMT="$(cd "${TPL_EXT}" && ckfmt --check 2>&1)"; then
            ok "stamped template passes ckfmt --check"
        else
            fail "stamped template fails ckfmt --check"
            echo "${TPL_FMT}"
        fi
        # phpstanBootstrap.php is written for the standalone layout, the one
        # extension CI runs on; the tests config is what reads tests/phpunit/.
        if [ "${CIVICRM_UF:-}" = "Standalone" ]; then
            mkdir -p "${TPL_EXT}/Civi" "${TPL_EXT}/CRM"
            printf '<?php\n' > "${TPL_EXT}/example_ext.civix.php"
            if TPL_STAN="$(cd "${TPL_EXT}" && phpstan analyse -c phpstan-tests.neon.dist --no-progress 2>&1)"; then
                ok "stamped template passes phpstan on the tests config"
            else
                fail "stamped template fails phpstan on the tests config"
                echo "${TPL_STAN}"
            fi
        fi
    else
        fail "ckinit could not stamp the template: ${TPL_OUT}"
    fi
fi

# ---------------------------------------------------------------------------
# 9b. The Playwright starter passes the same gates
# The docs tell consumers to copy its config and tests into their repository,
# where ck ci runs ckeslint and ckfmt over them.
echo "== Playwright starter vs. ckeslint/ckfmt =="
PW_SRC="${CK_SRC}/examples/extension-with-playwright"
if [[ ! -f "${PW_SRC}/playwright.config.ts" ]]; then
    fail "no Playwright starter at ${PW_SRC} — the starter gate cannot run"
else
    PW_EXT="${WORKDIR}/playwright_gate"
    mkdir -p "${PW_EXT}"
    cp -R "${PW_SRC}/playwright.config.ts" "${PW_SRC}/tests" "${PW_EXT}/"
    printf '%s\n' '<extension key="org.acme.pw_ext" type="module"><file>pw_ext</file></extension>' \
        > "${PW_EXT}/info.xml"
    git -C "${PW_EXT}" init -q
    git -C "${PW_EXT}" add -A
    if PW_LINT="$(cd "${PW_EXT}" && ckeslint 2>&1)"; then
        ok "Playwright starter passes ckeslint"
    else
        fail "Playwright starter fails ckeslint"
        echo "${PW_LINT}"
    fi
    if PW_FMT="$(cd "${PW_EXT}" && ckfmt --check 2>&1)"; then
        ok "Playwright starter passes ckfmt --check"
    else
        fail "Playwright starter fails ckfmt --check"
        echo "${PW_FMT}"
    fi
fi

# ---------------------------------------------------------------------------
echo
echo "${PASS} passed, ${FAIL} failed"
if [[ "${FAIL}" -gt 0 ]]; then
    printf '  - %s\n' "${FAILURES[@]}"
    exit 1
fi
