#!/usr/bin/env bash
# `policy.vendored_paths` in civikitchen.yaml keeps third-party source a repo carries
# verbatim out of the file lists cklint/ckfmt/ckanalyze build (Repository\Files). Without it a repo
# can only choose between a permanently red gate and reformatting code that has
# to stay byte-identical to its upstream.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

# Files reads the policy through ckconform and the list through git, so the fixture is a real checkout.
cd "$work"
git init -q .
git config user.email ck@example.org
git config user.name ck
mkdir -p own .docker/upstream/proxy
printf '<?php\n' > own/Thing.php
printf '<?php\n' > .docker/upstream/proxy/proxy.php
git add -A
git commit -qm fixture

list() {
  php -r 'require $argv[1] . "/toolbelt/lib/php/bootstrap.php";
    $files = (new CiviKitchen\Toolbelt\Repository\Files($argv[1]))->source(["php"], [], true, true);
    echo implode("\n", $files), "\n";' "$root"
}

# No civikitchen.yaml at all: the filter must be a no-op, not a filter that drops
# everything (an empty `grep -Ev` pattern matches every line).
[ "$(list | wc -l | tr -d ' ')" = 2 ] \
  || fail "without civikitchen.yaml the file list should hold both files, got: $(list | tr '\n' ' ')"

printf '%s\n' \
  'version: 1' \
  'policy:' \
  '  vendored_paths:' \
  '    - path: .docker/upstream/proxy' \
  '      reason: unmodified upstream, must stay byte-identical' > civikitchen.yaml
files="$(list)"
grep -q '^own/Thing.php$' <<<"$files" || fail "the repo's own file was dropped"
grep -q 'upstream/proxy' <<<"$files" && fail "the declared vendored path was still listed"

# A declared prefix names ONE place, not every directory sharing its name.
mkdir -p other/.docker/upstream/proxy
printf '<?php\n' > other/.docker/upstream/proxy/proxy.php
files="$(list)"
grep -q '^other/.docker/upstream/proxy/proxy.php$' <<<"$files" \
  || fail "the prefix matched a same-named directory somewhere else"

# A top-level prefix (civix drops `mixin/` into every extension) must survive
# the same path, not just a nested one like .docker/upstream/proxy.
mkdir -p mixin
printf '<?php\n' > mixin/polyfill.php
printf '%s\n' \
  '    - path: mixin' \
  '      reason: upstream polyfill, copied verbatim' >> civikitchen.yaml
files="$(list)"
grep -q '^mixin/polyfill.php$' <<<"$files" && fail "a top-level vendored prefix was still listed"

# A policy ckconform rejects fails the list instead of silently dropping the exclusions.
printf '%s\n' '  no_such_key: 1' >> civikitchen.yaml
rc=0
out=$(list 2>&1) || rc=$?
[ "$rc" != 0 ] || fail "an unreadable policy still produced a file list: $out"
grep -qF 'cannot read policy.vendored_paths' <<<"$out" || fail "the failure does not name the policy: $out"
grep -q 'mixin/polyfill.php' <<<"$out" && fail "an unreadable policy listed the vendored file"

echo "vendored_paths suite OK"
