#!/usr/bin/env bash
# The private-deps sibling build: a sibling declaring policy.dist.build with
# Bun is built before the mount, one without a declaration stays untouched,
# and every way the build can go wrong fails the step. `bun` and `composer`
# are stubbed; civikitchen.yaml is read by the real ckconform.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
script="$root/.github/actions/private-deps/build-siblings.sh"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

sibs="$work/ws/.civikitchen-siblings"
mkdir -p "$work/bin" "$sibs"/{built,other,plain,nocfg}
# `bun run build` writes dist/ unless CK_BUN_FAIL names the call to fail.
cat > "$work/bin/bun" <<'STUB'
#!/usr/bin/env bash
echo "$PWD $*" >> "$CK_CALLS"
[ "${CK_BUN_FAIL:-}" = "$*" ] && exit 1
if [ "$*" = "run build" ] && [ -z "${CK_BUN_NO_OUTPUT:-}" ]; then mkdir -p dist && echo '{}' > dist/strings.json; fi
exit 0
STUB
printf '#!/usr/bin/env bash\nexit 0\n' > "$work/bin/composer"
chmod +x "$work/bin/bun" "$work/bin/composer"

declare_build() {
  printf 'version: 1\npolicy:\n  dist:\n    build:\n      tool: bun\n      outputs:\n        - dist\n' > "$1/civikitchen.yaml"
  printf '{"packageManager": "bun@%s"}\n' "$2" > "$1/package.json"
  echo '{}' > "$1/bun.lock"
}
declare_build "$sibs/built" 1.3.0
declare_build "$sibs/other" 1.2.0
printf 'version: 1\npolicy:\n  vendor: committed\n' > "$sibs/plain/civikitchen.yaml"
echo '{"packageManager": "bun@1.3.0"}' > "$sibs/plain/package.json"

calls="$work/calls"
run() {
  : > "$calls"
  : > "$work/output"
  PATH="$work/bin:$PATH" CK_CALLS="$calls" GITHUB_WORKSPACE="$work/ws" GITHUB_OUTPUT="$work/output" \
    bash "$script" "$@" > "$work/out" 2>&1
}
all=".civikitchen-siblings/built .civikitchen-siblings/plain .civikitchen-siblings/nocfg"

# plan: only the declaring sibling is listed, with its package.json for setup-bun.
CK_SIBLING_DIRS="$all" CK_NODE_VERSION=24 run plan || { cat "$work/out" >&2; fail "plan failed"; }
grep -qx 'dirs=.civikitchen-siblings/built' "$work/output" \
  || fail "plan must list exactly the sibling with a declared build; got: $(cat "$work/output")"
grep -qx 'bun_version_file=.civikitchen-siblings/built/package.json' "$work/output" \
  || fail "plan must name the building sibling's package.json; got: $(cat "$work/output")"

# build: install and build in the sibling's own directory; the others are untouched.
CK_SIBLING_BUILD_DIRS=".civikitchen-siblings/built" run build || { cat "$work/out" >&2; fail "build failed"; }
[ "$(cat "$calls")" = "$sibs/built install --frozen-lockfile
$sibs/built run build" ] || fail "expected a frozen install and a build in the sibling; got: $(cat "$calls")"
[ -f "$sibs/built/dist/strings.json" ] || fail "the declared output was not built"
[ ! -e "$sibs/plain/dist" ] && [ ! -e "$sibs/nocfg/dist" ] || fail "a sibling without a declared build was built"

# No sibling declares a build: nothing to set up.
CK_SIBLING_DIRS=".civikitchen-siblings/plain .civikitchen-siblings/nocfg" run plan \
  || { cat "$work/out" >&2; fail "plan without builds failed"; }
grep -qx 'dirs=' "$work/output" || fail "plan must list no sibling; got: $(cat "$work/output")"

# A failing install or build fails the step and names the sibling.
for call in "install --frozen-lockfile" "run build"; do
  if CK_BUN_FAIL="$call" CK_SIBLING_BUILD_DIRS=".civikitchen-siblings/built" run build; then
    fail "a failing 'bun $call' must fail the step"
  fi
  grep -q 'sibling .civikitchen-siblings/built: its declared release build' "$work/out" \
    || fail "the failure must name the sibling; got: $(cat "$work/out")"
done

# A build that does not write its declared output fails.
/bin/rm -rf "$sibs/built/dist"
if CK_BUN_NO_OUTPUT=1 CK_SIBLING_BUILD_DIRS=".civikitchen-siblings/built" run build; then
  fail "a build without its declared output must fail the step"
fi
grep -q 'did not write its declared output dist' "$work/out" || fail "missing output not named; got: $(cat "$work/out")"

# One job installs one Bun: two building siblings must pin the same.
if CK_SIBLING_DIRS=".civikitchen-siblings/built .civikitchen-siblings/other" CK_NODE_VERSION=24 run plan; then
  fail "siblings pinning different Bun versions must fail the plan"
fi
grep -q 'must pin the same version' "$work/out" || fail "pin mismatch not named; got: $(cat "$work/out")"

# A declared build needs node_version, a committed bun.lock and a known tool.
if CK_SIBLING_DIRS=".civikitchen-siblings/built" run plan; then
  fail "a declared build without node_version must fail the plan"
fi
grep -q 'node_version is required' "$work/out" || fail "missing node_version not named; got: $(cat "$work/out")"
/bin/rm "$sibs/built/bun.lock"
if CK_SIBLING_DIRS=".civikitchen-siblings/built" CK_NODE_VERSION=24 run plan; then
  fail "a declared Bun build without bun.lock must fail the plan"
fi
grep -q 'needs a committed bun.lock' "$work/out" || fail "missing bun.lock not named; got: $(cat "$work/out")"

# A civikitchen.yaml this civikitchen cannot read fails, naming the sibling,
# whether or not it declares a build.
printf 'version: 1\npolicy:\n  future_key: true\n' > "$sibs/plain/civikitchen.yaml"
if CK_SIBLING_DIRS=".civikitchen-siblings/plain" CK_NODE_VERSION=24 run plan; then
  fail "an unreadable civikitchen.yaml must fail the plan"
fi
grep -q 'sibling .civikitchen-siblings/plain: its civikitchen.yaml could not be read by this civikitchen version' "$work/out" \
  || fail "unreadable civikitchen.yaml not named; got: $(cat "$work/out")"

# The build runs dependency code, so it comes before any step that writes the token.
# shellcheck disable=SC2016  # the $vars belong to PHP, not the shell.
php -r '
  require $argv[1] . "/packages/civikitchen-scenario-schema/vendor/autoload.php";
  $build = $token = null;
  foreach (Symfony\Component\Yaml\Yaml::parseFile($argv[1] . "/.github/actions/private-deps/action.yml")["runs"]["steps"] as $i => $step) {
    $run = (string) ($step["run"] ?? "");
    if ($build === null && str_contains($run, "build-siblings.sh build")) $build = $i;
    if ($token === null && str_contains($run, "github-oauth")) $token = $i;
  }
  exit($build !== null && $token !== null && $build < $token ? 0 : 1);
' "$root" || fail "the sibling build must run before the step that writes the composer token"

echo "sibling build: ok"
