#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT

export HOME="$work/home"
export XDG_CONFIG_HOME="$work/config"
export TMPDIR="$work/tmp"
export FAKE_DOCKER_LOG="$work/docker.log"
mkdir -p "$HOME" "$XDG_CONFIG_HOME" "$TMPDIR" "$work/bin"

ln -s "$root/tests/ckinit/fake-docker" "$work/bin/docker"
export PATH="$work/bin:$PATH"

expect_failure() {
  local label="$1"
  shift
  if "$@" >"$work/failure.out" 2>&1; then
    echo "$label unexpectedly succeeded" >&2
    exit 1
  fi
}

common=(--author "Acme Maintainer" --email dev@example.org --copyright "Acme Collective")

# Mandatory input and key validation happen before Docker or output creation.
expect_failure "missing key" "$root/scaffold/ckcreate"
expect_failure "missing author" "$root/scaffold/ckcreate" probe --email dev@example.org --copyright Acme
expect_failure "missing email" "$root/scaffold/ckcreate" probe --author Acme --copyright Acme
expect_failure "missing copyright" "$root/scaffold/ckcreate" probe --author Acme --email dev@example.org
expect_failure "missing option value" "$root/scaffold/ckcreate" probe --author --email dev@example.org
expect_failure "invalid key" "$root/scaffold/ckcreate" bad-key "${common[@]}"
expect_failure "unknown license" "$root/scaffold/ckcreate" probe "${common[@]}" --license MPL-2.0
test ! -e "$FAKE_DOCKER_LOG"

mkdir -p "$work/existing"
expect_failure "existing destination" "$root/scaffold/ckcreate" probe "${common[@]}" --dir "$work/existing"
test ! -e "$FAKE_DOCKER_LOG"

# Closed licences are generated through civix's MIT template, then made
# consistent across info.xml, composer.json, README, LICENSE and policy.
proprietary="$work/output/proprietary"
"$root/scaffold/ckcreate" probe "${common[@]}" --dir "$proprietary" > "$work/proprietary.out"
php -r '
  $xml = simplexml_load_file($argv[1] . "/info.xml");
  assert((string) $xml->license === "Proprietary");
  assert((string) $xml->version === "0.1.0");
  assert((string) $xml->php_compatibility->ver[0] === "8.2");
  foreach ($xml->urls->url ?? [] as $url) { assert((string) $url["desc"] !== "Licensing"); }
  $composer = json_decode(file_get_contents($argv[1] . "/composer.json"), true, 512, JSON_THROW_ON_ERROR);
  assert($composer["license"] === "proprietary");
  assert($composer["private"] === true);
' "$proprietary"
grep -q 'distributed as proprietary software' "$proprietary/README.md"
if grep -q 'MIT' "$proprietary/README.md"; then
  echo "MIT remained in the proprietary README" >&2
  exit 1
fi
grep -q '^Copyright (C) .* Acme Collective\. All rights reserved\.$' "$proprietary/LICENSE.txt"
grep -q $'^\tphpVersion: 80200$' "$proprietary/phpstan.neon.dist"
php -r '
  require $argv[1]; $d=ck_config_load($argv[2]);
  assert($d["policy"]["license"] === "Proprietary");
  assert($d["policy"]["copyright"] === "Acme Collective");
' "$root/packages/civikitchen-scenario-schema/scenario.php" "$proprietary/civikitchen.yaml"
drift=$("$root/scaffold/ckinit" --check "$proprietary")
grep -q 'up to date' <<<"$drift"
grep -q -- '--license MIT --compatibility 6.16' "$FAKE_DOCKER_LOG"
grep -q -- '--enable=no' "$FAKE_DOCKER_LOG"
grep -q 'down -v --remove-orphans' "$FAKE_DOCKER_LOG"
# A new repository with one test, and the civix output run through the fixer and the formatter.
git -C "$proprietary" rev-parse --git-dir >/dev/null
grep -q '^namespace Civi\\probe;$' "$proprietary/tests/phpunit/Civi/probe/InstallTest.php"
grep -q "getStatus('probe')" "$proprietary/tests/phpunit/Civi/probe/InstallTest.php"
grep -q -- '-w /out/probe app cklint --fix --all' "$FAKE_DOCKER_LOG"
grep -q -- '-w /out/probe app ckfmt' "$FAKE_DOCKER_LOG"

# A civix-supported licence stays intact while composer and the copyright
# holder are still normalised to the caller's values.
mit="$work/output/mit"
"$root/scaffold/ckcreate" mitprobe "${common[@]}" --license MIT --php 8.3 --dir "$mit" > "$work/mit.out"
php -r '
  $xml = simplexml_load_file($argv[1] . "/info.xml");
  assert((string) $xml->license === "MIT");
  assert((string) $xml->php_compatibility->ver[0] === "8.3");
  $composer = json_decode(file_get_contents($argv[1] . "/composer.json"), true, 512, JSON_THROW_ON_ERROR);
  assert($composer["license"] === "MIT");
  assert($composer["require"]["php"] === ">=8.3");
  assert(!array_key_exists("private", $composer));
' "$mit"
grep -q 'licensed under \[MIT\](LICENSE.txt)' "$mit/README.md"
grep -q '^Copyright (C) .* Acme Collective$' "$mit/LICENSE.txt"
grep -q $'^\tphpVersion: 80300$' "$mit/phpstan.neon.dist"
php -r '
  require $argv[1]; $d=ck_config_load($argv[2]);
  assert($d["policy"]["license"] === "MIT");
  assert($d["policy"]["copyright"] === "Acme Collective");
' "$root/packages/civikitchen-scenario-schema/scenario.php" "$mit/civikitchen.yaml"

# A failed civix run leaves no partial extension and cleans its bind mount.
failed="$work/output/failed"
export FAKE_CIVIX_FAIL=1
expect_failure "civix failure" "$root/scaffold/ckcreate" failed "${common[@]}" --dir "$failed"
unset FAKE_CIVIX_FAIL
test ! -e "$failed"
test -z "$(find "$HOME/.cache/civikitchen" -mindepth 1 -print -quit 2>/dev/null)"

# Debug mode preserves both the compose file and its bind mount and does not
# tear the stack down behind the caller.
before_down=$(grep -c 'down -v --remove-orphans' "$FAKE_DOCKER_LOG")
kept="$work/output/kept"
"$root/scaffold/ckcreate" kept "${common[@]}" --dir "$kept" --keep-stack > "$work/kept.out"
compose=$(sed -n 's/^  compose: //p' "$work/kept.out")
mount=$(sed -n 's/^  output:  //p' "$work/kept.out")
test -f "$compose"
test -d "$mount"
after_down=$(grep -c 'down -v --remove-orphans' "$FAKE_DOCKER_LOG")
test "$before_down" -eq "$after_down"

# Precedence is flags, then the environment, then the config file.
mkdir -p "$XDG_CONFIG_HOME/civikitchen"
printf '%s\n' 'CKCREATE_AUTHOR="Conf Author"' 'CKCREATE_EMAIL=conf@example.org' \
  'CKCREATE_COPYRIGHT="Conf Org"' 'CKCREATE_COMPATIBILITY=6.10' 'CKCREATE_LICENSE=AGPL-3.0' \
  > "$XDG_CONFIG_HOME/civikitchen/ckcreate.conf"
: > "$FAKE_DOCKER_LOG"
CKCREATE_COMPATIBILITY=6.11 "$root/scaffold/ckcreate" precedence --license MIT \
  --dir "$work/output/precedence" > "$work/precedence.out"
grep -qF -- '--author Conf\ Author --email conf@example.org --license MIT --compatibility 6.11' "$FAKE_DOCKER_LOG" \
  || { echo "ckcreate precedence: expected env over conf, flags over both" >&2; cat "$FAKE_DOCKER_LOG" >&2; exit 1; }
/bin/rm "$XDG_CONFIG_HOME/civikitchen/ckcreate.conf"

"$root/scaffold/ckcreate" --help >/dev/null
echo "ckcreate integration checks passed"
