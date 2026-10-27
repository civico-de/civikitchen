#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

help=$("${root}/toolbelt/bin/ck" help)
grep -q 'ck conform' <<<"${help}"
printf '%s\n' 'version: 1' 'policy:' '  coverage:' '    minimum: 73' > "${work}/civikitchen.yaml"
value=$(cd "${work}" && "${root}/toolbelt/bin/ck" conform --policy min_coverage)
[ "${value}" = 73 ] || { echo "ck conform did not dispatch (got: ${value})" >&2; exit 1; }
# A misspelt key is an error, not an unset value a coverage floor would skip.
status=0
out=$(cd "${work}" && "${root}/toolbelt/bin/ck" conform --policy min_covrage 2>&1) || status=$?
if [ "${status}" -ne 2 ] || ! grep -q 'min_covrage: not a policy key' <<<"${out}"; then
  echo "ckconform --policy accepted an unknown key (exit ${status}): ${out}" >&2
  exit 1
fi
# A policy file it cannot read is an error too, for both read-outs.
refuses_unreadable() {
  local status=0 out
  out=$(cd "${work}" && "${root}/toolbelt/bin/ckconform" "$@" 2>&1) || status=$?
  if [ "${status}" -ne 2 ] || ! grep -q 'cannot read civikitchen.yaml' <<<"${out}"; then
    echo "ckconform $* read an unreadable civikitchen.yaml as empty (exit ${status}): ${out}" >&2
    exit 1
  fi
}
chmod 000 "${work}/civikitchen.yaml"
refuses_unreadable --policy min_coverage
refuses_unreadable --policy-env
chmod 644 "${work}/civikitchen.yaml"
cat > "${work}/info.xml" <<'XML'
<?xml version="1.0"?>
<extension key="demo" type="module">
  <file>demo</file><name>Demo</name><license>Proprietary</license>
  <compatibility><ver>6.17</ver></compatibility>
</extension>
XML
(cd "${work}" && git init -q && git add .)
(
  cd "${work}"
  rc=0
  "${root}/toolbelt/bin/ck" conform --format=json > report.json || rc=$?
  [ "$rc" = 1 ]
)
php -r '
  $v = json_decode(file_get_contents($argv[1]), TRUE, 512, JSON_THROW_ON_ERROR);
  if (($v["tool"] ?? NULL) !== "ckconform" || !isset($v["results"][0]["rule"])) exit(1);
' "${work}/report.json"
(
  cd "${work}"
  rc=0
  "${root}/toolbelt/bin/ck" conform --format=sarif > report.sarif || rc=$?
  [ "$rc" = 1 ]
)
php -r '
  $v = json_decode(file_get_contents($argv[1]), TRUE, 512, JSON_THROW_ON_ERROR);
  if (($v["version"] ?? NULL) !== "2.1.0" || !isset($v["runs"][0]["tool"]["driver"])) exit(1);
' "${work}/report.sarif"
"${root}/toolbelt/bin/ck" profile validate "${root}/docker/profiles/mailing" >/dev/null
"${root}/toolbelt/bin/ckprofile" validate "${root}/docker/profiles/mailing" >/dev/null
profiles=$("${root}/toolbelt/bin/ck" profile list)
grep -q $'^mailing\t' <<<"${profiles}"
deps_help=$("${root}/toolbelt/bin/ckdeps" --help)
grep -q 'ck dependencies' <<<"${deps_help}"
# The tools outside the PHP dispatcher answer --help too, without doing their job.
"${root}/toolbelt/bin/ckconform" --help | grep -q '^ckconform - ' || { echo "ckconform --help printed no usage" >&2; exit 1; }
for tool in ckboot ckcoretest cktestreset; do
  bash "${root}/toolbelt/bin/${tool}" --help | grep -q "^${tool} - " || { echo "${tool} --help printed no usage" >&2; exit 1; }
done
for alias in ckanalyze ckcivix ckcompat ckconform ckcoverage ckdeps ckeslint ckfmt cklifecycle cklint ckmigrate ckmutate ckphpunit ckprofile ckrelease ckscenario ckschemadiff cksmarty; do
  [ -L "${root}/toolbelt/bin/${alias}" ] || { echo "${alias} is not a symlink" >&2; exit 1; }
  [ "$(readlink "${root}/toolbelt/bin/${alias}")" = ck ] || { echo "${alias} does not target ck" >&2; exit 1; }
done
if "${root}/toolbelt/bin/ck" no-such-command >/dev/null 2>&1; then
  echo "unknown ck command passed" >&2
  exit 1
fi

# The staged-release pin's line format is a contract: the extension
# provisioner and extension-release.yml both split it on spaces. Its own
# directory, so the policy above stays the one the conform run saw.
pins=$(mktemp -d)
trap 'rm -rf "$work" "$pins"' EXIT
digest=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
printf '%s\n' 'version: 1' 'policy:' '  extension_sources:' \
  '    - key: org.example.dep' "      version: '^1.2'" \
  '      release:' \
  '        repository: example-org/dep' \
  '        tag: v1.2.3' \
  '        asset: dep-1.2.3.zip' \
  "      sha256: ${digest}" \
  '      reason: private repository, no registry serves it' > "${pins}/civikitchen.yaml"
line=$(cd "${pins}" && "${root}/toolbelt/bin/ckconform" --policy extension_release)
expected="org.example.dep example-org/dep v1.2.3 dep-1.2.3.zip ${digest} -- private repository, no registry serves it"
[ "${line}" = "${expected}" ] || { echo "unexpected extension_release line: ${line}" >&2; exit 1; }
# The two pin forms are separate keys: a release pin has no URL to download.
[ -z "$(cd "${pins}" && "${root}/toolbelt/bin/ckconform" --policy extension_source)" ] \
  || { echo "a release pin must not also emit an extension_source" >&2; exit 1; }
version=$(cd "${pins}" && "${root}/toolbelt/bin/ckconform" --policy extension_version)
[ "${version}" = 'org.example.dep@^1.2' ] || { echo "unexpected extension_version: ${version}" >&2; exit 1; }

# `ck ci` is the one gate list extension-ci.yml's `ci` job runs. Fakes stand in
# for every gate but ckdeps, which is the real command over a fake analyser.
ci=$(cd "$(mktemp -d)" && pwd -P)
trap 'rm -rf "$work" "$pins" "$ci"' EXIT
mkdir -p "${ci}/bin" "${ci}/ext" "${ci}/repo/ext"
cp "${work}/info.xml" "${ci}/ext/info.xml"
cp "${work}/info.xml" "${ci}/repo/ext/info.xml"
printf '%s\n' '{"name": "example/demo", "require": {"php": ">=8.1"}}' > "${ci}/ext/composer.json"
for gate in cklint ckconform ckcivix ckfmt ckcoverage phpunit phpstan ckanalyze ckcompat cktaint cksmarty ckeslint; do
  cat > "${ci}/bin/${gate}" <<'SH'
#!/bin/bash
name=$(basename "$0")
printf '%s|%s|%s\n' "$name" "$(pwd -P)" "$*" >> "$CK_FAKE_LOG"
if [ -n "${GITHUB_ACTIONS:-}${GITHUB_STEP_SUMMARY:-}" ]; then echo "$name" >> "$CK_FAKE_LOG.github"; fi
case ",${CK_FAKE_FAIL:-}," in (*",${name},"*) exit 1 ;; esac
SH
  chmod +x "${ci}/bin/${gate}"
done
ln -s "${root}/toolbelt/bin/ck" "${ci}/bin/ck"
ln -s "${root}/toolbelt/bin/ck" "${ci}/bin/ckdeps"
# The shadow-dependency finding a real analyser reports for a package the code
# uses but composer.json does not declare.
cat > "${ci}/bin/composer-dependency-analyser" <<'SH'
#!/bin/bash
printf '%s|%s|%s\n' ckdeps "$(pwd -P)" "$*" >> "$CK_FAKE_LOG"
echo 'Found shadow dependencies! (those are used, but not listed as dependency in composer.json)'
echo '  • guzzlehttp/guzzle'
exit 255
SH
chmod +x "${ci}/bin/composer-dependency-analyser"
run_ci() {
  (cd "${ci}/ext" && env -u GITHUB_ACTIONS -u GITHUB_STEP_SUMMARY -u CK_EXT_PATH -u CK_TOOL_PATH \
    -u CK_EXTRA_PHPUNIT_CONFIG PATH="${ci}/bin:${PATH}" CK_FAKE_LOG="${ci}/log" "$@")
}
fail() { echo "ck ci: $*" >&2; exit 1; }

: > "${ci}/log"
rc=0
out=$(run_ci ck ci 2>&1) || rc=$?
[ "$rc" = 1 ] || fail "a shadow dependency must fail the run (rc=$rc)"$'\n'"$out"
grep -q 'Found shadow dependencies' <<<"$out" || fail "ckdeps output missing"
grep -qE '^  ckdeps +fail +[0-9.]+s +exit 255$' <<<"$out" || fail "summary does not mark ckdeps failed"$'\n'"$out"
grep -qE '^  cklint +pass ' <<<"$out" || fail "summary does not mark cklint passed"
grep -qE '^  phpunit-extra +skipped +- ' <<<"$out" || fail "phpunit-extra must be skipped without a config"
grep -qE '^  phpstan-tests +skipped ' <<<"$out" || fail "phpstan-tests must be skipped without its config"
order=$(cut -d'|' -f1 "${ci}/log" | paste -sd' ' -)
[ "$order" = 'cklint ckconform ckcivix ckfmt ckcoverage phpstan ckanalyze ckcompat ckdeps cktaint cksmarty ckeslint' ] \
  || fail "gates ran out of order or not after a failure: $order"
grep -qx "cklint|${ci}/ext|--all" "${ci}/log" || fail "cklint --all not run in the extension"
grep -qx "ckcoverage|${ci}/ext|tests/phpunit" "${ci}/log" || fail "ckcoverage tests/phpunit not run"
grep -qx "phpstan|${ci}/ext|analyse --no-progress" "${ci}/log" || fail "phpstan arguments differ"

# Git-reading gates run in CK_TOOL_PATH, CiviCRM-booting ones in CK_EXT_PATH;
# the opt-ins switch on by their config file and by the environment.
: > "${ci}/log"
touch "${ci}/ext/phpstan-tests.neon.dist"
rc=0
run_ci env CK_EXT_PATH="${ci}/ext" CK_TOOL_PATH="${ci}/repo/ext" CK_EXTRA_PHPUNIT_CONFIG=phpunit-unit.xml.dist \
  ck ci --skip ckdeps > "${ci}/out" 2>&1 || rc=$?
[ "$rc" = 0 ] || fail "all-green run exited $rc"
for tool in cklint ckconform ckcivix ckfmt ckanalyze ckcompat cktaint ckeslint; do
  grep -q "^${tool}|${ci}/repo/ext|" "${ci}/log" || fail "${tool} did not run in CK_TOOL_PATH"
done
for ext in ckcoverage phpstan cksmarty; do
  grep -q "^${ext}|${ci}/ext|" "${ci}/log" || fail "${ext} did not run in CK_EXT_PATH"
done
grep -qx "phpunit|${ci}/ext|-c phpunit-unit.xml.dist" "${ci}/log" || fail "extra PHPUnit suite not run"
grep -qx "phpstan|${ci}/ext|analyse -c phpstan-tests.neon.dist --no-progress" "${ci}/log" \
  || fail "phpstan-tests not run with its config"
if grep -q '^ckdeps' "${ci}/log"; then fail "--skip ckdeps still ran ckdeps"; fi
rm "${ci}/ext/phpstan-tests.neon.dist"

# Selection: canonical order whatever the flag order, unknown names refused.
: > "${ci}/log"
run_ci ck ci --only=ckeslint,cklint > /dev/null
[ "$(cut -d'|' -f1 "${ci}/log" | paste -sd' ' -)" = 'cklint ckeslint' ] || fail "--only ran the wrong gates"
for args in '--only nosuch' '--skip cklint,nosuch' '--only ,cklint' '--only' '--only cklint --skip cklint' '--frobnicate'; do
  rc=0
  read -ra argv <<<"$args"
  err=$(run_ci ck ci "${argv[@]}" 2>&1 >/dev/null) || rc=$?
  [ "$rc" = 2 ] || fail "'ck ci $args' exited $rc, expected a usage error"
  [ -n "$err" ] || fail "'ck ci $args' gave no message"
done
grep -q 'unknown gate: nosuch' <<<"$(run_ci ck ci --only nosuch 2>&1)" || fail "unknown gate not named"
grep -q 'empty gate name' <<<"$(run_ci ck ci --only ,cklint 2>&1)" || fail "empty gate name not named"

# A shadow gate's findings are reported, never the run's exit code.
rc=0
out=$(run_ci env CK_FAKE_FAIL=ckanalyze ck ci --only ckanalyze 2>&1) || rc=$?
[ "$rc" = 0 ] || fail "ckanalyze findings failed the run (rc=$rc)"$'\n'"$out"
grep -qE '^  ckanalyze +shadow ' <<<"$out" || fail "summary does not mark ckanalyze as shadow"$'\n'"$out"

# An early failure does not stop the later gates, and it alone sets the exit code.
: > "${ci}/log"
rc=0
run_ci env CK_FAKE_FAIL=cklint ck ci --skip ckdeps > /dev/null 2>&1 || rc=$?
[ "$rc" = 1 ] || fail "a failing cklint must fail the run (rc=$rc)"
grep -q '^ckeslint|' "${ci}/log" || fail "gates after a failure did not run"

# GitHub Actions: one log group per gate, an error annotation per failure, and
# the table plus the taint verdict appended to the step summary.
printf '%s\n' '# earlier step' > "${ci}/summary.md"
rc=0
out=$(run_ci env GITHUB_ACTIONS=true GITHUB_STEP_SUMMARY="${ci}/summary.md" CK_FAKE_FAIL=cktaint \
  ck ci --only cklint,ckdeps,cktaint 2>&1) || rc=$?
[ "$rc" = 1 ] || fail "GitHub run exited $rc"
[ "$(grep -c '^::group::' <<<"$out")" = 3 ] || fail "expected one ::group:: per gate"$'\n'"$out"
[ ! -e "${ci}/log.github" ] || fail "gates saw the GitHub Actions environment: $(cat "${ci}/log.github")"
[ "$(grep -c '^::endgroup::' <<<"$out")" = 3 ] || fail "expected one ::endgroup:: per gate"
grep -q '^::error title=ck ci::ckdeps failed (exit 255)$' <<<"$out" || fail "no error annotation for ckdeps"
grep -q '^# earlier step$' "${ci}/summary.md" || fail "step summary overwritten instead of appended"
grep -qF '| `ckdeps` | fail (exit 255) |' "${ci}/summary.md" || fail "step summary lacks the ckdeps row"
grep -qF '| `cklint` | pass |' "${ci}/summary.md" || fail "step summary lacks the cklint row"
grep -q '^### Taint analysis: blocking findings or analysis failure$' "${ci}/summary.md" \
  || fail "failed cktaint not reported as blocking"
run_ci env GITHUB_ACTIONS=true GITHUB_STEP_SUMMARY="${ci}/pass.md" ck ci --only cktaint > /dev/null
grep -q '^### Taint analysis: no blocking findings$' "${ci}/pass.md" || fail "passing cktaint not reported"
rc=0
err=$(run_ci env GITHUB_ACTIONS=true GITHUB_STEP_SUMMARY="${ci}/missing/summary.md" ck ci --only cklint 2>&1 >/dev/null) || rc=$?
[ "$rc" = 2 ] || fail "an unwritable step summary must fail the run (rc=$rc)"
grep -q 'cannot append the summary' <<<"$err" || fail "unwritable step summary not reported: $err"
run_ci env GITHUB_STEP_SUMMARY="${ci}/local.md" ck ci --only cklint > "${ci}/out"
[ ! -e "${ci}/local.md" ] || fail "step summary written outside GitHub Actions"
if grep -q '::group::' "${ci}/out"; then fail "log groups printed outside GitHub Actions"; fi

echo "ck dispatcher tests passed"
