# CiviCRM core development

## Core test suites: `ckcoretest`

To check a core patch or backport locally, run core's own phpunit suites with
`ckcoretest`. It works against the installed core, on the standalone image only.

The composer dist leaves out core's tests, `phpunit.xml.dist` and the
`sql/test_data*.mysql` seed files. On first use, `ckcoretest` fetches exactly
those for the installed version, including the per-extension suites under
`ext/*/tests`. It does this with a sparse, blob-filtered checkout of the
matching tag and caches the result in the container. Then it runs
`CIVICRM_UF=UnitTests phpunit <args>` from the core directory. With
`--ext <name>` it runs from that core extension's directory instead.

The test path goes last. When it lies in a directory with its own
`phpunit.xml.dist`, such as core's test extensions under
`tests/extensions/<name>`, phpunit runs from that directory with its config
and the path made relative to it. Core's root config boots CiviCRM only to
the settings level, which leaves those suites without a database connection:
`ckcoretest --e2e tests/extensions/router-test/tests/phpunit`.

A core version without a git tag, such as an alpha from `master`, comes from
a git-source image, which keeps its tests. `ckcoretest` has nothing to fetch
for it and stops with a message if `tests/` is missing.

It covers the headless PHP suites: api, CRM, Civi and the extensions. It
refuses to run until `TEST_DB_DSN` is provisioned.

A typical loop: patch the file under `/var/www/html/core`, then run
`ckcoretest tests/phpunit/api/v4/Query`. Run the suites your patch touches and
leave the rest to civicrm.org's Jenkins, which runs the full matrix on every
core PR. Upgrade tests and the karma and qunit JavaScript tests need a
buildkit/civibuild environment. That gap is accepted for the same reason.

### End-to-end tests

`ckcoretest --e2e` runs the `@group e2e` tests. They run against the dev site,
not the test database. They install the extensions they need into the site,
so disable those afterwards with `cv ext:disable`. They log in as
`ADMIN_USER`, which provisioning writes to `~/.cv.json` from
`CIVIKITCHEN_DEMO_USER`. They run as the web user `www-data`, also when
started as root (`exec -u root` or `user: root`), so files the site writes
during a test stay writable for it.

Browser tests (`Civi\Test\MinkBase`) also need the compose stack's `browser`
service, a headless Chrome on the app's network. Start it with
`docker compose --profile browser up -d`. Tests that read `DEMO_USER`, a
second user without admin rights, still fail, because the image creates only
the admin.

### Type-checking core's JavaScript

`ckeslint --core [dir]` type-checks CiviCRM core's own JavaScript. It is an
analysis tool for upstream bug reports, not a gate. The directory defaults to
`$CIVICRM_CORE_DIR`, then `/var/www/html/core`.

It copies core's first-party JavaScript, without vendored trees, minified
bundles or tests. Next to it go a `tsconfig.json` with `allowJs` and `checkJs`
and a declaration file for the globals that core's pages load by script tag.
Then it runs oxlint with `--type-check`, the correctness category and the
APIv4 contract rule.

To work with core's git history in a CMS site, build a buildkit image with
`--build-arg KEEP_GIT=1`
([Building locally](building.md#keeping-the-civicrm-git-history-keep_git1)).

## Standalone on an unreleased branch

`:standalone-master` and `:standalone-beta` are Standalone built daily from
core's `master` and from its newest release branch
([Tags and versions](images.md#tags-and-versions)). For any other branch or
tag, or for a build of your own, build Standalone from git:

```bash
scaffold/ck make build CIVICRM_SOURCE=git CIVICRM_VERSION=master   # tags civikitchen:standalone-master
```

The build does what core's release script does for the tarball. It checks out
civicrm-core and civicrm-packages at the same branch or tag, runs
`composer install --no-dev` with the bower assets, and then applies core's
standalone scaffold. Every build fetches the branch's current commit.
`/usr/local/share/civikitchen/civicrm-source.log` names the commits the image
holds. Unlike the tarball, this core keeps its test directories and seed SQL,
so `ckcoretest` runs without fetching them.

A core ref that civicrm-packages does not have, such as a single commit,
takes the packages ref separately:

```bash
scaffold/ck make build CIVICRM_SOURCE=git CIVICRM_VERSION=<commit> CIVICRM_PACKAGES_VERSION=master
```

To test a core change, mount the directory it touches from your civicrm-core
checkout over the image's copy. Keep the change as a patch file and toggle it
there with `git apply` and `git apply -R` to get a failing and a passing run,
or mount one of two worktrees. `git stash` would take the reproducing test
and any unrelated work along with the change:

```yaml
services:
  app:
    image: ghcr.io/civico-de/civikitchen:standalone-master
    volumes:
      - ../civicrm-core/ext/afform:/var/www/html/core/ext/afform
```

Run `cv flush` after each toggle, or the Angular and asset caches keep the old
files. A change to composer dependencies, or to anything outside the mounted
directory, needs a rebuilt image.

## One command on one version: `ck run`

`ck run` boots a fresh Standalone stack from an image, runs one command in it
and removes the stack with its volumes. It runs on the host through
`scaffold/ck` and needs no compose file.

```bash
ck run standalone-master -- ckcoretest tests/phpunit/api/v4/Query
ck run standalone-master --mount "$PWD/tests:/var/www/html/core/tests" -- ckcoretest tests/phpunit/CRM/Utils
ck run standalone-6.16 --name bug-1234 --keep -- cv api4 Contact.get
```

The image is a civikitchen tag or a full image name. `--mount` puts a host
path into the app container, such as a directory of a civicrm-core checkout
over the image's copy; the path must lie under `$HOME`, the civikitchen
checkout or the current git work tree. The command runs as www-data from
`/var/www/html`, and its exit status is the status of `ck run`.

The images carry no core language files, so CiviCRM offers only en_US.
`--locales de_DE,es_MX,fr_CA,fr_FR,it_IT,nl_NL` installs those at boot, as
`CIVIKITCHEN_LOCALES` does ([Configuration](configuration.md)); core's
`@group locale` tests use these. The image has only the C and POSIX system
locales, so `CRM_Core_I18n_LocaleTest::testInsertTurkish` (`tr_TR.utf8`) fails.

The compose project is `ck-run-<tag>`, or the name given with `--name`; a
second run on the same tag at the same time needs its own name. With `--keep`
the stack stays up: `ck run` writes its compose file to the current directory
and prints the commands to enter and to remove it.

## Comparing two versions: `ck ab`

`ck ab` answers whether a check that passes on one CiviCRM version fails on
another. It boots two Standalone stacks, runs the same command in both and
compares the results. It runs on the host through `scaffold/ck`.

```bash
ck ab standalone standalone-beta -- ckcoretest --log-junit /tmp/ck-ab.xml tests/phpunit/api/v4/Query
ck ab standalone-6.16 standalone-master --mount "$PWD:/var/www/html/ext/myext" -- cv api4 Contact.get
```

Each side is an image reference or a civikitchen tag
([Tags and versions](images.md#tags-and-versions)). The command runs in each
app container as `www-data`, from `/var/www/html`. `--mount <src>:<dst>` puts
a host path into both containers; Docker must share it, so keep it under
`$HOME` on colima and Docker Desktop. `--locales` installs core language files
in both, as with `ck run`.

When the command writes a JUnit report to `/tmp/ck-ab.xml`, `ck ab` compares
per test and lists four groups: pass on A and fail on B, fail on A and pass on
B, fail on both, and ran on A but skipped or missing on B. The exit status is
1 when the first group is not empty. Without a report it compares the two exit
statuses, and 1 means the command passes on A and fails on B. A report on one
side only, or one without test cases, is an error.

The output names the CiviCRM version of each side, and for a git-built image
the commits it holds. The daily tags move, so note those commits with a
result. The compose files and both reports stay in the output directory
(`--out`, default `./ck-ab-<A>-vs-<B>`); the stacks and their volumes are
removed at the end unless `--keep` is given.

## Recording a change before and after: `ck demo`

`ck demo` shows what a civicrm-core change does on screen. It boots a fresh
site, records a video without the change and one with it, and writes both side
by side into a single HTML page. It runs on the host through `scaffold/ck`,
which needs only Docker; PHP and the browser run in containers.

```bash
ck demo 12345 --page civicrm/admin/search                 # a civicrm-core PR
ck demo https://github.com/civicrm/civicrm-core/pull/12345 --page civicrm/admin/search --cms all
ck demo ./fix.diff --page 'civicrm/contact/search?reset=1' --cms standalone,joomla
```

`--cms` takes a list of `standalone` (the default), `wordpress`, `drupal10`,
`drupal11`, `joomla` and `backdrop`, or `all`. Every CMS boots from its image in a compose
project of its own, on a free port from 8300 up; `--image <cms>=<ref>` boots
one from another image. The docker CLI in `scaffold/ck` has no registry login,
so pull an image from a private registry on the host first; a stack boots from
an image that is already there. Before anything is recorded, the change is checked
against the core of every site. If it does not apply to one of them, the run
names the failed hunks and stops without recording, rather than writing a page
that leaves that CMS out.

Then, per site, `ck demo` runs each `--cv` command, for example
`--cv 'en -q riverlea'`, and records the `--page` routes logged in as
admin/admin. It applies the change with `patch`, flushes the caches (and
publishes the assets on Drupal), and records again. The output directory
(`--out`, default `./ck-demo-pr<number>` or the diff file's name) receives
`<cms>-before.webm`, `<cms>-after.webm` and `index.html`, which embeds the
videos and lists the script errors each page logged. `scaffold/ck` mounts
`$HOME`, the civikitchen checkout and the current git work tree, so a diff file
and a `--steps` module must lie below one of them.

The change is applied as files only: no `composer install`, no database
upgrade. A change to dependencies or the schema needs an image built from the
branch ([Standalone on an unreleased branch](#standalone-on-an-unreleased-branch)).

For more than page views, `--steps` takes a self-contained ES module that
drives the page after the routes; it can import `@playwright/test`, but no
files next to it. `url()` turns a CiviCRM route into the URL of the CMS being
recorded, `cms` names that CMS, and `phase` is `before` or `after`:

```ts
export default async ({ page, url, cms, phase }) => {
  await page.goto(url('civicrm/contact/search?reset=1'));
  await page.locator('#sort_name').fill('admin');
  await page.keyboard.press('Enter');
  await page.waitForLoadState('networkidle');
};
```

Both phases run against the same database, so a step that changes data leaves
that change for the after run. A step that shows a state-changing action must
create its own starting conditions in each phase, such as a fresh record, or
the after run may succeed only because the before run already did the work.

`--keep` leaves the stacks running with the change applied and prints their
URLs. A later run with the same `--out` and the same images finds them on
their ports and reverts the change before it records. Without `--keep`, the
stacks and their volumes are removed at the end, also after a failure.

The recorder runs in the image `ck playwright` builds for this repository:
Playwright's official image at the version `package-lock.json` locks, plus the
repository's npm dependencies, built once per lockfile as
`civikitchen-playwright:<hash>`. It joins the host network, so it opens
each site under the same `localhost` URL the site was installed with. Docker
writes the videos into the output directory, so Docker must share it: on colima
and Docker Desktop, keep it under `$HOME`. `ck demo` checks this before it
boots a stack.
