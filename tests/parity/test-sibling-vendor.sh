#!/usr/bin/env bash
# The private-deps sibling build: a sibling whose committed composer.lock
# carries runtime packages gets `composer install --no-dev` before it is
# mounted; one without stays untouched. `composer` is stubbed.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
script="$root/.github/actions/private-deps/install-sibling-vendor.sh"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

mkdir -p "$work/bin" "$work/ws/.civikitchen-siblings/"{runtime,devonly,nolock}
cat > "$work/bin/composer" <<'STUB'
#!/usr/bin/env bash
echo "$PWD $*" >> "$CK_CALLS"
STUB
chmod +x "$work/bin/composer"
echo '{"packages":[{"name":"acme/lib"}],"packages-dev":[]}' > "$work/ws/.civikitchen-siblings/runtime/composer.lock"
echo '{"packages":[],"packages-dev":[{"name":"phpunit/phpunit"}]}' > "$work/ws/.civikitchen-siblings/devonly/composer.lock"

calls="$work/calls"
: > "$calls"
PATH="$work/bin:$PATH" CK_CALLS="$calls" GITHUB_WORKSPACE="$work/ws" \
  CK_SIBLING_DIRS=".civikitchen-siblings/runtime .civikitchen-siblings/devonly .civikitchen-siblings/nolock" \
  bash "$script" > "$work/out" 2>&1 || { cat "$work/out" >&2; fail "the script failed"; }

[[ "$(wc -l < "$calls")" -eq 1 ]] || fail "expected exactly one composer run; got: $(cat "$calls")"
grep -q "^$work/ws/.civikitchen-siblings/runtime install .*--no-dev" "$calls" \
  || fail "the runtime sibling must be installed --no-dev in its own directory; got: $(cat "$calls")"

# An unreadable lock is an error, not a sibling without packages.
echo '{not json' > "$work/ws/.civikitchen-siblings/nolock/composer.lock"
if PATH="$work/bin:$PATH" CK_CALLS="$calls" GITHUB_WORKSPACE="$work/ws" CK_SIBLING_DIRS=".civikitchen-siblings/nolock" \
  bash "$script" > /dev/null 2>&1; then
  fail "an unreadable composer.lock must fail the step"
fi

# A failing install fails the step: a sibling without its vendor/ cannot boot.
printf '#!/usr/bin/env bash\nexit 3\n' > "$work/bin/composer"
if PATH="$work/bin:$PATH" GITHUB_WORKSPACE="$work/ws" CK_SIBLING_DIRS=".civikitchen-siblings/runtime" \
  bash "$script" > /dev/null 2>&1; then
  fail "a failing composer install must fail the step"
fi

echo "sibling vendor: ok"
