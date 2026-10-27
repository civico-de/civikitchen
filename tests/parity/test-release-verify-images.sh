#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
verify="${root}/.github/scripts/release-verify-images.sh"
work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }

# gh shim: the package listing is the fixture, run through the script's own --jq.
mkdir -p "${work}/bin"
cat > "${work}/bin/gh" <<SHIM
#!/bin/bash
[ "\$1 \$2 \$3 \$4" = "api /orgs/owner/packages/container/civikitchen/versions?per_page=100 --paginate --jq" ] \
  || { echo "unexpected gh call: \$*" >&2; exit 1; }
jq -c "\$5" "${work}/versions"
SHIM
chmod +x "${work}/bin/gh"
export PATH="${work}/bin:${PATH}"
export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL="${work}/gitconfig"
git config --global user.name test
git config --global user.email test@example.org
git config --global init.defaultBranch main

# Two commits whose image trees differ: `old` stands for a build from before
# the release, `rel` is the release commit.
git init -q "${work}/repo"
cd "${work}/repo"
mkdir docker toolbelt packages
echo one > docker/Dockerfile
echo tools > toolbelt/ck
echo schema > packages/schema.json
git add -A
git commit -q -m old
old=$(git rev-parse HEAD)
echo two > docker/Dockerfile
git commit -q -am rel
rel=$(git rev-parse HEAD)

export OWNER=owner PKG=civikitchen TAG=v1.2.3 RELEASE_SHA="${rel}" ALLOW_DRIFT=0
flavors=(standalone drupal10 drupal11 wordpress joomla backdrop
         standalone-demo drupal10-demo drupal11-demo wordpress-demo joomla-demo backdrop-demo)

# One package version per argument, `<digest>=<tag>,<tag>…`.
versions() {
  printf '%s\n' "$@" |
    jq -R 'capture("^(?<name>[^=]*)=(?<tags>.*)$")
           | {name, metadata: {container: {tags: (.tags | split(","))}}}' |
    jq -s . > "${work}/versions"
}
# Every flavor promoted from <sha>, except the ones given as <flavor>=<sha>
# (built elsewhere), <flavor>=pruned (candidate tag gone) or <flavor>= (never
# promoted).
live() {
  local sha="$1" f entry override
  shift
  local out=()
  for f in "${flavors[@]}"; do
    entry="sha256:${f}-new=${f},${f}-latest-${sha}"
    for override in "$@"; do
      [ "${override%%=*}" = "${f}" ] || continue
      if [ "${override#*=}" = pruned ]; then
        entry="sha256:${f}-new=${f}"
      elif [ -n "${override#*=}" ]; then
        entry="sha256:${f}-new=${f},${f}-latest-${override#*=}"
      else
        entry=''
      fi
    done
    [ -z "${entry}" ] || out+=("${entry}")
  done
  printf '%s\n' "${out[@]}"
}

run() { "${verify}" > "${work}/out" 2>&1; }
expect_fail() { # <grep pattern>
  local rc=0
  run || rc=$?
  [ "${rc}" = 1 ] || { cat "${work}/out" >&2; fail "exit ${rc}, expected 1 (${1})"; }
  grep -q -- "$1" "${work}/out" || { cat "${work}/out" >&2; fail "message missing: $1"; }
}
attached() { cut -f1 digests.txt | tr '\n' ' '; }

# Healthy: every flavor attached, nothing warned.
mapfile -t v < <(live "${rel}")
versions "${v[@]}"
run || { cat "${work}/out" >&2; fail "a healthy release failed"; }
[ "$(attached)" = "${flavors[*]} " ] || fail "healthy release attached: $(attached)"
grep -q '::warning::' "${work}/out" && { cat "${work}/out" >&2; fail "a healthy release warned"; }

# A CMS flavor built from another tree is left out with a warning.
mapfile -t v < <(live "${rel}" "drupal11=${old}")
versions "${v[@]}"
run || { cat "${work}/out" >&2; fail "a drifted drupal11 blocked the release"; }
grep -q '::warning::.*:drupal11 was built from' "${work}/out" || { cat "${work}/out" >&2; fail "drupal11 drift not warned"; }
[[ " $(attached)" != *" drupal11 "* ]] || fail "drifted drupal11 attached: $(attached)"
[[ " $(attached)" == *" drupal11-demo "* ]] || fail "drupal11-demo left out: $(attached)"
[[ " $(attached)" == *" standalone "* ]] || fail "standalone left out: $(attached)"

# allow_image_drift attaches it after all.
ALLOW_DRIFT=1 run || { cat "${work}/out" >&2; fail "allow_image_drift"; }
[[ " $(attached)" == *" drupal11 "* ]] || fail "allow_image_drift did not attach drupal11: $(attached)"

# A CMS flavor without a published image is left out with a warning.
mapfile -t v < <(live "${rel}" "joomla-demo=")
versions "${v[@]}"
run || { cat "${work}/out" >&2; fail "a missing joomla-demo blocked the release"; }
grep -q '::warning::no published image behind :joomla-demo' "${work}/out" || { cat "${work}/out" >&2; fail "missing joomla-demo not warned"; }
[[ " $(attached)" != *" joomla-demo "* ]] || fail "missing joomla-demo attached"

# A build commit that cannot be told counts as drift: candidate tag pruned, or
# a commit this checkout does not have.
unknown=0123456789abcdef0123456789abcdef01234567
for why in pruned "${unknown}"; do
  mapfile -t v < <(live "${rel}" "wordpress=${why}")
  versions "${v[@]}"
  run || { cat "${work}/out" >&2; fail "unknown provenance of wordpress (${why}) blocked the release"; }
  grep -q '::warning::.*:wordpress.*its release tags follow' "${work}/out" || { cat "${work}/out" >&2; fail "unknown wordpress provenance (${why}) not warned"; }
  [[ " $(attached)" != *" wordpress "* ]] || fail "wordpress of unknown provenance (${why}) attached"
  for f in standalone standalone-demo; do
    mapfile -t v < <(live "${rel}" "${f}=${why}")
    versions "${v[@]}"
    expect_fail "image drift: .*:${f}"
  done
  # allow_image_drift attaches both as before.
  mapfile -t v < <(live "${rel}" "wordpress=${why}" "standalone=${why}")
  versions "${v[@]}"
  ALLOW_DRIFT=1 run || { cat "${work}/out" >&2; fail "allow_image_drift with unknown provenance (${why})"; }
  [[ " $(attached)" == *" wordpress "* && " $(attached)" == *" standalone "* ]] \
    || fail "allow_image_drift left out a flavor of unknown provenance (${why}): $(attached)"
done

# standalone and standalone-demo are mandatory.
for f in standalone standalone-demo; do
  mapfile -t v < <(live "${rel}" "${f}=${old}")
  versions "${v[@]}"
  expect_fail ":${f} was built from"
  mapfile -t v < <(live "${rel}" "${f}=")
  versions "${v[@]}"
  expect_fail "FATAL: no published image behind :${f}\$"
done

# Catch-up: the release went out without drupal11. A later full run rebuilt
# every flavor from the same tree (new digests) and promoted drupal11. The
# re-run attaches drupal11 and leaves every released tag where it is.
mapfile -t v < <(live "${rel}")
released=()
for f in "${flavors[@]}"; do
  [ "${f}" = drupal11 ] && continue
  released+=("sha256:${f}-old=${f}-v1,${f}-${TAG}")
done
released+=("sha256:standalone-old=v1,${TAG}")
versions "${v[@]}" "${released[@]}"
run || { cat "${work}/out" >&2; fail "catch-up re-run failed"; }
[ "$(attached)" = "drupal11 " ] || fail "catch-up attached: $(attached)"
grep -q ':standalone-v1.2.3 is already released' "${work}/out" || { cat "${work}/out" >&2; fail "released standalone not reported"; }
# Released flavors are not checked again: main may have moved their trees on.
mapfile -t v2 < <(live "${rel}" "standalone=${old}" "standalone-demo=${old}")
versions "${v2[@]}" "${released[@]}"
run || { cat "${work}/out" >&2; fail "catch-up re-run failed on a released, since rebuilt standalone"; }
[ "$(attached)" = "drupal11 " ] || fail "catch-up after a rebuild attached: $(attached)"

# A patch tag on another digest is never moved: standalone half-tagged (its
# unprefixed :v1.2.3 missing) would need exactly that.
versions "${v[@]}" "sha256:standalone-old=standalone-v1,standalone-${TAG}"
expect_fail "FATAL: :standalone-${TAG} is already published on another digest"
# The same for the unprefixed alias.
versions "${v[@]}" "sha256:standalone-old=v1,${TAG}"
expect_fail "FATAL: :${TAG} is already published on another digest"

echo "release image verification regression test passed"
