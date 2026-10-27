#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }

# gh shim: prints the run fixture verbatim, so the newest-run selection under
# test is the script's, not jq's.
mkdir -p "${work}/bin"
cat > "${work}/bin/gh" <<SHIM
#!/bin/bash
[ "\$1" = run ] || { echo "unexpected gh call: \$*" >&2; exit 1; }
printf '%s\n' "\$*" > "${work}/gh.args"
cat "${work}/runs"
SHIM
chmod +x "${work}/bin/gh"
export PATH="${work}/bin:${PATH}"
export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL="${work}/gitconfig"
git config --global user.name test
git config --global user.email test@example.org
git config --global init.defaultBranch main
# origin keeps its github.com URL so the repo slug is derivable; insteadOf
# delivers every fetch and push to a local bare repo.
git config --global url."${work}/remote.git".insteadOf https://github.com/owner/repo

git init -q --bare "${work}/remote.git"
git init -q "${work}/clone"
cd "${work}/clone"
git remote add origin https://github.com/owner/repo
mkdir -p scripts scaffold .github/scripts .github/workflows docker
cp "${root}/scripts/release.sh" scripts/release.sh
printf '#!/bin/bash\nexit 0\n' > .github/scripts/changelog-check.sh
printf '#!/bin/bash\nexit 0\n' > scaffold/ck
chmod +x scaffold/ck
cat > .github/workflows/build-dev-images.yml <<'YAML'
name: Build Dev Images
on:
  push:
    branches: [main]
    paths:
      - 'docker/**'
      - 'toolbelt/**'
  schedule:
    - cron: '0 4 * * *'
YAML
echo base > docker/base
git add -A
git commit -q -m one
git push -q origin main
image_sha=$(git rev-parse HEAD)

runs() { printf '%s\n' "$@" > "${work}/runs"; }
green="2024-01-01T10:00:00Z 111 schedule completed success ${image_sha}"
red="2024-01-02T10:00:00Z 222 workflow_dispatch completed failure ${image_sha}"
runs "${green}"

release() { bash scripts/release.sh "$@" > "${work}/out" 2>&1; }
expect_fail() { # <expected exit> <grep pattern> <args...>
  local want="$1" pat="$2"; shift 2
  local rc=0
  release "$@" || rc=$?
  [ "${rc}" = "${want}" ] || { cat "${work}/out" >&2; fail "exit ${rc}, expected ${want}: $*"; }
  grep -q "${pat}" "${work}/out" || { cat "${work}/out" >&2; fail "message missing: ${pat}"; }
}

expect_fail 2 usage v1.2.3
expect_fail 2 usage 1.2
expect_fail 2 usage 1.2.3 --force

# Happy path first: everything below must be the one thing that breaks it.
release 1.2.3 || { cat "${work}/out" >&2; fail "dry run on a clean repo"; }
grep -q 'dry run: would tag' "${work}/out" || fail "dry run did not announce the tag"
grep -q 'Build Dev Images run 111 (schedule) is green' "${work}/out" || fail "image gate not reported"
grep -q -- "-R owner/repo" "${work}/gh.args" || { cat "${work}/gh.args" >&2; fail "gh was not pointed at owner/repo"; }
[ -z "$(git tag -l v1.2.3)" ] || fail "the dry run created a tag"

git switch -q -c side
expect_fail 1 'branch is side, not main' 1.2.3
git switch -q main

echo dirt > docker/base
expect_fail 1 'working tree is not clean' 1.2.3
git checkout -q docker/base

git commit -q --allow-empty -m ahead
expect_fail 1 'HEAD is not origin/main' 1.2.3
git reset -q --hard origin/main

git tag v1.2.3
git push -q origin v1.2.3
expect_fail 1 "tag v1.2.3 exists on origin" 1.2.3
git push -q origin :refs/tags/v1.2.3
expect_fail 1 "tag v1.2.3 exists locally" 1.2.3
git tag -d v1.2.3 >/dev/null

runs "${red}"
expect_fail 1 'run 222 for .* concluded failure' 1.2.3
runs "2024-01-01T10:00:00Z 333 schedule in_progress  ${image_sha}"
expect_fail 1 'run 333 for .* is in_progress' 1.2.3
: > "${work}/runs"
expect_fail 1 "no Build Dev Images run for image commit ${image_sha}" 1.2.3

# A push builds every image that gates a release: its run counts like any other.
runs "${green}" "2024-01-03T10:00:00Z 777 push completed success ${image_sha}"
release 1.2.3 || { cat "${work}/out" >&2; fail "a green push run did not count"; }
grep -q 'run 777 (push) is green' "${work}/out" || { cat "${work}/out" >&2; fail "push run not selected"; }
runs "${green}" "2024-01-03T10:00:00Z 777 push in_progress  ${image_sha}"
expect_fail 1 'run 777 for .* is in_progress' 1.2.3
runs "${green}" "2024-01-03T10:00:00Z 777 push completed failure ${image_sha}"
expect_fail 1 'run 777 for .* concluded failure' 1.2.3

# A run on a commit outside image commit..HEAD does not count.
runs "2024-01-01T10:00:00Z 555 schedule completed success 0000000000000000000000000000000000000000"
expect_fail 1 "no Build Dev Images run for image commit ${image_sha}" 1.2.3

# A run builds its head: a run on a later commit covers the image commit.
git commit -q --allow-empty -m later
git push -q origin main
runs "2024-01-01T10:00:00Z 666 schedule completed success $(git rev-parse HEAD)"
release 1.2.3 || { cat "${work}/out" >&2; fail "run on a descendant of the image commit"; }
grep -q 'run 666 (schedule) is green' "${work}/out" || fail "descendant run not selected"

# A rerun that failed must beat the older green run, and the reverse must pass.
runs "${green}" "${red}"
expect_fail 1 'run 222 for .* concluded failure' 1.2.3
runs "${red}" "2024-01-03T10:00:00Z 444 workflow_dispatch completed success ${image_sha}"
release 1.2.3 || { cat "${work}/out" >&2; fail "newest green run after a red one" ; }
grep -q 'run 444 (workflow_dispatch) is green' "${work}/out" || fail "wrong run selected"

runs "${green}"
release 1.2.3 --apply || { cat "${work}/out" >&2; fail "--apply"; }
[ -n "$(git tag -l v1.2.3)" ] || fail "--apply created no tag"
[ "$(git --git-dir="${work}/remote.git" rev-parse refs/tags/v1.2.3^{})" = "$(git rev-parse HEAD)" ] \
  || fail "--apply did not push the tag"
[ "$(git cat-file -t refs/tags/v1.2.3)" = tag ] || fail "the tag is not annotated"

echo "release script regression test passed"
