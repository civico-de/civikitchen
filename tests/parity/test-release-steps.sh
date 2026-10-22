#!/usr/bin/env bash
# The dist-job steps of extension-release.yml that decide what the smoke test
# installs, run as written against fixture repositories: working_directory
# normalization, the same-repository closure, the pins union and the staging.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow="$root/.github/workflows/extension-release.yml"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }

step() {
  php "$root/tests/parity/workflow-step.php" "$1" "$2" "$3" > "$work/step.sh"
}

# Runs the extracted step in $ws with the runner's files; $out holds stdout+stderr.
run_step() {
  : > "$work/output" && : > "$work/env"
  rc=0
  out=$(cd "$ws/${1:-.}" && GITHUB_WORKSPACE="$ws" GITHUB_OUTPUT="$work/output" GITHUB_ENV="$work/env" \
    RUNNER_TEMP="$work/runner" GITHUB_RUN_ID=1 GITHUB_RUN_ATTEMPT=1 \
    bash --noprofile --norc -eo pipefail "$work/step.sh" 2>&1) || rc=$?
}

output() { sed -n "s/^$1=//p" "$work/output"; }

# extension <dir> <key> [required key ...]
extension() {
  local dir="$ws/$1" key="$2" requires=''
  shift 2
  for required in "$@"; do requires+="<ext>$required</ext>"; done
  mkdir -p "$dir"
  printf '<extension key="%s" type="module"><file>%s</file><version>1.0.0</version><requires>%s</requires></extension>\n' \
    "$key" "${key##*.}" "$requires" > "$dir/info.xml"
}

# pin <dir> <key> <asset>: a staged-release pin in that extension's civikitchen.yaml.
pin() {
  printf '%s\n' 'version: 1' 'policy:' '  extension_sources:' \
    "    - key: $2" "      version: '^1'" '      release:' \
    '        repository: example-org/dep' '        tag: v1.0.0' "        asset: $3" \
    "      sha256: $(printf 'a%.0s' {1..64})" "      reason: private, no registry serves it ($1)" \
    > "$ws/$1/civikitchen.yaml"
}

new_workspace() {
  ws="$work/ws-$1"
  mkdir -p "$ws" "$work/runner"
  rm -f "$work/runner"/*.txt
  ln -sfn "$root" "$ws/.civikitchen-ci"
}

# --- working_directory normalization -------------------------------------
step "$workflow" dist 'Validate the inputs and read the extension key'
new_workspace single
extension . org.example.single
for input in . ./; do
  CK_STAGE=release CK_DRY_RUN=true CK_WORKING_DIRECTORY=$input run_step
  [ "$rc" -eq 0 ] || fail "working_directory '$input' was refused: $out"
  [ "$(output wd)" = . ] || fail "working_directory '$input' normalized to '$(output wd)', expected '.'"
  [ "$(output key)" = org.example.single ] || fail "working_directory '$input' read key '$(output key)'"
done
new_workspace mono
extension base org.example.base
for input in base ./base/ base/; do
  CK_STAGE=build CK_DRY_RUN=true CK_WORKING_DIRECTORY=$input run_step
  [ "$rc" -eq 0 ] || fail "working_directory '$input' was refused: $out"
  [ "$(output wd)" = base ] || fail "working_directory '$input' normalized to '$(output wd)', expected 'base'"
done
for input in '' /base ../base base/../base base//x; do
  CK_STAGE=build CK_DRY_RUN=true CK_WORKING_DIRECTORY=$input run_step
  [ "$rc" -ne 0 ] || fail "working_directory '$input' was accepted"
done

# extension-ci.yml validates the same input in its key job.
step "$root/.github/workflows/extension-ci.yml" key 'Validate working_directory and read the extension key from info.xml'
new_workspace ci
extension . org.example.single
CK_EXT_KEY_INPUT='' CK_WORKING_DIRECTORY=./ run_step
[ "$rc" -eq 0 ] || fail "extension-ci refused working_directory './': $out"
[ "$(output key)" = single ] || fail "extension-ci read key '$(output key)' for './'"

# --- the same-repository closure ------------------------------------------
step "$workflow" dist 'Find the same-repository extensions this one requires'
new_workspace closure
extension base org.example.base org.civicrm.registryonly
extension mid org.example.mid org.example.base
extension addon org.example.addon org.example.mid org.example.base
extension other org.example.other
CK_EXT_KEY=org.example.addon CK_WD=addon run_step
[ "$rc" -eq 0 ] || fail "closure of addon failed: $out"
expected=$'org.example.mid mid org.example.mid-1.0.0.zip\norg.example.base base org.example.base-1.0.0.zip'
[ "$(cat "$work/runner/release-siblings.txt")" = "$expected" ] \
  || fail "closure of addon: $(cat "$work/runner/release-siblings.txt")"
[ "$(output any)" = true ] || fail "closure of addon did not report any=true"

CK_EXT_KEY=org.example.base CK_WD=base run_step
[ "$rc" -eq 0 ] && [ ! -s "$work/runner/release-siblings.txt" ] && [ "$(output any)" = false ] \
  || fail "base has no same-repository requirement: $out"

# A sibling whose info.xml cannot be read aborts; it never silently drops out.
printf '<extension key=' > "$ws/other/info.xml"
CK_EXT_KEY=org.example.addon CK_WD=addon run_step
[ "$rc" -ne 0 ] || fail "a malformed sibling info.xml was ignored"
case "$out" in *'other/info.xml carries no key'*) ;; *) fail "malformed sibling: $out" ;; esac

# Nested below the root, the siblings are the directories next to working_directory.
new_workspace nested
extension a/b/base org.example.base
extension a/b/addon org.example.addon org.example.base
extension a/other org.example.other
CK_EXT_KEY=org.example.addon CK_WD=a/b/addon run_step
[ "$rc" -eq 0 ] || fail "closure of a nested addon failed: $out"
[ "$(cat "$work/runner/release-siblings.txt")" = 'org.example.base a/b/base org.example.base-1.0.0.zip' ] \
  || fail "closure of a nested addon: $(cat "$work/runner/release-siblings.txt")"
# Its pins are read from that directory too.
pin a/b/base org.example.dep dep-1.0.0.zip
step "$workflow" dist 'Read the staged-release dependency pins'
run_step a/b/addon
grep -q '^org.example.dep ' "$work/runner/release-pins.txt" || fail "a nested sibling's pin was not staged: $out"
step "$workflow" dist 'Find the same-repository extensions this one requires'
ws="$work/ws-closure"
# A failing parser aborts the step instead of reading as "no requirements".
printf '<extension key="org.example.other"/>\n' > "$ws/other/info.xml"
mkdir -p "$work/failphp"
printf '#!/usr/bin/env bash\ncase " $* " in *" extension-requires "*) exit 70 ;; esac\nexec %q "$@"\n' "$(command -v php)" \
  > "$work/failphp/php"
chmod +x "$work/failphp/php"
PATH="$work/failphp:$PATH" CK_EXT_KEY=org.example.addon CK_WD=addon run_step
[ "$rc" -ne 0 ] || fail "a failing extension-requires was read as no requirements"

# --- the pins union --------------------------------------------------------
step "$workflow" dist 'Read the staged-release dependency pins'
new_workspace pins
extension base org.example.base org.example.dep
extension addon org.example.addon org.example.base org.example.dep
pin base org.example.dep dep-1.0.0.zip
pin addon org.example.dep dep-1.0.0.zip
printf '%s\n' 'org.example.base base org.example.base-1.0.0.zip' > "$work/runner/release-siblings.txt"
run_step addon
[ "$rc" -eq 0 ] || fail "pins step failed: $out"
[ "$(wc -l < "$work/runner/release-pins.txt")" -eq 1 ] || fail "identical pins were not merged: $(cat "$work/runner/release-pins.txt")"
pin base org.example.dep2 dep2-1.0.0.zip
run_step addon
grep -q '^org.example.dep2 ' "$work/runner/release-pins.txt" || fail "a sibling's own pin was not staged"
[ "$(output any)" = true ] || fail "pins step did not report any=true"
# The same key pinned to two releases fails here, naming the key.
pin base org.example.dep dep-1.1.0.zip
run_step addon
[ "$rc" -ne 0 ] || fail "one key pinned to two releases was accepted"
case "$out" in *'pinned to different releases by extensions of this repository: org.example.dep '*) ;; *) fail "pin conflict: $out" ;; esac

# --- staging by archive name ----------------------------------------------
step "$workflow" dist 'Stage the required same-repository archives'
new_workspace stage
run_dir="$work/run" && siblings="$work/siblings"
mkdir -p "$run_dir"
# Merged downloads: both build jobs' archives side by side, whatever else ran.
for name in org.example.base-1.0.0.zip org.example.addon-1.0.0.zip; do
  printf 'zip %s' "$name" > "$run_dir/$name"
  (cd "$run_dir" && sha256sum "$name" > "$name.sha256")
done
printf '%s\n' 'org.example.base base org.example.base-1.0.0.zip' > "$work/runner/release-siblings.txt"
CK_RUN_ARCHIVES=$run_dir CK_SIBLINGS=$siblings run_step
[ "$rc" -eq 0 ] || fail "staging failed: $out"
[ "$(ls "$siblings")" = org.example.base-1.0.0.zip ] || fail "staged $(ls "$siblings")"

printf 'tampered' > "$run_dir/org.example.base-1.0.0.zip"
CK_RUN_ARCHIVES=$run_dir CK_SIBLINGS=$siblings run_step
[ "$rc" -ne 0 ] || fail "a tampered sibling archive was staged"

printf '%s\n' 'org.example.mid mid org.example.mid-1.0.0.zip' > "$work/runner/release-siblings.txt"
CK_RUN_ARCHIVES=$run_dir CK_SIBLINGS=$siblings run_step
[ "$rc" -ne 0 ] || fail "a missing sibling archive was not reported"
case "$out" in *"no org.example.mid-1.0.0.zip in this run"*) ;; *) fail "missing archive: $out" ;; esac

# --- the smoke step resolves <requires> with this contract's ck -----------
step "$workflow" dist 'Install smoke test'
new_workspace smoke
# A checkout of its own, so the ck the step reaches is told apart from the image's.
rm "$ws/.civikitchen-ci"
mkdir -p "$ws/.civikitchen-ci/toolbelt/bin" "$work/smoke-bin" "$work/docker"
printf '#!/usr/bin/env bash\necho "$*" >> "$CK_LOG"\n' > "$ws/.civikitchen-ci/toolbelt/bin/ck"
printf '#!/usr/bin/env bash\necho "image ck $*" >> "$CK_LOG"\nexit 1\n' > "$work/smoke-bin/ck"
printf '#!/usr/bin/env bash\n' > "$work/smoke-bin/cv"
# docker keeps the compose file and the argv of each exec; the status query answers "installed".
cat > "$work/smoke-bin/docker" <<'SH'
#!/usr/bin/env bash
case "$4" in
  up) cp "$3" "$DOCKER_LOG/compose.yml" ;;
  exec)
    calls=$(($(cat "$DOCKER_LOG/calls" 2>/dev/null || echo 0) + 1))
    echo "$calls" > "$DOCKER_LOG/calls"
    printf '%s\0' "$@" > "$DOCKER_LOG/exec-$calls"
    [ "$calls" != 3 ] || echo installed
    ;;
esac
SH
chmod +x "$ws/.civikitchen-ci/toolbelt/bin/ck" "$work/smoke-bin/ck" "$work/smoke-bin/cv" "$work/smoke-bin/docker"
: > "$work/runner/release-siblings.txt"
PATH="$work/smoke-bin:$PATH" DOCKER_LOG="$work/docker" CK_EXT_KEY=org.example.smoke CK_DIST="$work/dist-smoke" \
  CK_POLICY_DIR="$work/policy" CK_DEPS="$work/deps" CK_SIBLINGS="$work/siblings-smoke" CK_CIVIKITCHEN="$ws/.civikitchen-ci" run_step
[ "$rc" -eq 0 ] || fail "smoke step failed: $out"
# The checkout it mounts is this workflow's own, at the SHA it was resolved from.
php -r '
  require $argv[1] . "/tests/parity/workflow-jobs.php";
  $job = ck_jobs_parsed($argv[2])["dist"];
  $smoke = array_values(array_filter($job["steps"], fn ($s) => ($s["name"] ?? "") === "Install smoke test"))[0];
  $checkout = array_values(array_filter($job["steps"], fn ($s) => ($s["with"]["path"] ?? "") === ".civikitchen-ci"))[0];
  $compose = Symfony\Component\Yaml\Yaml::parseFile($argv[3]);
  $exec = explode("\0", rtrim((string) file_get_contents($argv[4]), "\0"));
  $problems = array_keys(array_filter([
      "env CK_CIVIKITCHEN is not the .civikitchen-ci checkout" => ($smoke["env"]["CK_CIVIKITCHEN"] ?? "") !== "\${{ github.workspace }}/.civikitchen-ci",
      ".civikitchen-ci is not checked out at job.workflow_sha" => ($checkout["with"]["ref"] ?? "") !== "\${{ job.workflow_sha }}",
      "the app does not mount CK_CIVIKITCHEN read-only at /ck-civikitchen" => !in_array("\${CK_CIVIKITCHEN}:/ck-civikitchen:ro", $compose["services"]["app"]["volumes"], true),
      "the provisioning exec does not name the mounted ck" => !in_array("CK_CLI=/ck-civikitchen/toolbelt/bin/ck", $exec, true),
  ]));
  foreach ($problems as $problem) { fwrite(STDERR, "FAIL: smoke step: $problem\n"); }
  exit($problems === [] ? 0 : 1);
' "$root" "$workflow" "$work/docker/compose.yml" "$work/docker/exec-2"
# The provisioning script calls that ck, never the image's.
script=$(php -r '$a = explode("\0", rtrim((string) file_get_contents($argv[1]), "\0")); echo end($a);' "$work/docker/exec-2")
: > "$work/ck.log"
PATH="$work/smoke-bin:$PATH" CK_LOG="$work/ck.log" CK_CLI="$ws/.civikitchen-ci/toolbelt/bin/ck" \
  CK_EXT_KEY=org.example.smoke CK_SIBLING_KEYS='' CK_EXT_DIR=/ext bash -c "$script" \
  || fail "the provisioning script failed: $(cat "$work/ck.log")"
[ "$(cat "$work/ck.log")" = 'internal provision-requires --ext-dir /ext --archive-dir /ck-deps org.example.smoke' ] \
  || fail "the provisioning script did not run the mounted ck: $(cat "$work/ck.log")"

# --- publish: one version across the archives, flags even in a dry run -----
step "$workflow" publish 'Publish the GitHub release'
new_workspace publish
dist="$work/dist" && mkdir -p "$dist" "$work/bin"
# archive <key> <version>
archive() {
  mkdir -p "$work/src/$1"
  printf '<extension key="%s"><version>%s</version></extension>\n' "$1" "$2" > "$work/src/$1/info.xml"
  (cd "$work/src" && zip -q "$dist/$1-$2.zip" "$1/info.xml")
}
# The gh stub logs every call; `api` lists $GH_TAGS, `release view` finds a
# release only when GH_RELEASE_EXISTS=1.
cat > "$work/bin/gh" <<'SH'
#!/usr/bin/env bash
echo "$*" >> "$GH_LOG"
case "$1 ${2:-}" in
  'api '*) printf '%s\n' $GH_TAGS ;;
  'release view') [ "${GH_RELEASE_EXISTS:-0}" = 1 ] ;;
esac
SH
chmod +x "$work/bin/gh"
# publish <ref> <dry run> [draft]: runs the step, $calls holds the gh calls.
publish() {
  : > "$work/gh.log"
  PATH="$work/bin:$PATH" GH_LOG="$work/gh.log" GITHUB_REPOSITORY=example-org/mono \
    GITHUB_REF="$1" GITHUB_REF_NAME="${1##*/}" CK_DRY_RUN="$2" CK_DRAFT="${3:-false}" CK_DIST=$dist run_step
  calls=$(cat "$work/gh.log")
}
export GH_TAGS='refs/tags/v0.9.0'
archive org.example.base 1.0.0
archive org.example.addon 1.0.0
publish refs/heads/main true
[ "$rc" -eq 0 ] || fail "dry-run publish failed: $out"
case "$out" in *'latest=true'*'dry run: release v1.0.0 would carry'*) ;; *) fail "dry-run publish: $out" ;; esac
case "$calls" in *release*) fail "a dry run called gh release: $calls" ;; esac

publish refs/tags/v1.0.1 false
[ "$rc" -ne 0 ] || fail "archives of 1.0.0 were published under v1.0.1"
case "$out" in *'the archives carry 1.0.0, but the tag is v1.0.1'*) ;; *) fail "tag mismatch: $out" ;; esac

# A real run: create with the computed flags, or replace the assets of an existing release.
publish refs/tags/v1.0.0 false
[ "$rc" -eq 0 ] || fail "publish failed: $out"
grep -qx "release create v1.0.0 --verify-tag --title v1.0.0 --generate-notes --latest=true $dist/org.example.addon-1.0.0.zip $dist/org.example.base-1.0.0.zip" \
  "$work/gh.log" || fail "unexpected release create: $calls"
GH_TAGS='refs/tags/v0.9.0 refs/tags/v2.0.0' publish refs/tags/v1.0.0 false
grep -q '^release create v1.0.0 .*--latest=false ' "$work/gh.log" || fail "an older version took Latest: $calls"
publish refs/tags/v1.0.0 false true
grep -q '^release create v1.0.0 .*--generate-notes --draft ' "$work/gh.log" || fail "a draft was not created as one: $calls"
case "$calls" in *--latest*) fail "a draft was given Latest: $calls" ;; esac
GH_RELEASE_EXISTS=1 publish refs/tags/v1.0.0 false
grep -qx "release upload v1.0.0 $dist/org.example.addon-1.0.0.zip $dist/org.example.base-1.0.0.zip --clobber" "$work/gh.log" \
  || fail "an existing release did not get its assets replaced: $calls"
case "$calls" in *'release create'*) fail "an existing release was created again: $calls" ;; esac

rm "$dist"/*.zip
archive org.example.base 1.1.0-beta.1
publish refs/tags/v1.1.0-beta.1 false
grep -q '^release create v1.1.0-beta.1 .*--latest=false --prerelease ' "$work/gh.log" || fail "a pre-release was not flagged: $calls"

archive org.example.addon 1.1.0
publish refs/heads/main true
[ "$rc" -ne 0 ] || fail "archives of two versions passed as one release"
case "$out" in *'different versions (1.1.0, 1.1.0-beta.1)'*) ;; *) fail "version mismatch: $out" ;; esac

rm "$dist"/*.zip
printf 'x' > "$work/src/readme.txt"
(cd "$work/src" && zip -q "$dist/stray.zip" readme.txt)
publish refs/heads/main true
case "$out" in *'stray.zip has no <key>/info.xml at its top level'*) ;; *) fail "archive without info.xml: $out" ;; esac

echo "release step tests passed"
