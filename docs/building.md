# Building locally

This page covers work on civikitchen itself: the make targets that test and
lint it, building and testing the images, and how the dev tools are pinned.
Every make target runs in the toolchain container that `scaffold/ck` builds
from `docker/cli/Dockerfile` (target `toolchain`), with PHP, GNU Make, bash,
git, jq and the docker CLI. A plain `make` on the host stops and names the
`scaffold/ck make` command.

| Task | Command | Section |
|---|---|---|
| Run every fast test suite | `scaffold/ck make test` | [The fast loop](#the-fast-loop) |
| Run every static check | `scaffold/ck make lint` | [The fast loop](#the-fast-loop) |
| List all targets | `scaffold/ck make help` | |
| Build the standalone image | `scaffold/ck make build` | [The slow loop](#the-slow-loop) |
| Boot and test images | `make test-images`, `e2e`, `docs-a11y`, `e2e-ckcreate` | [The slow loop](#the-slow-loop) |
| Run the image test suite outside make | `bash tests/images/run-local.sh` | [Running the test suite locally](#running-the-test-suite-locally) |
| Check the tools in a built image | `tests/images/test-dev-tools.sh` | [Verifying a built image](#verifying-a-built-image) |
| Build any image with `docker build` | `docker build -f docker/…/Dockerfile .` | [Building images by hand](#building-images-by-hand) |
| Keep core's git history in a CMS image | `--build-arg KEEP_GIT=1` | [Keeping the CiviCRM git history](#keeping-the-civicrm-git-history-keep_git1) |
| Bump a pinned tool | `composer update <package>` | [Bumping a dev tool](#bumping-a-dev-tool) |
| Run ck's host commands without a checkout | `docker run … civikitchen-cli` | [ck without a checkout](#ck-without-a-checkout) |

## Requirements

The host needs bash and Docker. `scaffold/ck` mounts `$HOME`, the checkout and
the Docker socket at their own paths, so on macOS the checkout has to live
under `$HOME`, which colima and Docker Desktop share with their VM. The
container starts as root with `CK_USER=<uid>:<gid>`; its entrypoint drops to
that user plus the Docker socket's group, so files it writes belong to you.

## The fast loop

```bash
scaffold/ck make test   # every fast test suite; the suites themselves start no containers
scaffold/ck make lint   # every static check CI runs
```

The `Lint` workflow runs exactly these two targets in the same container on
pushes to main and on pull requests, so CI and a laptop cannot drift apart.

`make lint` covers:

- strict shellcheck at style level over all shell scripts (`.shellcheckrc`
  names the two disabled false-positive classes)
- a portability check that rejects the BSD-only `sed -i ''`
- actionlint and zizmor over the workflows
- `php -l` over every PHP file
- phpstan over the repository's own PHP at the PHP 8.1 floor, with the pinned
  CiviCRM source for the scripts that run inside a site (`phpstan.neon.dist`)
- the profile and scenario schemas against their metaschema, and every
  repository-owned instance against its schema
- the grammar of `CHANGELOG.md`
- a build of this documentation site that fails on broken links

On first use, make fetches the pinned tools (phpunit phar, actionlint,
shellcheck, composer, uv, Node) and the CiviCRM source tree the catalog drift
gates need into `.cache/`. `scaffold/ck make clean` removes it.

## The slow loop

The slow loop builds and boots images. `build` builds the standalone image as
`civikitchen:standalone` (`civikitchen:standalone-<version>` with
`CIVICRM_VERSION`); `test-images`, `e2e`, `docs-a11y` and `e2e-ckcreate` test
images. The browser checks run in the repository's Playwright image, so the
host needs no Node and no browsers for them.

`scaffold/ck make e2e-ckcreate IMAGE=civikitchen:standalone` creates an
extension with real civix and runs `ck ci` on it in the template's dev stack.
The image workflow runs the same check before promoting.

To build Standalone from a core branch instead of a release, see
[Standalone on an unreleased branch](core-development.md#standalone-on-an-unreleased-branch).

## Running the test suite locally

`tests/images/run-local.sh` runs the same test scripts as CI, on a laptop or
in a throwaway VM. It needs only bash and docker, not gh or node:

```bash
bash tests/images/run-local.sh                          # everything, published images
bash tests/images/run-local.sh drupal11 drupal11-demo   # a subset
bash tests/images/run-local.sh -p civikitchen drupal11-demo   # your locally built tags
CK_PROFILE=common bash tests/images/run-local.sh drupal10-demo # + a profile leg
```

For each dev flavor it runs the dev-tools functional check and the
first-boot test (against an external database on the buildkit flavors). For
each demo flavor it runs the single-container boot test, which includes the
`CIVIKITCHEN_SITE_URL` rewrite leg on a host port other than 80. A summary
table prints at the end, and the exit code reflects failures. Budget about an
hour for the full default run. Each profile leg adds up to 15 minutes.

## Verifying a built image

`tests/images/test-dev-tools.sh` checks that every bundled tool works. It:

- lints non-conforming PHP through cklint
- runs phpstan against a typed mistake
- executes a phpunit assertion
- installs a real package via composer
- verifies the xdebug toggle
- holds the scaffold template to cklint and ckfmt

CI runs the same script against `:standalone` and the buildkit images. Before
promoting stable tags, CI also boots each dev flavor's compose example and
runs Playwright browser smoke tests.

```bash
docker run --rm -v "$(pwd)/tests/images:/civikitchen-test:ro" \
    -v "$(pwd):/civikitchen-src:ro" \
    --entrypoint='' \
    ghcr.io/civico-de/civikitchen:standalone \
    bash /civikitchen-test/test-dev-tools.sh
```

## Building images by hand

The build context is the repository root for both the standalone and the
buildkit-based images. The Dockerfiles copy from three trees:

- `toolbelt/`: the ck tools, the mago baseline and extension, and the
  phpstan, psalm and rector packages
- `docker/`: the image's own entrypoints, provisioning and demo profiles
- `packages/`: the profile and scenario schemas

None of them has to live inside another. `.dockerignore` keeps `.git` and
host-built artifacts out.

| Build argument | Image | Meaning |
|---|---|---|
| `CIVICRM_VERSION` | standalone | An exact release such as `6.19.1`: it names a tarball on download.civicrm.org, and a bare minor is not one. Without it the Dockerfile's default applies, so pass the current version. |
| `CIVICRM_VERSION` | buildkit | Pins the baked CiviCRM: any civicrm-core tag or branch civibuild can fetch. |
| `CIVICRM_BUILD_VERSION` | buildkit | Overrides only the civibuild input. CI passes the stable minor branch here, while the image metadata keeps the resolved patch version. |
| `DEFAULT_SITE_TYPE` | buildkit | The civibuild site type the entrypoint creates on first run. |
| `PHP_VERSION` | both | The PHP of the base image. |
| `KEEP_GIT` | buildkit | `1` keeps core's git history ([below](#keeping-the-civicrm-git-history-keep_git1)). |

```bash
# Standalone
docker build -f docker/standalone/Dockerfile -t civikitchen:standalone .

# Standalone pinned to an exact CiviCRM release
VERSION=6.20.0
docker build -f docker/standalone/Dockerfile \
    --build-arg CIVICRM_VERSION="$VERSION" \
    -t civikitchen:standalone-"$VERSION" .

# Buildkit-based images
docker build -f docker/buildkit/Dockerfile \
    --build-arg PHP_VERSION=8.3 \
    --build-arg DEFAULT_SITE_TYPE=drupal10-demo \
    --build-arg CIVICRM_VERSION="$VERSION" \
    -t civikitchen:drupal10 .

docker build -f docker/buildkit/Dockerfile \
    --build-arg PHP_VERSION=8.3 \
    --build-arg DEFAULT_SITE_TYPE=drupal11-demo \
    -t civikitchen:drupal11 .

docker build -f docker/buildkit/Dockerfile \
    --build-arg PHP_VERSION=8.3 \
    --build-arg DEFAULT_SITE_TYPE=wp-demo \
    -t civikitchen:wordpress .

docker build -f docker/buildkit/Dockerfile \
    --build-arg PHP_VERSION=8.3 \
    --build-arg DEFAULT_SITE_TYPE=joomla-demo \
    -t civikitchen:joomla .

docker build -f docker/buildkit/Dockerfile \
    --build-arg PHP_VERSION=8.3 \
    --build-arg DEFAULT_SITE_TYPE=backdrop-demo \
    -t civikitchen:backdrop .
```

## Keeping the CiviCRM git history (`KEEP_GIT=1`)

The published buildkit images strip the git history that civibuild clones
into the site, such as `vendor/civicrm/civicrm-core`, about 550 MB in total.
Extension development and the runtime `civibuild reinstall` do not need it.
For a git-enabled site, to work on core, run `civibuild update` or dig through
`git log`, build your own image with the history kept:

```bash
docker build -f docker/buildkit/Dockerfile \
    --build-arg DEFAULT_SITE_TYPE=drupal10-demo \
    --build-arg KEEP_GIT=1 \
    -t civikitchen:drupal10-git .
```

## Bumping a dev tool

Tools are pinned in three places:

| Where | What | How to bump |
|---|---|---|
| `composer.json` + `composer.lock` at the repository root | Every composer-installable tool | Edit `composer.json`, run `composer update <package>`, commit both files. |
| `package.json` + `package-lock.json` in `toolbelt/oxlint` and `toolbelt/oxfmt` | oxlint and oxfmt | Edit `package.json`, re-lock, commit both files. |
| `toolbelt/install-dev-tools.sh`, shared pins in `toolbelt/versions.env` | composer, the civix and phpunit phars, the mago binary, npm, infection | Edit the version, and its checksum where it has one. |

### The composer root

`require` holds only the YAML parser the ck tools need everywhere, so CI jobs
outside the images install it with `composer install --no-dev`. The quality
tools the images ship sit in `require-dev`.

| Package | Why this pin |
| --- | --- |
| `phpstan/phpstan` | Moves with rector, which runs on phpstan's internals and requires a minimum phpstan version. Check that `ckmodernize` still runs after a bump of either. |
| `phpstan/phpstan-deprecation-rules` | Reports every call into code marked `@deprecated`, which is how deprecated CiviCRM symbols are caught without a maintained list. |
| `phpstan/extension-installer` | Registers the phpstan extensions in the root automatically, so no repository adds an `includes:` line. |
| `phpstan/phpstan-strict-rules` | Opt-in per repository, hence the `extra.phpstan/extension-installer.ignore` entry: installed, not registered. See [Extension standards](extension-standards.md). |
| `phpstan/phpstan-phpunit`, `phpat/phpat`, `spaze/phpstan-disallowed-calls` | Inert until a repository has tests, architecture rules or a ban config, so they register automatically. |
| `civikitchen/phpstan-ck-legacy` | The CiviKitchen phpstan extension in `toolbelt/phpstan`, joined as a path repository. |
| `rector/rector` | Pinned so a rebuild cannot change the rewrites `ckmodernize` applies. |
| `vimeo/psalm` | The taint engine for `cktaint`. Its `php` constraint is patch-level, which is why the platform floor below is 8.1.31. |
| `carthage-software/mago` | The mago extension SDK, pinned to the mago binary in `versions.env`. |
| `shipmonk/composer-dependency-analyser` | The analyser behind `ckdeps`. |

The lock resolves against PHP 8.1.31 (`config.platform.php`), the lowest
version the images support. A re-lock on a newer laptop therefore cannot pull
in a transitive dependency that refuses to start on an 8.1 or 8.2 image;
resolved against 8.3, psalm picks a `sebastian/diff` that needs 8.3 and dies at
startup on older images.

The lock is the pin. It fixes the transitive tree too, so a daily image
rebuild cannot move a gate under a repository that did not change. These
tools have no build argument, because an image whose tool tree was resolved
fresh at build time is not the image anyone reviewed.

### npm-installed tools

An `overrides` entry in the `package.json` of `toolbelt/oxlint` or
`toolbelt/oxfmt` lifts a transitive dependency that the tool locks to a
vulnerable version. It is removed once the tool's own pin moves past the fix.

### Tools outside the lockfiles

`toolbelt/install-dev-tools.sh` installs what neither composer nor npm
installs: composer itself, the civix and phpunit phars, the mago binary, and
npm. All but composer can be overridden with a build argument. The phars and
mago have a checksum, so overriding a `*_VERSION` means overriding its
`*_SHA256` too. The composer, phpunit and mago pins sit in
`toolbelt/versions.env`, because the Makefile runs the tool test suites on the
same releases.

infection is the one composer tool pinned in `install-dev-tools.sh` instead of
the root lock, because the pin works as a ceiling. It resolves per image PHP,
which one lock cannot express: images for older CiviCRM lines run on PHP 8.1
or 8.2, and composer picks the newest release that PHP accepts.

## ck without a checkout

`ghcr.io/civico-de/civikitchen-cli` (target `cli` of `docker/cli/Dockerfile`)
carries ck's host commands — `playwright`, `demo`, `ab`, `run` and `bisect` —
and needs no civikitchen checkout. Run it from a directory below `$HOME`:

```bash
docker run --rm -it --init --network host -v /var/run/docker.sock:/var/run/docker.sock -v "$HOME:$HOME" -w "$PWD" -e HOME=/tmp -e CK_USER="$(id -u):$(id -g)" ghcr.io/civico-de/civikitchen-cli:v1 run standalone-master -- php -v
```

Everything after the image name is a ck command line. `:latest` follows main,
`:v1` and `:vX.Y.Z` follow the [releases](releases.md#what-a-version-names).
Without `CK_USER` the command runs as the user the container was started as;
a missing socket or a malformed `CK_USER` stops it.
