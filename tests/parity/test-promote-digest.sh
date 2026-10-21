#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }

# docker shim: `imagetools inspect <ref>` answers from the registry fixture
# (`<ref> <digest>` lines), `imagetools create` is recorded.
mkdir -p "${work}/bin"
cat > "${work}/bin/docker" <<SHIM
#!/bin/bash
case "\$3" in
  inspect) awk -v ref="\$4" '\$1 == ref { print \$2 }' "${work}/registry" ;;
  create) printf '%s\n' "\$*" >> "${work}/calls" ;;
  *) echo "unexpected docker call: \$*" >&2; exit 1 ;;
esac
SHIM
chmod +x "${work}/bin/docker"
export PATH="${work}/bin:${PATH}" IMAGE_PREFIX=ghcr.io/owner/civikitchen SHA=abc
P="${IMAGE_PREFIX}"
d1="sha256:$(printf '1%.0s' {1..64})"
d2="sha256:$(printf '2%.0s' {1..64})"
printf '%s\n' "${P}:standalone-6.19.0-abc ${d1}" "${P}:standalone-6.18.3-abc ${d2}" > "${work}/registry"

resolve() { : > "${work}/gh_out"; GITHUB_OUTPUT="${work}/gh_out" "${root}/.github/scripts/resolve-candidate-digests.sh" > "${work}/out" 2>&1; }

# Every candidate resolves once, into one map the scan and promote both read.
TAGS='["6.19.0","6.18.3"]' PREFIX=standalone- resolve || { cat "${work}/out" >&2; fail "resolve"; }
[ "$(cat "${work}/gh_out")" = "digests={\"6.19.0\":\"${d1}\",\"6.18.3\":\"${d2}\"}" ] \
  || { cat "${work}/gh_out" >&2; fail "wrong digest map"; }
# A candidate that does not resolve, or no candidate at all, is an error.
if TAGS='["6.19.0","6.17.9"]' PREFIX=standalone- resolve; then fail "an unresolved candidate passed"; fi
grep -q "could not resolve a digest for ${P}:standalone-6.17.9-abc" "${work}/out" || { cat "${work}/out" >&2; fail "unresolved candidate not named"; }
if TAGS='[]' PREFIX='' resolve; then fail "an empty tag list passed"; fi

promote() { : > "${work}/calls"; "${root}/.github/scripts/promote-digest.sh" "$@" > "${work}/out" 2>&1; }

# Promote publishes exactly the scanned digest, digest-qualified.
DIGEST="${d1}" promote standalone-6.19.0 standalone-6.19 || { cat "${work}/out" >&2; fail "promote"; }
[ "$(cat "${work}/calls")" = "buildx imagetools create --prefer-index=false -t ${P}:standalone-6.19.0 -t ${P}:standalone-6.19 ${P}@${d1}" ] \
  || { cat "${work}/calls" >&2; fail "promote did not publish the scanned digest"; }
# Without a scanned digest (a tag the scan never covered) nothing moves.
for bad in '' 'standalone-6.19.0-abc' 'sha256:short'; do
  if DIGEST="${bad}" promote standalone; then fail "promote accepted digest '${bad}'"; fi
  [ ! -s "${work}/calls" ] || fail "promote moved a tag without a scanned digest ('${bad}')"
done

echo "promote digest regression test passed"
