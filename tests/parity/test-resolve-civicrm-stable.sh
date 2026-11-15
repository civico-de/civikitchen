#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/bin"
# Stands in for latest.civicrm.org and download.civicrm.org.
cat > "$work/bin/curl" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
for arg in "$@"; do url=$arg; done
case "$url" in
  */stable.php) echo "$FAKE_STABLE" ;;
  */versions.json) echo '{"6.18":{"releases":[{"version":"6.18.2"}]},"6.19":{"releases":[{"version":"6.19.5"}]},"6.20":{"releases":[{"version":"6.20.0"},{"version":"6.20.2"}]}}' ;;
  *standalone.tar.gz) ;;
  *) echo "unexpected url $url" >&2; exit 1 ;;
esac
SH
chmod +x "$work/bin/curl"

resolve() {
  : > "$work/out"
  (cd "$root" && PATH="$work/bin:$PATH" GITHUB_OUTPUT="$work/out" FAKE_STABLE="$1" EXTRA_MINORS="${2:-}" \
    bash .github/scripts/resolve-civicrm-stable.sh >/dev/null)
  sed -n 's/^standalone_versions=//p' "$work/out"
}

catalog=$(sed -n "s/^ *public const CORE_VERSION = '\([0-9]*\.[0-9]*\)\.[0-9]*';\$/\1/p" \
  "$root/toolbelt/phpstan/src/Api4Catalog.php")
[ "$catalog" = 6.20 ] || { echo "catalog minor is $catalog; update this test's fake versions.json" >&2; exit 1; }

# The catalog's minor is the stable one: no extra row.
test "$(resolve 6.20.2 6.18,6.19)" = '["6.20.2","6.18.2","6.19.5"]'
# A newer stable keeps the catalog's minor in the matrix, once.
test "$(resolve 6.21.0 6.18,6.19)" = '["6.21.0","6.18.2","6.19.5","6.20.2"]'
test "$(resolve 6.21.0 6.20,6.18)" = '["6.21.0","6.20.2","6.18.2"]'

echo 'ok   resolve-civicrm-stable keeps the phpstan catalog minor in the matrix'
