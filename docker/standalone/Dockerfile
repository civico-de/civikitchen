# CiviCRM Standalone Development Image
# CiviCRM Standalone from the release tarball (or a civicrm-core branch, see
# docs/images.md) plus the dev tools (coverage, debug, testing, static
# analysis, linting).
#
# Self-built base, not FROM civicrm/civicrm: the Docker Hub image lags the
# release, while the tarball this installs is published with the release
# itself. The base block below mirrors what the official image provides
# (github.com/civicrm/civicrm-docker) — PHP extensions, Apache vhost, ini
# values; test-dev-tools.sh asserts the parity points.
#
# Build context: the repo root, so this can COPY from both toolbelt/ (what gets
# baked in) and docker/ (this image's own runtime files) without either having
# to live inside the other. See docs/building.md.
#
#   docker build -f docker/standalone/Dockerfile --build-arg CIVICRM_VERSION=<exact> .
#
# In your extension's .docker/compose.yaml:
#   services:
#     app:
#       image: ghcr.io/civico-de/civikitchen:standalone

# Same PHP the official image published (civicrm/civicrm:6.16 ships 8.4).
ARG PHP_VERSION=8.4
# Exact release, never a minor: this names a tarball. CI passes the resolved
# stable; the default here only serves a bare local `docker build` and rots.
# With CIVICRM_SOURCE=git it is a civicrm-core branch or tag instead (master).
ARG CIVICRM_VERSION=6.17.0
ARG CIVICRM_SOURCE=tarball

# --- fetch stage: the CiviCRM Standalone release tarball ---------------------
FROM php:${PHP_VERSION}-apache-bookworm AS download-tarball
ARG CIVICRM_VERSION
ARG CIVICRM_DOWNLOAD_URL="https://download.civicrm.org/civicrm-${CIVICRM_VERSION}-standalone.tar.gz"
# The release archive export-ignores core's headless-test seed SQL even though
# Civi\Test\Data reads it for every extension PHPUnit bootstrap. Restore the
# two exact-version fixtures so direct `phpunit` runs don't emit warnings and
# silently initialize without the standard seed rows.
RUN curl -fsSL "${CIVICRM_DOWNLOAD_URL}" -o /tmp/civicrm-standalone.tar.gz \
    && mkdir /tmp/civicrm \
    && tar -xzf /tmp/civicrm-standalone.tar.gz -C /tmp/civicrm \
    && test -f /tmp/civicrm/civicrm-standalone/civicrm.standalone.php \
    && for fixture in test_data.mysql test_data_second_domain.mysql; do \
         curl -fsSL \
           "https://raw.githubusercontent.com/civicrm/civicrm-core/${CIVICRM_VERSION}/sql/${fixture}" \
           -o "/tmp/civicrm/civicrm-standalone/core/sql/${fixture}"; \
       done \
    && echo "tarball ${CIVICRM_DOWNLOAD_URL}" > /tmp/civicrm-source.log

# --- fetch stage: the same layout built from a civicrm-core branch -----------
# What core's distmaker does for the tarball: core plus civicrm-packages of the
# same name, composer deps and bower assets, then the standalone scaffold. The
# git checkout keeps tests/ and the seed SQL. ADD resolves the branch on every
# build, so the layer cache never serves an old commit.
# PHP extensions are checked in the runtime stage, which installs them.
FROM php:${PHP_VERSION}-apache-bookworm AS download-git
ARG CIVICRM_VERSION
# The composer release install-dev-tools.sh bakes into the image.
COPY toolbelt/versions.env /tmp/versions.env
RUN . /tmp/versions.env \
    && apt-get update && apt-get install -y --no-install-recommends git unzip \
    && curl -fsSL --retry 3 --retry-all-errors -o /usr/local/bin/composer \
       "https://getcomposer.org/download/${CK_COMPOSER_VERSION}/composer.phar" \
    && echo "${CK_COMPOSER_SHA256}  /usr/local/bin/composer" | sha256sum -c --strict - \
    && chmod +x /usr/local/bin/composer
ADD --keep-git-dir=true "https://github.com/civicrm/civicrm-core.git#${CIVICRM_VERSION}" /tmp/civicrm/civicrm-standalone/core
ADD --keep-git-dir=true "https://github.com/civicrm/civicrm-packages.git#${CIVICRM_VERSION}" /tmp/civicrm/civicrm-standalone/core/packages
WORKDIR /tmp/civicrm/civicrm-standalone/core
# composer_auth is an optional BuildKit secret holding a COMPOSER_AUTH JSON. CI passes
# its job token so GitHub dist downloads are not rate-limited; local builds run without.
RUN --mount=type=secret,id=composer_auth,env=COMPOSER_AUTH for repo in . packages; do \
         echo "git ${repo} $(git -C "${repo}" rev-parse HEAD)" >> /tmp/civicrm-source.log \
         && rm -rf "${repo}/.git"; \
       done \
    && COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_COMPILE=1 \
       composer install --no-dev --no-interaction --no-progress --ignore-platform-req='ext-*' \
    && composer clear-cache \
    && php tools/standalone/bin/scaffold /tmp/civicrm/civicrm-standalone \
    && test -f /tmp/civicrm/civicrm-standalone/civicrm.standalone.php

# --- the selected source, with core patches ----------------------------------
FROM download-${CIVICRM_SOURCE} AS download
# Core bugs a dev stack must not reproduce, each with its upstream link, until
# the release carries the fix. The log lands in the image as core-patches.log.
# The script reads core's version.xml through the toolbelt's ck, in checkout layout.
COPY docker/standalone/core-patches /tmp/core-patches
COPY toolbelt/bin/ck /tmp/ck/toolbelt/bin/ck
COPY toolbelt/lib /tmp/ck/toolbelt/lib
COPY docker/standalone/apply-core-patches.sh /tmp/ck/docker/standalone/apply-core-patches.sh
RUN apt-get update && apt-get install -y --no-install-recommends patch \
    && bash /tmp/ck/docker/standalone/apply-core-patches.sh /tmp/civicrm/civicrm-standalone/core /tmp/core-patches \
       > /tmp/core-patches.log \
    && cat /tmp/core-patches.log

FROM php:${PHP_VERSION}-apache-bookworm

# OS-level deps:
# - libfreetype/libjpeg62-turbo/libpng/libwebp-dev: the PHP gd extension
# - libicu-dev: intl; libzip-dev: zip
# - imagemagick + libmagickwand-dev: the imagick extension (e.g. Mosaico)
# - mariadb-client: cv, ckschemadiff and poking the DB from inside the container
# - unzip/git: composer needs them for dist/source installs
# - nodejs/npm: required by extensions with Angular/JS assets. 24.x is the
#   current LTS line; npm is upgraded past what the distro package ships in
#   install-dev-tools.sh (NPM_VERSION), which keeps npm's own bundled
#   dependency tree out of the image scan.
# - jq: the profile driver (CIVIKITCHEN_PROFILE) parses profile.json with it
# - zstd: compresses and loads seed snapshots (`ck seed`)
# apt-get upgrade: security updates come from Debian at build time; the base
# image ships none. The daily rebuild runs without cache, so they land.
RUN apt-get update && apt-get upgrade -y --no-install-recommends \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip zip jq \
        libfreetype-dev libicu-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
        libzip-dev imagemagick libmagickwand-dev mariadb-client zstd \
    && curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# The extension set the official image shipped, plus soap and opcache to stay
# in lockstep with the buildkit base. gd with webp: Mosaico.
# pcov: fast code-coverage driver (PHPUnit's default coverage path).
# xdebug: installed but NOT enabled — entrypoint activates it when XDEBUG_MODE is set.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd bcmath intl mysqli pdo_mysql zip soap opcache \
    && pecl install imagick pcov xdebug \
    && docker-php-ext-enable imagick pcov

# cv, pinned phar + checksum (the official image took a floating cv.phar).
# Re-derive the hash on a bump from the release's SHA256SUMS file.
ARG CV_VERSION=0.3.71
ARG CV_SHA256=06794d7ea5ea7dc901ae9b2a1c14acd79c63979b73a00be9303990688e6f6e46
RUN curl -fsSLo /usr/local/bin/cv \
        "https://github.com/civicrm/cv/releases/download/v${CV_VERSION}/cv-${CV_VERSION}.phar" \
    && echo "${CV_SHA256}  /usr/local/bin/cv" | sha256sum -c --strict - \
    && chmod +x /usr/local/bin/cv

# Apache: same modules, vhost and ServerName the official image configured.
# civikitchen's own extras stay in civikitchen.conf further down.
RUN a2enmod rewrite remoteip
COPY docker/standalone/apache/civicrm.conf /etc/apache2/conf-available/civicrm.conf
COPY docker/standalone/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
RUN a2enconf civicrm

# php.ini values 1:1 with the official image, so the switch changes no
# behavior for the repos running CI in here.
COPY docker/standalone/php/civicrm.ini ${PHP_INI_DIR}/conf.d/civicrm.ini
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php-cli.ini" \
    && echo 'memory_limit = ${PHP_MEMORY_LIMIT:-"256M"}\n' >> "$PHP_INI_DIR/php.ini" \
    && echo 'memory_limit = -1\n' >> "$PHP_INI_DIR/php-cli.ini"

ENV CIVICRM_UF=Standalone

# Shared dev-tools install (composer, civix, phpunit, phpstan, mago,
# rector/ckmodernize, the ckdeps analyser).
# Lives in toolbelt/ so the buildkit image uses the same script.
# Composer-installable tools are pinned in the committed root composer.lock;
# what is left below is what needs a phar, a binary release or a git ref, and
# those stay overridable per --build-arg.
# Overriding a phar *_VERSION requires overriding its *_SHA256 too — the
# install script fails a mismatch on purpose. Each ARG reaches the script as an
# environment variable; one left unset keeps the script's default.
ARG CIVIX_VERSION
ARG CIVIX_SHA256
ARG PHPUNIT_VERSION
ARG PHPUNIT_SHA256
ARG MAGO_VERSION
ARG MAGO_SHA256_X86_64
ARG MAGO_SHA256_AARCH64
ARG NPM_VERSION
ARG INFECTION_VERSION
# The one composer root of the toolbelt; install-dev-tools.sh installs from it.
COPY composer.json composer.lock /opt/civikitchen/
# The toolbelt, laid out as in a checkout: every ck* tool resolves its library,
# configs and toolchains relative to its own real path, so nothing branches on
# "image or checkout". install-dev-tools.sh links the tools into PATH.
COPY toolbelt /opt/civikitchen/toolbelt
RUN --mount=type=secret,id=composer_auth,env=COMPOSER_AUTH bash /opt/civikitchen/toolbelt/install-dev-tools.sh

# CiviCRM itself, AFTER the dev tools: this is the layer a version bump
# invalidates, and everything above it is version-independent. The tarball's
# own layout provides every container path consumers rely on
# (/var/www/html/{ext,private,public}, civicrm.standalone.php).
ARG CIVICRM_VERSION
COPY --from=download --chown=www-data:www-data /tmp/civicrm/civicrm-standalone /var/www/html
COPY --from=download /tmp/core-patches.log /tmp/civicrm-source.log /usr/local/share/civikitchen/
# /var/lib/civikitchen/snapshots: the seed snapshot store; mount a host
# directory there to keep snapshots across stacks. CK_SEED_SNAPSHOTS marks the
# image that has the store, zstd and private/ (docker/profiles/apply.sh).
RUN chown www-data:www-data /var/www \
    && install -d -o www-data -g www-data /var/lib/civikitchen/snapshots
ENV CK_SEED_SNAPSHOTS=1
LABEL org.opencontainers.image.version="${CIVICRM_VERSION}"

# Add our vhost extras (e.g. the bare-/ → /civicrm/login redirect) via the
# standard apache conf-available + a2enconf flow.
COPY docker/standalone/apache/civikitchen.conf /etc/apache2/conf-available/civikitchen.conf
RUN a2enconf civikitchen

# The container runs as www-data (USER below), so `docker exec` and cv run as
# the web user too. Docker lets it bind port 80 (ip_unprivileged_port_start=0);
# the conf files the entrypoint rewrites are handed to www-data here.
RUN touch /usr/local/etc/php/conf.d/xdebug.ini /etc/apache2/conf-enabled/civikitchen-site-port.conf \
    && chown www-data:www-data /usr/local/etc/php/conf.d/xdebug.ini /etc/apache2/conf-enabled/civikitchen-site-port.conf

# Post-install demo-user script (run via `cv scr` from the entrypoint when
# CIVIKITCHEN_DEMO_USER is set). Lives outside the docroot so it's not web-served.
COPY docker/standalone/demo-user.php /usr/local/share/civikitchen/demo-user.php

# Shared xdebug toggle + provisioning library — sourced from the entrypoint,
# also used by the buildkit image so the two stay in lockstep.
COPY docker/runtime/xdebug-toggle.sh /usr/local/share/civikitchen/xdebug-toggle.sh
COPY docker/runtime/provision.sh /usr/local/share/civikitchen/provision.sh
COPY docker/runtime/patch-test-db-boot.php /usr/local/share/civikitchen/patch-test-db-boot.php

# Demo profiles (CIVIKITCHEN_PROFILE) — same set as the buildkit images;
# applied at first boot after the auto-install (needs an admin user, see
# docs/images.md).
COPY docker/profiles /opt/civikitchen/docker/profiles
COPY packages /opt/civikitchen/packages

COPY docker/standalone/entrypoint.sh /usr/local/bin/civikitchen-entrypoint
RUN chmod +x /usr/local/bin/civikitchen-entrypoint

# Readiness signal for the auto-install flow: the entrypoint writes this marker
# only after install + provisioning (extensions, init.d hooks) complete, then
# hands off to Apache. The marker alone is written before Apache listens, so
# the check also asks Apache for a non-error HTTP answer. Lets dependent services wait via
# `depends_on: { condition: service_healthy }` instead of racing a half-installed
# site. start-period covers a cold install plus enabling extensions that seed demo data
# (minutes); without CIVICRM_AUTO_INSTALL the marker is never written and the container
# just reports unhealthy (harmless).
HEALTHCHECK --interval=10s --timeout=5s --start-period=600s --retries=18 \
    CMD test -f /var/www/html/private/.civikitchen-provisioned \
        && curl -fsS -o /dev/null http://localhost/ || exit 1

# A *.sh init hook runs as this user and installs extra tools into its ~/.local/bin.
ENV PATH="${PATH}:/var/www/.local/bin"
USER www-data
ENTRYPOINT ["/usr/local/bin/civikitchen-entrypoint"]
CMD ["apache2-foreground"]
