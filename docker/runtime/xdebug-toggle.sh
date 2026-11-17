# shellcheck shell=bash
# Xdebug is on only with XDEBUG_MODE set (https://xdebug.org/docs/all_settings#mode);
# it slows every request. pcov is loaded disabled; ckcoverage enables it.
XDEBUG_INI="/usr/local/etc/php/conf.d/xdebug.ini"
if [[ -n "${XDEBUG_MODE}" && "${XDEBUG_MODE}" != "off" ]]; then
    cat > "${XDEBUG_INI}" <<EOF
zend_extension=xdebug.so
xdebug.mode=${XDEBUG_MODE}
xdebug.client_host=${XDEBUG_CLIENT_HOST:-host.docker.internal}
xdebug.client_port=${XDEBUG_CLIENT_PORT:-9003}
xdebug.start_with_request=${XDEBUG_START_WITH_REQUEST:-trigger}
xdebug.discover_client_host=${XDEBUG_DISCOVER_CLIENT_HOST:-0}
xdebug.idekey=${XDEBUG_IDEKEY:-VSCODE}
EOF
    echo "[civikitchen] xdebug enabled (mode=${XDEBUG_MODE}, client=${XDEBUG_CLIENT_HOST:-host.docker.internal}:${XDEBUG_CLIENT_PORT:-9003})"
elif [[ -s "${XDEBUG_INI}" ]]; then
    # Emptied, not removed: the web user owns the file, not conf.d.
    : > "${XDEBUG_INI}"
fi
