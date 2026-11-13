# Two images around ck: toolchain (scaffold/ck, a checkout's own code and make targets)
# and cli (ck's host commands without a checkout). The build context is the repo root.
# Same docker CLI as toolbelt/playwright/Dockerfile (tests/ckinit/test-ck.sh).
FROM docker:29.5.2-cli AS docker-cli

# Debian: make fetches glibc builds of Node, uv and mago.
FROM debian:trixie-slim AS toolchain
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates curl git git-lfs jq make patch unzip xz-utils zip \
      php-cli php-mbstring php-phpdbg php-xml php-zip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=docker-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker-cli /usr/local/libexec/docker/cli-plugins /usr/local/libexec/docker/cli-plugins
COPY docker/cli/entrypoint.sh /usr/local/bin/ck-entrypoint
ENV CK_TOOLCHAIN=1 LANG=C.UTF-8
ENTRYPOINT ["ck-entrypoint"]

FROM docker-cli AS cli
RUN apk add --no-cache curl git setpriv \
      php84 php84-ctype php84-dom php84-mbstring php84-openssl php84-pcntl php84-posix php84-tokenizer \
 && ln -s /usr/bin/php84 /usr/local/bin/php
COPY docker/cli/entrypoint.sh /usr/local/bin/ck-entrypoint
COPY package.json package-lock.json /opt/civikitchen/
COPY toolbelt/bin/ck /opt/civikitchen/toolbelt/bin/ck
COPY toolbelt/lib/php /opt/civikitchen/toolbelt/lib/php
COPY toolbelt/playwright/Dockerfile /opt/civikitchen/toolbelt/playwright/Dockerfile
COPY toolbelt/demo/record.mts /opt/civikitchen/toolbelt/demo/record.mts
ENV LANG=C.UTF-8
ENTRYPOINT ["ck-entrypoint", "php", "/opt/civikitchen/toolbelt/bin/ck"]
