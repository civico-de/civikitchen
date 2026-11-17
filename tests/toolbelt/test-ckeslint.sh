#!/usr/bin/env bash
# ckeslint's APIv4 contract rule, policy.javascript.type_check and --core mode,
# through the real command and the pinned oxlint toolchain. Each fixture marks its expected findings inline
# (`// expect: <text>`); the reported lines must equal the marked ones.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
fixtures="$root/tests/toolbelt/fixtures/ckeslint-api4"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
touch "$work/.start"
fail() { echo "FAIL: $*" >&2; exit 1; }

# `file:line: text` for every marker, and for every finding of the given rule.
expected() { (cd "$1" && grep -rn --include='*.js' --include='*.ts' '// expect: ' . | sed -E 's#^\./##; s#^([^:]+:[0-9]+):.*expect: (.*)$#\1: \2#' | sort); }
reported() { grep -E "$2" "$1" | sed -E 's#^([^:]+:[0-9]+):[0-9]+: (.*) \[.*$#\1: \2#' | sort; }

# Every marker's text must occur in the finding reported on its line.
compare() {
  local want="$1" got="$2" label="$3"
  [ "$(cut -d: -f1-2 <<<"$want")" = "$(cut -d: -f1-2 <<<"$got")" ] \
    || fail "$label: reported lines differ from the marked ones
--- marked
$want
--- reported
$got"
  while IFS= read -r line; do
    local location="${line%%: *}" text="${line#*: }"
    grep -F "$location: " <<<"$got" | grep -qF "$text" || fail "$label: $location does not report '$text'"
  done <<<"$want"
}

# --- extension mode ---------------------------------------------------------
cp -R "$fixtures/ext" "$work/ext"
(cd "$work/ext" && git init -q . && git add -A && git -c user.email=ck@example.org -c user.name=ck commit -qm fixture)
status=0
(cd "$work/ext" && php "$root/toolbelt/bin/ckeslint" --format=unix) > "$work/ext.out" 2>&1 || status=$?
[ "$status" = 1 ] || fail "ckeslint should exit 1 on the fixture, got $status: $(cat "$work/ext.out")"
compare "$(expected "$work/ext")" "$(reported "$work/ext.out" 'civikitchen\(api4-contract\)')" extension

# Without the catalog the rule must not pass silently.
status=0
(cd "$work/ext" && env -u CIVIKITCHEN_API4_CATALOG "$root/toolbelt/oxlint/node_modules/.bin/oxlint" \
  -c "$root/toolbelt/oxlint/.oxlintrc-no-type-aware.json" js) > "$work/nocatalog.out" 2>&1 || status=$?
[ "$status" != 0 ] || fail "the rule passed without CIVIKITCHEN_API4_CATALOG: $(cat "$work/nocatalog.out")"
grep -q 'CIVIKITCHEN_API4_CATALOG is not set' "$work/nocatalog.out" \
  || fail "the missing catalog is not named: $(cat "$work/nocatalog.out")"

# --- policy.javascript.type_check -------------------------------------------
# A repo's own strict tsconfig over js/, with the globals it declares itself.
own_tsconfig() {
  printf '%s\n' '{"compilerOptions": {"allowJs": true, "checkJs": true, "noEmit": true, "strict": true, "jsx": "preserve",' \
    ' "target": "es2023", "module": "esnext", "moduleResolution": "bundler", "types": []}, "include": ["js/**/*.js", "globals.d.ts"]}' > "$work/tc/tsconfig.json"
  printf '%s\n' 'declare var CRM: any;' 'declare function ts(text: string): string;' > "$work/tc/globals.d.ts"
}
cp -R "$fixtures/../ckeslint-typecheck" "$work/tc"
(cd "$work/tc" && git init -q . && git add -A && git -c user.email=ck@example.org -c user.name=ck commit -qm fixture)
# Untracked like every installed dependency; the copy links it and must leave it intact.
mkdir -p "$work/tc/node_modules/greeter"
echo 'export function greet(name) { return "hi " + name; }' > "$work/tc/node_modules/greeter/index.js"
status=0
(cd "$work/tc" && php "$root/toolbelt/bin/ckeslint" --format=unix) > "$work/tc.out" 2>&1 || status=$?
[ "$status" = 1 ] || fail "the type check should exit 1 on the fixture, got $status: $(cat "$work/tc.out")"
compare "$(expected "$work/tc")" "$(reported "$work/tc.out" '^[^ ]+:[0-9]+:[0-9]+: ')" type-check
[ -f "$work/tc/node_modules/greeter/index.js" ] || fail "the type check removed the repo's node_modules"
# An absolute path into the repo reaches the copy; untracked JS is named, not checked in silence.
echo 'var draft = 1; draft.nope();' > "$work/tc/js/draft.js"
status=0
(cd "$work/tc" && php "$root/toolbelt/bin/ckeslint" --format=unix "$work/tc/js/widget.js" js/draft.js) > "$work/tc-path.out" 2>&1 || status=$?
[ "$status" = 1 ] || fail "the type check over an absolute path should exit 1, got $status: $(cat "$work/tc-path.out")"
grep -q "^js/widget.js:9:.*toUpperCase" "$work/tc-path.out" || fail "the absolute path was not type-checked: $(cat "$work/tc-path.out")"
grep -q '^ckeslint: not type-checked, no tracked JS/TS of this repo: js/draft.js$' "$work/tc-path.out" \
  || fail "the untracked file is not named: $(cat "$work/tc-path.out")"
rm "$work/tc/js/draft.js"
# A path scopes the type check's report too: greeting.ts alone, not widget.js.
(cd "$work/tc" && php "$root/toolbelt/bin/ckeslint" --format=unix js/greeting.ts) > "$work/tc-one.out" 2>&1 || true
[ "$(reported "$work/tc-one.out" '^[^ ]+:[0-9]+:[0-9]+: ' | cut -d: -f1 | sort -u)" = js/greeting.ts ] \
  || fail "a path-scoped run reported other files: $(cat "$work/tc-one.out")"

# Without the opt-in the same source is clean.
printf 'version: 1\npolicy:\n  license: Proprietary\n' > "$work/tc/civikitchen.yaml"
(cd "$work/tc" && php "$root/toolbelt/bin/ckeslint" --format=unix) > "$work/tc-off.out" 2>&1 \
  || fail "without policy.javascript.type_check the fixture should pass: $(cat "$work/tc-off.out")"
# An unreadable policy is an error, not a type check silently off.
printf 'version: 1\npolicy:\n  javascript:\n    type_check: "yes"\n' > "$work/tc/civikitchen.yaml"
status=0
(cd "$work/tc" && php "$root/toolbelt/bin/ckeslint") > "$work/tc-bad.out" 2>&1 || status=$?
[ "$status" = 2 ] || fail "an invalid policy.javascript.type_check should exit 2, got $status: $(cat "$work/tc-bad.out")"
cp "$fixtures/../ckeslint-typecheck/civikitchen.yaml" "$work/tc/civikitchen.yaml"

# The repo's own tsconfig.json carries the check in place. JSX would need the
# repo's own JSX types there, so the copy mode alone covers view.tsx.
rm "$work/tc/js/view.tsx"
own_tsconfig
status=0
(cd "$work/tc" && php "$root/toolbelt/bin/ckeslint" --format=unix js) > "$work/tc-own.out" 2>&1 || status=$?
[ "$status" = 1 ] || fail "the in-place type check should exit 1, got $status: $(cat "$work/tc-own.out")"
grep -q "through this repo's tsconfig.json" "$work/tc-own.out" || fail "the in-place mode is not named: $(cat "$work/tc-own.out")"
compare "$(expected "$work/tc")" "$(reported "$work/tc-own.out" '^[^ ]+:[0-9]+:[0-9]+: ')" type-check-in-place
rm "$work/tc/tsconfig.json" "$work/tc/globals.d.ts"

# A repo with its own .oxlintrc.json turns it on there; the policy must not pass unnoticed.
echo '{}' > "$work/tc/.oxlintrc.json"
status=0
(cd "$work/tc" && php "$root/toolbelt/bin/ckeslint") > "$work/tc-rc.out" 2>&1 || status=$?
if [ "$status" != 2 ] || ! grep -q 'options.*typeCheck' "$work/tc-rc.out"; then
  fail "policy.javascript.type_check with an own .oxlintrc.json should exit 2, got $status: $(cat "$work/tc-rc.out")"
fi
# ...and the option that message names does run the type check.
printf 'version: 1\npolicy:\n  license: Proprietary\n' > "$work/tc/civikitchen.yaml"
echo '{"options": {"typeAware": true, "typeCheck": true}}' > "$work/tc/.oxlintrc.json"
own_tsconfig
status=0
(cd "$work/tc" && php "$root/toolbelt/bin/ckeslint" --format=unix js) > "$work/tc-rc-on.out" 2>&1 || status=$?
[ "$status" = 1 ] || fail "options.typeCheck in an own .oxlintrc.json should exit 1, got $status: $(cat "$work/tc-rc-on.out")"
compare "$(expected "$work/tc")" "$(reported "$work/tc-rc-on.out" 'typescript\(TS[0-9]+\)')" own-config-type-check

# --- core mode --------------------------------------------------------------
cp -R "$fixtures/core" "$work/core"
# Vendored, minified and test JS are not core's own code; each would fail.
mkdir -p "$work/core/ext/widget/node_modules/lib" "$work/core/tests/karma"
for file in ext/widget/node_modules/lib/index.js js/app.min.js tests/karma/widget.js; do
  echo "var x = 1; x.nope(); CRM.api4('Contatc', 'get', {});" > "$work/core/$file"
done
status=0
php "$root/toolbelt/bin/ckeslint" --core --format=unix "$work/core" > "$work/core.out" 2>&1 || status=$?
[ "$status" = 1 ] || fail "ckeslint --core should exit 1 on the fixture, got $status: $(cat "$work/core.out")"
grep -q '^ckeslint --core: 2 JS file(s) from' "$work/core.out" || fail "--core did not select exactly core's own files: $(head -1 "$work/core.out")"
compare "$(expected "$work/core")" "$(reported "$work/core.out" '^[^ ]+:[0-9]+:[0-9]+: ')" core
[ -z "$(find "${TMPDIR:-/tmp}" -maxdepth 1 -name 'ckeslint-*' -newer "$work/.start" 2>/dev/null)" ] \
  || fail "a run left its working copy or catalog behind"

echo "ok - ckeslint api4-contract, type check and --core"
