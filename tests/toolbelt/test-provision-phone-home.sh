#!/usr/bin/env bash
# First-boot provisioning turns off core's calls to civicrm.org: the
# version_check job goes inactive and a settings file sets ext_repo_url to
# boolean false (a string "false" or "" leaves the feed on). Fake cv records args.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
mkdir -p "$work/bin"
fail() { echo "FAIL: $*" >&2; exit 1; }

cat > "$work/bin/cv" <<'FAKE'
#!/usr/bin/env bash
printf 'args: %s\n' "$*" >> "$CV_LOG"
if [[ "$*" == *"--in=json"* ]]; then printf 'stdin: %s\n' "$(cat)" >> "$CV_LOG"; fi
FAKE
chmod +x "$work/bin/cv"
export PATH="$work/bin:$PATH" CV_LOG="$work/cv.log" CK_SETTINGS_FILE="$work/civicrm.settings.php"
printf '<?php\nrequire_once "CRM/Core/ClassLoader.php";\n' > "$CK_SETTINGS_FILE"

ck_as_web() { "$@"; }
export CK_PROVISIONED_MARKER="$work/provisioned"
# shellcheck source=../../docker/runtime/provision.sh
. "$root/docker/runtime/provision.sh"
# The other provisioning steps have suites of their own.
ck_locales() { :; }
ck_apply_profile() { :; }
ck_extra_extensions() { :; }
ck_enable_extensions() { :; }
ck_run_init_hooks() { :; }

: > "$CV_LOG"
ck_post_install_provision >/dev/null
grep -qx 'args: api4 Job.update +w api_action=version_check +v is_active=0' "$CV_LOG" \
    || fail "version_check job not deactivated: $(cat "$CV_LOG")"
# ext_repo_url as core resolves it: the groups merged in order, the last one winning.
# cv ext:list/ext:download put their own feed into the global before the file runs.
effective_feed() { # effective_feed <feed preset by cv, or "">
    php -r '$civicrm_setting = [];
        if ($argv[2] !== "") { $civicrm_setting["Extension Preferences"]["ext_repo_url"] = $argv[2]; }
        eval(str_replace(["<?php", "require_once"], ["", "//"], file_get_contents($argv[1])));
        var_export(array_merge(...array_values($civicrm_setting))["ext_repo_url"] ?? NULL);' "$CK_SETTINGS_FILE" "$1"
}
[[ "$(effective_feed '')" == false ]] \
    || fail "civicrm.settings.php must set ext_repo_url to boolean false: $(cat "$CK_SETTINGS_FILE")"
feed='https://civicrm.org/extdir/ver=6.20|status=stable|ready=ready'
[[ "$(effective_feed "$feed")" == "'$feed'" ]] \
    || fail "cv's own feed must survive civicrm.settings.php, got $(effective_feed "$feed")"
ck_no_phone_home >/dev/null
[[ "$(grep -c "ext_repo_url" "$CK_SETTINGS_FILE")" == 1 ]] || fail "a second run appends again: $(cat "$CK_SETTINGS_FILE")"
[[ -f "$CK_PROVISIONED_MARKER" ]] || fail "provisioning did not complete"

# Buildkit: civibuild loads app/civicrm.settings.d/*.php, sorted by name, from
# inside a function. Ours sorts before buildkit's own 210-ext-repo.php.
export CK_SETTINGS_D="$work/settings.d"
mkdir "$CK_SETTINGS_D"
: > "$CV_LOG"
ck_no_phone_home >/dev/null
CK_SETTINGS_FILE="$CK_SETTINGS_D/150-civikitchen-no-phone-home.php"
grep -q 'setting:set' "$CV_LOG" && fail "buildkit must not set the feed through the API: $(cat "$CV_LOG")"
[[ "$(php -r 'function load($f) { require $f; } $GLOBALS["civicrm_setting"] = []; load($argv[1]);
        var_export($GLOBALS["civicrm_setting"]["Extension Preferences"]["ext_repo_url"] ?? NULL);' "$CK_SETTINGS_FILE")" == false ]] \
    || fail "the settings.d file must set the global ext_repo_url to boolean false: $(cat "$CK_SETTINGS_FILE")"
[[ "$(effective_feed "$feed")" == "'$feed'" ]] \
    || fail "cv's own feed must survive the settings.d file, got $(effective_feed "$feed")"

CK_SETTINGS_D="" CK_SETTINGS_FILE="$work/missing.php"
if ck_no_phone_home 2>"$work/err"; then fail "no settings file and no settings.d must fail"; fi
grep -q 'ERROR: no' "$work/err" || fail "wrong message: $(cat "$work/err")"

echo "provision phone-home: ok"
