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
make build CIVICRM_SOURCE=git CIVICRM_VERSION=master   # tags civikitchen:standalone-master
```

The build does what core's release script does for the tarball. It checks out
civicrm-core and civicrm-packages at the same branch, runs
`composer install --no-dev` with the bower assets, and then applies core's
standalone scaffold. Every build fetches the branch's current commit.
`/usr/local/share/civikitchen/civicrm-source.log` names the commits the image
holds. Unlike the tarball, this core keeps its test directories and seed SQL,
so `ckcoretest` runs without fetching them.

To test a core change, mount the directory it touches from your civicrm-core
checkout over the image's copy. Toggle the change there with `git stash` and
`git stash pop` to get a failing and a passing run:

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
