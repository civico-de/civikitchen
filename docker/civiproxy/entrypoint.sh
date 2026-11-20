#!/bin/sh
# Refuses to start without the consumer's config.php: every proxy script requires it.
set -eu
if [ ! -f /var/www/html/config.php ]; then
    echo "civiproxy: no /var/www/html/config.php; mount or COPY your CiviProxy config there" >&2
    exit 1
fi
exec docker-php-entrypoint "$@"
