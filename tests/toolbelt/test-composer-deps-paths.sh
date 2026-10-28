#!/bin/bash
# ckdeps with the bundled config and the real analyser (the root composer.json):
# which directories of an extension root count as the extension's own code.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
analyser_bin="${root}/vendor/bin"
[ -x "${analyser_bin}/composer-dependency-analyser" ] \
  || { echo "run: composer install" >&2; exit 1; }
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }

ext="${work}/org.example.widget"
mkdir -p "${ext}/CRM/Widget"
cat > "${ext}/composer.json" <<'JSON'
{
  "name": "org.example/widget",
  "require": {"ext-json": "*"},
  "autoload": {"psr-0": {"CRM_": "."}}
}
JSON
cat > "${ext}/CRM/Widget/Codec.php" <<'PHP'
<?php
class CRM_Widget_Codec {
  public function encode(array $value): string {
    return json_encode($value, JSON_THROW_ON_ERROR);
  }
}
PHP
(cd "${ext}" && composer install --quiet --no-interaction)

# What extension CI checks out next to the extension: PHP whose classes the
# extension's composer.json cannot resolve.
unknown_class_file() {
  mkdir -p "$(dirname "$1")"
  printf '<?php\nnamespace Acme\\Toolbelt;\nfinal class Check extends \\Acme\\Unknown\\Base {}\n' > "$1"
}

run_ckdeps() {
  (cd "${ext}" && PATH="${analyser_bin}:${PATH}" "${root}/toolbelt/bin/ckdeps" 2>&1)
}

# Control: the same file in the extension's own tree is a finding, so the
# sibling case below passes because of the exclusion, not a blind analyser.
unknown_class_file "${ext}/CRM/Widget/Check.php"
rc=0
out=$(run_ckdeps) || rc=$?
[ "${rc}" != 0 ] || fail "ckdeps passed an unknown class in the extension's own code"$'\n'"${out}"
grep -q 'Acme\\Unknown\\Base' <<<"${out}" || fail "ckdeps did not name the unknown class"$'\n'"${out}"
rm "${ext}/CRM/Widget/Check.php"

unknown_class_file "${ext}/.civikitchen-siblings/ci/toolbelt/src/Check.php"
rc=0
out=$(run_ckdeps) || rc=$?
[ "${rc}" = 0 ] || fail "ckdeps scanned .civikitchen-siblings (exit ${rc})"$'\n'"${out}"
if grep -q 'civikitchen-siblings' <<<"${out}"; then
  fail "ckdeps output names .civikitchen-siblings"$'\n'"${out}"
fi

echo "ok   ckdeps skips .civikitchen-siblings and still reports the extension's own code"
