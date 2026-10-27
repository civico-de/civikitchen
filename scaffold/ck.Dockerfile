# The container scaffold/ck runs ck's host commands in: PHP for the ck CLI, curl
# for ck demo's downloads, and the docker CLI with its buildx and compose plugins.
# Same docker CLI as toolbelt/playwright/Dockerfile (tests/ckinit/test-ck.sh).
FROM docker:29.5.2-cli
RUN apk add --no-cache curl php84 php84-posix
