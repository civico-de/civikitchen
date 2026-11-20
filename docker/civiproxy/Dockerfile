# Unmodified upstream CiviProxy for extension dev and e2e stacks. The consumer
# mounts or COPYs its config.php into /var/www/html. Build context: the repo root.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-apache-bookworm

# A proper GitHub release, never a pre-release; bump both lines together.
ARG CIVIPROXY_VERSION=1.0.1
ARG CIVIPROXY_SHA256=99dacdba1814dd8aadff896f12b717a60cd8f36a951d0530a795f7adcf09abcc

# The bundled target.pem pins a stale CA bundle, and local targets are plain http.
# mod_rewrite serves the bundled .htaccess route /civicrm/ajax/api4/<entity>/<action>.
RUN apt-get update && apt-get upgrade -y && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL --retry 3 --retry-all-errors -o /tmp/civiproxy.tar.gz \
       "https://github.com/systopia/CiviProxy/archive/refs/tags/${CIVIPROXY_VERSION}.tar.gz" \
    && echo "${CIVIPROXY_SHA256}  /tmp/civiproxy.tar.gz" | sha256sum -c --strict - \
    && tar -xzf /tmp/civiproxy.tar.gz -C /tmp \
    && cp -a "/tmp/CiviProxy-${CIVIPROXY_VERSION}/proxy/." /var/www/html/ \
    && rm -rf /tmp/civiproxy.tar.gz "/tmp/CiviProxy-${CIVIPROXY_VERSION}" /var/www/html/target.pem \
    && a2enmod rewrite
COPY docker/civiproxy/civiproxy.conf /etc/apache2/conf-enabled/civiproxy.conf
COPY docker/civiproxy/entrypoint.sh /usr/local/bin/civiproxy-entrypoint

LABEL org.opencontainers.image.title="CiviProxy ${CIVIPROXY_VERSION}" \
      org.opencontainers.image.version="${CIVIPROXY_VERSION}" \
      org.opencontainers.image.source="https://github.com/civico-de/civikitchen"
HEALTHCHECK --interval=5s --timeout=3s --start-period=10s \
    CMD curl -fsS -o /dev/null http://localhost/ || exit 1
ENTRYPOINT ["civiproxy-entrypoint"]
CMD ["apache2-foreground"]
