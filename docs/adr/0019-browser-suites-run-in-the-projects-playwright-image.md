# ADR-0019: Browser suites run in the project's Playwright image

*Status: accepted · 2026-10-27*

## Context

A Playwright suite needs Node, the project's npm tree and the browsers of the
exact Playwright version that tree resolves, with their system libraries.
Every suite in this repository and in the extensions installed all of that on
the machine that ran it: a developer's laptop, a GitHub-hosted runner, a
self-hosted runner. `playwright install --with-deps` asks for sudo, which
hardened runners do not grant, so their images had to carry the libraries.
Install hooks of the npm tree ran on that machine too.

On a self-hosted Docker host, Chromium aborts in-flight requests with
`ERR_NETWORK_CHANGED` whenever another job adds or removes an interface in the
host's network namespace. The extension workflow worked around it with a
browser server in a container, a WebSocket endpoint and a loopback tunnel, and
it still published a free port per job for the site.

## Decision

`ck playwright [--network <mode>] <command>` runs a command in an image built
per project from `toolbelt/playwright/Dockerfile`:

- the official `mcr.microsoft.com/playwright` image at the version the
  project's tree resolves, with its browsers and Node;
- the project's tree, installed in a build stage from its one lockfile and
  its `.npmrc` or `bunfig.toml`: `npm ci --ignore-scripts`, or
  `bun install --frozen-lockfile` with the Bun that `packageManager` pins,
  which the image also carries;
- the docker CLI and the compose plugin.

The tag hashes the Dockerfile, those files and `DOCKER_DEFAULT_PLATFORM`, so
each lockfile is built once per platform. The run mounts the git work tree at
its own path, works in the current directory, runs as the host user, mounts
the Docker socket and passes every environment variable but the host's paths,
identity and locale. The network is the host's by default; the extension
workflow passes the app container's.

On a developer host `scaffold/ck` runs `ck playwright` in a container with PHP
and the docker CLI, so the host needs only Docker. `make docs-a11y`,
`make e2e` and the browser jobs of `extension-ci.yml`, `docs.yml`,
`extension-docs.yml` and the image builds run this way, and `ck demo` records
in the same image.

## Rationale

The version comes from the installed tree. The first build exports
`playwright-core/package.json` from the dependency stage, so neither npm's nor
Bun's lockfile format needs a parser here.

The tree lives at `/ck/node_modules`, linked from `/node_modules`. Node looks
for packages in every parent directory up to `/`, so a project at any path
finds it, and nothing is written into the work tree. A `node_modules` on the
host is hidden behind an empty tmpfs: its native binaries may not run in the
image, and it would shadow the image's tree.

The same-path mount and the socket keep suites working that call
`docker compose exec … cv` against their stack, and paths in configs, reports
and traces mean the same inside and outside.

In the app container's network namespace the site is `http://localhost`, no
port is published, and only that container's own interfaces exist. Parallel
jobs on one Docker host neither collide on ports nor disturb the browser.

## Consequences

The first run for a lockfile pulls the Playwright image and installs the tree,
which takes minutes; later runs start at once. An older tag stays until it is
removed (`docker images civikitchen-playwright`).

The build sees package.json, the lockfile and the install settings, and no
other file and no environment. A dependency from a local path or from a
registry that needs a token does not install, nor does a Bun project whose
own install script reads its files. A workspace member, whose lockfile lies in
the workspace root, is refused.

There is no headed browser. UI mode (`--ui-host=127.0.0.1`), `show-report` and
`show-trace` serve their pages over HTTP instead.

The socket is `/var/run/docker.sock` as the Docker daemon sees it. That holds
for Docker Engine and colima; rootless Docker keeps its socket elsewhere.

The browser job no longer reads `node_version`, and its suite reaches the site
at `http://localhost`. The app container serves the checkout, so the job
copies the image's tree there before the build. `playwright-e2e.yml` keeps
its command inputs and offers `ck playwright` as `$CK`. The GitLab template
still installs into its throwaway job container.
