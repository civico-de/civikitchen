#!/bin/bash
# release-attach-cli.sh over a fake gh listing and a fake docker: the release tags
# land on :latest's digest only when the cli target's inputs match the release.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work=$(mktemp -d)
trap '/bin/rm -rf "${work}"' EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }

mkdir -p "${work}/bin" "${work}/scripts"
cp "${root}/.github/scripts/release-attach-cli.sh" "${work}/scripts/"
printf '#!/bin/sh\necho "${NEWEST}"\n' > "${work}/scripts/release-newest-tag.sh"
cat > "${work}/bin/gh" <<SHIM
#!/bin/bash
[ "\$1 \$2 \$3 \$4" = "api /orgs/owner/packages/container/civikitchen-cli/versions?per_page=100 --paginate --jq" ] \
  || { echo "unexpected gh call: \$*" >&2; exit 1; }
jq -c "\$5" "${work}/versions"
SHIM
printf '#!/bin/sh\necho "docker $*" >> "%s/docker.log"\n' "${work}" > "${work}/bin/docker"
chmod +x "${work}/bin/gh" "${work}/bin/docker" "${work}/scripts/release-newest-tag.sh"
export PATH="${work}/bin:${PATH}"
export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL="${work}/gitconfig"
git config --global user.name test
git config --global user.email test@example.org

# The toolchain stage's COPY and the --from COPY are not cli inputs.
git init -q "${work}/repo"
cd "${work}/repo"
mkdir -p docker/cli toolbelt
cat > docker/cli/Dockerfile <<'DOCKERFILE'
FROM docker:29.5.2-cli AS docker-cli
FROM debian:trixie-slim AS toolchain
COPY toolchain-only /x
FROM docker-cli AS cli
COPY --from=docker-cli /usr/local/bin/docker /usr/local/bin/docker
COPY package.json package-lock.json /opt/civikitchen/
COPY toolbelt/ck /opt/civikitchen/toolbelt/ck
DOCKERFILE
echo tools > toolbelt/ck
echo '{}' > package.json
echo '{"v": 1}' > package-lock.json
echo a > toolchain-only
echo a > unrelated
git add -A
git commit -q -m base
base=$(git rev-parse HEAD)
commit() { git add -A; git commit -q -m "$1"; git rev-parse HEAD; }
echo '{"v": 2}' > package-lock.json
lock=$(commit lock)
echo b > toolchain-only
echo b > unrelated
rel=$(commit rel)

export OWNER=owner PKG=civikitchen-cli IMAGE_PREFIX=ghcr.io/owner/civikitchen-cli
export TAG=v1.2.3 MAJOR=v1 RELEASE_SHA="${rel}" ALLOW_DRIFT=0 NEWEST=v1.2.3
# One package version per argument, `<digest>=<tag>,<tag>…`.
versions() {
  printf '%s\n' "$@" | jq -R 'capture("^(?<name>[^=]*)=(?<tags>.*)$")
    | {name, metadata: {container: {tags: (.tags | split(","))}}}' | jq -s . > "${work}/versions"
}
attach() { : > "${work}/docker.log"; "${work}/scripts/release-attach-cli.sh" > "${work}/out" 2>&1; }
refused() {
  if attach; then fail "$1 must fail"; fi
  [ ! -s "${work}/docker.log" ] || fail "$1 reached docker"
}

# Only non-input paths differ from the build commit: patch and major tag.
versions "sha256:new=latest,latest-${lock}"
attach || fail "a matching build must attach: $(cat "${work}/out")"
grep -qxF "docker buildx imagetools create --prefer-index=false -t ${IMAGE_PREFIX}:v1.2.3 -t ${IMAGE_PREFIX}:v1 ${IMAGE_PREFIX}@sha256:new" "${work}/docker.log" \
  || fail "tags: $(cat "${work}/docker.log")"

# An older release keeps the major tag where it is.
NEWEST=v1.3.0 attach || fail "an older release must attach: $(cat "${work}/out")"
grep -qxF "docker buildx imagetools create --prefer-index=false -t ${IMAGE_PREFIX}:v1.2.3 ${IMAGE_PREFIX}@sha256:new" "${work}/docker.log" \
  || fail "older release tags: $(cat "${work}/docker.log")"

# package-lock.json is a cli input: a build before its change is drift.
versions "sha256:new=latest,latest-${base}"
refused "a build with another package-lock.json"
grep -qF 'image drift' "${work}/out" || fail "no drift message: $(cat "${work}/out")"
ALLOW_DRIFT=1 attach || fail "allow_image_drift must attach: $(cat "${work}/out")"
grep -qF 'attached anyway' "${work}/out" || fail "no drift warning: $(cat "${work}/out")"

# A pruned candidate tag and a missing :latest stop before docker.
versions "sha256:new=latest"
refused "a build without a candidate tag"
versions "sha256:other=latest-${rel}"
refused "a package without :latest"

# A path the cli stage copies joins the check as soon as the Dockerfile names it.
echo 'COPY unrelated /opt/civikitchen/unrelated' >> docker/cli/Dockerfile
copies=$(commit copies)
versions "sha256:new=latest,latest-${lock}"
RELEASE_SHA="${copies}" refused "a build without the newly copied path's content"
echo 'COPY missing* /opt/civikitchen/' >> docker/cli/Dockerfile
glob=$(commit glob)
RELEASE_SHA="${glob}" refused "a COPY source that is not a path"
grep -qF 'copies missing*, which is not a path' "${work}/out" || fail "glob source: $(cat "${work}/out")"

# A published patch tag is the release's image: a re-run leaves it, even after :latest moved on.
versions "sha256:newer=latest,latest-${rel}" "sha256:new=v1.2.3,v1"
attach || fail "a re-run after :latest moved must pass: $(cat "${work}/out")"
grep -qF 'already released on sha256:new, left as is' "${work}/out" || fail "re-run: $(cat "${work}/out")"
[ ! -s "${work}/docker.log" ] || fail "a re-run re-tagged: $(cat "${work}/docker.log")"

echo "release attach cli: ok"
