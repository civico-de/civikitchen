# CiviCRM buildkit image for the CMS flavors, with a civibuild site baked in;
# DEFAULT_SITE_TYPE picks the flavor. Needs an external MariaDB. Build context
# is the repo root; build and run recipes are in docs/building.md.

ARG PHP_VERSION=8.3
# Local-build fallback only: CI resolves the current stable from
# latest.civicrm.org and passes it as --build-arg CIVICRM_VERSION.
ARG CIVICRM_VERSION=6.15.1
ARG DEFAULT_SITE_TYPE=drupal10-demo

# =============================================================================
# Stage 1: Base system with PHP, Apache, and tools
# =============================================================================
# Bookworm, not trixie: trixie's mariadb-client 11.8 requires TLS and fails
# against cert-less dev MariaDB sidecars ("ERROR 2026 ... SSL is required").
FROM php:${PHP_VERSION}-apache-bookworm AS base

ARG DEBIAN_FRONTEND=noninteractive

# OS packages, Node 24 and the PHP extensions; pcov is loaded but disabled
# (ckcoverage enables it), xdebug is enabled by the entrypoint on XDEBUG_MODE.
# -dev headers are purged in the same layer, keeping the libraries the .so link.
RUN apt-get update && apt-get upgrade -y --no-install-recommends \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip zip rsync patch acl bzip2 jq \
        default-mysql-client \
    && curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && savedAptMark="$(apt-mark showmanual)" \
    && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev \
        libicu-dev libxml2-dev libzip-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mysqli gd intl zip bcmath soap opcache \
    && pecl install pcov xdebug \
    && docker-php-ext-enable pcov \
    && echo 'pcov.enabled=0' >> "$PHP_INI_DIR/conf.d/docker-php-ext-pcov.ini" \
    && rm -rf /tmp/pear \
    && apt-mark auto '.*' > /dev/null \
    && apt-mark manual $savedAptMark > /dev/null \
    && find /usr/local/lib/php/extensions -type f -name '*.so' -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); print "*" so }' \
        | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u \
        | xargs -r apt-mark manual > /dev/null \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && a2enmod rewrite ssl headers \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Dev tools, shared with the standalone image. Overriding a *_VERSION needs its
# *_SHA256 too; an unset ARG keeps the install script's default.
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
# The toolbelt as in a checkout, minus the standalone-only cktestreset,
# ckcoretest and ckboot.
COPY toolbelt /opt/civikitchen/toolbelt
# Optional composer_auth secret (COMPOSER_AUTH JSON) against GitHub rate limits.
RUN --mount=type=secret,id=composer_auth,env=COMPOSER_AUTH rm /opt/civikitchen/toolbelt/bin/cktestreset /opt/civikitchen/toolbelt/bin/ckcoretest \
        /opt/civikitchen/toolbelt/bin/ckboot \
    && bash /opt/civikitchen/toolbelt/install-dev-tools.sh

# PHP runtime tuning — matches what civibuild expects for site builds.
RUN { \
        echo 'upload_max_filesize = 64M'; \
        echo 'post_max_size = 64M'; \
        echo 'memory_limit = 512M'; \
        echo 'max_execution_time = 300'; \
    } > /usr/local/etc/php/conf.d/civikitchen.ini

# Joomla CLI bootstrap shim, auto-loaded by the site settings; inert on other
# flavors.
COPY docker/buildkit/joomla-cli-bootstrap.php /etc/civicrm.settings.d/post.d/000-joomla-cli-bootstrap.php
# Headless (CIVICRM_UF=UnitTests) boots cache in the test DB whatever cache
# class the site settings pick (see the file header). pre.d loads before them.
COPY docker/buildkit/test-db-cache.php /etc/civicrm.settings.d/pre.d/000-civikitchen-test-db-cache.php

# Joomla finish-install helpers for both bake.sh and the runtime reinstall;
# inert on other flavors.
COPY docker/buildkit/joomla-finish.sh /usr/local/share/civikitchen/joomla-finish.sh
COPY docker/buildkit/joomla-enable-plugins.php /usr/local/share/civikitchen/joomla-enable-plugins.php
COPY docker/buildkit/ext/ckjoomlaidentity /usr/local/share/civikitchen/ext/ckjoomlaidentity

# civibuild site types upstream lacks (drupal11-demo); bake.sh installs them.
COPY docker/buildkit/site-types /usr/local/share/civikitchen/site-types

# The scenario CLI's schema and loader; its YAML parser is in the root vendor/.
COPY packages/civikitchen-scenario-schema /opt/civikitchen/packages/civikitchen-scenario-schema

# Apache runs as buildkit, the owner of the site tree, so Civi's runtime cache
# writes succeed.
ENV APACHE_RUN_USER=buildkit \
    APACHE_RUN_GROUP=buildkit
# No MultiViews: Backdrop keeps civicrm.settings.php in the web root, and content
# negotiation would serve /civicrm/<path> from it instead of the CMS front controller.
# PHP warnings stay out of the pages: on a localhost URL core's Smarty reports E_ALL,
# and WordPress and Joomla leave display_errors on. A CMS debug mode still turns it on.
RUN useradd -m -s /bin/bash buildkit \
    && chown -R buildkit:buildkit /var/log/apache2 /var/run/apache2 \
    && printf '<VirtualHost *:80>\n\
    ServerName localhost\n\
    DocumentRoot /home/buildkit/buildkit/build/site/web\n\
    <Directory /home/buildkit/buildkit/build/site/web>\n\
        Options Indexes FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
        php_flag display_errors off\n\
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

# Clone buildkit and download its tools, retried against transient 5xx.
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
# civibuild against a throwaway MariaDB; the dev image keeps the codebase but
# not the data, and runs a `civibuild reinstall` (~60s) at first boot.
FROM buildkit-setup AS builder

ARG CIVICRM_VERSION
ARG CIVICRM_BUILD_VERSION
ARG DEFAULT_SITE_TYPE
# KEEP_GIT=1 keeps the ~550MB of git history civibuild clones into the site.
ARG KEEP_GIT=0

# procps provides pgrep, which bake.sh uses to verify the clean DB shutdown.
USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends mariadb-server procps \
    && rm -rf /var/lib/apt/lists/*

# Bake the site.
COPY docker/buildkit/bake.sh /tmp/bake.sh
RUN CIVICRM_VERSION="${CIVICRM_VERSION}" CIVICRM_BUILD_VERSION="${CIVICRM_BUILD_VERSION}" \
        DEFAULT_SITE_TYPE="${DEFAULT_SITE_TYPE}" \
        KEEP_GIT="${KEEP_GIT}" \
        bash /tmp/bake.sh && rm -rf /tmp/bake.sh

# =============================================================================
# Stage 4: Demo image — single-container, embedded MariaDB, baked demo data
# =============================================================================
# Keeps the builder's baked database and boots it with an embedded MariaDB.
# Built with `--target demo`; profiles apply at first boot, not at build.
FROM builder AS demo

ARG DEFAULT_SITE_TYPE
ARG CIVICRM_VERSION
# Which CiviCRM the image bakes, readable via `docker inspect` without booting.
LABEL org.opencontainers.image.version="${CIVICRM_VERSION}"
ENV PATH="/home/buildkit/buildkit/bin:${PATH}" \
    CIVIBUILD_ADMIN_PASS="admin" \
    CIVICRM_SITE_TYPE_DEFAULT="${DEFAULT_SITE_TYPE}"

# The demo stays root: its entrypoint starts the embedded MariaDB service.
USER root

# Standalone answers an anonymous `/` with 403; send it to the login form.
RUN if [ "${DEFAULT_SITE_TYPE}" = "standalone-demo" ]; then \
        echo 'RedirectMatch 302 ^/$ /civicrm/login' \
            > /etc/apache2/conf-available/civikitchen-demo.conf \
        && a2enconf civikitchen-demo; \
    fi

# Shared xdebug toggle and provisioning library, as in the dev images.
COPY docker/runtime/xdebug-toggle.sh /usr/local/share/civikitchen/xdebug-toggle.sh
COPY docker/runtime/provision.sh /usr/local/share/civikitchen/provision.sh
COPY docker/buildkit/entrypoint-common.sh /usr/local/share/civikitchen/entrypoint-common.sh
COPY docker/profiles /opt/civikitchen/docker/profiles
COPY packages/civicrm-profile-schema /opt/civikitchen/packages/civicrm-profile-schema

COPY docker/buildkit/entrypoint-demo.sh /home/buildkit/entrypoint.sh
RUN chmod +x /home/buildkit/entrypoint.sh

EXPOSE 80

# The marker is written before Apache starts, so Apache is asked too. A profile
# apply on first boot takes minutes, hence the start period.
HEALTHCHECK --interval=10s --timeout=5s --start-period=600s --retries=18 \
    CMD test -f /home/buildkit/.civikitchen-provisioned \
        && curl -fsS -o /dev/null http://localhost/ || exit 1

ENTRYPOINT ["/home/buildkit/entrypoint.sh"]

# =============================================================================
# Stage 5: Final dev image — copies the baked site, NO embedded DB (default)
# =============================================================================
# The last stage, so a plain `docker build` builds it. FROM base: the COPY below
# brings all of /home/buildkit, and buildkit-setup's ~200MB layer would ship shadowed.
FROM base AS final

ARG DEFAULT_SITE_TYPE
ARG CIVICRM_VERSION
# Which CiviCRM the image bakes, readable via `docker inspect` without booting.
LABEL org.opencontainers.image.version="${CIVICRM_VERSION}"
ENV PATH="/home/buildkit/buildkit/bin:${PATH}" \
    CIVIBUILD_ADMIN_PASS="admin" \
    CIVICRM_SITE_TYPE_DEFAULT="${DEFAULT_SITE_TYPE}"

USER root
# Parity with the buildkit-setup-derived stages (base leaves /var/www/html).
WORKDIR /home/buildkit

# The baked site and civibuild/amp registry, without the database. The baked
# ~/.my.cnf points at the build DB; the entrypoint rewrites it.
COPY --from=builder --chown=buildkit:buildkit /home/buildkit /home/buildkit

# ckcompat and ckanalyze read core at /var/www/html/core, as in the standalone
# image; cv only finds it once the site's database is up.
RUN core="$(find /home/buildkit/buildkit/build/site -name Civi.php -printf '%h\n')" \
    && test "$(printf '%s\n' "${core}" | wc -l)" -eq 1 && test -n "${core}" \
    && ln -s "${core}" /var/www/html/core

# Shared xdebug toggle and provisioning library, as in the standalone image.
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

# The marker is written before Apache starts, so Apache is asked too. A profile
# apply on first boot takes minutes, hence the start period.
HEALTHCHECK --interval=10s --timeout=5s --start-period=600s --retries=18 \
    CMD test -f /home/buildkit/.civikitchen-provisioned \
        && curl -fsS -o /dev/null http://localhost/ || exit 1

# A *.sh init hook runs as this user and installs extra tools into its ~/.local/bin.
ENV PATH="${PATH}:/home/buildkit/.local/bin"
USER buildkit
ENTRYPOINT ["/home/buildkit/entrypoint.sh"]
