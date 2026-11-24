# CiviCRM Standalone dev image: the release tarball (or a civicrm-core branch)
# plus the dev tools. The base mirrors the official civicrm/civicrm image;
# test-dev-tools.sh asserts the parity points. Build context: the repo root.

# Same PHP the official image published (civicrm/civicrm:6.16 ships 8.4).
ARG PHP_VERSION=8.4
# Exact release, never a minor: this names a tarball. CI passes the stable.
# With CIVICRM_SOURCE=git it is a civicrm-core branch or tag instead (master).
ARG CIVICRM_VERSION=6.17.0
ARG CIVICRM_SOURCE=tarball
# Git builds only: the civicrm-packages ref, when it differs from CIVICRM_VERSION.
ARG CIVICRM_PACKAGES_VERSION=""

# --- fetch stage: the CiviCRM Standalone release tarball ---------------------
FROM php:${PHP_VERSION}-apache-bookworm AS download-tarball
ARG CIVICRM_VERSION
ARG CIVICRM_DOWNLOAD_URL="https://download.civicrm.org/civicrm-${CIVICRM_VERSION}-standalone.tar.gz"
# The tarball omits the seed SQL Civi\Test\Data reads in every headless
# bootstrap; fetch the two files for the exact version.
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
# What core's distmaker does for the tarball. ADD resolves the branch on every
# build, so the layer cache never serves an old commit.
FROM php:${PHP_VERSION}-apache-bookworm AS download-git
ARG CIVICRM_VERSION
ARG CIVICRM_PACKAGES_VERSION
# The composer release install-dev-tools.sh bakes into the image.
COPY toolbelt/versions.env /tmp/versions.env
RUN . /tmp/versions.env \
    && apt-get update && apt-get install -y --no-install-recommends git unzip \
    && curl -fsSL --retry 3 --retry-all-errors -o /usr/local/bin/composer \
       "https://getcomposer.org/download/${CK_COMPOSER_VERSION}/composer.phar" \
    && echo "${CK_COMPOSER_SHA256}  /usr/local/bin/composer" | sha256sum -c --strict - \
    && chmod +x /usr/local/bin/composer
ADD --keep-git-dir=true "https://github.com/civicrm/civicrm-core.git#${CIVICRM_VERSION}" /tmp/civicrm/civicrm-standalone/core
ADD --keep-git-dir=true "https://github.com/civicrm/civicrm-packages.git#${CIVICRM_PACKAGES_VERSION:-${CIVICRM_VERSION}}" /tmp/civicrm/civicrm-standalone/core/packages
WORKDIR /tmp/civicrm/civicrm-standalone/core
# Optional composer_auth secret (COMPOSER_AUTH JSON) against GitHub rate limits.
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
# Core bug fixes until the release carries them, logged as core-patches.log.
COPY docker/standalone/core-patches /tmp/core-patches
COPY toolbelt/bin/ck /tmp/ck/toolbelt/bin/ck
COPY toolbelt/lib /tmp/ck/toolbelt/lib
COPY docker/standalone/apply-core-patches.sh /tmp/ck/docker/standalone/apply-core-patches.sh
RUN apt-get update && apt-get install -y --no-install-recommends patch \
    && bash /tmp/ck/docker/standalone/apply-core-patches.sh /tmp/civicrm/civicrm-standalone/core /tmp/core-patches \
       > /tmp/core-patches.log \
    && cat /tmp/core-patches.log

FROM php:${PHP_VERSION}-apache-bookworm

# OS packages, Node 24, and the locales core's CRM_Core_I18n_LocaleTest needs.
# pcov is loaded but disabled (enabled it costs ~30 % PHP CPU; ckcoverage turns
# it on); xdebug is enabled by the entrypoint on XDEBUG_MODE. -dev headers are
# purged in the same layer, keeping the libraries the .so files link against.
RUN apt-get update && apt-get upgrade -y --no-install-recommends \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip zip jq mariadb-client zstd locales \
    && printf '%s\n' 'en_US.UTF-8 UTF-8' 'tr_TR.UTF-8 UTF-8' > /etc/locale.gen \
    && locale-gen \
    && curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && savedAptMark="$(apt-mark showmanual)" \
    && apt-get install -y --no-install-recommends \
        libfreetype-dev libicu-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
        libzip-dev libmagickwand-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd bcmath intl mysqli pdo_mysql zip soap opcache \
    && pecl install imagick pcov xdebug \
    && docker-php-ext-enable imagick pcov \
    && echo 'pcov.enabled=0' >> "$PHP_INI_DIR/conf.d/docker-php-ext-pcov.ini" \
    && rm -rf /tmp/pear \
    && apt-mark auto '.*' > /dev/null \
    && apt-mark manual $savedAptMark > /dev/null \
    && find /usr/local/lib/php/extensions -type f -name '*.so' -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); print "*" so }' \
        | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u \
        | xargs -r apt-mark manual > /dev/null \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# cv, pinned phar; take the hash from the release's SHA256SUMS on a bump.
ARG CV_VERSION=0.3.71
ARG CV_SHA256=06794d7ea5ea7dc901ae9b2a1c14acd79c63979b73a00be9303990688e6f6e46
RUN curl -fsSLo /usr/local/bin/cv \
        "https://github.com/civicrm/cv/releases/download/v${CV_VERSION}/cv-${CV_VERSION}.phar" \
    && echo "${CV_SHA256}  /usr/local/bin/cv" | sha256sum -c --strict - \
    && chmod +x /usr/local/bin/cv

# Apache modules and vhost as in the official image.
RUN a2enmod rewrite remoteip
COPY docker/standalone/apache/civicrm.conf /etc/apache2/conf-available/civicrm.conf
COPY docker/standalone/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
RUN a2enconf civicrm

# php.ini values as in the official image.
COPY docker/standalone/php/civicrm.ini ${PHP_INI_DIR}/conf.d/civicrm.ini
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php-cli.ini" \
    && echo 'memory_limit = ${PHP_MEMORY_LIMIT:-"256M"}\n' >> "$PHP_INI_DIR/php.ini" \
    && echo 'memory_limit = -1\n' >> "$PHP_INI_DIR/php-cli.ini"

ENV CIVICRM_UF=Standalone

# Dev tools, shared with the buildkit image. Overriding a *_VERSION needs its
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
# The toolbelt as in a checkout; the tools resolve everything from their real path.
COPY toolbelt /opt/civikitchen/toolbelt
RUN --mount=type=secret,id=composer_auth,env=COMPOSER_AUTH bash /opt/civikitchen/toolbelt/install-dev-tools.sh

# CiviCRM after the dev tools, so a version bump invalidates only this layer.
ARG CIVICRM_VERSION
COPY --from=download --chown=www-data:www-data /tmp/civicrm/civicrm-standalone /var/www/html
COPY --from=download /tmp/core-patches.log /tmp/civicrm-source.log /usr/local/share/civikitchen/
# The composer inputs vendor/ was installed from; the entrypoint reinstalls when the mounted ones differ.
RUN cat /var/www/html/core/composer.json /var/www/html/core/composer.lock | sha256sum | cut -d' ' -f1 \
        > /var/www/html/core/vendor/.civikitchen-composer-inputs \
    && chown www-data:www-data /var/www/html/core/vendor/.civikitchen-composer-inputs
# The seed snapshot store; mount a host directory to keep snapshots across stacks.
RUN chown www-data:www-data /var/www \
    && install -d -o www-data -g www-data /var/lib/civikitchen/snapshots
ENV CK_SEED_SNAPSHOTS=1
LABEL org.opencontainers.image.version="${CIVICRM_VERSION}"

# civikitchen's vhost extras.
COPY docker/standalone/apache/civikitchen.conf /etc/apache2/conf-available/civikitchen.conf
RUN a2enconf civikitchen

# The container runs as www-data (USER below), so `docker exec` and cv run as
# the web user too. Docker lets it bind port 80 (ip_unprivileged_port_start=0);
# the conf files the entrypoint rewrites are handed to www-data here.
RUN touch /usr/local/etc/php/conf.d/xdebug.ini /etc/apache2/conf-enabled/civikitchen-site-port.conf \
    && chown www-data:www-data /usr/local/etc/php/conf.d/xdebug.ini /etc/apache2/conf-enabled/civikitchen-site-port.conf

# Demo-user script, outside the docroot.
COPY docker/standalone/demo-user.php /usr/local/share/civikitchen/demo-user.php

# Shared xdebug toggle and provisioning library, as in the buildkit image.
COPY docker/runtime/xdebug-toggle.sh /usr/local/share/civikitchen/xdebug-toggle.sh
COPY docker/runtime/provision.sh /usr/local/share/civikitchen/provision.sh
COPY docker/runtime/patch-test-db-boot.php /usr/local/share/civikitchen/patch-test-db-boot.php

# Profiles (CIVIKITCHEN_PROFILE), applied at first boot after the auto-install.
COPY docker/profiles /opt/civikitchen/docker/profiles
COPY packages /opt/civikitchen/packages

COPY docker/standalone/entrypoint.sh /usr/local/bin/civikitchen-entrypoint
RUN chmod +x /usr/local/bin/civikitchen-entrypoint

# The marker is written after provisioning but before Apache listens, so Apache
# is asked too. Without CIVICRM_AUTO_INSTALL the container stays unhealthy.
HEALTHCHECK --interval=10s --timeout=5s --start-period=600s --retries=18 \
    CMD test -f /var/www/html/private/.civikitchen-provisioned \
        && curl -fsS -o /dev/null http://localhost/ || exit 1

# A *.sh init hook runs as this user and installs extra tools into its ~/.local/bin.
ENV PATH="${PATH}:/var/www/.local/bin"
USER www-data
ENTRYPOINT ["/usr/local/bin/civikitchen-entrypoint"]
CMD ["apache2-foreground"]
