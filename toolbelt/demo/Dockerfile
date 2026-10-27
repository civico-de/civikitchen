# The `ck demo` recorder: Playwright's browser image at the version the checkout
# locks, with the checkout's npm dependencies installed next to record.mts.
ARG PLAYWRIGHT_VERSION
FROM mcr.microsoft.com/playwright:v${PLAYWRIGHT_VERSION}-noble
WORKDIR /civikitchen
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
