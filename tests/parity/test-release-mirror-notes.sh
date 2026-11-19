#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
notes_script="$root/.github/scripts/release-mirror-notes.sh"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }

# A stand-in curl: it logs each call's method, URL, headers and body, and
# answers with the next status from $CK_TEST_STATUSES.
mkdir "$work/bin"
cat > "$work/bin/curl" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$CK_TEST_ARGV"
method=GET url='' out='' body=''
headers=()
while [ $# -gt 0 ]; do
  case "$1" in
    --request) method="$2"; shift 2 ;;
    --output) out="$2"; shift 2 ;;
    --header) if [[ "$2" == @* ]]; then headers+=("$(cat "${2#@}")"); else headers+=("$2"); fi; shift 2 ;;
    --data-binary) body="$(cat "${2#@}")"; shift 2 ;;
    --write-out) shift 2 ;;
    --*) shift ;;
    *) url="$1"; shift ;;
  esac
done
n=$(( $(wc -l < "$CK_TEST_CALLS") + 1 ))
{ printf 'call %s %s %s\n' "$n" "$method" "$url"; printf 'header %s\n' "${headers[@]}"; printf 'body %s\n' "$body"; } >> "$CK_TEST_LOG"
echo "$method" >> "$CK_TEST_CALLS"
status="$(sed -n "${n}p" <<< "${CK_TEST_STATUSES// /$'\n'}")"
printf '{"message":"answer %s"}' "$status" > "$out"
printf '%s' "$status"
FAKE
chmod +x "$work/bin/curl"

printf '### Fixed\n\n- A "quoted" fix & more.\n' > "$work/notes.md"

run() { # <statuses> <version>
  : > "$work/argv" && : > "$work/log" && : > "$work/calls"
  CK_TEST_STATUSES="$1" CK_TEST_ARGV="$work/argv" CK_TEST_LOG="$work/log" CK_TEST_CALLS="$work/calls" \
    PATH="$work/bin:$PATH" CK_MIRROR_URL=https://lab.example.org/extensions/demo.git CK_MIRROR_TOKEN=s3cret \
    bash "$notes_script" "$2" "$work/notes.md" > "$work/out" 2>&1
}
api='https://lab.example.org/api/v4/projects/extensions%2Fdemo/releases'

# No release for the tag yet: one is created with the notes as description.
run '404 201' 1.2.3 || { cat "$work/out" "$work/log" >&2; fail "creating the release failed"; }
grep -qx "call 1 GET $api/1.2.3" "$work/log" || { cat "$work/log" >&2; fail "the release was not looked up by its tag"; }
grep -qx "call 2 POST $api" "$work/log" || { cat "$work/log" >&2; fail "the release was not created"; }
body="$(sed -n 's/^body //p' "$work/log" | tail -1)"
expected="$(php -r 'echo json_encode(["tag_name" => "1.2.3", "name" => "1.2.3", "description" => file_get_contents($argv[1])], JSON_UNESCAPED_SLASHES);' "$work/notes.md")"
[ "$body" = "$expected" ] || fail "the request body is not the tag and the notes: $body"
grep -qx 'header PRIVATE-TOKEN: s3cret' "$work/log" || fail "the token was not sent as PRIVATE-TOKEN header"
grep -qx 'header Content-Type: application/json' "$work/log" || fail "the body was not sent as JSON"
grep -q 'created the mirror' "$work/out" || fail "the creation was not reported"
if grep -q 's3cret' "$work/argv" "$work/out"; then fail "the token reached an argument or the output"; fi

# A re-run updates the existing release instead of failing on it.
run '200 200' 1.2.3 || { cat "$work/out" >&2; fail "updating the release failed"; }
grep -qx "call 2 PUT $api/1.2.3" "$work/log" || { cat "$work/log" >&2; fail "the existing release was not updated"; }
grep -q 'updated the mirror' "$work/out" || fail "the update was not reported"

# A pre-release version is a tag like any other.
run '404 201' 2.0.0-rc.1 || { cat "$work/out" >&2; fail "a pre-release failed"; }
grep -qx "call 1 GET $api/2.0.0-rc.1" "$work/log" || fail "the pre-release tag was not looked up"

# A token without the api scope fails the run and shows GitLab's answer.
if run '403' 1.2.3; then fail "a refused lookup passed"; fi
grep -q 'returned HTTP 403: {"message":"answer 403"}' "$work/out" || { cat "$work/out" >&2; fail "wrong message for a refused lookup"; }
[ "$(wc -l < "$work/calls")" -eq 1 ] || fail "a refused lookup still wrote"
if run '404 400' 1.2.3; then fail "a refused write passed"; fi
grep -q 'writing the mirror.s release 1.2.3 returned HTTP 400' "$work/out" || { cat "$work/out" >&2; fail "wrong message for a refused write"; }

# Refused inputs, before any request.
if run '' v1.2.3; then fail "a v-prefixed version was accepted"; fi
grep -q "not a release version: 'v1.2.3'" "$work/out" || fail "wrong message for a v-prefixed version"
[ ! -s "$work/calls" ] || fail "a refused version still sent a request"
if CK_MIRROR_URL=git@lab.example.org:extensions/demo.git CK_MIRROR_TOKEN=s3cret PATH="$work/bin:$PATH" \
  bash "$notes_script" 1.2.3 "$work/notes.md" > "$work/out" 2>&1; then
  fail "an SSH address was accepted"
fi
grep -q 'not an HTTPS project address' "$work/out" || fail "wrong message for an SSH address"
if CK_MIRROR_URL=https://lab.example.org/extensions/demo.git PATH="$work/bin:$PATH" \
  bash "$notes_script" 1.2.3 "$work/notes.md" > "$work/out" 2>&1; then
  fail "a run without a token passed"
fi

echo "release mirror notes suite OK"
