# Extension development

The standalone image is built around the usual loop: edit the code, reload the
page, run phpunit. Start from the example in
[`examples/standalone/`](https://github.com/civico-de/civikitchen/tree/main/examples/standalone/).

## The dev stack

Mount your extension into the container:

```yaml
volumes:
  - /path/to/your/extension:/var/www/html/ext/myextension
```

Then start the stack. With auto-install on, as in the example, the first start
installs CiviCRM and enables every mounted extension together with its
required extensions. Later starts reuse the database. Where third-party
dependencies come from is described under `CIVIKITCHEN_ENABLE_EXTENSIONS` in
[Configuration](configuration.md).

If your extension uses composer, install its dependencies as your own user so
that the vendor directory in your checkout belongs to you. Then enable the
extension and run its tests:

```bash
docker compose up -d
docker compose run --rm --no-deps -u "$(id -u):$(id -g)" -e HOME=/tmp \
  --entrypoint composer app install -d /var/www/html/ext/myextension
docker compose exec app cv ext:enable myextension
docker compose exec app bash -c "cd /var/www/html/ext/myextension && phpunit"
```

ckup does the composer install for every mounted extension before it starts
the stack.

`docker compose down` keeps the database volume, so the next boot is fast and
the data survives. A recreated container re-applies profile, locales and
extensions but does not run the init hooks a second time. With `-v` the volume
goes too, and the next start is a fresh install.

Several stacks on one machine collide on host ports. The template's compose
file reads its ports from a gitignored `.docker/.env` (keys CK_HTTP_PORT,
CK_MAILDEV_PORT, CK_SMTP_PORT, CK_PMA_PORT). The first run of ckup
writes that file with free ports, then runs `docker compose up -d` with any
further arguments. Without the file the ports are 8080 (web), 1080 (Maildev),
1025 (SMTP) and 8081 (phpMyAdmin).

phpMyAdmin is opt-in, like the browser service. Add `COMPOSE_PROFILES=pma` to
`.docker/.env` (ckup keeps the line, and compose reads it on every call), or
start it once with `docker compose --profile pma up -d`. Its port is assigned
either way, so it stays the same when you switch it on later.

ckup runs the image CI runs: `:standalone-<minor>` when `civikitchen.yaml` sets
`policy.civicrm`, otherwise the compose file's default. `CIVIKITCHEN_IMAGE` in
the environment or in `.docker/.env` overrides both, and a plain
`docker compose up` sees only that variable, not the policy.

```bash
/path/to/civikitchen/scaffold/ckup
/path/to/civikitchen/scaffold/ckx              # shell in the extension directory
/path/to/civikitchen/scaffold/ckx cv flush     # any command, as www-data
```

ckx runs in the template's stack, in the container directory that matches the
one you are in. For an extension below the git root it also sets the tool path
described under [Running the CI gates locally](#running-the-ci-gates-locally).
The examples below use ckx. Outside the template layout, write
`docker compose exec -w /var/www/html/ext/<file> app …` instead.

`CKX_USER=root` is for a command that must write where www-data cannot. Do not
use it for anything that boots CiviCRM, such as cv or the gates: that leaves
caches the web server cannot write until the container is recreated.

Every first-boot setting, from SMTP to demo users, is in the
[configuration reference](configuration.md).

## Headless tests

Headless tests run with `CIVICRM_UF=UnitTests`:

```bash
docker compose exec -e CIVICRM_UF=UnitTests app \
  bash -c "cd /var/www/html/ext/myextension && phpunit"
```

They run against a scratch database named after the main one with a `_test`
suffix, never against your dev site. The image creates it, writes the test DSN
into the cv config and patches the boot stub so a headless boot cannot fall
back to the dev database. If you manage `TEST_DB_DSN` yourself, opt out with
`CIVIKITCHEN_TEST_DB=0`; the boot stub stays patched and reads your DSN.
**Never point it at the dev database: a headless phpunit run rebuilds that
schema.**

**Database grants.** The test harness needs SUPER on MariaDB, so first-boot
provisioning grants it as the database root user and sets
log_bin_trust_function_creators. Your compose file needs no grant script. It
reaches root with `CIVICRM_DB_ROOT_PASSWORD`, which defaults to `root` as in the
example stacks. If your database uses a different root password, pass it to
the app service, or the first boot fails and the site stays unhealthy.

**Resetting the scratch DB — `cktestreset`.** Two situations leave the test
database stale:

- A test installs its own extension without the extensions it requires, since
  the test framework does not resolve them. Every later headless boot then dies
  with `Interface "..." not found`, and it does not heal itself.
- A suite does not see new seed data or a bumped version: the framework
  rebuilds only when its list of install steps changes.

```bash
docker compose exec app cktestreset
```

This drops the test database, reseeds it from the main one and clears the
cached containers.

The lasting fix for the first case belongs in the extension. Build the
environment with ck_headless() from the managed bootstrap instead of
\Civi\Test::headless(). It installs every extension the info.xml requires, one
level deep, then the extension itself:

```php
public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
  return ck_headless()->apply();   // or ck_headless()->sqlFile(...)->apply()
}
```

It also rebuilds the test database when a file that decides the install
changes: info.xml, the main file, the upgrader, any `*.mgd.php`, and `schema/`,
`xml/` and `sql/`. `\Civi\Test::headless()` alone keeps the old install.

An extension the code integrates with but does not require goes in as an
argument. It installs after the `<requires>` and before the extension itself:

```php
return ck_headless('org.example.optional')->apply();
```

## Dev profile and provisioning hooks

Data a test setup needs beyond the extension belongs in the dev profile,
`.docker/profiles/dev/profile.json`: dependencies, settings, seed records,
scripts and API users, in the
[profile format](images.md#profiles-civikitchen_profile). Both template stacks
and ckboot use it. Its dependencies are enabled before the extension and its
content runs after it, so a seed script can use the extension's own entities.

What the stack itself needs, such as a renderer binary or system packages, goes
into first-boot hooks. Scripts mounted into `/civikitchen-init.d/` run after
the profile, in lexical order: `.sh` with bash, `.php` with `cv scr`, as the
image's web user (www-data on standalone, buildkit on the CMS images). A
failing hook aborts the boot.

A hook that needs root, like extra packages, needs `user: root` on the service.
`docker compose exec` then defaults to root too, so pass `-u www-data` to cv
and the ck tools.

```yaml
services:
  app:
    user: root  # apt for CIVIKITCHEN_EXTRA_PACKAGES
    environment:
      CIVIKITCHEN_EXTRA_PACKAGES: "libreoffice-writer,unoconv"
    volumes:
      - ../../org.example.myext:/var/www/html/ext/org.example.myext
      - ./init.d:/civikitchen-init.d:ro
```

## No calls to civicrm.org

CiviCRM's status check contacts civicrm.org for the version check and the
extension feed. Without outbound network each call waits for a socket timeout,
long enough to fail a Playwright test. First-boot provisioning therefore turns
both off on every flavor, and the status page shows *Update Check Disabled* and
*Extensions check disabled*. `cv ext:download` still works. To switch the
version check back on in an init hook:

```bash
cv api4 Job.update +w api_action=version_check +v is_active=1
```

## Creating an extension

The host-side wrapper boots a temporary CiviCRM for civix, generates the
module, applies civikitchen's tooling layer and only then moves the result into
place. A failure leaves no partial directory.

```bash
/path/to/civikitchen/scaffold/ckcreate example_ext \
  --author "Example Maintainer" \
  --email dev@example.org \
  --copyright "Example Organisation" \
  --license Proprietary
```

The target is `./<key>`. Values you repeat go into
`~/.config/civikitchen/ckcreate.conf`; `ckcreate --help` lists the keys, flags
and defaults. The result is an uncommitted git repository with one install
test, and every gate of ck ci passes from the start.

An existing civix module gets the tooling layer from ckinit. Run it from the
civikitchen checkout at the version the repository pins, with the extension
directory as argument; `CKINIT_IMAGE` overrides the image it runs in. It refuses to overwrite existing files; use `--force`
only after reviewing the conflicts.

```bash
/path/to/civikitchen/scaffold/ckinit /path/to/org.example.myext
```

### Managed and seeded template files

The template stays an interface after day one
([ADR-0013](adr/0013-managed-and-seeded-template-files.md)). Managed files stay
byte-identical in every repository: the CI caller, the test bootstraps and the
CI compose stack. Seeded files become the repository's own after the first
copy: composer.json, the phpstan config, the dev compose file and .gitignore.

```bash
/path/to/civikitchen/scaffold/ckinit --check  /path/to/org.example.myext   # report drift, exit 1 on any
/path/to/civikitchen/scaffold/ckinit --update /path/to/org.example.myext   # rewrite managed files, create missing ones
```

`--update` never touches an existing seeded file; review its result with
`git diff`. The shared workflow runs `--check` on every push against the ref
the caller pins ([Releases](releases.md#what-a-consumer-pins)), so a template
improvement shows up as a red CI run that one `--update` fixes.

The CI caller and the CI compose file contain managed blocks between
`# BEGIN CIVIKITCHEN MANAGED <name>` and `# END CIVIKITCHEN MANAGED <name>`.
Only those blocks are compared. Workflow inputs, further jobs or extra mounts
outside them belong to the repository.

A repository that must deviate inside a managed block or file declares it,
with a mandatory reason, in its civikitchen.yaml:

```yaml
policy:
  template_custom:
    paths:
      - tests/phpunit/bootstrap.php
    reason: Drupal settings discovery and its own test DSN
```

Repository-specific test setup goes into `tests/phpunit/bootstrap.local.php`,
which the managed bootstrap loads when it exists. The shell suites work the
same way. The managed tests/e2e/lib.sh carries the shared plumbing:

- `check`, `note` and `finish` for the verdict
- `app` and `cv` as the web user of either image flavor
- `cv_scr [--user=<name>] <file>` and `sql`, which run a host PHP file
  (as that CiviCRM user when given) or a query; inline PHP goes through
  `cv_scr /dev/stdin <<< "$code"`
- `hit`, `body_lacks`, `cfg_value` and `auth_header` for HTTP calls with
  `$BASE_URL`, which defaults to the app's published port
- the maildev helpers, with `$MAILDEV_URL` taken from maildev's published port

A suite's domain helpers go into `tests/e2e/lib.local.sh`, which lib.sh
sources last. They can use everything above and override its defaults.

The suites leave their evidence in `out/` next to themselves. The seeded
`.gitignore` ignores `/tests/e2e/**/out/`; a repository whose `.gitignore`
predates that adds the line by hand, since ckinit never rewrites it.

## Civix workflow

The image ships [civix](https://github.com/totten/civix):

```bash
ckx civix generate:entity MyEntity       # APIv4-exposed entity + schema
ckx civix generate:test --template headless \
    \\Civi\\Myext\\Test\\MyHeadlessTest       # boilerplate for a headless test
ckx civix upgrade                        # re-run periodically; bumps mixins,
                                         # backports polyfills, refreshes
                                         # generated stubs to current civix
ckx ckcivix --check                      # fails when the civix layer is behind
ckx ckcivix --update                     # runs civix upgrade -n
```

`ckcivix --check` compares the declared `<civix><format>` with the newest
upgrade step civix knows. `civix upgrade` also runs a general pass every time:
it regenerates `*.civix.php`, removes empty hook stubs from the module file and
reconciles the mixins in info.xml. `--update` can therefore still change files
while `--check` passes. Mixin declarations are checked by ckconform; run
`ckcivix --update` after a civix bump to pick up the rest.

Use generate:entity rather than writing a schema file by hand: it also creates
the DAO stub the schema names. A schema without its stub passes every static
check and then fails at install with `Class "…" not found`, after earlier
tables already exist. ckconform catches a missing or drifted stub.

Configure features in info.xml through
[standard mixins](https://docs.civicrm.org/dev/en/latest/framework/mixin/standard/)
instead of hooks. smarty-v2 is a deprecated alias of smarty@1 and behaves the
same.

## Static analysis and linting

What each gate enforces, and why, is in
[Extension standards](extension-standards.md).

PHPStan resolves CiviCRM classes through the managed
[`phpstanBootstrap.php`](https://github.com/civico-de/civikitchen/blob/main/scaffold/template/extension/phpstanBootstrap.php).
It loads core, core's own ext packages, and every extension listed under
requires in info.xml that exists in the ext directory, so no repository needs
its own bootstrap or scanDirectories entry. A missing required extension is
noted on stderr, and phpstan reports its classes where the code uses them. The
same list drives the
[extension boundary rule](extension-standards.md#tooling-every-repo-must-have).

cklint checks changed PHP files with `php -l` and mago lint and skips generated
code. It uses civikitchen's baseline unless the extension commits its own
mago.toml, which should extend the baseline
([Extension standards](extension-standards.md#tooling-every-repo-must-have)).

```bash
ckx phpstan analyse
ckx cklint
ckx cklint --all
ckx ckconform     # repository conventions, run from the extension root
ckx cktaint       # taint analysis
```

## Modernizing

ckmodernize runs civix upgrades (civix extensions only) and a bundled Rector
setup with CiviCRM-specific rules. By default it previews the code changes and
lists the civix steps; `--fix` applies both. An extension's own rector.php
wins. `--no-civix`, `--no-rector`, explicit paths and `--all` change the scope.

```bash
ckx ckmodernize
ckx ckmodernize --fix --php 8.2
ckx ckmodernize --api          # also migrate safe APIv3 calls; --api=array for array style
```

The API migration touches only get calls whose result is discarded or read as
values or count. The rows it returns are APIv4 rows, so review the code that
reads them:

- Ids are integers, and APIv3's joined contact fields such as email are gone.
- APIv4 hides deleted, test and template records unless the where clause names
  the field. Test recurring contributions or a trashed contact can appear or
  disappear.
- APIv3-only defaults, such as entity_table on Note, no longer filter.

When a release retires a tool, `ckx ckmigrate` moves the repository off it in
place. For phpcs it turns ignores of sniffs that became mago rules into mago
pragmas, drops the other phpcs comments and the phpcs config. It exits 1 and
lists what it cannot decide. A pragma covers one finding where the old ignore
covered the line, so run cklint afterwards and review with `git diff`.

## CI

CI runs the same loop unattended: boot the stack, enable the extension, run
phpunit headless. A templated extension calls the shared extension-ci.yml. For
a repository without the template,
[`examples/ci/`](https://github.com/civico-de/civikitchen/tree/main/examples/ci/)
holds a workflow and a minimal compose stack to copy.

### Running the CI gates locally

ck ci runs every in-container gate of the shared workflow, in the same order
and with the same arguments, and ends with a summary table. The workflow calls
the same command.

```bash
ckx ck ci
ckx ck ci --only cklint,ckfmt
ckx ck ci --skip ckcoverage
ckx ck ci --help                  # list the gates
```

Every gate runs even after one failed. Exit code 1 means a gate failed, 2 a
usage error. phpstan-tests runs when its neon file exists, and phpunit-extra
when `--extra-phpunit-config FILE` is given. ckanalyze is on trial: its
findings show as shadow and never fail the run.

Gates that read git run in `CK_TOOL_PATH`, gates that boot CiviCRM in
`CK_EXT_PATH`. Both default to the current directory. ckx sets the tool path
for an extension below the repository root (see
[Several extensions in one repository](#several-extensions-in-one-repository)).

To check against the
[organisation-wide defaults](extension-standards.md#repository-policy-civikitchenyaml),
mount that civikitchen.yaml into the container and pass its container path as
`-e CK_DEFAULT_CONFIG=…`.

The template drift check, the lockfile and secret scans, the JS unit tests and
the stack start run on the host and only in the workflow. Private composer
packages and private sibling extensions need opt-in inputs and secrets,
described in [Extension standards](extension-standards.md#private-dependencies).

## Several extensions in one repository

A repository can hold several extensions that ship together. Its root has no
info.xml; each extension sits in a direct subdirectory with its own info.xml,
civikitchen.yaml, composer.json and tests. Deeper nesting is not supported. The
decisions are in [ADR-0005](adr/0005-several-extensions-in-one-repository.md),
[ADR-0006](adr/0006-lockstep-releases-in-a-multi-extension-repository.md) and
[ADR-0007](adr/0007-the-repository-at-a-second-neutral-path.md), the checks in
[Extension standards](extension-standards.md#several-extensions-in-one-repository).

Run ckinit at the root. It manages the root files (.gitattributes,
renovate.json, the CI and release workflows) and then each subdirectory with an
info.xml. Each extension's CI compose file gets a managed mount of the
repository root at `/civikitchen-repo`, where the git-reading gates run. The
dev compose file is seeded, so an existing repository adds that mount by hand.

The root workflow has one static job per extension, not a matrix, because the
workflow checks cannot evaluate matrix values
([Reusable workflows](reusable-workflows.md)):

```yaml
jobs:
  base:
    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      working_directory: base
  addon:
    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      working_directory: addon
      playwright: true
```

ckinit owns each job's id, uses line and working directory; inputs after the
END marker belong to the repository. `--update` adds and drops jobs as
extension directories come and go.

**Dependencies inside the repository.** An extension that requires a neighbour
mounts it after the managed block of its CI compose file. The mount target is
the dependency's key, not its file name:

```yaml
# END CIVIKITCHEN MANAGED app
      - ../../base:/var/www/html/ext/org.example.base
```

A mount in the dev compose file alone does not reach CI
([`monorepo-requires-mounted`](extension-standards.md#several-extensions-in-one-repository)).

**Versions move in lockstep.** All extensions carry the same version and
release date, so one `vX.Y.Z` tag describes all of them.

**One tag releases every extension.** The root release caller has one build job
per extension and one publish job that needs all of them and creates the
GitHub release with every archive:

```yaml
jobs:
  base:
    permissions:
      contents: write        # a called workflow can only narrow this
    uses: civico-de/civikitchen/.github/workflows/extension-release.yml@v1
    with:
      working_directory: base
      stage: build
  addon:
    needs: [base]
    permissions:
      contents: write
    uses: civico-de/civikitchen/.github/workflows/extension-release.yml@v1
    with:
      working_directory: addon
      stage: build
  publish:
    needs: [addon, base]
    permissions:
      contents: write
    uses: civico-de/civikitchen/.github/workflows/extension-release.yml@v1
    with:
      stage: publish
```

ckinit owns ids, uses, working directory, stage and needs. A build job needs
the jobs of the neighbours its extension requires, because the smoke test
installs their archives from the same run. If one build fails, nothing is
published. An extension with `release: none` gets no job, and nothing that
releases may require it.

[`examples/monorepo/`](https://github.com/civico-de/civikitchen/tree/main/examples/monorepo/)
holds two extensions, one requiring the other.

### What adopting it costs a repository

Expect the first run of the real gates to be red: code that was never
formatted, linted, analysed or tested in CI. Fix that debt per extension, in
its own commits, before switching the root workflow over, and do not waive it
through ignore_checks. In the same change, delete the per-extension CI
workflows and renovate.json and align the versions.

## UI tests with Playwright

For browser tests of forms, Angular or React widgets and JS behaviour, start
from
[`examples/extension-with-playwright/`](https://github.com/civico-de/civikitchen/tree/main/examples/extension-with-playwright/).
It boots the standalone stack and runs the suite against localhost:8080 with
one shared login. `ck playwright` runs it in an image with the browsers of
the Playwright version the lockfile resolves and the repository's npm or Bun
tree, so the host needs neither Node nor browsers
([ADR-0019](adr/0019-browser-suites-run-in-the-projects-playwright-image.md)):

```bash
cd examples/extension-with-playwright
docker compose up -d
../../scaffold/ck playwright npm run test:e2e
```

`ck` is `scaffold/ck` of a civikitchen checkout, which runs ck itself in a
container, so the host needs no PHP. The image is built once per lockfile,
which takes a few minutes the first time. It mounts the git work tree at its
own path and shares the host network, so the suite reaches the stack on
localhost and can call `docker compose`. A `node_modules` on the host is hidden
from it. There is no headed browser; UI mode, the HTML report and the trace
viewer serve on localhost instead:

```bash
ck playwright playwright test --ui-host=127.0.0.1 --ui-port=9323
ck playwright playwright show-report --host 127.0.0.1
```

The
[example's README](https://github.com/civico-de/civikitchen/blob/main/examples/extension-with-playwright/README.md)
explains how to move the config into your repository, and
[Extension standards](extension-standards.md#frontend-js-dependencies-js-tests-and-browser-tests)
covers the CI job.

## IDE step debugging

Xdebug is installed and stays off until you set `XDEBUG_MODE`:

```yaml
services:
  app:
    environment:
      XDEBUG_MODE: "debug,develop"
      # XDEBUG_CLIENT_HOST: host.docker.internal   # default — works for Docker Desktop
      # XDEBUG_CLIENT_PORT: "9003"                 # default
      # XDEBUG_START_WITH_REQUEST: trigger         # default; "yes" to break on every request
      # XDEBUG_IDEKEY: VSCODE                      # default
```

For VS Code, add a launch.json whose path mapping matches your volume mount:

```json
{
  "version": "0.2.0",
  "configurations": [{
    "name": "Listen for Xdebug",
    "type": "php",
    "request": "launch",
    "port": 9003,
    "pathMappings": {
      "/var/www/html/ext/myextension": "${workspaceFolder}"
    }
  }]
}
```

In PhpStorm, enable "Listen for PHP Debug Connections" on port 9003 and map
your project to `/var/www/html/ext/myextension`.

With the default trigger mode, Xdebug activates only when a request carries
`XDEBUG_TRIGGER=1` as cookie, GET/POST parameter or environment variable. The
[Xdebug Helper](https://xdebug.org/docs/step_debug#start_with_request) browser
extension sends it.

## Troubleshooting

- `$GLOBALS[_CV][TEST_DB_DSN] is not set`: a headless boot found no test DSN.
  The image writes it on every boot unless `CIVIKITCHEN_TEST_DB=0`; with the
  opt-out, set it in the cv config yourself.
- `Table 'civicrm_test.civicrm_search_display' doesn't exist`: the test
  database read the dev site's cache, which CiviCRM keys by version alone.
  The image sets `CIVICRM_DB_CACHE_CLASS=ArrayCache` for headless boots so
  this cannot happen even when the settings select FileCache, Redis or Memcache.
- MariaDB error 1227 during schema init: the harness runs
  `SET global innodb_flush_log_at_trx_commit` (Civi\Test\Schema::setStrict),
  which needs SUPER. Provisioning grants it; a database without the grant, or
  restarted without `--log-bin-trust-function-creators=1` (the example stacks
  pass it), refuses the harness.
- `DB Error: already exists` on install: an earlier attempt failed after
  creating some tables, typically `Class "…" not found` on a schema without
  its DAO stub. A retry on the same database hits the tables that exist.
- The status check still calls civicrm.org: the stack was provisioned before
  the setting existed. Recreate it with `docker compose down -v`. If you set
  ext_repo_url yourself, it must be boolean false; an empty string still checks.
- ckanalyze failed the run: findings never do, a crash of the tool does. A
  repository's own mago.toml loads the civikitchen plugins only when it
  extends the baseline, and its `[source]` lists add to ckanalyze's scope.
- Monorepo release and CI checks: `ckinit --check` fails while jobs and
  extension directories disagree. ckinit refuses a releasing extension that
  requires a `release: none` one, reports a leftover release.yml when nothing
  releases (`--update` deletes it unless lines outside its managed blocks are
  the repository's), never overwrites one without managed markers, and reports
  a second workflow calling extension-release.yml. The release-workflow check
  fails when an extension's job is missing, runs a stage other than build,
  does not need its same-repository dependencies' build jobs, or is not needed
  by a publish job (indirect needs count).
