#!/bin/bash
# Install OS-agnostic CiviCRM extension dev tools used by both the standalone
# and buildkit images:
#   - composer (the phar the Makefile's suites run on too)
#   - civix    (CiviCRM extension scaffolding/build tool)
#   - phpunit  (pinned to 9 for CiviCRM compatibility)
#   - phpstan, rector (`ckmodernize`), psalm (`cktaint`, taint only),
#     composer-dependency-analyser (`ckdeps`): from the root composer.json + lock
#   - infection (own root; powers `ckmutate`)
#   - mago     (Rust binary; `ckfmt`, `cklint`, `ckanalyze`, `ckcompat`)
#   - oxlint   (npm toolchain + tsgolint Go binary; powers `ckeslint`)
#   - oxfmt    (npm toolchain; JS/TS half of `ckfmt`)
#
# Prerequisites (handled per image):
#   - php, curl, git on PATH
#   - composer.json + composer.lock copied beside toolbelt/
set -euo pipefail

# Pins with more than one consumer come from versions.env as CK_*, so a
# --build-arg still wins over the ${VAR:-...} defaults below.
TOOLBELT="$(cd "$(dirname "$0")" && pwd)"
CK_ROOT="$(dirname "${TOOLBELT}")"
# shellcheck source=versions.env
. "${TOOLBELT}/versions.env"

# ---------------------------------------------------------------------------
# Tool pins the root composer.lock cannot hold; image tags rebuild monthly, so
# unpinned tools would drift. docs/building.md explains each pin.
CIVIX_VERSION="${CIVIX_VERSION:-26.02.0}"
# phpunit 9 is the last line CiviCRM's test base supports.
PHPUNIT_VERSION="${PHPUNIT_VERSION:-$CK_PHPUNIT_VERSION}"
# A ceiling, not an exact pin: images on PHP 8.1/8.2 need older infection. It
# still stops new mutators from moving a repo's score with no code change.
INFECTION_VERSION="${INFECTION_VERSION:-0.34.1}"
MAGO_VERSION="${MAGO_VERSION:-$CK_MAGO_VERSION}"
# npm replaces the distro's, whose bundled tree lands in the image scan. npm 12
# skips install scripts a project has not approved (measured: 12.0.2 skips, 11
# runs); CiviCRM core, buildkit and esbuild/playwright frontends still need
# theirs, so the dangerously-allow-all-scripts line below re-enables them.
NPM_VERSION="${NPM_VERSION:-12.2.1}"

# ---------------------------------------------------------------------------
# Phars: composer, civix, phpunit. The hashes are the actual pin; overriding a
# *_VERSION means overriding its *_SHA256. Re-derive after a bump with:
#   curl -LsS <url> | shasum -a 256
CIVIX_SHA256="${CIVIX_SHA256:-1c480133bee248c1f09f19c724e2e44266297b63ff2e55a7cbf3ea17a910d906}"
PHPUNIT_SHA256="${PHPUNIT_SHA256:-$CK_PHPUNIT_SHA256}"
MAGO_SHA256_X86_64="${MAGO_SHA256_X86_64:-$CK_MAGO_SHA256_linux_x86_64}"
MAGO_SHA256_AARCH64="${MAGO_SHA256_AARCH64:-$CK_MAGO_SHA256_linux_aarch64}"

# Not `sha256sum -c`: it exits 0 on a malformed line, so an empty *_SHA256
# would pass.
fetch_phar() {
    local url="$1" dest="$2" want="$3" got
    curl -LsS --fail --retry 3 --retry-all-errors "${url}" -o "${dest}"
    got=$(sha256sum < "${dest}" | cut -d' ' -f1)
    if [ "${got}" != "${want}" ]; then
        echo "checksum mismatch for ${url}: expected ${want}, got ${got}" >&2
        exit 1
    fi
    chmod +x "${dest}"
}

# No build argument: the standalone git stage and the Makefile run this release.
fetch_phar "https://getcomposer.org/download/${CK_COMPOSER_VERSION}/composer.phar" \
    /usr/local/bin/composer "${CK_COMPOSER_SHA256}"

fetch_phar "https://download.civicrm.org/civix/civix-${CIVIX_VERSION}.phar" \
    /usr/local/bin/civix "${CIVIX_SHA256}"

fetch_phar "https://phar.phpunit.de/phpunit-${PHPUNIT_VERSION}.phar" \
    /usr/local/bin/phpunit "${PHPUNIT_SHA256}"

# ---------------------------------------------------------------------------
# mago: a prebuilt binary per architecture, checksummed like the phars; the gnu
# build, because the images are Debian-based.
case "$(uname -m)" in
    x86_64)  MAGO_ARCH=x86_64;  MAGO_SHA256="${MAGO_SHA256_X86_64}" ;;
    aarch64) MAGO_ARCH=aarch64; MAGO_SHA256="${MAGO_SHA256_AARCH64}" ;;
    *) echo "unsupported architecture for mago: $(uname -m)" >&2; exit 1 ;;
esac
curl -LsS --fail --retry 3 --retry-all-errors -o /tmp/mago.tar.gz \
    "https://github.com/carthage-software/mago/releases/download/${MAGO_VERSION}/mago-${MAGO_VERSION}-${MAGO_ARCH}-unknown-linux-gnu.tar.gz"
got=$(sha256sum < /tmp/mago.tar.gz | cut -d' ' -f1)
if [ "${got}" != "${MAGO_SHA256}" ]; then
    echo "checksum mismatch for mago ${MAGO_VERSION} (${MAGO_ARCH}): expected ${MAGO_SHA256}, got ${got}" >&2
    exit 1
fi
tar -xzf /tmp/mago.tar.gz -C /tmp "mago-${MAGO_VERSION}-${MAGO_ARCH}-unknown-linux-gnu/mago"
mv "/tmp/mago-${MAGO_VERSION}-${MAGO_ARCH}-unknown-linux-gnu/mago" /usr/local/bin/mago
chmod +x /usr/local/bin/mago
rm -rf /tmp/mago.tar.gz "/tmp/mago-${MAGO_VERSION}-${MAGO_ARCH}-unknown-linux-gnu"

# ---------------------------------------------------------------------------
# The composer root from the committed lock; toolbelt/phpstan joins as a path
# repository, so extension-installer registers it without a neon line.
export COMPOSER_HOME=/tmp/civikitchen-composer
export COMPOSER_ALLOW_SUPERUSER=1
composer install --working-dir="${CK_ROOT}" --no-interaction --no-progress

# Wrappers, because vendor/bin on PATH would expose every transitive binary.
for tool in phpstan composer-dependency-analyser; do
    cat > "/usr/local/bin/${tool}" <<EOF
#!/bin/sh
exec php ${CK_ROOT}/vendor/bin/${tool} "\$@"
EOF
    chmod +x "/usr/local/bin/${tool}"
done

# ---------------------------------------------------------------------------
# infection has its own root: it follows the image's PHP version.
INFECTION_DIR=/opt/civikitchen-infection
mkdir -p "${INFECTION_DIR}"
[ -f "${INFECTION_DIR}/composer.json" ] || echo '{}' > "${INFECTION_DIR}/composer.json"
# infection/extension-installer is a composer plugin, like phpstan's.
composer config --working-dir="${INFECTION_DIR}" --no-plugins \
    allow-plugins.infection/extension-installer true
# `<=`, not the exact version: composer then picks the newest release the
# image's PHP accepts (8.1 -> 0.29.x, 8.2 -> 0.32.x, 8.3+ -> the ceiling).
composer require --working-dir="${INFECTION_DIR}" --no-interaction --no-progress \
    "infection/infection:<=${INFECTION_VERSION}"

cat > /usr/local/bin/infection <<EOF
#!/bin/sh
exec php ${INFECTION_DIR}/vendor/bin/infection "\$@"
EOF
chmod +x /usr/local/bin/infection

# ---------------------------------------------------------------------------
# npm itself, before the two `npm ci` runs below.
npm install -g "npm@${NPM_VERSION}" --no-audit --no-fund --loglevel=error

# TRANSITIONAL (reason at NPM_VERSION): delete once those trees approve their
# own install scripts.
npm config set dangerously-allow-all-scripts true --location=global

# ---------------------------------------------------------------------------
# oxlint toolchain (`ckeslint`), installed beside the baseline .oxlintrc.json so
# its `jsPlugins` resolve; pinned by the committed lockfile.
OXLINT_DIR="${TOOLBELT}/oxlint"
npm ci --prefix "${OXLINT_DIR}" --no-audit --no-fund --loglevel=error

# Without a platform tsgolint binary, ckeslint silently loses its type-aware rules.
ls "${OXLINT_DIR}"/node_modules/@oxlint-tsgolint/*/tsgolint >/dev/null

# ckeslint links @types/node into a repo so `process.env` is typed; without it
# the type-aware rules report no-unsafe-* findings.
ls "${OXLINT_DIR}"/node_modules/@types/node/package.json >/dev/null

# oxfmt toolchain (JS half of `ckfmt`), installed like oxlint.
OXFMT_DIR="${TOOLBELT}/oxfmt"
npm ci --prefix "${OXFMT_DIR}" --no-audit --no-fund --loglevel=error

# ---------------------------------------------------------------------------
# The /opt/civikitchen-* and profile links are deprecated v1 paths; ckconform
# warns about them and v2 drops them.
ln -s "${TOOLBELT}"/bin/ck* /usr/local/bin/
# phpstan-root was the phpstan install root; its vendor/ is the root one now.
ln -s .. "${TOOLBELT}/phpstan-root"
for link in ckconform:ckconform rector:rector phpstan:phpstan-root \
    phpstan-ext:phpstan phpstan-config:phpstan-config psalm:psalm oxlint:oxlint \
    oxfmt:oxfmt mago:mago composer-deps.php:lib/composer-deps.php; do
    ln -s "${TOOLBELT}/${link#*:}" "/opt/civikitchen-${link%%:*}"
done
mkdir -p /usr/local/share/civikitchen
ln -s "$(dirname "${TOOLBELT}")/docker/profiles" /usr/local/share/civikitchen/profiles

rm -rf "${COMPOSER_HOME}" ~/.npm
chmod -R a+rX "${CK_ROOT}/vendor" "${TOOLBELT}" "${INFECTION_DIR}" /usr/local/bin/mago
