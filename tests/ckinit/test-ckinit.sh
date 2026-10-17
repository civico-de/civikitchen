#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

make_extension() {
  local target="$1"
  mkdir -p "$target"
  printf '%s\n' '<extension key="org.acme.example_ext" type="module"><file>example_ext</file><license>AGPL-3.0</license></extension>' > "$target/info.xml"
}

# POSIX sed has no portable in-place flag: GNU and BSD sed interpret `-i`
# differently. Rewrite through a sibling temporary file and copy back so the
# original file mode remains intact for ckinit's mode-drift assertions.
rewrite_with_sed() {
  local expression="$1"
  local file="$2"
  local output

  output=$(mktemp "${file}.sed-output.XXXXXX")
  sed "$expression" "$file" > "$output"
  cat "$output" > "$file"
  /bin/rm "$output"
}

make_extension "$work/clean"
"$root/scaffold/ckinit.php" "$work/clean" >/dev/null
grep -q 'acme/example_ext' "$work/clean/composer.json"
# composer.json declares info.xml's licence, `proprietary` in composer's spelling.
grep -q '"license": "AGPL-3.0",' "$work/clean/composer.json"
mkdir -p "$work/closed"
printf '%s\n' '<extension key="org.acme.closed" type="module"><file>closed</file><license>Proprietary</license></extension>' > "$work/closed/info.xml"
"$root/scaffold/ckinit.php" "$work/closed" >/dev/null
grep -q '"license": "proprietary",' "$work/closed/composer.json"
mkdir -p "$work/unlicensed"
printf '%s\n' '<extension key="org.acme.unlicensed" type="module"><file>unlicensed</file></extension>' > "$work/unlicensed/info.xml"
if out=$("$root/scaffold/ckinit.php" "$work/unlicensed" 2>&1); then
  echo "FAIL: ckinit seeded an extension whose info.xml has no <license>" >&2
  exit 1
fi
grep -q 'info.xml has no <license>' <<<"$out"
[ ! -e "$work/unlicensed/composer.json" ]
# An existing repo whose composer.json is already seeded is checked without one.
cp -R "$work/clean" "$work/unlicensed-seeded"
printf '%s\n' '<extension key="org.acme.example_ext" type="module"><file>example_ext</file></extension>' > "$work/unlicensed-seeded/info.xml"
"$root/scaffold/ckinit.php" --check "$work/unlicensed-seeded" >/dev/null
grep -q '"extends": \["config:recommended"\]' "$work/clean/renovate.json"
# The monorepo example's dev compose file, and the CI one's header above its
# managed blocks, are what ckinit stamps at that depth.
header() { sed '/BEGIN CIVIKITCHEN MANAGED/q' "$1"; }
for key in ckmonobase ckmonoaddon; do
  stamp="$work/stamp-$key/examples/monorepo/$key"
  example="$root/examples/monorepo/$key/.docker"
  mkdir -p "$stamp"
  git init -q "$work/stamp-$key"
  cp "$root/examples/monorepo/$key/info.xml" "$stamp/"
  "$root/scaffold/ckinit.php" "$stamp" >/dev/null
  diff -u "$stamp/.docker/docker-compose.yml" "$example/docker-compose.yml" \
    || { echo "examples/monorepo/$key dev compose file differs from a fresh stamp" >&2; exit 1; }
  diff -u <(header "$stamp/.docker/docker-compose.ci.yml") <(header "$example/docker-compose.ci.yml") \
    || { echo "examples/monorepo/$key CI compose header differs from a fresh stamp" >&2; exit 1; }
done

# The local gate recipe is `ck ci`, the one gate list the shared CI runs too.
grep -q -- 'scaffold/ckx ck ci$' "$work/clean/.docker/docker-compose.yml" \
  || { echo "docker-compose.yml does not point at ck ci" >&2; exit 1; }
grep -q -- '-w /var/www/html/ext/example_ext app ck ci$' "$work/clean/.docker/docker-compose.ci.yml" \
  || { echo "docker-compose.ci.yml does not point at ck ci" >&2; exit 1; }
if grep -R -q '__EXTKEY__\|__EXTENSION_KEY__\|__SCENARIO_NAME__\|__VENDOR__\|__RENOVATE_PRESET__' "$work/clean"; then
  echo "placeholder remained after rendering" >&2
  exit 1
fi

mkdir -p "$work/nokey"
printf '%s\n' '<extension><file>example_ext</file><license>AGPL-3.0</license></extension>' > "$work/nokey/info.xml"
"$root/scaffold/ckinit.php" "$work/nokey" >/dev/null
grep -q 'example/example_ext' "$work/nokey/composer.json"

# renovate_preset from the repo policy, or from the organisation defaults file.
make_extension "$work/preset"
printf '%s\n' 'version: 1' 'policy:' '  renovate_preset: github>acme/renovate' > "$work/preset/civikitchen.yaml"
"$root/scaffold/ckinit.php" --update "$work/preset" >/dev/null
grep -q '"extends": \["github>acme/renovate"\]' "$work/preset/renovate.json"
make_extension "$work/orgpreset"
printf '%s\n' 'version: 1' 'policy:' '  renovate_preset: github>org/renovate' > "$work/org-policy"
CK_DEFAULT_CONFIG="$work/org-policy" "$root/scaffold/ckinit.php" "$work/orgpreset" >/dev/null
grep -q '"extends": \["github>org/renovate"\]' "$work/orgpreset/renovate.json"
if CK_DEFAULT_CONFIG="$work/missing" "$root/scaffold/ckinit.php" "$work/orgpreset" --update >/dev/null 2>&1; then
  echo "an unreadable CK_DEFAULT_CONFIG must fail" >&2
  exit 1
fi

if "$root/scaffold/ckinit.php" "$work/clean" >/dev/null 2>&1; then
  echo "existing files were overwritten without --force" >&2
  exit 1
fi
"$root/scaffold/ckinit.php" --force "$work/clean" >/dev/null

make_extension "$work/symlink"
mkdir -p "$work/outside"
ln -s "$work/outside" "$work/symlink/.docker"
if "$root/scaffold/ckinit.php" --force "$work/symlink" >/dev/null 2>&1; then
  echo "symlink destination was accepted" >&2
  exit 1
fi
test -z "$(find "$work/outside" -mindepth 1 -print -quit)"

mkdir -p "$work/invalid"
printf '%s\n' '<extension><file>bad-key</file></extension>' > "$work/invalid/info.xml"
if "$root/scaffold/ckinit.php" "$work/invalid" >/dev/null 2>&1; then
  echo "invalid extension key was accepted" >&2
  exit 1
fi

# --check on a freshly seeded extension: no drift.
make_extension "$work/drift"
"$root/scaffold/ckinit.php" "$work/drift" >/dev/null
out=$("$root/scaffold/ckinit.php" --check "$work/drift")
grep -q 'up to date' <<<"$out"

# A drifted MANAGED file fails --check; an edited SEEDED file does not.
# Inside the managed block — an addition after the END marker would be the repo's own.
rewrite_with_sed 's|extension-ci.yml@v1|extension-ci.yml@v0|' "$work/drift/.github/workflows/ci.yml"
printf '%s\n' '{"name": "acme/example_ext", "edited": true}' > "$work/drift/composer.json"
if out=$("$root/scaffold/ckinit.php" --check "$work/drift" 2>&1); then
  echo "managed drift was not detected" >&2
  exit 1
fi
grep -q 'drifted   .github/workflows/ci.yml' <<<"$out"
if grep -q 'composer.json' <<<"$out"; then
  echo "seeded file was reported as drift" >&2
  exit 1
fi

# --update restores the managed file (re-stamped) and leaves the seeded edit.
/bin/rm "$work/drift/phpstan.neon.dist"
rewrite_with_sed 's|mariadb:11.4|mariadb:10.6|' "$work/drift/.docker/docker-compose.ci.yml"
out=$("$root/scaffold/ckinit.php" --update "$work/drift")
grep -q 'updated   .github/workflows/ci.yml' <<<"$out"
grep -q 'updated   .docker/docker-compose.ci.yml' <<<"$out"
grep -q 'created   phpstan.neon.dist' <<<"$out"
grep -q 'ext/example_ext' "$work/drift/.docker/docker-compose.ci.yml"
grep -q '"edited": true' "$work/drift/composer.json"
out=$("$root/scaffold/ckinit.php" --check "$work/drift")
grep -q 'up to date' <<<"$out"

# A deviation declared in civikitchen.yaml (with its mandatory reason) is respected.
php -r '
  require $argv[1]; $f=$argv[2]; $d=ck_scenario_parse_yaml($f);
  $d["policy"]["template_custom"]=["paths"=>[".github/workflows/ci.yml"],"reason"=>"bespoke pipeline"];
  file_put_contents($f, ck_scenario_dump_yaml($d));
' "$root/packages/civikitchen-scenario-schema/scenario.php" "$work/drift/civikitchen.yaml"
printf '%s\n' '# local edit' >> "$work/drift/.github/workflows/ci.yml"
out=$("$root/scaffold/ckinit.php" --check "$work/drift")
grep -q 'custom    .github/workflows/ci.yml' <<<"$out"
"$root/scaffold/ckinit.php" --update "$work/drift" >/dev/null
grep -q '# local edit' "$work/drift/.github/workflows/ci.yml"

# ... but the reason is not optional, and only managed files may be listed.
rewrite_with_sed '/reason:/d' "$work/drift/civikitchen.yaml"
if "$root/scaffold/ckinit.php" --check "$work/drift" >/dev/null 2>&1; then
  echo "template_custom without a reason was accepted" >&2
  exit 1
fi
# A typo'd file name must fail loudly, not silently disable nothing.
php -r '
  require $argv[1]; $f=$argv[2]; $d=ck_scenario_parse_yaml($f);
  $d["policy"]["template_custom"]=["paths"=>["composer.jsn"],"reason"=>"edited anyway"];
  file_put_contents($f, ck_scenario_dump_yaml($d));
' "$root/packages/civikitchen-scenario-schema/scenario.php" "$work/drift/civikitchen.yaml"
if "$root/scaffold/ckinit.php" --check "$work/drift" >/dev/null 2>&1; then
  echo "template_custom with a typo'd file name was accepted" >&2
  exit 1
fi

# A SEEDED file declared custom may be absent: the repo owns its existence.
# --check accepts the absence and --update must not reseed it.
/bin/rm "$work/drift/civikitchen.yaml"
"$root/scaffold/ckinit.php" --update "$work/drift" >/dev/null
php -r '
  require $argv[1]; $f=$argv[2]; $d=ck_scenario_parse_yaml($f);
  $d["policy"]["template_custom"]=["paths"=>["phpunit.xml.dist"],"reason"=>"no PHP suite in this repo"];
  file_put_contents($f, ck_scenario_dump_yaml($d));
' "$root/packages/civikitchen-scenario-schema/scenario.php" "$work/drift/civikitchen.yaml"
/bin/rm "$work/drift/phpunit.xml.dist"
out=$("$root/scaffold/ckinit.php" --check "$work/drift")
grep -q 'custom    phpunit.xml.dist' <<<"$out"
"$root/scaffold/ckinit.php" --update "$work/drift" >/dev/null
if [ -e "$work/drift/phpunit.xml.dist" ]; then
  echo "--update reseeded a custom-declared seeded file" >&2
  exit 1
fi

# The executable bit is part of the managed contract (the one mode bit git
# tracks); content-identical but chmod +x must read as drift.
/bin/rm "$work/drift/civikitchen.yaml"
"$root/scaffold/ckinit.php" --update "$work/drift" >/dev/null
chmod +x "$work/drift/phpstanBootstrap.php"
if out=$("$root/scaffold/ckinit.php" --check "$work/drift" 2>&1); then
  echo "executable-bit drift was not detected" >&2
  exit 1
fi
grep -q 'drifted   phpstanBootstrap.php' <<<"$out"
"$root/scaffold/ckinit.php" --update "$work/drift" >/dev/null
if [ -x "$work/drift/phpstanBootstrap.php" ]; then
  echo "--update did not restore the file mode" >&2
  exit 1
fi

# phpstan.neon.dist is SEEDED (a repo's project layer): edits must not be drift.
printf '%s\n' '# local layer' >> "$work/drift/phpstan.neon.dist"
out=$("$root/scaffold/ckinit.php" --check "$work/drift")
grep -q 'up to date' <<<"$out"

# phpstan-tests.neon.dist is seeded but OPTIONAL: its absence is not drift,
# and --update must not reintroduce it (existence is the CI opt-in switch).
test -f "$work/drift/phpstan-tests.neon.dist"
/bin/rm "$work/drift/phpstan-tests.neon.dist"
out=$("$root/scaffold/ckinit.php" --check "$work/drift")
grep -q 'optional  phpstan-tests.neon.dist' <<<"$out"
grep -q 'up to date' <<<"$out"
"$root/scaffold/ckinit.php" --update "$work/drift" >/dev/null
if [ -e "$work/drift/phpstan-tests.neon.dist" ]; then
  echo "--update recreated the opt-in phpstan-tests.neon.dist" >&2
  exit 1
fi

# --force is a seeding flag; refuse the ambiguous combinations.
if "$root/scaffold/ckinit.php" --force --update "$work/drift" >/dev/null 2>&1; then
  echo "--force --update was accepted" >&2
  exit 1
fi
if "$root/scaffold/ckinit.php" --update --check "$work/drift" >/dev/null 2>&1; then
  echo "--update --check was accepted" >&2
  exit 1
fi

# Managed blocks: what a repo writes outside the markers is its own and
# survives --update; what it changes inside is drift.
make_extension "$work/blocks"
"$root/scaffold/ckinit.php" "$work/blocks" >/dev/null
cat >> "$work/blocks/.github/workflows/ci.yml" <<'YAML'
    with:
      sibling_repo: acme/other
  extra:
    runs-on: ubuntu-latest
    steps:
      - run: echo extra
YAML
rewrite_with_sed 's|^# END CIVIKITCHEN MANAGED app$|# END CIVIKITCHEN MANAGED app\
      - ../../other:/var/www/html/ext/other:ro|' "$work/blocks/.docker/docker-compose.ci.yml"
out=$("$root/scaffold/ckinit.php" --check "$work/blocks")
grep -q 'up to date' <<<"$out" || { echo "additions outside the managed blocks were reported as drift: $out" >&2; exit 1; }
# An edit inside a block is drift; --update restores the block and keeps the additions.
rewrite_with_sed 's|extension-ci.yml@v1|extension-ci.yml@v0|' "$work/blocks/.github/workflows/ci.yml"
rewrite_with_sed 's|mariadb:11.4|mariadb:10.6|' "$work/blocks/.docker/docker-compose.ci.yml"
if "$root/scaffold/ckinit.php" --check "$work/blocks" >/dev/null 2>&1; then
  echo "an edit inside a managed block was not detected" >&2; exit 1
fi
out=$("$root/scaffold/ckinit.php" --update "$work/blocks")
grep -q 'updated   .github/workflows/ci.yml' <<<"$out"
grep -q 'updated   .docker/docker-compose.ci.yml' <<<"$out"
grep -q 'extension-ci.yml@v1' "$work/blocks/.github/workflows/ci.yml"
grep -q 'sibling_repo: acme/other' "$work/blocks/.github/workflows/ci.yml"
grep -q 'runs-on: ubuntu-latest' "$work/blocks/.github/workflows/ci.yml"
grep -q 'mariadb:11.4' "$work/blocks/.docker/docker-compose.ci.yml"
grep -q 'ext/other:ro' "$work/blocks/.docker/docker-compose.ci.yml"
"$root/scaffold/ckinit.php" --check "$work/blocks" >/dev/null
# A repo file from before the markers: whole-file drift, --update brings the template.
make_extension "$work/legacy"
"$root/scaffold/ckinit.php" "$work/legacy" >/dev/null
grep -v 'CIVIKITCHEN MANAGED' "$work/legacy/.github/workflows/ci.yml" > "$work/legacy/ci.tmp"
/bin/mv "$work/legacy/ci.tmp" "$work/legacy/.github/workflows/ci.yml"
out=$("$root/scaffold/ckinit.php" --update "$work/legacy")
grep -q 'updated   .github/workflows/ci.yml' <<<"$out"
grep -q 'BEGIN CIVIKITCHEN MANAGED caller' "$work/legacy/.github/workflows/ci.yml"
# The release caller: seeded with the trigger for plain and pre-release tags.
rel="$work/legacy/.github/workflows/release.yml"
grep -qF "tags: ['v[0-9]+.[0-9]+.[0-9]+', 'v[0-9]+.[0-9]+.[0-9]+-*']" "$rel"
grep -q 'extension-release.yml@v1' "$rel"
# A repo without one gets it from --update; --check reports it missing first.
/bin/rm "$rel"
out=$("$root/scaffold/ckinit.php" --check "$work/legacy" 2>&1 || true)
grep -q 'missing   .github/workflows/release.yml' <<<"$out" || { echo "a missing release caller was not reported: $out" >&2; exit 1; }
"$root/scaffold/ckinit.php" --update "$work/legacy" >/dev/null
test -f "$rel"
# A repo that declares release: none gets no caller and is not told one is missing.
cp -R "$work/legacy" "$work/norelease"
/bin/rm "$work/norelease/.github/workflows/release.yml"
printf '%s\n' 'version: 1' 'policy:' '  release:' '    mode: none' "    reason: 'no archives yet'" > "$work/norelease/civikitchen.yaml"
out=$("$root/scaffold/ckinit.php" --check "$work/norelease" 2>&1 || true)
if grep -q 'release.yml' <<<"$out"; then
  echo "release: none still asks for a release caller: $out" >&2
  exit 1
fi
"$root/scaffold/ckinit.php" --update "$work/norelease" >/dev/null
test ! -e "$work/norelease/.github/workflows/release.yml"
# A managed caller left behind by release: none still publishes every tag:
# drift for --check, deleted by --update unless the repository owns lines in it.
cp -R "$work/legacy" "$work/stalerelease"
cp "$work/norelease/civikitchen.yaml" "$work/stalerelease/civikitchen.yaml"
out=$("$root/scaffold/ckinit.php" --check "$work/stalerelease" 2>&1 || true)
grep -q 'drifted   .github/workflows/release.yml (release: none' <<<"$out" \
  || { echo "a caller left behind by release: none was not reported: $out" >&2; exit 1; }
cp -R "$work/stalerelease" "$work/stalerelease-owned"
out=$("$root/scaffold/ckinit.php" --update "$work/stalerelease")
grep -q 'removed   .github/workflows/release.yml' <<<"$out"
test ! -e "$work/stalerelease/.github/workflows/release.yml"
"$root/scaffold/ckinit.php" --check "$work/stalerelease" >/dev/null
printf '%s\n' '    secrets:' '      composer_app_id: ${{ secrets.APP_ID }}' >> "$work/stalerelease-owned/.github/workflows/release.yml"
cp "$work/stalerelease-owned/.github/workflows/release.yml" "$work/stalerelease-owned.yml"
if out=$("$root/scaffold/ckinit.php" --update "$work/stalerelease-owned" 2>&1); then
  echo "--update deleted a release caller carrying repository lines" >&2
  exit 1
fi
grep -q 'composer_app_id' <<<"$out" || { echo "the refusal did not name the repository lines: $out" >&2; exit 1; }
cmp -s "$work/stalerelease-owned/.github/workflows/release.yml" "$work/stalerelease-owned.yml" \
  || { echo "--update touched a release caller it refused to delete" >&2; exit 1; }
# A release.yml that calls no release is the repository's, release: none or not.
printf '%s\n' 'name: Docs' 'on: {push: {tags: ["v*"]}}' 'jobs:' '  docs:' '    runs-on: ubuntu-latest' \
  '    steps: [{run: "true"}]' > "$work/stalerelease/.github/workflows/release.yml"
cp "$work/stalerelease/.github/workflows/release.yml" "$work/docs-release.yml"
"$root/scaffold/ckinit.php" --check "$work/stalerelease" >/dev/null \
  || { echo "a release.yml that publishes nothing was reported under release: none" >&2; exit 1; }
"$root/scaffold/ckinit.php" --update "$work/stalerelease" >/dev/null
cmp -s "$work/stalerelease/.github/workflows/release.yml" "$work/docs-release.yml" \
  || { echo "--update touched a release.yml that publishes nothing" >&2; exit 1; }
# Inputs below the marker are the repo's and survive --update; an old trigger
# inside the block is drift and gets refreshed.
rewrite_with_sed 's|^# END CIVIKITCHEN MANAGED caller$|# END CIVIKITCHEN MANAGED caller\
    with:\
      # The install needs a payment processor no headless site has.\
      smoke_test: false\
      require_changelog: true|' "$rel"
"$root/scaffold/ckinit.php" --check "$work/legacy" >/dev/null
rewrite_with_sed "s|tags: \\['v\\[0-9\\]+.*$|tags: ['v*.*.*']|" "$rel"
if "$root/scaffold/ckinit.php" --check "$work/legacy" >/dev/null 2>&1; then
  echo "an old release trigger was not reported as drift" >&2
  exit 1
fi
out=$("$root/scaffold/ckinit.php" --update "$work/legacy")
grep -q 'updated   .github/workflows/release.yml' <<<"$out"
grep -qF "'v[0-9]+.[0-9]+.[0-9]+-*'" "$rel"
grep -q '# The install needs a payment processor no headless site has.' "$rel"
grep -q 'smoke_test: false' "$rel"
grep -q 'require_changelog: true' "$rel"
"$root/scaffold/ckinit.php" --check "$work/legacy" >/dev/null
# A caller from before the markers: --update keeps the job's inputs and secrets
# as written, comments included, and replaces the rest with the template.
adopt="$work/adopt"
make_extension "$adopt"
"$root/scaffold/ckinit.php" "$adopt" >/dev/null
cat > "$adopt/.github/workflows/release.yml" <<'YAML'
name: Release

on:
  push:
    tags: ['v[0-9]+.[0-9]+.[0-9]+']

permissions:
  contents: read

jobs:
  release:
    permissions:
      contents: write
    uses: jfilter/civikitchen/.github/workflows/extension-release.yml@v1
    with:
      # The dependency release is pinned in civikitchen.yaml.
      composer_app_repositories: exampledep
      composer_install: true
    secrets:
      # Read-only GitHub App for the private dependency.
      composer_app_id: ${{ vars.EXAMPLE_APP_ID }}
      composer_app_private_key: ${{ secrets.EXAMPLE_APP_KEY }}
YAML
out=$("$root/scaffold/ckinit.php" --check "$adopt" 2>&1 || true)
grep -q 'drifted   .github/workflows/release.yml$' <<<"$out" || { echo "a marker-less caller was not plain drift: $out" >&2; exit 1; }
"$root/scaffold/ckinit.php" --update "$adopt" >/dev/null
arel="$adopt/.github/workflows/release.yml"
grep -q 'BEGIN CIVIKITCHEN MANAGED caller' "$arel"
grep -qF "'v[0-9]+.[0-9]+.[0-9]+-*'" "$arel"
grep -qF 'uses: civico-de/civikitchen/.github/workflows/extension-release.yml@v1' "$arel" \
  || { echo "--update kept the previous owner in the release caller" >&2; exit 1; }
for kept in '# The dependency release is pinned in civikitchen.yaml.' 'composer_app_repositories: exampledep' \
  'composer_install: true' '# Read-only GitHub App for the private dependency.' \
  'composer_app_id: ${{ vars.EXAMPLE_APP_ID }}' 'composer_app_private_key: ${{ secrets.EXAMPLE_APP_KEY }}'; do
  grep -qF "$kept" "$arel" || { echo "--update dropped '$kept' from the release caller" >&2; exit 1; }
done
php -r '
  require $argv[1];
  $job = \Symfony\Component\Yaml\Yaml::parseFile($argv[2])["jobs"]["release"];
  assert($job["with"] === ["composer_app_repositories" => "exampledep", "composer_install" => true]);
  assert(count($job["secrets"]) === 2);
' "$root/packages/civikitchen-scenario-schema/vendor/autoload.php" "$arel"
"$root/scaffold/ckinit.php" --check "$adopt" >/dev/null

# Repo-owned content --update cannot place: nothing is written, both modes name it.
cat > "$arel" <<'YAML'
name: Release
on:
  push:
    tags: ['v[0-9]+.[0-9]+.[0-9]+']
  workflow_dispatch:
env:
  EXAMPLE: 1
permissions:
  contents: read
jobs:
  release:
    permissions:
      contents: write
    uses: jfilter/civikitchen/.github/workflows/extension-release.yml@v1
YAML
cp "$arel" "$work/adopt-before.yml"
/bin/rm "$adopt/tests/e2e/lib.sh"
out=$("$root/scaffold/ckinit.php" --check "$adopt" 2>&1 || true)
grep -q 'would drop: .*on.workflow_dispatch' <<<"$out" || { echo "--check did not name the lost trigger: $out" >&2; exit 1; }
grep -q 'would drop: .*env' <<<"$out" || { echo "--check did not name the lost env: $out" >&2; exit 1; }
if out=$("$root/scaffold/ckinit.php" --update "$adopt" 2>&1); then
  echo "--update accepted a caller it would have cut down" >&2
  exit 1
fi
grep -q 'would drop: .*on.workflow_dispatch' <<<"$out"
cmp -s "$arel" "$work/adopt-before.yml" || { echo "--update rewrote a caller it refused" >&2; exit 1; }
test ! -e "$adopt/tests/e2e/lib.sh" || { echo "--update wrote files after refusing" >&2; exit 1; }

# A caller in another workflow: --update creates no second one next to it.
/bin/rm "$arel"
cat > "$adopt/.github/workflows/publish.yml" <<'YAML'
name: Publish
on:
  push:
    tags: ['v*']
jobs:
  publish:
    uses: civico-de/civikitchen/.github/workflows/extension-release.yml@v1
YAML
if out=$("$root/scaffold/ckinit.php" --update "$adopt" 2>&1); then
  echo "--update created a second release caller" >&2
  exit 1
fi
grep -q '.github/workflows/publish.yml also calls extension-release.yml' <<<"$out"
test ! -e "$arel"
# A mention in a comment is no caller.
printf '%s\n' '# see extension-release.yml' 'name: Publish' 'on: push' 'jobs:' '  x:' '    runs-on: ubuntu-latest' \
  '    steps: [{run: "true"}]' > "$adopt/.github/workflows/publish.yml"
"$root/scaffold/ckinit.php" --update "$adopt" >/dev/null
test -f "$arel"
# A second caller beside the managed one is drift as well.
printf '%s\n' 'on: push' 'jobs:' '  rel:' '    uses: civico-de/civikitchen/.github/workflows/extension-release.yml@v1' \
  > "$adopt/.github/workflows/publish.yml"
out=$("$root/scaffold/ckinit.php" --check "$adopt" 2>&1 || true)
grep -q 'publish.yml also calls extension-release.yml' <<<"$out" \
  || { echo "a second caller beside release.yml was not reported: $out" >&2; exit 1; }
/bin/rm "$adopt/.github/workflows/publish.yml"
# A dry-run caller publishes nothing, so it is no second caller.
printf '%s\n' 'on: push' 'jobs:' '  rel:' '    uses: civico-de/civikitchen/.github/workflows/extension-release.yml@v1' \
  '    with:' '      dry_run: true' > "$adopt/.github/workflows/release-dry-run.yml"
"$root/scaffold/ckinit.php" --check "$adopt" >/dev/null \
  || { echo "a dry-run caller was counted as a second release caller" >&2; exit 1; }
/bin/rm "$adopt/.github/workflows/release-dry-run.yml"

# A repo that removed a block is reported, never silently rewritten.
rewrite_with_sed '/CIVIKITCHEN MANAGED db/d' "$work/blocks/.docker/docker-compose.ci.yml"
out=$("$root/scaffold/ckinit.php" --check "$work/blocks" 2>&1 || true)
grep -q 'managed blocks do not match' <<<"$out" || { echo "a removed block was not reported: $out" >&2; exit 1; }

# --- several extensions in one repository ------------------------------------

make_keyed_extension() {
  local target="$1" key="$2" file="$3" requires="${4:-}"
  local element=''
  if [ -n "$requires" ]; then
    element="<requires><ext>$requires</ext></requires>"
  fi
  mkdir -p "$target"
  printf '%s\n' "<extension key=\"$key\" type=\"module\"><file>$file</file><license>AGPL-3.0</license>$element</extension>" > "$target/info.xml"
}

# A single-extension repository: `../..` there is the parent of the repository,
# so the repo mount must NOT appear — and the root-only files must.
mkdir -p "$work/single"
git -C "$work/single" init -q
make_extension "$work/single"
"$root/scaffold/ckinit.php" "$work/single" >/dev/null
test -f "$work/single/renovate.json"
if grep -q -- '- \.\./\.\.:/civikitchen-repo' "$work/single/.docker/docker-compose.ci.yml"; then
  echo "single-extension repo got the /civikitchen-repo mount" >&2
  exit 1
fi

mono="$work/mono"
mkdir -p "$mono"
git -C "$mono" init -q
make_keyed_extension "$mono/base" org.acme.base base
make_keyed_extension "$mono/addon" org.acme.addon addon org.acme.base
"$root/scaffold/ckinit.php" "$mono" >/dev/null

# The root pass owns the root files, one managed job block per extension.
grep -q 'BEGIN CIVIKITCHEN MANAGED job-base' "$mono/.github/workflows/ci.yml"
grep -q 'working_directory: addon' "$mono/.github/workflows/ci.yml"
grep -q '"extends": \["config:recommended"\]' "$mono/renovate.json"
test -f "$mono/.gitattributes"
# The release caller: one build job per extension, needing the jobs of the
# same-repository extensions it requires, and one publish job needing all.
assert_release_jobs() {
  php -r '
    require $argv[1];
    $jobs = \Symfony\Component\Yaml\Yaml::parseFile($argv[2])["jobs"] ?? [];
    $actual = [];
    foreach ($jobs as $id => $job) {
      $actual[] = $id . "[" . implode(",", (array) ($job["needs"] ?? [])) . "](" . implode(",", array_map(
        static fn ($k, $v) => "$k=" . var_export($v, TRUE),
        array_keys($job["with"] ?? []),
        $job["with"] ?? [],
      )) . ")";
    }
    $actual = implode(" ", $actual);
    if ($actual !== $argv[3]) {
      fwrite(STDERR, "release jobs are \"$actual\", expected \"{$argv[3]}\"\n");
      exit(1);
    }
  ' "$root/packages/civikitchen-scenario-schema/vendor/autoload.php" \
    "$mono/.github/workflows/release.yml" "$1"
}
assert_release_jobs "addon[base](working_directory='addon',stage='build') base[](working_directory='base',stage='build') publish[addon,base](stage='publish')"
# No root-only file below the root: GitHub and Renovate never read them there.
for stray in renovate.json .github/workflows/ci.yml .github/workflows/release.yml; do
  if [ -e "$mono/base/$stray" ]; then
    echo "root-only file was written into an extension directory: $stray" >&2
    exit 1
  fi
done
# The repository root is mounted for the git-reading tools, in both stacks.
grep -q -- '- \.\./\.\.:/civikitchen-repo' "$mono/base/.docker/docker-compose.ci.yml"
grep -q -- '- \.\./\.\.:/civikitchen-repo' "$mono/base/.docker/docker-compose.yml"
"$root/scaffold/ckinit.php" --check "$mono" >/dev/null

# A removed mount is drift in the monorepo case.
rewrite_with_sed '\|../..:/civikitchen-repo|d' "$mono/addon/.docker/docker-compose.ci.yml"
if out=$("$root/scaffold/ckinit.php" --check "$mono" 2>&1); then
  echo "a missing /civikitchen-repo mount was not detected" >&2
  exit 1
fi
grep -q 'drifted   .docker/docker-compose.ci.yml' <<<"$out"
"$root/scaffold/ckinit.php" --update "$mono" >/dev/null
"$root/scaffold/ckinit.php" --check "$mono" >/dev/null

# `with:` inputs the repository added to a managed job survive --update.
rewrite_with_sed 's|^  # END CIVIKITCHEN MANAGED job-addon$|  # END CIVIKITCHEN MANAGED job-addon\
      playwright: true|' "$mono/.github/workflows/ci.yml"
"$root/scaffold/ckinit.php" --check "$mono" >/dev/null
rewrite_with_sed 's|extension-ci.yml@v1|extension-ci.yml@v0|' "$mono/.github/workflows/ci.yml"
if "$root/scaffold/ckinit.php" --check "$mono" >/dev/null 2>&1; then
  echo "an edit inside a root job block was not detected" >&2
  exit 1
fi
out=$("$root/scaffold/ckinit.php" --update "$mono")
grep -q 'updated   .github/workflows/ci.yml' <<<"$out"
grep -q 'extension-ci.yml@v1' "$mono/.github/workflows/ci.yml"
grep -q 'playwright: true' "$mono/.github/workflows/ci.yml"

# The same for the release caller: repo-owned inputs and secrets after a job's
# END marker survive, a stale managed line is drift that --update repairs.
rewrite_with_sed 's|^  # END CIVIKITCHEN MANAGED job-addon$|  # END CIVIKITCHEN MANAGED job-addon\
      smoke_test: false\
    secrets:\
      composer_app_id: ${{ secrets.APP_ID }}|' "$mono/.github/workflows/release.yml"
"$root/scaffold/ckinit.php" --check "$mono" >/dev/null
rewrite_with_sed 's|^      stage: publish$|      stage: release|' "$mono/.github/workflows/release.yml"
out=$("$root/scaffold/ckinit.php" --check "$mono" 2>&1 || true)
grep -q 'drifted   .github/workflows/release.yml' <<<"$out" \
  || { echo "a stale release publish job was not reported: $out" >&2; exit 1; }
out=$("$root/scaffold/ckinit.php" --update "$mono")
grep -q 'updated   .github/workflows/release.yml' <<<"$out"
grep -q 'composer_app_id: ${{ secrets.APP_ID }}' "$mono/.github/workflows/release.yml"
assert_release_jobs "addon[base](working_directory='addon',stage='build',smoke_test=false) base[](working_directory='base',stage='build') publish[addon,base](stage='publish')"

# The jobs of the root caller, as YAML: job id => its `with:` inputs.
assert_root_jobs() {
  php -r '
    require $argv[1];
    $jobs = \Symfony\Component\Yaml\Yaml::parseFile($argv[2])["jobs"] ?? [];
    $actual = [];
    foreach ($jobs as $id => $job) {
      $actual[] = $id . "(" . implode(",", array_map(
        static fn ($k, $v) => "$k=" . var_export($v, TRUE),
        array_keys($job["with"] ?? []),
        $job["with"] ?? [],
      )) . ")";
    }
    $actual = implode(" ", $actual);
    if ($actual !== $argv[3]) {
      fwrite(STDERR, "root jobs are \"$actual\", expected \"{$argv[3]}\"\n");
      exit(1);
    }
  ' "$root/packages/civikitchen-scenario-schema/vendor/autoload.php" \
    "$mono/.github/workflows/ci.yml" "$1"
}

assert_root_jobs "addon(working_directory='addon',playwright=true) base(working_directory='base')"

# A new extension directory without a job fails --check; --update adds the job
# and leaves the other jobs' repo-owned inputs where they were.
make_keyed_extension "$mono/third" org.acme.third third
out=$("$root/scaffold/ckinit.php" --check "$mono" 2>&1 || true)
grep -q 'drifted   .github/workflows/ci.yml' <<<"$out" \
  || { echo "an extension directory without a job was not reported: $out" >&2; exit 1; }
out=$("$root/scaffold/ckinit.php" --update "$mono")
grep -q 'updated   .github/workflows/ci.yml' <<<"$out"
"$root/scaffold/ckinit.php" --check "$mono" >/dev/null
assert_root_jobs "addon(working_directory='addon',playwright=true) base(working_directory='base') third(working_directory='third')"
assert_release_jobs "addon[base](working_directory='addon',stage='build',smoke_test=false) base[](working_directory='base',stage='build') publish[addon,base,third](stage='publish') third[](working_directory='third',stage='build')"

# A removed directory whose job carries no repo-owned input: --update drops it.
/bin/rm -rf "$mono/third"
out=$("$root/scaffold/ckinit.php" --check "$mono" 2>&1 || true)
grep -q 'drifted   .github/workflows/ci.yml' <<<"$out" \
  || { echo "a job for a removed directory was not reported: $out" >&2; exit 1; }
"$root/scaffold/ckinit.php" --update "$mono" >/dev/null
if grep -q 'job-third' "$mono/.github/workflows/ci.yml" "$mono/.github/workflows/release.yml"; then
  echo "--update kept the job of a removed directory" >&2
  exit 1
fi
"$root/scaffold/ckinit.php" --check "$mono" >/dev/null
assert_release_jobs "addon[base](working_directory='addon',stage='build',smoke_test=false) base[](working_directory='base',stage='build') publish[addon,base](stage='publish')"
assert_root_jobs "addon(working_directory='addon',playwright=true) base(working_directory='base')"

# A removed directory whose job carries repo-owned inputs: refuse, touch nothing.
cp -R "$mono" "$work/mono-gone"
/bin/rm -rf "$work/mono-gone/addon"
out=$("$root/scaffold/ckinit.php" --check "$work/mono-gone" 2>&1 || true)
grep -q 'drifted   .github/workflows/ci.yml' <<<"$out" \
  || { echo "a job for a removed directory was not reported: $out" >&2; exit 1; }
cp "$work/mono-gone/.github/workflows/ci.yml" "$work/mono-gone-ci.yml"
if out=$("$root/scaffold/ckinit.php" --update "$work/mono-gone" 2>&1); then
  echo "--update silently dropped repo-owned inputs of a removed job" >&2
  exit 1
fi
grep -q 'playwright: true' <<<"$out" \
  || { echo "the refusal did not name the orphaned lines: $out" >&2; exit 1; }
cmp -s "$work/mono-gone-ci.yml" "$work/mono-gone/.github/workflows/ci.yml" \
  || { echo "--update rewrote the file it refused" >&2; exit 1; }

# A dot-directory is no extension of the repository: in CI the workspace root
# also holds the .civikitchen-* helper checkouts.
make_keyed_extension "$mono/.hidden" org.acme.hidden hidden
"$root/scaffold/ckinit.php" --check "$mono" >/dev/null
if grep -q 'hidden' "$mono/.github/workflows/ci.yml"; then
  echo "a dot-directory was given a job" >&2
  exit 1
fi
assert_root_jobs "addon(working_directory='addon',playwright=true) base(working_directory='base')"
/bin/rm -rf "$mono/.hidden"

# Two directories that need the same job id: a hard error in every mode, and
# nothing is written — a duplicate YAML key would lose one extension.
mkdir -p "$work/collide"
git -C "$work/collide" init -q
make_keyed_extension "$work/collide/foo.bar" org.acme.foobar foobar
make_keyed_extension "$work/collide/foo_bar" org.acme.foo_bar foo_bar
for mode in seed check update; do
  # Never an empty array: `set -u` with bash 3.2 rejects expanding one.
  case "$mode" in
    check) arguments=(--check "$work/collide") ;;
    update) arguments=(--update "$work/collide") ;;
    *) arguments=("$work/collide") ;;
  esac
  if out=$("$root/scaffold/ckinit.php" "${arguments[@]}" 2>&1); then
    echo "colliding job ids were accepted ($mode)" >&2
    exit 1
  fi
  grep -q "'foo.bar' and 'foo_bar'" <<<"$out" \
    || { echo "the collision did not name both directories: $out" >&2; exit 1; }
  grep -q "job id 'foo_bar'" <<<"$out" \
    || { echo "the collision did not name the job id: $out" >&2; exit 1; }
done
for stray in .github/workflows/ci.yml renovate.json .gitattributes foo.bar/composer.json; do
  if [ -e "$work/collide/$stray" ]; then
    echo "the colliding root was stamped anyway: $stray" >&2
    exit 1
  fi
done

# A missing release caller is reported; the job of a directory named like the
# publish job, or one requiring an extension that never releases, is refused.
cp "$mono/.github/workflows/release.yml" "$work/mono-release.yml"
/bin/rm "$mono/.github/workflows/release.yml"
out=$("$root/scaffold/ckinit.php" --check "$mono" 2>&1 || true)
grep -q 'missing   .github/workflows/release.yml' <<<"$out" \
  || { echo "a missing root release caller was not reported: $out" >&2; exit 1; }
# Another workflow already calling the shared release blocks a second caller.
mkdir -p "$mono/.github/workflows"
printf '%s\n' 'on: push' 'jobs:' '  rel:' '    uses: civico-de/civikitchen/.github/workflows/extension-release.yml@v1' \
  > "$mono/.github/workflows/publish.yml"
if out=$("$root/scaffold/ckinit.php" --update "$mono" 2>&1); then
  echo "a second release caller was stamped beside an existing one" >&2
  exit 1
fi
grep -q 'publish.yml also calls extension-release.yml' <<<"$out" \
  || { echo "the existing release caller was not named: $out" >&2; exit 1; }
test ! -e "$mono/.github/workflows/release.yml"
/bin/rm "$mono/.github/workflows/publish.yml"
cp "$work/mono-release.yml" "$mono/.github/workflows/release.yml"
"$root/scaffold/ckinit.php" --check "$mono" >/dev/null
# ... and beside an existing root caller too.
printf '%s\n' 'on: push' 'jobs:' '  rel:' '    uses: civico-de/civikitchen/.github/workflows/extension-release.yml@v1' \
  > "$mono/.github/workflows/publish.yml"
out=$("$root/scaffold/ckinit.php" --check "$mono" 2>&1 || true)
grep -q 'publish.yml also calls extension-release.yml' <<<"$out" \
  || { echo "a second caller beside the root release.yml was not reported: $out" >&2; exit 1; }
/bin/rm "$mono/.github/workflows/publish.yml"
printf '%s\n' 'on: push' 'jobs:' '  rel:' '    uses: civico-de/civikitchen/.github/workflows/extension-release.yml@v1' \
  '    with: {dry_run: true, working_directory: base}' > "$mono/.github/workflows/release-dry-run.yml"
"$root/scaffold/ckinit.php" --check "$mono" >/dev/null \
  || { echo "a dry-run caller was counted as a second root release caller" >&2; exit 1; }
/bin/rm "$mono/.github/workflows/release-dry-run.yml"
# A hand-written root caller: its inputs and secrets are never overwritten.
printf '%s\n' 'name: Release' 'on: {push: {tags: ["v*"]}}' 'jobs:' '  release:' \
  '    uses: civico-de/civikitchen/.github/workflows/extension-release.yml@v1' \
  '    with: {working_directory: base}' '    secrets:' '      composer_app_id: ${{ secrets.APP_ID }}' \
  > "$mono/.github/workflows/release.yml"
cp "$mono/.github/workflows/release.yml" "$work/mono-handwritten.yml"
out=$("$root/scaffold/ckinit.php" --check "$mono" 2>&1 || true)
grep -q 'drifted   .github/workflows/release.yml (no managed markers' <<<"$out" \
  || { echo "a hand-written root release caller was not reported: $out" >&2; exit 1; }
if "$root/scaffold/ckinit.php" --update "$mono" >/dev/null 2>&1; then
  echo "--update accepted a hand-written root release caller" >&2
  exit 1
fi
cmp -s "$mono/.github/workflows/release.yml" "$work/mono-handwritten.yml" \
  || { echo "--update overwrote a hand-written root release caller" >&2; exit 1; }
cp "$work/mono-release.yml" "$mono/.github/workflows/release.yml"
"$root/scaffold/ckinit.php" --check "$mono" >/dev/null

for layout in publish-dir none-dependency; do
  tree="$work/release-$layout"
  mkdir -p "$tree"
  git -C "$tree" init -q
  make_keyed_extension "$tree/base" org.acme.base base
  case "$layout" in
    publish-dir)
      make_keyed_extension "$tree/publish" org.acme.publish publish
      expected="release job id 'publish'"
      ;;
    none-dependency)
      make_keyed_extension "$tree/addon" org.acme.addon addon org.acme.base
      printf '%s\n' 'version: 1' 'policy:' '  release:' '    mode: none' '    reason: internal glue' > "$tree/base/civikitchen.yaml"
      expected='addon requires base, which declares release: none'
      ;;
  esac
  if out=$("$root/scaffold/ckinit.php" "$tree" 2>&1); then
    echo "the $layout layout was accepted" >&2
    exit 1
  fi
  grep -q "$expected" <<<"$out" || { echo "the $layout refusal did not say why: $out" >&2; exit 1; }
  test ! -e "$tree/.github/workflows/release.yml"
done

# Extensions that never release get no job; when none releases, there is no
# release caller to write or check.
none="$work/release-none"
mkdir -p "$none"
git -C "$none" init -q
for extension in base addon; do
  make_keyed_extension "$none/$extension" "org.acme.$extension" "$extension"
  printf '%s\n' 'version: 1' 'policy:' '  release:' '    mode: none' '    reason: internal glue' > "$none/$extension/civikitchen.yaml"
done
"$root/scaffold/ckinit.php" --update "$none" >/dev/null
test ! -e "$none/.github/workflows/release.yml"
"$root/scaffold/ckinit.php" --check "$none" >/dev/null
# A root caller left from when they did release: drift, deleted by --update
# unless the repository owns lines in it.
/bin/rm "$none"/*/civikitchen.yaml
"$root/scaffold/ckinit.php" --update "$none" >/dev/null
cp "$none/.github/workflows/release.yml" "$work/none-release.yml"
for extension in base addon; do
  printf '%s\n' 'version: 1' 'policy:' '  release:' '    mode: none' '    reason: internal glue' > "$none/$extension/civikitchen.yaml"
done
out=$("$root/scaffold/ckinit.php" --check "$none" 2>&1 || true)
grep -q 'drifted   .github/workflows/release.yml (release: none' <<<"$out" \
  || { echo "a root caller left behind by release: none was not reported: $out" >&2; exit 1; }
printf '%s\n' '      smoke_test: false' >> "$none/.github/workflows/release.yml"
if out=$("$root/scaffold/ckinit.php" --update "$none" 2>&1); then
  echo "--update deleted a root release caller carrying repository lines" >&2
  exit 1
fi
grep -q 'smoke_test: false' <<<"$out" || { echo "the refusal did not name the repository lines: $out" >&2; exit 1; }
test -f "$none/.github/workflows/release.yml"
cp "$work/none-release.yml" "$none/.github/workflows/release.yml"
out=$("$root/scaffold/ckinit.php" --update "$none")
grep -q 'removed   .github/workflows/release.yml' <<<"$out"
test ! -e "$none/.github/workflows/release.yml"
"$root/scaffold/ckinit.php" --check "$none" >/dev/null
printf '%s\n' 'name: Docs' 'on: {push: {tags: ["v*"]}}' 'jobs:' '  docs:' '    runs-on: ubuntu-latest' \
  '    steps: [{run: "true"}]' > "$none/.github/workflows/release.yml"
"$root/scaffold/ckinit.php" --check "$none" >/dev/null \
  || { echo "a root release.yml that publishes nothing was reported under release: none" >&2; exit 1; }
"$root/scaffold/ckinit.php" --update "$none" >/dev/null
grep -q 'name: Docs' "$none/.github/workflows/release.yml" \
  || { echo "--update touched a root release.yml that publishes nothing" >&2; exit 1; }

# A repository root that is neither an extension nor a monorepo stays an error.
mkdir -p "$work/empty-root"
git -C "$work/empty-root" init -q
if "$root/scaffold/ckinit.php" --check "$work/empty-root" >/dev/null 2>&1; then
  echo "a root without extensions was accepted" >&2
  exit 1
fi

# The seeded CI compose file must be valid: a `volumes:` key with no entries
# is rejected by docker compose ("must be a array").
if awk '/^ *volumes: *$/ { v=1; next } v && !/^ *(- |#)/ { bad=1 } { v=0 } END { exit bad }' \
    "$work/clean/.docker/docker-compose.ci.yml"; then :; else
  echo "ckinit: seeded docker-compose.ci.yml has an empty volumes: key" >&2
  exit 1
fi

# The seeded dev profile, which both compose stacks select, passes the boot-time validation.
"$root/toolbelt/bin/ck" profile validate "$work/clean/.docker/profiles/dev" >/dev/null \
  || { echo "ckinit: seeded dev profile is not a valid profile" >&2; exit 1; }
grep -q 'example_ext' "$work/clean/.docker/profiles/dev/profile.json" \
  || { echo "ckinit: seeded dev profile did not get the extension's name" >&2; exit 1; }

# ci: gitlab swaps the GitHub callers and renovate.json for a managed
# .gitlab-ci.yml; without the key no GitLab file appears.
test ! -e "$work/clean/.gitlab-ci.yml"
make_extension "$work/gitlab"
printf '%s\n' 'version: 1' 'policy:' '  ci: gitlab' '  release:' '    mode: none' "    reason: 'tagged on GitLab'" > "$work/gitlab/civikitchen.yaml"
out=$("$root/scaffold/ckinit.php" --update "$work/gitlab")
grep -q 'created   .gitlab-ci.yml' <<<"$out"
grep -q 'remote: https://raw.githubusercontent.com/civico-de/civikitchen/v1/ci/gitlab/extension-ci.yml' "$work/gitlab/.gitlab-ci.yml"
for stray in renovate.json .github/workflows/ci.yml .github/workflows/release.yml; do
  if [ -e "$work/gitlab/$stray" ]; then
    echo "ci: gitlab still seeded a GitHub file: $stray" >&2
    exit 1
  fi
done
"$root/scaffold/ckinit.php" --check "$work/gitlab" >/dev/null
# Variables below the managed block are the repository's; the include is not.
printf '%s\n' 'variables:' '  CK_LIFECYCLE: "0"' >> "$work/gitlab/.gitlab-ci.yml"
"$root/scaffold/ckinit.php" --check "$work/gitlab" >/dev/null
rewrite_with_sed 's|civikitchen/v1/|civikitchen/v0/|' "$work/gitlab/.gitlab-ci.yml"
out=$("$root/scaffold/ckinit.php" --check "$work/gitlab" 2>&1 || true)
grep -q 'drifted   .gitlab-ci.yml' <<<"$out" || { echo "a drifted GitLab caller was not reported: $out" >&2; exit 1; }
"$root/scaffold/ckinit.php" --update "$work/gitlab" >/dev/null
grep -q 'civikitchen/v1/' "$work/gitlab/.gitlab-ci.yml"
grep -q 'CK_LIFECYCLE: "0"' "$work/gitlab/.gitlab-ci.yml"
# The managed e2e lib.sh, with docker, cv, mariadb and curl stubbed.
lib="$work/gitlab/tests/e2e/lib.sh"
mkdir -p "$work/fakebin" "$work/site"
cat > "$work/fakebin/docker" <<'SH'
#!/bin/sh
case "$*" in
  "compose port app 80") echo "0.0.0.0:8123" ;;
  "compose port maildev 1080") echo "${FAKE_MAILDEV:-0.0.0.0:1091}" ;;
  *"civicrm.standalone.php"*) echo "${FAKE_FLAVOR:-standalone}" ;;
  *) echo "compose:$*"; [ -t 0 ] || cat ;;
esac
SH
printf '%s\n' '#!/bin/sh' 'echo "direct:$PWD:$*"' > "$work/fakebin/cv"
# sql goes through the mariadb client: MariaDB 11 images ship no mysql binary.
printf '%s\n' '#!/bin/sh' 'echo "mariadb:$*"' > "$work/fakebin/mariadb"
chmod +x "$work/fakebin/docker" "$work/fakebin/cv" "$work/fakebin/mariadb"
in_image() { PATH="$work/fakebin:$PATH" CK_IN_IMAGE=1 CK_APP_USER=www-data CK_APP_CWD="$work/site" bash -c "$@"; }
via_compose() { PATH="$work/fakebin:$PATH" COMPOSE_DIR="$work" bash -c "$@"; }

# Inside the image (GitLab jobs) the helpers run directly from the site root.
out=$(in_image '. "$1"; cv ext:list; app pwd; echo "$BASE_URL $MAILDEV_URL"' _ "$lib")
[ "$out" = "direct:$work/site:ext:list"$'\n'"$work/site"$'\n'"http://localhost:8080 http://localhost:1080" ] \
  || { echo "lib.sh in the image: $out" >&2; exit 1; }
out=$(in_image '. "$1"; sql "SELECT 1"' _ "$lib")
[ "$out" = 'mariadb:-h db -N -B -ucivicrm -pcivicrm civicrm -e SELECT 1' ] || { echo "lib.sh sql in the image: $out" >&2; exit 1; }

# Through compose, cv and app run as the probed flavor's web user from its site
# root; BASE_URL and MAILDEV_URL follow the published ports.
out=$(via_compose '. "$1"; echo "$BASE_URL $MAILDEV_URL"; cv ext:list; sql "SELECT 1"' _ "$lib" </dev/null)
[ "$out" = $'http://localhost:8123 http://localhost:1091\ncompose:compose exec -T -u www-data -w /var/www/html app cv ext:list\ncompose:compose exec -T db mariadb -N -B -ucivicrm -pcivicrm civicrm -e SELECT 1' ] \
  || { echo "lib.sh via compose: $out" >&2; exit 1; }
out=$(FAKE_FLAVOR=buildkit via_compose '. "$1"; cv ext:list' _ "$lib" </dev/null)
[ "$out" = 'compose:compose exec -T -u buildkit -w /home/buildkit/buildkit/build/site/web app cv ext:list' ] || { echo "lib.sh cv on buildkit: $out" >&2; exit 1; }
out=$(FAKE_FLAVOR=down via_compose '. "$1"; cv ext:list' _ "$lib" 2>&1 </dev/null || true)
grep -q 'does not answer' <<<"$out" || { echo "lib.sh guessed a flavor for a stack that is down: $out" >&2; exit 1; }
# A half override is probed like none; an unpublished port (:0) is no URL.
out=$(CK_APP_USER=buildkit FAKE_MAILDEV='0.0.0.0:0' via_compose 'set -u; . "$1"; echo "[$MAILDEV_URL]"; cv ext:list' _ "$lib" </dev/null)
[ "$out" = $'[]\ncompose:compose exec -T -u www-data -w /var/www/html app cv ext:list' ] || { echo "lib.sh override or port 0: $out" >&2; exit 1; }
# cv_scr hands the host file to the container on stdin.
printf '<?php echo 1;\n' > "$work/seed.php"
out=$(via_compose '. "$1"; cv_scr "$2"' _ "$lib" "$work/seed.php")
if ! grep -q '^compose:compose exec -T -u www-data -w /var/www/html app bash -c f=$(mktemp --suffix=.php) && cat > "$f" && cv scr "$f"; rc=$?' <<<"$out" \
  || ! grep -q '^<?php echo 1;$' <<<"$out"; then
  echo "lib.sh cv_scr: $out" >&2
  exit 1
fi

# hit hands curl its configuration on stdin, so a credential reaches no argv.
printf '%s\n' '#!/bin/sh' 'echo "argv:$*"' 'cat' > "$work/fakebin/curl"
chmod +x "$work/fakebin/curl"
out=$(in_image '. "$1"; TOKEN=secret; hit probe "http://x/a?b=1" "$(auth_header)" "header = \"X-Other: 1\""' _ "$lib")
expected='argv:-K -
url = "http://x/a?b=1"
output = "'"$work"'/gitlab/tests/e2e/out/probe.body"
write-out = "%{http_code}"
silent
globoff
header = "X-Civi-Auth: Bearer secret"
header = "X-Other: 1"'
[ "$out" = "$expected" ] || { echo "lib.sh hit: $out" >&2; exit 1; }
# cfg_value escapes a JSON payload for a double-quoted curl config value.
out=$(in_image '. "$1"; cfg_value "$2"' _ "$lib" '{"k":"a\b"}')
[ "$out" = '{\"k\":\"a\\b\"}' ] || { echo "lib.sh cfg_value: $out" >&2; exit 1; }
# body_lacks fails on a missing or empty body instead of passing silently.
printf 'all fine' > "$work/gitlab/tests/e2e/out/ok.body"
: > "$work/gitlab/tests/e2e/out/empty.body"
in_image '. "$1"; body_lacks ok "error" && ! body_lacks ok "fine" && ! body_lacks empty "x" && ! body_lacks missing "x"' _ "$lib" \
  || { echo "lib.sh body_lacks" >&2; exit 1; }
# A suite file keeps its evidence next to itself.
printf '%s\n' '. "$(dirname "$0")/lib.sh"; echo "$OUT_DIR"' > "$work/gitlab/tests/e2e/probe.sh"
out=$(PATH="$work/fakebin:$PATH" CK_IN_IMAGE=1 CK_APP_USER=www-data CK_APP_CWD="$work/site" bash "$work/gitlab/tests/e2e/probe.sh")
[ "$out" = "$work/gitlab/tests/e2e/out" ] || { echo "lib.sh OUT_DIR: $out" >&2; exit 1; }
/bin/rm "$work/gitlab/tests/e2e/probe.sh"

# Repository helpers in lib.local.sh load after the managed ones and may use them.
printf '%s\n' 'local_helper() { note "local $RUN_ID"; }' > "$work/gitlab/tests/e2e/lib.local.sh"
out=$(RUN_ID=7 in_image '. "$1"; local_helper' _ "$lib")
[ "$out" = '  · local 7' ] || { echo "lib.sh did not load lib.local.sh: $out" >&2; exit 1; }
/bin/rm "$work/gitlab/tests/e2e/lib.local.sh"
# A caller under set -e can source lib.sh while the stack is still down.
printf '%s\n' '#!/bin/sh' 'exit 1' > "$work/fakebin/docker"
out=$(via_compose 'set -euo pipefail; . "$1"; echo "sourced:$BASE_URL"' _ "$lib")
[ "$out" = 'sourced:' ] || { echo "lib.sh aborted a set -e caller: $out" >&2; exit 1; }
/bin/rm -r "$work/gitlab/tests/e2e/out"
# The root pass writes GitHub callers only, so an extension below a root refuses.
git init -q "$work/gitlab-mono"
cp -R "$work/gitlab" "$work/gitlab-mono/ext"
/bin/rm "$work/gitlab-mono/ext/.gitlab-ci.yml"
if out=$("$root/scaffold/ckinit.php" --update "$work/gitlab-mono/ext" 2>&1); then
  echo "ci: gitlab was accepted below a repository root" >&2
  exit 1
fi
grep -q 'ci: gitlab supports a repository holding one extension' <<<"$out"
cp "$work/gitlab/civikitchen.yaml" "$work/gitlab-mono/civikitchen.yaml"
if out=$("$root/scaffold/ckinit.php" --update "$work/gitlab-mono" 2>&1); then
  echo "ci: gitlab was accepted at a monorepo root" >&2
  exit 1
fi
grep -q 'ci: gitlab supports a repository holding one extension' <<<"$out"
test ! -e "$work/gitlab-mono/.github/workflows/ci.yml"

# The Strauss runner exists only where composer.json declares extra.strauss;
# there it is managed like any other file.
test ! -e "$work/clean/.civikitchen/strauss.php"
cp -R "$work/clean" "$work/strauss"
php -r '$f = $argv[1]; $j = json_decode(file_get_contents($f), true); $j["extra"]["strauss"] = ["packages" => ["acme/lib"]];
  file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");' "$work/strauss/composer.json"
out=$("$root/scaffold/ckinit.php" --check "$work/strauss" 2>&1 || true)
grep -q 'missing   .civikitchen/strauss.php' <<<"$out" || { echo "an absent Strauss runner was not reported: $out" >&2; exit 1; }
"$root/scaffold/ckinit.php" --update "$work/strauss" >/dev/null
cmp "$work/strauss/.civikitchen/strauss.php" "$root/scaffold/template/extension/.civikitchen/strauss.php"
"$root/scaffold/ckinit.php" --check "$work/strauss" >/dev/null
# Seeding replaces composer.json with the template's, so it writes no runner.
cp -R "$work/clean" "$work/strauss-seed"
cp "$work/strauss/composer.json" "$work/strauss-seed/composer.json"
"$root/scaffold/ckinit.php" --force "$work/strauss-seed" >/dev/null
test ! -e "$work/strauss-seed/.civikitchen/strauss.php"
printf '%s\n' '{' > "$work/strauss/composer.json"
if out=$("$root/scaffold/ckinit.php" --check "$work/strauss" 2>&1); then
  echo "an unparseable composer.json was accepted" >&2
  exit 1
fi
grep -q 'cannot parse .*composer.json' <<<"$out"

# Behind an egress proxy the download goes through HTTPS_PROXY: a one-shot
# listener records the runner's request line and hangs up.
mkdir "$work/proxy-tmp"
php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); file_put_contents($argv[1], stream_socket_get_name($s, false));
  $c = stream_socket_accept($s, 30); file_put_contents($argv[2], $c ? fgets($c) : "no request");' "$work/proxy-addr" "$work/proxy-request" &
proxy_pid=$!
for _ in $(seq 50); do [ -s "$work/proxy-addr" ] && break; sleep 0.1; done
if out=$(TMPDIR="$work/proxy-tmp" https_proxy='' HTTPS_PROXY="http://$(cat "$work/proxy-addr")/" \
  php "$root/scaffold/template/extension/.civikitchen/strauss.php" 2>&1); then
  echo "the Strauss runner succeeded through a proxy that hung up" >&2
  exit 1
fi
wait "$proxy_pid"
grep -q '^CONNECT github.com:443 ' "$work/proxy-request" \
  || { echo "the Strauss runner bypassed HTTPS_PROXY: $(cat "$work/proxy-request") $out" >&2; exit 1; }

"$root/scaffold/ckinit.php" --help >/dev/null
echo "ckinit integration checks passed"
