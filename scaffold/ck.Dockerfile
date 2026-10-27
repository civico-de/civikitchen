# The container scaffold/ck runs in: PHP for the ck CLI and the test suites, the
# tools the Makefile drives, and the docker CLI with its buildx and compose plugins.
# Same docker CLI as toolbelt/playwright/Dockerfile (tests/ckinit/test-ck.sh).
FROM docker:29.5.2-cli AS docker-cli

FROM debian:trixie-slim
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates curl git jq make patch unzip xz-utils zip \
      php-cli php-mbstring php-phpdbg php-xml php-zip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=docker-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker-cli /usr/local/libexec/docker/cli-plugins /usr/local/libexec/docker/cli-plugins
ENV CK_TOOLCHAIN=1 LANG=C.UTF-8
