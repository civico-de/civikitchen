#!/usr/bin/env bash
# A recreated container with the database kept re-applies profile, locales and
# extensions but not the init.d hooks marked in the database. Fake cv uses a file.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
mkdir -p "$work/bin"
fail() { echo "FAIL: $*" >&2; exit 1; }

cat > "$work/bin/cv" <<'FAKE'
#!/usr/bin/env bash
if [[ "$1 $2" == "ev "*"->set('civikitchen_provisioned', TRUE);" ]]; then touch "$DB_SETTING"; fi
if [[ "$1 $2" == "ev "*"->get('civikitchen_provisioned')"* ]]; then
    if [[ -f "$DB_SETTING" ]]; then echo yes; else echo no; fi
fi
FAKE
chmod +x "$work/bin/cv"
export PATH="$work/bin:$PATH" DB_SETTING="$work/db-setting"

ck_as_web() { "$@"; }
export CK_PROVISIONED_MARKER="$work/provisioned"
# shellcheck source=../../docker/runtime/provision.sh
. "$root/docker/runtime/provision.sh"
ck_no_phone_home() { echo phone >> "$work/steps"; }
ck_locales() { echo locales >> "$work/steps"; }
ck_apply_profile() { echo "profile-$1" >> "$work/steps"; }
ck_extra_extensions() { echo extra >> "$work/steps"; }
ck_enable_extensions() { echo enable >> "$work/steps"; }
ck_run_init_hooks() { echo run >> "$work/hooks"; echo hooks >> "$work/steps"; }

ck_post_install_provision >/dev/null
[[ -f "$CK_PROVISIONED_MARKER" && -f "$DB_SETTING" ]] || fail "first boot did not mark file and database"
# Profile content (seed, scripts) can use the mounted extension; init.d layers on top.
[[ "$(tr '\n' ' ' < "$work/steps")" == "phone locales profile-deps extra enable profile-content hooks " ]] \
    || fail "first boot ran the steps out of order: $(tr '\n' ' ' < "$work/steps")"

# A recreated container: the marker file is gone, the database keeps its setting.
/bin/rm "$CK_PROVISIONED_MARKER"
ck_post_install_provision >/dev/null
[[ "$(wc -l < "$work/hooks")" -eq 1 ]] || fail "init.d hooks ran again on a provisioned database"
[[ "$(sort -u "$work/steps" | tr '\n' ' ')" == "enable extra hooks locales phone profile-content profile-deps " && "$(wc -l < "$work/steps")" -eq 13 ]] \
    || fail "recreated container did not re-apply profile, locales and extensions"
[[ -f "$CK_PROVISIONED_MARKER" ]] || fail "recreated container did not restore the marker file the healthcheck reads"

# A fresh database (down -v) provisions again.
/bin/rm "$CK_PROVISIONED_MARKER" "$DB_SETTING"
ck_post_install_provision >/dev/null
[[ "$(wc -l < "$work/hooks")" -eq 2 ]] || fail "a fresh database was not provisioned"

echo "provision db marker: ok"
