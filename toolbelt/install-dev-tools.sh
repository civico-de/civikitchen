#!/bin/bash
# Install OS-agnostic CiviCRM extension dev tools used by both the standalone
# and buildkit images:
#   - composer (the phar the Makefile's suites run on too)
#   - civix    (CiviCRM extension scaffolding/build tool)
#   - phpunit  (pinned to 9 for CiviCRM compatibility)
#   - phpstan  (static analysis incl. the deprecation rules and
#              CiviKitchen's `@ck-legacy` deprecated-scope resolver)
#   - rector   (powers `ckmodernize` — rector's PHP-version + quality
#              sets plus CiviKitchen's CiviCRM footgun rules)
#   - psalm    (powers `cktaint` — used ONLY as a taint engine, with
#              CiviCRM source/sink/escape stubs)
#   - composer-dependency-analyser (powers `ckdeps`)
#     ... these four, the mago extension SDK and the YAML parser come from the
#     one root composer.json + lock beside toolbelt/
#   - infection (own root; powers `ckmutate` — mutation testing, opt-in floor)
#   - mago     (Rust binary; PHP half of `ckfmt`, the linter of `cklint`,
#              the engine of `ckanalyze` and `ckcompat`)
#   - oxlint   (npm toolchain + tsgolint Go binary; powers `ckeslint`)
#   - oxfmt    (npm toolchain; JS/TS half of `ckfmt`)
#
# Prerequisites (handled per image):
#   - php, curl, git on PATH
#   - composer.json + composer.lock copied beside toolbelt/
set -euo pipefail

# Pins with more than one consumer come from versions.env, which Make `include`s
# and bash sources natively — no parser on either side. Of the tool pins,
# composer, phpunit and mago are shared (the Makefile runs the tool test suites
# on the same releases this image bakes); everything else below has exactly one
# consumer and stays here, next to the comment that explains it.
#
# The image carries the toolbelt at /opt/civikitchen/toolbelt, laid out as in
# the checkout, so versions.env sits beside this script and the composer root
# one level up.
# The CK_ prefix is why a --build-arg still wins — the file's values land in
# CK_*, and the ${VAR:-...} defaults below only fall back to them.
TOOLBELT="$(cd "$(dirname "$0")" && pwd)"
CK_ROOT="$(dirname "${TOOLBELT}")"
# shellcheck source=versions.env
. "${TOOLBELT}/versions.env"

# ---------------------------------------------------------------------------
# Pinned tool versions — bump deliberately here. The image tags are floating
# (standalone-6.12 rebuilds monthly for CiviCRM patches); without pins the dev
# tools would drift too, so a rebuild could silently bump phpstan to a new
# major and turn a green `phpstan analyse` red with no code change.
#
# Everything composer can install is pinned in the committed root composer.json
# + composer.lock instead of here; docs/building.md explains each pin.
# A lock beats a variable: it fixes the transitive tree too, and it is a
# reviewable diff rather than a resolution that happens at build time. What
# stays below is what composer cannot pin — phars, a binary release, a git ref
# — plus infection, which has to follow the image's PHP version (see below).
# download.civicrm.org also serves versioned civix phars next to the floating
# civix.phar, so the scaffolding tool can be pinned like everything else.
CIVIX_VERSION="${CIVIX_VERSION:-26.02.0}"
# phpunit 9 is the last line CiviCRM's test base supports; pin the patch too.
# From versions.env — the Makefile runs the tool test suites on this same phar.
PHPUNIT_VERSION="${PHPUNIT_VERSION:-$CK_PHPUNIT_VERSION}"
# infection powers `ckmutate` (nightly mutation testing, never a push gate).
# Applied as a CEILING (see the require below), because images for older
# CiviCRM lines run on PHP 8.1/8.2 and recent infection rejects those. The
# ceiling still holds the SCORE: an engine that gained mutators would push a
# repo through its civikitchen.yaml floor with no code change.
INFECTION_VERSION="${INFECTION_VERSION:-0.34.1}"
# mago powers the PHP half of `ckfmt` and cklint's mago stage. From
# versions.env — the Makefile runs the mago extension's fixtures on the same
# release, and the extension SDK pins it too.
MAGO_VERSION="${MAGO_VERSION:-$CK_MAGO_VERSION}"
# npm bundles its own dependency tree (tar, glob, minimatch, sigstore, ...) and
# that tree lands in the image scan; nothing in this repo's lockfiles can move
# it, so npm gets installed over the distro package's own — and pinned, like
# every other tool here, because an unpinned `npm@latest` would drift the
# resolver under two committed lockfiles on every monthly rebuild.
NPM_VERSION="${NPM_VERSION:-12.2.1}"

# ---------------------------------------------------------------------------
# Phars: composer, civix, phpunit. (phpstan is NOT a phar: it is
# composer-installed below so extensions can register, and its integrity is
# composer's dist checksum — a phar fetched here would be overwritten by that
# wrapper and its hash check would guard nothing.)
#
# A pinned version only pins what the server chooses to serve — over TLS or
# not, an unverified phar is arbitrary code entering every image. The hashes
# below are the actual pin. Re-derive them after a version bump with:
#
#   curl -LsS <url> | shasum -a 256      # or sha256sum on Linux
#
# A mismatch is fatal on purpose: it means the artifact behind a version that
# is supposed to be immutable changed. Overriding a *_VERSION at build time
# therefore means overriding its *_SHA256 as well.
CIVIX_SHA256="${CIVIX_SHA256:-1c480133bee248c1f09f19c724e2e44266297b63ff2e55a7cbf3ea17a910d906}"
PHPUNIT_SHA256="${PHPUNIT_SHA256:-$CK_PHPUNIT_SHA256}"
MAGO_SHA256_X86_64="${MAGO_SHA256_X86_64:-$CK_MAGO_SHA256_linux_x86_64}"
MAGO_SHA256_AARCH64="${MAGO_SHA256_AARCH64:-$CK_MAGO_SHA256_linux_aarch64}"

# Compared in the shell rather than with `sha256sum -c`: that exits 0 on a
# malformed checksum line, so an empty or truncated *_SHA256 would wave the
# download straight through.
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

# From versions.env, with no build argument: the standalone image's git source
# stage and the Makefile's lockfile gate run the same release.
fetch_phar "https://getcomposer.org/download/${CK_COMPOSER_VERSION}/composer.phar" \
    /usr/local/bin/composer "${CK_COMPOSER_SHA256}"

fetch_phar "https://download.civicrm.org/civix/civix-${CIVIX_VERSION}.phar" \
    /usr/local/bin/civix "${CIVIX_SHA256}"

fetch_phar "https://phar.phpunit.de/phpunit-${PHPUNIT_VERSION}.phar" \
    /usr/local/bin/phpunit "${PHPUNIT_SHA256}"

# ---------------------------------------------------------------------------
# mago (PHP half of `ckfmt`) — a prebuilt Rust binary per architecture, same
# verify-then-trust treatment as the phars. gnu build, not musl: these are
# Debian-based images.
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
# The composer root: phpstan with the extensions every image ships, rector,
# psalm, the mago extension SDK, the ckdeps analyser and the YAML parser, all
# from the committed lock. The CiviKitchen phpstan extension (toolbelt/phpstan)
# joins as a path repository, so extension-installer registers it like the
# upstream rules — for every project this phpstan analyses, without a neon line.
#
# COMPOSER_HOME is scoped to this script so it doesn't leak into the runtime
# image — users who run `composer install` inside a container later
# shouldn't be steered at its cache.
export COMPOSER_HOME=/tmp/civikitchen-composer
export COMPOSER_ALLOW_SUPERUSER=1
composer install --working-dir="${CK_ROOT}" --no-interaction --no-progress

# Through composer's bin proxies, the documented entry point of a composer
# install. Wrappers rather than vendor/bin on PATH, which would expose every
# transitive binary too.
for tool in phpstan composer-dependency-analyser; do
    cat > "/usr/local/bin/${tool}" <<EOF
#!/bin/sh
exec php ${CK_ROOT}/vendor/bin/${tool} "\$@"
EOF
    chmod +x "/usr/local/bin/${tool}"
done

# ---------------------------------------------------------------------------
# infection (its own root) — powers `ckmutate`. It follows the image's PHP
# version (see the require below), which the one root lock cannot express.
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

# TRANSITIONAL: npm 12 skips a dependency's install scripts unless the project
# approved them (measured — under npm 10/11 a postinstall runs, under 12.0.2 it
# does not). CiviCRM core's package.json runs tools/scripts/npm/postinstall.sh,
# buildkit's civi-download-tools installs a set old enough to carry install
# scripts, and extension frontends lean on esbuild/playwright postinstalls —
# none of those trees has an `allowScripts` block yet, so the default leaves
# every one of them half-installed behind a warning.
# Deliberate opt-out of a real protection, with a real exit: delete this line
# once those trees approve their own scripts.
npm config set dangerously-allow-all-scripts true --location=global

# ---------------------------------------------------------------------------
# oxlint toolchain (powers `ckeslint`) — installed into the directory of the
# baseline .oxlintrc.json, so the config's `jsPlugins`
# resolve against the node_modules right beside it.
#
# In the IMAGE and not in a dozen package.json files, for the same reason the
# mago lint baseline lives here: an extension's frontend is a handful of files, and
# a linter devDependency block per repo is a dozen trees to keep in step and a
# dozen dependabot PRs a month for a linter nobody chose per-repo. A repo that
# wants its own rules writes an .oxlintrc.json and ckeslint steps aside.
#
# Versions are pinned in that package.json, not floated with a caret, and the
# lockfile beside it is committed and installed with `npm ci`: the image tags
# rebuild monthly, and a linter minor that adds a rule to a category — or a
# transitive dependency moving underneath a pinned direct one — would turn a
# green repo red with no code change. Exactly what the phpstan pin above
# prevents on the PHP side, and the same rule this project's own standards put
# on every extension's lockfile.
OXLINT_DIR="${TOOLBELT}/oxlint"
npm ci --prefix "${OXLINT_DIR}" --no-audit --no-fund --loglevel=error

# The type-aware half is a Go binary in a platform-specific subpackage; if npm
# resolved none of them, ckeslint would silently lose every type-aware rule.
ls "${OXLINT_DIR}"/node_modules/@oxlint-tsgolint/*/tsgolint >/dev/null

# @types/node is not a linter dependency but the payload ckeslint links into a
# repo for the run, so an e2e suite's `process.env` is typed without the repo
# installing anything. Missing, the type-aware rules would bury it in
# no-unsafe-* findings.
ls "${OXLINT_DIR}"/node_modules/@types/node/package.json >/dev/null

# oxfmt toolchain (JS half of `ckfmt`) — same shape as the oxlint install:
# pinned in its package.json, resolved from its committed lockfile. The npm
# package selects the platform binding itself via optionalDependencies.
OXFMT_DIR="${TOOLBELT}/oxfmt"
npm ci --prefix "${OXFMT_DIR}" --no-audit --no-fund --loglevel=error

# ---------------------------------------------------------------------------
# The ck* tools run from the toolbelt and are only linked into PATH. The
# /opt/civikitchen-* links and the profile directory link are the v1 paths repo
# configs may still name or mount into; ckconform's deprecated-image-path warns
# about them, and v2 drops them.
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
