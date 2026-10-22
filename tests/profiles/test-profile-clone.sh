#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/ext" "$work/profile" "$work/repo"
# apply.sh finds ck on PATH, where the image links it.
ln -s "$root/toolbelt/bin/ck" "$work/bin/ck"

printf '%s\n' '<?php' > "$work/repo/demo.php"
printf '%s\n' '<extension key="org.example.demo" type="module"><file>demo</file></extension>' \
  > "$work/repo/info.xml"
git -C "$work/repo" init -q
git -C "$work/repo" add demo.php info.xml
git -C "$work/repo" -c user.name=CiviKitchen -c user.email=ci@example.org commit -qm initial
commit=$(git -C "$work/repo" rev-parse HEAD)

cat > "$work/bin/cv" <<'SH'
#!/bin/bash
set -euo pipefail
requires='foreach (simplexml_load_string($x)->requires->ext ?? [] as $e) { echo $e, "\n"; }'
case " $* " in
  *extensionsDir*) printf '%s' "$CK_FAKE_EXT_DIR" ;;
  # Extension.get learns a directory created in the ext dir only on a refresh.
  *'->refresh()'*) for dir in "$CK_FAKE_EXT_DIR"/*/; do basename "$dir"; done > "$CK_FAKE_FEED/known" ;;
  *getFullContainer*) printf '%s' "${CK_FAKE_LOCAL_KEYS:-}" ;;
  *CIVICRM_UF*) printf 'Standalone' ;;
  *loadFromString*) php -r '$x = stream_get_contents(STDIN); '"$requires" ;;
  # The feed serves $CK_FAKE_FEED/<key>.zip; Extension.get knows the local keys.
  *' ext:list -R '*)
    php -r 'echo json_encode(array_map(fn($z) => ["key" => basename($z, ".zip"), "downloadUrl" => "https://feed.example.org/" . basename($z)], glob($argv[1] . "/*.zip")));' "$CK_FAKE_FEED"
    ;;
  *' api4 Extension.get '*)
    key="${4#key=}"
    if grep -qx "$key" <<<"${CK_FAKE_LOCAL_KEYS:-}" || grep -qx "$key" "$CK_FAKE_FEED/known"; then
      printf '[{"key":"%s","status":"uninstalled"}]' "$key"
    else
      printf '[]'
    fi
    ;;
  *' ext:list '*'--statuses=installed,disabled'*)
    [ "${CK_FAKE_STATUS_QUERY_FAIL:-0}" != 1 ] || exit 1
    printf '%s\n' "${CK_FAKE_DB_INSTALLED:-}"
    ;;
  *' ext:list '*'--statuses=uninstalled'*)
    [ "${CK_FAKE_STATUS_QUERY_FAIL:-0}" != 1 ] || exit 1
    printf '%s\n' "${CK_FAKE_UNINSTALLED:-${CK_FAKE_LOCAL_KEYS:-}}"
    ;;
  *' ext:enable '*)
    [ "${CK_FAKE_FAIL_ENABLE:-0}" != 1 ]
    printf '%s\n' "$*" >> "${CK_FAKE_CV_LOG:-/dev/null}"
    shift
    printf '%s\n' "$@" >> "$CK_FAKE_FEED/installed"
    ;;
  # Like core's download queue: refused while a <requires> is not installed.
  *' ext:download '*)
    printf '%s\n' "$*" >> "${CK_FAKE_CV_LOG:?}"
    for required in $(unzip -p "$CK_FAKE_FEED/$4.zip" '*/info.xml' | php -r '$x = stream_get_contents(STDIN); '"$requires"); do
      grep -qx "$required" "$CK_FAKE_FEED/installed" || { echo "Missing Requirement: $required" >&2; exit 1; }
    done
    ;;
  *' vset '*|*' vdel '*) printf '%s\n' "$*" >> "${CK_FAKE_CV_LOG:-/dev/null}" ;;
  *' scr --user=admin '*apply-content.php*)
    printf '%s\n' content >> "${CK_FAKE_CONTENT_LOG:-/dev/null}"
    [ "${CK_FAKE_FAIL_CONTENT:-0}" != 1 ]
    ;;
  *'--user=admin'*) printf 'ok' ;;
  *) : ;;
esac
SH
chmod +x "$work/bin/cv"
# A registry extension's <requires> are read from its feed archive.
cat > "$work/bin/curl" <<'SH'
#!/bin/bash
while [ "$#" -gt 0 ]; do
  case "$1" in
    -o) output="$2"; shift ;;
    https://feed.example.org/*) url="$1" ;;
  esac
  shift
done
cp "$CK_FAKE_FEED/${url##*/}" "$output"
SH
chmod +x "$work/bin/curl"
export CK_FAKE_FEED="$work/feed"
mkdir -p "$CK_FAKE_FEED"
: > "$CK_FAKE_FEED/installed"
: > "$CK_FAKE_FEED/known"
feed_archive() {
  local key="$1" requires='' required
  shift
  for required in "$@"; do requires+="<ext>$required</ext>"; done
  mkdir -p "$work/feed-src/$key"
  printf '%s\n' "<extension key=\"$key\" type=\"module\"><file>${key##*.}</file><requires>$requires</requires></extension>" \
    > "$work/feed-src/$key/info.xml"
  rm -f "$CK_FAKE_FEED/$key.zip"
  (cd "$work/feed-src" && zip -qr "$CK_FAKE_FEED/$key.zip" "$key")
}

write_profile() {
  php -r '
    file_put_contents($argv[1], json_encode([
      "description" => "clone retry fixture",
      "dependencies" => [["name" => "org.example.demo", "repo" => $argv[2], "version" => $argv[3]]],
    ], JSON_THROW_ON_ERROR));
  ' "$work/profile/profile.json" "$work/repo" "$1"
}

write_profile does-not-exist
if PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null 2>"$work/err"; then
  echo "profile clone accepted a missing ref" >&2
  exit 1
fi
grep -qxF "  ERROR: could not clone org.example.demo at requested ref does-not-exist" "$work/err" \
  || { echo "failed checkout did not report its ERROR line: $(cat "$work/err")" >&2; exit 1; }
[ ! -e "$work/ext/org.example.demo" ] \
  || { echo "failed checkout left a discovery-visible extension directory" >&2; exit 1; }

write_profile "$commit"
PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null
[ "$(git -C "$work/ext/org.example.demo" rev-parse HEAD)" = "$commit" ] \
  || { echo "profile clone did not install the requested commit" >&2; exit 1; }

# A retry at the same immutable ref is a verified no-op, not another clone.
PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null

# A packaged extension is recognizable to CiviCRM but has no Git metadata.
# Profiles still converge it to their immutable Git commit, while an arbitrary
# leftover directory must not receive this privilege.
printf '%s\n' '<extension key="org.example.demo" type="module"><file>demo</file></extension>' \
  > "$work/ext/org.example.demo/info.xml"
rm -rf "$work/ext/org.example.demo/.git"
printf '%s\n' packaged > "$work/ext/org.example.demo/demo.php"
PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_LOCAL_KEYS=org.example.demo \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null
[ "$(git -C "$work/ext/org.example.demo" rev-parse HEAD)" = "$commit" ] \
  || { echo "profile clone did not replace packaged code with the pinned commit" >&2; exit 1; }
[ "$(cat "$work/ext/org.example.demo/demo.php")" = '<?php' ] \
  || { echo "profile clone retained packaged code instead of the pinned commit" >&2; exit 1; }

# A failure after the filesystem swap restores disabled packaged code. Enabled
# packaged extensions are refused by apply.sh because DB upgrades are not
# safely reversible by swapping PHP files.
rm -rf "$work/ext/org.example.demo/.git"
printf '%s\n' packaged > "$work/ext/org.example.demo/demo.php"
php -r '
  $v=json_decode(file_get_contents($argv[1]), TRUE, 512, JSON_THROW_ON_ERROR);
  $v["dependencies"][0]["enable"]=true;
  file_put_contents($argv[1], json_encode($v, JSON_THROW_ON_ERROR));
' "$work/profile/profile.json"
if PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_LOCAL_KEYS=org.example.demo \
  CK_FAKE_FAIL_ENABLE=1 CK_FAKE_CV_LOG="$work/cv.log" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null 2>&1; then
  echo "profile clone accepted a downstream enable failure" >&2
  exit 1
fi
[ "$(cat "$work/ext/org.example.demo/demo.php")" = packaged ] && [ ! -d "$work/ext/org.example.demo/.git" ] \
  || { echo "downstream failure did not restore packaged extension" >&2; exit 1; }
if PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_LOCAL_KEYS=org.example.demo \
  CK_FAKE_DB_INSTALLED=org.example.demo CK_FAKE_CV_LOG="$work/cv.log" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null 2>&1; then
  echo "profile clone replaced an installed packaged extension without a reversible DB lifecycle" >&2
  exit 1
fi
[ "$(cat "$work/ext/org.example.demo/demo.php")" = packaged ] && [ ! -d "$work/ext/org.example.demo/.git" ] \
  || { echo "installed packaged extension changed despite fail-closed refusal" >&2; exit 1; }
if PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_LOCAL_KEYS=org.example.demo \
  CK_FAKE_STATUS_QUERY_FAIL=1 CK_FAKE_CV_LOG="$work/cv.log" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null 2>&1; then
  echo "profile clone replaced packaged code after a failed lifecycle status query" >&2
  exit 1
fi
[ "$(cat "$work/ext/org.example.demo/demo.php")" = packaged ] && [ ! -d "$work/ext/org.example.demo/.git" ] \
  || { echo "packaged extension changed after failed lifecycle status query" >&2; exit 1; }

# The deps phase swaps in the pin without touching content; a failing content
# phase in a later process still restores the packaged code.
: > "$work/content.log"
PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_LOCAL_KEYS=org.example.demo \
  CK_FAKE_CONTENT_LOG="$work/content.log" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" deps >/dev/null
[ "$(git -C "$work/ext/org.example.demo" rev-parse HEAD)" = "$commit" ] \
  || { echo "deps phase did not install the pinned commit" >&2; exit 1; }
! grep -qx content "$work/content.log" || { echo "deps phase applied profile content" >&2; exit 1; }
if PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_LOCAL_KEYS=org.example.demo \
  CK_FAKE_CONTENT_LOG="$work/content.log" CK_FAKE_FAIL_CONTENT=1 \
  bash "$root/docker/profiles/apply.sh" "$work/profile" content >/dev/null 2>&1; then
  echo "content phase accepted a failed content step" >&2
  exit 1
fi
[ "$(cat "$work/ext/org.example.demo/demo.php")" = packaged ] && [ ! -d "$work/ext/org.example.demo/.git" ] \
  || { echo "content failure did not restore the packaged extension swapped in by deps" >&2; exit 1; }
[ -z "$(find "$work/ext" -maxdepth 1 -name '.*')" ] \
  || { echo "content failure left rollback state behind" >&2; exit 1; }
PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_LOCAL_KEYS=org.example.demo \
  bash "$root/docker/profiles/apply.sh" "$work/profile" deps >/dev/null
: > "$work/content.log"
PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_LOCAL_KEYS=org.example.demo \
  CK_FAKE_CONTENT_LOG="$work/content.log" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" content > "$work/out"
grep -qx content "$work/content.log" || { echo "content phase did not apply profile content" >&2; exit 1; }
! grep -q cloning "$work/out" || { echo "content phase fetched dependencies" >&2; exit 1; }
[ -z "$(find "$work/ext" -maxdepth 1 -name '.*')" ] && [ -d "$work/ext/org.example.demo/.git" ] \
  || { echo "successful content phase kept the packaged backup" >&2; exit 1; }
if PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" bogus >/dev/null 2>&1; then
  echo "apply.sh accepted an unknown phase" >&2
  exit 1
fi

# Registry acquisition is download-only. `enable` is handled exclusively by
# the ordered enable phase, so absent/false keeps the extension disabled.
printf '%s\n' '{"description":"registry fetch only","dependencies":[{"name":"org.example.registry","registry":true}]}' \
  > "$work/profile/profile.json"
feed_archive org.example.registry
: > "$work/cv.log"
PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_CV_LOG="$work/cv.log" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null
[ "$(cat "$work/cv.log")" = "vset http_timeout=120
ext:download -n --no-install org.example.registry
vdel http_timeout" ] \
  || { echo "registry dependency was not fetched with --no-install under a raised http_timeout" >&2; exit 1; }

# A core-bundled requirement is present but not installed, so CiviCRM refuses
# the download until it is enabled.
feed_archive org.example.registry org.example.bundled
: > "$work/cv.log"
PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_CV_LOG="$work/cv.log" CK_FAKE_LOCAL_KEYS=org.example.bundled \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null
[ "$(cat "$work/cv.log")" = "ext:enable org.example.bundled
vset http_timeout=120
ext:download -n --no-install org.example.registry
vdel http_timeout" ] \
  || { echo "registry dependency was fetched before its bundled requirement was enabled" >&2; exit 1; }

write_profile "$commit"
php -r '
  $v=json_decode(file_get_contents($argv[1]), TRUE, 512, JSON_THROW_ON_ERROR);
  $v["dependencies"][0]["name"]="org.example.wrong";
  file_put_contents($argv[1], json_encode($v, JSON_THROW_ON_ERROR));
' "$work/profile/profile.json"
if PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null 2>"$work/err"; then
  echo "profile clone accepted a repository with the wrong extension key" >&2
  exit 1
fi
grep -qxF "  ERROR: cloned repository does not declare extension key org.example.wrong" "$work/err" \
  || { echo "wrong-key clone did not report its ERROR line: $(cat "$work/err")" >&2; exit 1; }
[ ! -e "$work/ext/org.example.wrong" ] \
  || { echo "wrong-key clone left a discovery-visible extension directory" >&2; exit 1; }

expect_cv_log() {
  [ "$(cat "$work/cv.log")" = "$1
vset http_timeout=120
ext:download -n --no-install org.example.registry
vdel http_timeout" ] || { echo "$2: $(cat "$work/cv.log")" >&2; exit 1; }
}

# A failed deps phase restores only its own swaps: an earlier deps phase's swap,
# which that phase also enabled, stays pinned and recorded for the content phase.
write_profile "$commit"
rm -rf "$work/ext/org.example.demo/.git"
printf '%s\n' packaged > "$work/ext/org.example.demo/demo.php"
php -r '
  $v=json_decode(file_get_contents($argv[1]), TRUE, 512, JSON_THROW_ON_ERROR);
  $v["dependencies"][0]["enable"]=true;
  file_put_contents($argv[1], json_encode($v, JSON_THROW_ON_ERROR));
' "$work/profile/profile.json"
state="$work/ext/.ck-profile-profile.replaced"
PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_LOCAL_KEYS=org.example.demo \
  bash "$root/docker/profiles/apply.sh" "$work/profile" deps >/dev/null
[ "$(wc -l < "$state")" -eq 1 ] && [ -d "$work/ext/org.example.demo/.git" ] \
  || { echo "deps phase did not swap in and record the pin" >&2; exit 1; }
cp "$state" "$work/state.before"
if PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_LOCAL_KEYS=org.example.demo \
  CK_FAKE_DB_INSTALLED=org.example.demo CK_FAKE_FAIL_ENABLE=1 \
  bash "$root/docker/profiles/apply.sh" "$work/profile" deps >/dev/null 2>&1; then
  echo "deps phase accepted a failed enable" >&2
  exit 1
fi
[ "$(git -C "$work/ext/org.example.demo" rev-parse HEAD)" = "$commit" ] \
  || { echo "failed deps phase restored the packaged copy an earlier deps phase swapped out and enabled" >&2; exit 1; }
cmp -s "$state" "$work/state.before" \
  || { echo "failed deps phase did not keep the earlier deps phase's rollback entry" >&2; exit 1; }

# provision-profile killed between its two moves leaves the target missing and
# the backup recorded; the failure restores it, and the earlier entry stays.
mkdir -p "$work/killed-bin" "$work/ext/org.example.other"
printf '%s\n' packaged > "$work/ext/org.example.other/other.php"
cat > "$work/killed-bin/ck" <<'SH'
#!/bin/bash
if [ "${2:-}" = provision-profile ]; then
  while [ "$#" -gt 0 ]; do
    [ "$1" != --replaced-state ] || state="$2"
    shift
  done
  printf '%s\t%s\n' "$CK_FAKE_EXT_DIR/org.example.other" "$CK_FAKE_EXT_DIR/.org.example.other.packaged.killed" >> "$state"
  mv "$CK_FAKE_EXT_DIR/org.example.other" "$CK_FAKE_EXT_DIR/.org.example.other.packaged.killed"
  kill -9 $$
fi
exec "$CK_REAL" "$@"
SH
chmod +x "$work/killed-bin/ck"
if PATH="$work/killed-bin:$work/bin:$PATH" CK_REAL="$root/toolbelt/bin/ck" CK_FAKE_EXT_DIR="$work/ext" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" deps >/dev/null 2>&1; then
  echo "deps phase accepted a killed provision-profile" >&2
  exit 1
fi
[ "$(cat "$work/ext/org.example.other/other.php")" = packaged ] && [ ! -e "$work/ext/.org.example.other.packaged.killed" ] \
  || { echo "killed provision-profile did not get its moved-aside extension restored" >&2; exit 1; }
cmp -s "$state" "$work/state.before" && [ -d "$work/ext/org.example.demo/.git" ] \
  || { echo "killed provision-profile rolled back an earlier deps phase" >&2; exit 1; }
rm -rf "$work/ext/org.example.other" "$work/ext"/.org.example.demo.packaged.* "$state"

# A registry dependency requiring a repo dependency cloned in the same run
# finds the checkout, although the extension map cached before the clone lacks it.
rm -rf "$work/ext/org.example.demo"
: > "$CK_FAKE_FEED/known"
php -r '
  file_put_contents($argv[1], json_encode(["description" => "registry on a checkout", "dependencies" => [
    ["name" => "org.example.demo", "repo" => $argv[2], "version" => $argv[3]],
    ["name" => "org.example.registry", "registry" => true],
  ]], JSON_THROW_ON_ERROR));
' "$work/profile/profile.json" "$work/repo" "$commit"
feed_archive org.example.registry org.example.demo
: > "$work/cv.log"
PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_CV_LOG="$work/cv.log" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null
expect_cv_log "ext:enable org.example.demo" "the cloned requirement was not taken as present"

# A registry dependency's requirement takes a mounted extension's release pin,
# installed from the archive staged in CK_DEP_ARCHIVE_DIR.
mkdir -p "$work/mounted" "$work/staged" "$work/pinned-src/org.example.pinned"
printf '%s\n' '<extension key="org.example.pinned" type="module"><file>pinned</file><version>1.0.0</version></extension>' \
  > "$work/pinned-src/org.example.pinned/info.xml"
(cd "$work/pinned-src" && zip -qr "$work/staged/pinned-1.0.0.zip" org.example.pinned)
digest=$(php -r 'echo hash_file("sha256", $argv[1]);' "$work/staged/pinned-1.0.0.zip")
printf '%s\n' 'version: 1' 'policy:' '  extension_sources:' '    - key: org.example.pinned' "      version: '^1.0'" \
  '      release:' '        repository: example-org/pinned' '        tag: v1.0.0' '        asset: pinned-1.0.0.zip' \
  "      sha256: $digest" '      reason: no registry serves it' > "$work/mounted/civikitchen.yaml"
printf '%s\n' '{"description":"pinned requirement","dependencies":[{"name":"org.example.registry","registry":true}]}' \
  > "$work/profile/profile.json"
feed_archive org.example.registry org.example.pinned
: > "$work/cv.log"
PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_CV_LOG="$work/cv.log" \
  CK_SEED_EXTENSION_DIRS="$work/mounted" CK_DEP_ARCHIVE_DIR="$work/staged" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null
# Enabled once installed, and again with the other requirements before the download.
expect_cv_log "ext:enable org.example.pinned
ext:enable org.example.pinned" "the requirement did not come from its staged release"
[ -f "$work/ext/org.example.pinned/info.xml" ] || { echo "the staged requirement was not installed" >&2; exit 1; }

echo "profile clone tests passed"
