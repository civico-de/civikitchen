#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bundled/builtin" "$work/external/custom"
printf '{"description":"built in","dependencies":[]}\n' > "$work/bundled/builtin/profile.json"
printf '{"description":"custom","dependencies":[]}\n' > "$work/external/custom/profile.json"

# provision.sh runs where the image links ck into PATH.
PATH="$root/toolbelt/bin:$PATH"
CK_PROFILE_DIR="$work/bundled"
CIVIKITCHEN_PROFILE_PATH="$work/external"
# shellcheck source=../../docker/runtime/provision.sh
source "$root/docker/runtime/provision.sh"

[ "$(ck_resolve_profile custom)" = "$work/external/custom" ] || { echo "external profile not resolved" >&2; exit 1; }
[ "$(ck_resolve_profile builtin)" = "$work/bundled/builtin" ] || { echo "bundled profile not resolved" >&2; exit 1; }
ck_validate_profile "$work/external/custom"
if ck_require_profile_trust "$work/external/custom" >/dev/null 2>&1; then
  echo "untrusted external profile passed" >&2; exit 1
fi
CIVIKITCHEN_TRUST_EXTERNAL_PROFILES=1
ck_require_profile_trust "$work/external/custom"
ck_require_profile_trust "$work/bundled/builtin"

printf '{"description":"one","dependencies":[],"authx":{"header_cred":["api_key"]},"apiUsers":[{"username":"apiOne","role":"civikitchen_api_one","permissions":["access CiviCRM"]}]}\n' > "$work/external/custom/profile.json"
mkdir -p "$work/external/second"
printf '{"description":"two","dependencies":[],"authx":{"header_cred":["api_key"]},"apiUsers":[{"username":"apiTwo","role":"civikitchen_api_two","permissions":["access CiviCRM"]}]}\n' > "$work/external/second/profile.json"
[ "$(ck_resolve_authx_policy "$work/external/custom" "$work/external/second")" = api_key ] \
  || { echo "equal AuthX policies did not converge" >&2; exit 1; }
printf '{"description":"two","dependencies":[],"authx":{"header_cred":["pass"]},"apiUsers":[{"username":"apiTwo","role":"civikitchen_api_two","permissions":["access CiviCRM"]}]}\n' > "$work/external/second/profile.json"
if ck_resolve_authx_policy "$work/external/custom" "$work/external/second" >/dev/null 2>&1; then
  echo "conflicting AuthX policies passed" >&2; exit 1
fi

mkdir -p "$work/external/builtin"
cp "$work/bundled/builtin/profile.json" "$work/external/builtin/profile.json"
if ck_resolve_profile builtin >/dev/null 2>&1; then echo "ambiguous profile passed" >&2; exit 1; fi
if ck_resolve_profile ../escape >/dev/null 2>&1; then echo "profile traversal passed" >&2; exit 1; fi

printf '{"description":"broken","dependencies":[],"surprise":true}\n' > "$work/external/custom/profile.json"
if ck_validate_profile "$work/external/custom" >/dev/null 2>&1; then echo "invalid external profile passed schema" >&2; exit 1; fi

printf '{"description":"scripted","dependencies":[],"scripts":["scripts/10-data.php"]}\n' > "$work/external/custom/profile.json"
if ck_validate_profile "$work/external/custom" >/dev/null 2>&1; then echo "missing profile script passed" >&2; exit 1; fi
mkdir -p "$work/external/custom/scripts" && printf '<?php\n' > "$work/external/custom/scripts/10-data.php"
ck_validate_profile "$work/external/custom"
printf '{"description":"escape","dependencies":[],"scripts":["../second/profile.json"]}\n' > "$work/external/custom/profile.json"
if ck_validate_profile "$work/external/custom" >/dev/null 2>&1; then echo "script outside the profile passed" >&2; exit 1; fi
printf '{"description":"legacy","dependencies":[]}\n' > "$work/external/custom/profile.json"
mkdir -p "$work/external/custom/seeds"
if ck_validate_profile "$work/external/custom" 2>"$work/err"; then echo "seeds/ directory passed" >&2; exit 1; fi
grep -q 'name its PHP files in the profile.s "scripts" list' "$work/err" || { echo "seeds/ error does not point at scripts" >&2; exit 1; }

# include: depth-first, each profile once, across roots; cycles and unknown names fail.
mkdir -p "$work/external/combo" "$work/external/mid"
/bin/rm -rf "$work/external/builtin" "$work/external/custom/seeds"
printf '{"description":"custom","dependencies":[]}\n' > "$work/external/custom/profile.json"
printf '{"description":"mid","include":["builtin"],"dependencies":[]}\n' > "$work/external/mid/profile.json"
printf '{"description":"combo","include":["mid","builtin","custom"],"dependencies":[]}\n' > "$work/external/combo/profile.json"
CK_EXPANDED_PROFILES=()
ck_expand_profile combo ""
ck_expand_profile custom ""
[ "${CK_EXPANDED_PROFILES[*]}" = "$work/bundled/builtin $work/external/mid $work/external/custom $work/external/combo" ] \
  || { echo "include expansion order: ${CK_EXPANDED_PROFILES[*]}" >&2; exit 1; }
printf '{"description":"builtin","include":["combo"],"dependencies":[]}\n' > "$work/bundled/builtin/profile.json"
CK_EXPANDED_PROFILES=()
if ck_expand_profile combo "" 2>"$work/err"; then echo "include cycle passed" >&2; exit 1; fi
grep -q 'cycle: combo -> mid -> builtin -> combo' "$work/err" || { echo "cycle error: $(cat "$work/err")" >&2; exit 1; }
printf '{"description":"built in","include":["nowhere"],"dependencies":[]}\n' > "$work/bundled/builtin/profile.json"
CK_EXPANDED_PROFILES=()
if ck_expand_profile combo "" 2>/dev/null; then echo "unknown include passed" >&2; exit 1; fi

# ck_apply_profile drives the expanded list, includes first, passing the driver the pins and
# staged releases explicitly: the web user's switch may drop the environment.
printf '{"description":"built in","dependencies":[]}\n' > "$work/bundled/builtin/profile.json"
printf '#!/bin/bash\necho "$(basename "$1") $2 ${CK_DEP_ARCHIVE_DIR:-none} ${CK_SEED_EXTENSION_DIRS:-none}" >> "%s"\n' "$work/applied" > "$work/bundled/apply.sh"
ck_as_web() { env -i PATH="$PATH" "$@"; }
mkdir -p "$work/ext/mounted"
printf '100 90 0:37 /host/m %s rw,relatime - fakeowner /dev/x rw\n' "$work/ext/mounted" > "$work/mountinfo"
CK_EXT_DIR="$work/ext" CK_MOUNTINFO="$work/mountinfo" CK_DEP_ARCHIVE_DIR=/staged \
  CIVIKITCHEN_PROFILE="combo, custom" ck_apply_profile deps >/dev/null
staged="/staged $work/ext/mounted"
[ "$(tr '\n' ',' < "$work/applied")" = "builtin deps $staged,mid deps $staged,custom deps $staged,combo deps $staged," ] \
  || { echo "ck_apply_profile applied: $(cat "$work/applied")" >&2; exit 1; }

echo "external profile tests passed"
