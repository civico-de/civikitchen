# CiviCRM Buildkit Development Image
#
# Used for Drupal 10, Drupal 11, WordPress, and Joomla dev images — the only
# difference is the default civibuild site type, set via the DEFAULT_SITE_TYPE
# build arg.
# Pre-built CiviCRM site via buildkit for instant startup on first run.
# Requires external MariaDB (not embedded).
#
# Base image: php:${PHP_VERSION}-apache (same family as standalone, which
# inherits from civicrm/civicrm → php:apache). Keeps PHP-config paths and
# extension-install flow consistent across both images.
#
# Build context: the repo root, so this can COPY from both toolbelt/ (what gets
# baked in) and docker/ (this image's own runtime files). See docs/building.md.
#
# Build (all four flavors, and the run recipe: docs/building.md):
#   docker build -f docker/buildkit/Dockerfile \
#                --build-arg DEFAULT_SITE_TYPE=drupal10-demo \
#                -t civikitchen:drupal10 .
#
# Run (needs MariaDB):
#   docker run -d -p 8080:80 \
#     -e CIVICRM_DB_HOST=db -e CIVICRM_DB_ROOT_PASSWORD=root \
#     civikitchen:drupal10

ARG PHP_VERSION=8.3
# Local-build fallback only: CI resolves the current stable from
# latest.civicrm.org and passes it as --build-arg CIVICRM_VERSION.
ARG CIVICRM_VERSION=6.15.1
ARG DEFAULT_SITE_TYPE=drupal10-demo

# =============================================================================
# Stage 1: Base system with PHP, Apache, and tools
# =============================================================================
# Pin to -bookworm (Debian 12) explicitly. Trixie (Debian 13, the rolling
# default) ships mariadb-client 11.8 which enforces TLS by default and breaks
# every dev MariaDB sidecar that doesn't have a cert configured (drush, amp,
# civibuild all fail with "ERROR 2026 ... SSL is required, but the server
# does not support it"). Bookworm's mariadb-client is 10.11, the same as the
# standalone image's base. Revisit when MariaDB 11.4+ servers are the norm.
FROM php:${PHP_VERSION}-apache-bookworm AS base

ARG DEBIAN_FRONTEND=noninteractive

# OS-level deps. The php:apache base ships PHP + Apache; we add:
# - git/unzip/zip: composer source/dist installs
# - rsync/patch/acl/bzip2/jq: civibuild and its site templates use these
# - default-mysql-client: civibuild + entrypoint DB probe
# - lib*-dev: headers needed by docker-php-ext-install for gd/intl/zip/soap
# - nodejs: extensions with Angular/JS assets, and civicrm-buildkit's own
#   civi-download-tools. 24.x is the current LTS line; npm is upgraded past
#   what the distro package ships in install-dev-tools.sh (NPM_VERSION).
# apt-get upgrade: security updates come from Debian at build time; the base
# image ships none. The daily rebuild runs without cache, so they land.
RUN apt-get update && apt-get upgrade -y --no-install-recommends \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip zip rsync patch acl bzip2 jq \
        default-mysql-client \
        libpng-dev libjpeg-dev libfreetype6-dev \
        libicu-dev libxml2-dev libzip-dev libonig-dev \
    && curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mysqli gd intl zip bcmath soap opcache \
    && a2enmod rewrite ssl headers \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# pcov: fast code-coverage driver (always on). xdebug: installed but disabled
# — entrypoint enables it when XDEBUG_MODE is set. Same approach as standalone.
RUN pecl install pcov xdebug \
    && docker-php-ext-enable pcov

# Shared dev-tools install (composer, civix, phpunit, phpstan, mago,
# rector/ckmodernize, the ckdeps analyser).
# Lives in toolbelt/ so the standalone image uses the same script.
# Composer-installable tools are pinned in committed lockfiles (toolbelt/*-root,
# toolbelt/rector, toolbelt/psalm); what is left below is what needs a phar,
# a binary release or a git ref, and those stay overridable per --build-arg.
ENV PATH="/opt/composer/vendor/bin:${PATH}"
# Overriding a phar *_VERSION requires overriding its *_SHA256 too — the
# install script fails a mismatch on purpose.
ARG CIVIX_VERSION
ARG CIVIX_SHA256
ARG PHPUNIT_VERSION
ARG PHPUNIT_SHA256
ARG MAGO_VERSION
ARG MAGO_SHA256_X86_64
ARG MAGO_SHA256_AARCH64
ARG NPM_VERSION
ARG INFECTION_VERSION
# The ckdeps installation root IS composer's home dir — `composer global
# install` in install-dev-tools.sh reads exactly these two files.
COPY toolbelt/ckdeps-root /opt/composer
# The toolbelt, laid out as in a checkout, as in the standalone image — minus
# the standalone-only helpers: cktestreset reseeds the standalone scratch DB,
# ckcoretest runs core suites against its dist core, ckboot provisions the
# standalone site inside a CI job container.
COPY toolbelt /opt/civikitchen/toolbelt
# composer_auth is an optional BuildKit secret holding a COMPOSER_AUTH JSON. CI passes
# its job token so GitHub dist downloads are not rate-limited; local builds run without.
RUN --mount=type=secret,id=composer_auth,env=COMPOSER_AUTH rm /opt/civikitchen/toolbelt/bin/cktestreset /opt/civikitchen/toolbelt/bin/ckcoretest \
        /opt/civikitchen/toolbelt/bin/ckboot \
    && CIVIX_VERSION="${CIVIX_VERSION}" CIVIX_SHA256="${CIVIX_SHA256}" \
        PHPUNIT_VERSION="${PHPUNIT_VERSION}" PHPUNIT_SHA256="${PHPUNIT_SHA256}" \
        INFECTION_VERSION="${INFECTION_VERSION}" \
        MAGO_VERSION="${MAGO_VERSION}" MAGO_SHA256_X86_64="${MAGO_SHA256_X86_64}" \
        MAGO_SHA256_AARCH64="${MAGO_SHA256_AARCH64}" \
        bash /opt/civikitchen/toolbelt/install-dev-tools.sh

# PHP runtime tuning — matches what civibuild expects for site builds.
RUN { \
        echo 'upload_max_filesize = 64M'; \
        echo 'post_max_size = 64M'; \
        echo 'memory_limit = 512M'; \
        echo 'max_execution_time = 300'; \
    } > /usr/local/etc/php/conf.d/civikitchen.ini

# Joomla CLI bootstrap shim (see the file header). Inert on every other flavor
# (CLI + CIVICRM_UF==Joomla guarded), so it ships on all buildkit images. The
# civibuild site settings auto-load /etc/civicrm.settings.d/post.d/*.php, which
# is what makes cv/civibuild/phpunit able to boot CiviCRM on Joomla at all —
# during the build-time bake (builder stage) and at runtime (demo + final).
COPY docker/buildkit/joomla-cli-bootstrap.php /etc/civicrm.settings.d/post.d/000-joomla-cli-bootstrap.php
# Headless (CIVICRM_UF=UnitTests) boots cache in the test DB whatever cache
# class the site settings pick (see the file header). pre.d loads before them.
COPY docker/buildkit/test-db-cache.php /etc/civicrm.settings.d/pre.d/000-civikitchen-test-db-cache.php

# Joomla "finish install" helpers, shipped in the shared dir so the SAME script
# runs both at build time (bake.sh, builder stage) and at runtime (entrypoint.sh,
# after the dev image's first-boot `civibuild reinstall`). Putting them in the
# `base` stage makes them present in builder (bake), demo, and final (dev) alike.
# Inert on non-Joomla flavors (joomla-finish.sh self-guards on CIVICRM_UF).
COPY docker/buildkit/joomla-finish.sh /usr/local/share/civikitchen/joomla-finish.sh
COPY docker/buildkit/joomla-enable-plugins.php /usr/local/share/civikitchen/joomla-enable-plugins.php
COPY docker/buildkit/ext/ckjoomlaidentity /usr/local/share/civikitchen/ext/ckjoomlaidentity

# CiviKitchen-provided civibuild site types upstream buildkit lacks (currently
# drupal11-demo). bake.sh installs them into the buildkit clone's app/config
# before `civibuild create`; shipped in the base stage so the builder bake and
# any runtime `civibuild` invocation see the same definition.
COPY docker/buildkit/site-types /usr/local/share/civikitchen/site-types

# The scenario CLI parses the canonical civikitchen.yaml with Symfony's
# maintained YAML component. Install it once in the shared base so both final
# and demo targets inherit the exact locked parser without runtime downloads.
COPY packages/civikitchen-scenario-schema /opt/civikitchen/packages/civikitchen-scenario-schema
RUN --mount=type=secret,id=composer_auth,env=COMPOSER_AUTH composer install --no-dev --no-interaction --no-progress --classmap-authoritative \
    --working-dir=/opt/civikitchen/packages/civikitchen-scenario-schema \
    && composer clear-cache

# buildkit user owns the site tree so civibuild can write to it. Apache is
# reconfigured to run as buildkit so the served files match the writer's uid
# — otherwise Civi's runtime cache writes (templates_c, files/civicrm) from
# Apache fail with "does not have permission to write temp files".
#
# The php:apache envvars file uses POSIX default-assignment syntax
# (: ${APACHE_RUN_USER:=www-data}), so an `ENV` here wins over the default
# without the in-place sed dance Ubuntu's apache2 package required.
ENV APACHE_RUN_USER=buildkit \
    APACHE_RUN_GROUP=buildkit
RUN useradd -m -s /bin/bash buildkit \
    && chown -R buildkit:buildkit /var/log/apache2 /var/run/apache2 \
    && printf '<VirtualHost *:80>\n\
    ServerName localhost\n\
    DocumentRoot /home/buildkit/buildkit/build/site/web\n\
    <Directory /home/buildkit/buildkit/build/site/web>\n\
        Options Indexes FollowSymLinks MultiViews\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog /var/log/apache2/error.log\n\
    CustomLog /var/log/apache2/access.log combined\n\
</VirtualHost>\n' > /etc/apache2/sites-available/civicrm.conf \
    && a2dissite 000-default.conf \
    && a2ensite civicrm.conf

# =============================================================================
# Stage 2: Install buildkit
# =============================================================================
FROM base AS buildkit-setup

ARG CIVICRM_VERSION

USER buildkit
WORKDIR /home/buildkit

# Clone buildkit + download its tools (composer, drush, cv, node, ...). Both pull
# from github.com / CDNs with no built-in resilience, so a single transient 5xx
# would fail the whole image. Retry the network-heavy setup; --depth 1 trims
# the clone (its .git is dropped anyway) to shrink the failure surface.
# No composer_auth here or in the bake: buildkit's pinned composer predates the
# ghs_<id>_<jwt> token format, rejects it and prints the token.
RUN for attempt in 1 2 3; do \
        rm -rf buildkit; \
        if git clone --depth 1 https://github.com/civicrm/civicrm-buildkit.git buildkit \
            && rm -rf buildkit/.git \
            && ( cd buildkit && ./bin/civi-download-tools ); then \
            break; \
        fi; \
        if [ "$attempt" = 3 ]; then echo "Dockerfile: buildkit clone/download-tools failed after 3 attempts" >&2; exit 1; fi; \
        echo "Dockerfile: buildkit setup attempt $attempt failed (transient download?); retrying..." >&2; \
        sleep $((attempt * 15)); \
    done

ENV PATH="/home/buildkit/buildkit/bin:${PATH}"

# =============================================================================
# Stage 3: Bake the civibuild site at BUILD time (the slow part)
# =============================================================================
# civibuild downloads the CMS + CiviCRM (~3-4 min) and installs a site. We run
# it HERE against a THROWAWAY MariaDB started just for this layer, so the heavy
# codebase is baked into the image. The DB data (/var/lib/mysql) is NOT carried
# into the final image — at runtime the entrypoint does a fast `civibuild
# reinstall` (~60s) against the external DB instead of a full create.
FROM buildkit-setup AS builder

ARG CIVICRM_VERSION
ARG CIVICRM_BUILD_VERSION
ARG DEFAULT_SITE_TYPE
# KEEP_GIT=1 keeps the ~550MB of git history civibuild clones into the site
# (vendor/civicrm/civicrm-core etc.). Default strips it — the published images
# target extension development; build your own image to work on core.
ARG KEEP_GIT=0

# procps provides pgrep, which bake.sh uses to verify the clean DB shutdown.
USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends mariadb-server procps \
    && rm -rf /var/lib/apt/lists/*

# Bake the site (logic in buildkit/bake.sh to keep the shell quoting sane). Its
# joomla-demo path calls /usr/local/share/civikitchen/joomla-finish.sh, baked in
# the base stage above (shared with the runtime dev-image path).
COPY docker/buildkit/bake.sh /tmp/bake.sh
RUN CIVICRM_VERSION="${CIVICRM_VERSION}" CIVICRM_BUILD_VERSION="${CIVICRM_BUILD_VERSION}" \
        DEFAULT_SITE_TYPE="${DEFAULT_SITE_TYPE}" \
        KEEP_GIT="${KEEP_GIT}" \
        bash /tmp/bake.sh && rm -rf /tmp/bake.sh

# =============================================================================
# Stage 4: Demo image — single-container, embedded MariaDB, baked demo data
# =============================================================================
# Reuses the `builder` stage's baked civibuild site AND its populated
# /var/lib/mysql (which the dev `final` stage discards via a selective COPY).
# The demo therefore boots in seconds: start the embedded MariaDB on the baked
# data + Apache — no external DB, no `civibuild reinstall`. `docker run -p 80:80
# IMAGE` → a working CiviCRM with demo data. Built with `--target demo`.
#
# Profiles (extensions + seeds + API users, e.g. eu-ngo) are NOT baked — they
# apply at first boot via `-e CIVIKITCHEN_PROFILE=<name>` (see docker/profiles/
# and provision.sh's ck_apply_profile). That keeps one image per CMS flavor.
FROM builder AS demo

ARG DEFAULT_SITE_TYPE
ARG CIVICRM_VERSION
# Which CiviCRM the image bakes, readable via `docker inspect` without booting.
LABEL org.opencontainers.image.version="${CIVICRM_VERSION}"
ENV PATH="/opt/composer/vendor/bin:/home/buildkit/buildkit/bin:${PATH}" \
    CIVIBUILD_ADMIN_PASS="admin" \
    CIVICRM_SITE_TYPE_DEFAULT="${DEFAULT_SITE_TYPE}"

# The demo stays root: its entrypoint starts the embedded MariaDB service.
USER root

# Standalone serves CiviCRM directly: anonymous `/` (→ /civicrm/home) returns
# 403 under the standaloneusers extension, so bounce bare-/ to the login form
# (the same rule the standalone dev image ships). drupal10/wp serve their CMS
# front page at `/`, so only add the redirect for the standalone flavor.
RUN if [ "${DEFAULT_SITE_TYPE}" = "standalone-demo" ]; then \
        echo 'RedirectMatch 302 ^/$ /civicrm/login' \
            > /etc/apache2/conf-available/civikitchen-demo.conf \
        && a2enconf civikitchen-demo; \
    fi

# Shared xdebug toggle + provisioning library — same files the dev images use.
# The demo runs provision.sh too, so its opt-in knobs (SMTP, mounted-extension
# enabling, CIVIKITCHEN_PROFILE, /civikitchen-init.d hooks) work here as well.
COPY docker/runtime/xdebug-toggle.sh /usr/local/share/civikitchen/xdebug-toggle.sh
COPY docker/runtime/provision.sh /usr/local/share/civikitchen/provision.sh
COPY docker/buildkit/entrypoint-common.sh /usr/local/share/civikitchen/entrypoint-common.sh
COPY docker/profiles /opt/civikitchen/docker/profiles
COPY packages/civicrm-profile-schema /opt/civikitchen/packages/civicrm-profile-schema

COPY docker/buildkit/entrypoint-demo.sh /home/buildkit/entrypoint.sh
RUN chmod +x /home/buildkit/entrypoint.sh

EXPOSE 80

# The site + DB are baked, so a plain first boot is just "start embedded
# MariaDB + provisioning + Apache" — fast. With CIVIKITCHEN_PROFILE set, first
# boot also clones + enables + seeds the profile (minutes, needs network) —
# hence the generous start period. The entrypoint writes the provisioned
# marker before handing off to Apache, so the check also asks Apache for a non-error
# HTTP answer.
HEALTHCHECK --interval=10s --timeout=5s --start-period=600s --retries=18 \
    CMD test -f /home/buildkit/.civikitchen-provisioned \
        && curl -fsS -o /dev/null http://localhost/ || exit 1

ENTRYPOINT ["/home/buildkit/entrypoint.sh"]

# =============================================================================
# Stage 5: Final dev image — copies the baked site, NO embedded DB (default)
# =============================================================================
# This is the LAST stage, so a plain `docker build` (no --target) builds the dev
# image; the demo above is opt-in via `--target demo`.
#
# FROM base (not buildkit-setup) on purpose: the COPY --from=builder below
# brings the WHOLE of /home/buildkit (buildkit tools + baked site). Inheriting
# buildkit-setup would mean its ~200MB buildkit-clone layer ships shadowed
# underneath that COPY, inflating the image for nothing.
FROM base AS final

# /opt/composer/vendor/bin is prepended ahead of /home/buildkit/buildkit/bin
# so the toolbelt's pinned tools win over buildkit's own copies.
ARG DEFAULT_SITE_TYPE
ARG CIVICRM_VERSION
# Which CiviCRM the image bakes, readable via `docker inspect` without booting.
LABEL org.opencontainers.image.version="${CIVICRM_VERSION}"
ENV PATH="/opt/composer/vendor/bin:/home/buildkit/buildkit/bin:${PATH}" \
    CIVIBUILD_ADMIN_PASS="admin" \
    CIVICRM_SITE_TYPE_DEFAULT="${DEFAULT_SITE_TYPE}"

USER root
# Parity with the buildkit-setup-derived stages (base leaves /var/www/html).
WORKDIR /home/buildkit

# Bake: bring in the civibuild site tree + civibuild/amp registry built in the
# `builder` stage. This is the heavy part (CMS + CiviCRM codebase) — having it
# here means runtime only needs a fast `civibuild reinstall`. The builder's
# throwaway MariaDB data is left behind (not copied). The baked ~/.my.cnf points
# at the build-time localhost DB; the entrypoint overwrites it for the runtime
# external DB.
COPY --from=builder --chown=buildkit:buildkit /home/buildkit /home/buildkit

# ckcompat and ckanalyze read core at /var/www/html/core, as in the standalone
# image; cv only finds it once the site's database is up.
RUN core="$(find /home/buildkit/buildkit/build/site -name Civi.php -printf '%h\n')" \
    && test "$(printf '%s\n' "${core}" | wc -l)" -eq 1 && test -n "${core}" \
    && ln -s "${core}" /var/www/html/core

# Shared xdebug toggle + provisioning library — sourced from the entrypoint,
# also used by the standalone image so the two stay in lockstep. Profiles ship
# here too: CIVIKITCHEN_PROFILE works on the dev images as well (applied after
# the first-boot `civibuild reinstall`).
COPY docker/runtime/xdebug-toggle.sh /usr/local/share/civikitchen/xdebug-toggle.sh
COPY docker/runtime/provision.sh /usr/local/share/civikitchen/provision.sh
COPY docker/buildkit/entrypoint-common.sh /usr/local/share/civikitchen/entrypoint-common.sh
COPY docker/profiles /opt/civikitchen/docker/profiles
COPY packages/civicrm-profile-schema /opt/civikitchen/packages/civicrm-profile-schema

COPY docker/buildkit/entrypoint.sh /home/buildkit/entrypoint.sh
# The dev image runs as buildkit (USER below): Docker lets it bind port 80
# (ip_unprivileged_port_start=0), and the xdebug toggle's conf file is its own.
RUN chmod +x /home/buildkit/entrypoint.sh \
    && touch /usr/local/etc/php/conf.d/xdebug.ini \
    && chown buildkit:buildkit /usr/local/etc/php/conf.d/xdebug.ini

EXPOSE 80

# Readiness signal: the entrypoint writes this marker after the first-boot
# install + provisioning complete, before handing off to Apache — so the check
# also asks Apache for a non-error HTTP answer. The site
# codebase is baked, so first boot is a fast `civibuild reinstall` (~60s) +
# provisioning — but a CIVIKITCHEN_PROFILE apply on top (git clones + seeds)
# can take minutes, hence the generous start period.
HEALTHCHECK --interval=10s --timeout=5s --start-period=600s --retries=18 \
    CMD test -f /home/buildkit/.civikitchen-provisioned \
        && curl -fsS -o /dev/null http://localhost/ || exit 1

# A *.sh init hook runs as this user and installs extra tools into its ~/.local/bin.
ENV PATH="${PATH}:/home/buildkit/.local/bin"
USER buildkit
ENTRYPOINT ["/home/buildkit/entrypoint.sh"]
