# The `ck playwright` image: Playwright's own image at the version the project's
# lockfile resolves, that dependency tree, Bun when the project uses it, and the
# docker CLI for suites that reach their stack. Read by Playwright/Image.php.
ARG PACKAGE_MANAGER=npm
ARG BUN_VERSION=none
ARG PLAYWRIGHT_VERSION=none

FROM docker:29.5.2-cli AS docker-cli

# No lifecycle scripts: an install hook in a browser suite's tree would run unseen.
# The wildcards take the project's install settings when it has them.
FROM node:24-bookworm-slim AS npm
WORKDIR /ck
COPY package.json package-lock.json .npmrc* ./
RUN npm ci --ignore-scripts --no-audit --no-fund && mkdir bin

FROM oven/bun:${BUN_VERSION}-slim AS bun
WORKDIR /ck
COPY package.json bun.lock .npmrc* bunfig.toml* ./
RUN bun install --frozen-lockfile && mkdir bin && cp /usr/local/bin/bun bin/

FROM ${PACKAGE_MANAGER} AS dependencies

# Exported first; its version picks the base image of the last stage.
FROM scratch AS playwright-core
COPY --from=dependencies /ck/node_modules/playwright-core/package.json /

FROM mcr.microsoft.com/playwright:v${PLAYWRIGHT_VERSION}-noble
COPY --from=docker-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker-cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose
COPY --from=dependencies /ck /ck
# Node searches every parent directory up to /, so a project mounted anywhere finds the tree.
RUN ln -s /ck/node_modules /node_modules
ENV PATH=/ck/bin:/node_modules/.bin:$PATH NPM_CONFIG_UPDATE_NOTIFIER=false
