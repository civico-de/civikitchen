# Building locally

Every step on this page is a make target, and make runs in the toolchain
container that `scaffold/ck` builds from `scaffold/ck.Dockerfile`, with PHP,
GNU Make, bash, git, jq and the docker CLI. `scaffold/ck make help` lists the
targets. The fast loop:

```bash
scaffold/ck make test   # ckconform's fixtures, the phpstan extension's rule tests, ckinit, the compose files
scaffold/ck make lint   # all static checks, see below
```

The `Lint` workflow runs exactly these two targets in the same container on
pushes to main and on pull requests, so CI and a laptop cannot drift apart.
A plain `make` on the host stops and names the `scaffold/ck make` command.

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

On first use, make fetches the pinned tools (phpunit phar, actionlint,
shellcheck, composer, uv, Node) and the CiviCRM source tree the catalog drift
gates need into `.cache/`. `scaffold/ck make clean` removes it.

## Requirements

The host needs bash and Docker. `scaffold/ck` mounts `$HOME`, the checkout and
the Docker socket at their own paths, so on macOS the checkout has to live
under `$HOME`, which colima and Docker Desktop share with their VM.

The slow loop builds and boots images: `build` builds the standalone image as
`civikitchen:standalone`, and `test-images`, `e2e`, `docs-a11y` and
`e2e-ckcreate` test them. The browser checks run in the repository's
Playwright image, so the host needs no Node and no browsers for them.
`scaffold/ck make e2e-ckcreate IMAGE=civikitchen:standalone` creates an
extension with real civix and runs `ck ci` on it in the template's dev stack.
The image workflow runs the same check before promoting.

## Building images by hand

The build context is the repository root for both the standalone and the
buildkit-based images. The Dockerfiles copy from three trees:

- `toolbelt/`: the ck tools, the mago baseline and extension, and the
  phpstan, psalm and rector packages
- `docker/`: the image's own entrypoints, provisioning and demo profiles
- `packages/`: the profile and scenario schemas

None of them has to live inside another. `.dockerignore` keeps `.git` and
host-built artifacts out.

For the buildkit images, `DEFAULT_SITE_TYPE` picks the civibuild site type the
entrypoint creates on first run. `CIVICRM_VERSION` pins the baked CiviCRM, and
accepts any civicrm-core tag or branch civibuild can fetch.
`CIVICRM_BUILD_VERSION` overrides only the civibuild input. CI uses it to pass
the stable minor branch, such as 6.15, while the image metadata keeps the
resolved patch version.

For Standalone, `CIVICRM_VERSION` must be an exact release such as 6.15.1,
because it names a tarball on download.civicrm.org. A bare minor like 6.15 is
not a tarball. Without the argument the Dockerfile's default applies, so pass
the current version.

```bash
# Standalone
docker build -f docker/standalone/Dockerfile -t civikitchen:standalone .

# Standalone pinned to an exact CiviCRM release
docker build -f docker/standalone/Dockerfile \
    --build-arg CIVICRM_VERSION=6.15.1 \
    -t civikitchen:standalone-6.15.1 .

# Buildkit-based images
docker build -f docker/buildkit/Dockerfile \
    --build-arg PHP_VERSION=8.3 \
    --build-arg DEFAULT_SITE_TYPE=drupal10-demo \
    --build-arg CIVICRM_VERSION=6.15.1 \
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

## Bumping a dev tool

Every composer-installable tool is pinned in the one `composer.json` and
`composer.lock` at the repository root. `require` holds only the YAML parser
the ck tools need everywhere, so CI jobs outside the images install it with
`composer install --no-dev`. The quality tools the images ship sit in
`require-dev`. To bump one, edit `composer.json`, run `composer update` with the
package name, and commit both files.

| Package | Why this pin |
| --- | --- |
| `phpstan/phpstan` | Between a floor and a ceiling. `phpstan-phpunit` 2.0.18 needs at least 2.2.3. rector reads a private property of phpstan's `RichParser` that 2.2.6 removed, so every `ckmodernize` run dies with `MissingPrivatePropertyException` above 2.2.5. Check that `ckmodernize` still runs after a bump. |
| `phpstan/phpstan-deprecation-rules` | Reports every call into code marked `@deprecated`, which is how deprecated CiviCRM symbols are caught without a maintained list. |
| `phpstan/extension-installer` | Registers the phpstan extensions in the root automatically, so no repository adds an `includes:` line. |
| `phpstan/phpstan-strict-rules` | Opt-in per repository, hence the `extra.phpstan/extension-installer.ignore` entry: installed, not registered. See `docs/extension-standards.md`. |
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
startup on older images. infection is the one tool outside the root: it
resolves per image PHP, which one lock cannot express.

The lock is the pin. It fixes the transitive tree too, so a daily image
rebuild cannot move a gate under a repository that did not change. These
tools have no build argument, because an image whose tool tree was resolved
fresh at build time is not the image anyone reviewed.

oxlint and oxfmt are pinned the same way, by the `package.json` and
`package-lock.json` pairs in `toolbelt/oxlint` and `toolbelt/oxfmt`. An
`overrides` entry in such a `package.json` lifts a transitive dependency that
the tool locks to a vulnerable version. It is removed once the tool's own pin
moves past the fix.

Tools that neither composer nor npm installs stay in
`toolbelt/install-dev-tools.sh`: composer itself, the civix and phpunit phars,
the mago binary, and npm. All but composer can be overridden with a build
argument. The phars and mago have a checksum, so overriding a `*_VERSION`
means overriding its `*_SHA256` too. The composer, phpunit and mago pins sit in
`toolbelt/versions.env`, because the Makefile runs the tool test suites on the
same releases.

infection is the one composer tool pinned in `install-dev-tools.sh` instead of
a lockfile, because the pin works as a ceiling. Images for older CiviCRM lines
run on PHP 8.1 or 8.2, and composer picks the newest release that PHP accepts.

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

## Running the test suite locally

`tests/images/run-local.sh` runs the same test scripts as CI, on a laptop or
in a throwaway VM. It needs only bash and docker, not gh or node:

```bash
bash tests/images/run-local.sh                          # everything, published images
bash tests/images/run-local.sh drupal11 drupal11-demo   # a subset
bash tests/images/run-local.sh -p civikitchen drupal11-demo   # your locally built tags
CK_PROFILE=verein bash tests/images/run-local.sh drupal10-demo # + a profile leg
```

For each dev flavor it runs the dev-tools functional check and the
external-database first-boot test. For each demo flavor it runs the
single-container boot test, which includes the `CIVIKITCHEN_SITE_URL` rewrite
leg on a host port other than 80. A summary table prints at the end, and the
exit code reflects failures. Budget about an hour for the full default run.
Each profile leg adds up to 15 minutes.

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
