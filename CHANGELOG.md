# Changelog

All notable changes to civikitchen are documented here. A release is one
versioned contract — the reusable workflows, the extension template,
`scaffold/ckinit.php` and the images ship together, and a consumer pins `@v1`
and `:v1` (see [Releases](docs/releases.md)). Entries are written for that
consumer: what changes for a repo calling the workflows, running the toolbelt,
or pulling an image.

The format is [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
except that a break the consumers are adjusted for ships as a minor, marked
**Breaking** ([versioning rules](docs/releases.md#versioning-rules)).

## [Unreleased]

### Added

- *Build CMS Images* takes a `skip` input on dispatch: the CMS families to
  leave out of the run (e.g. `joomla` while its download host is down), so the
  others still build and promote. An unknown family or skipping all of them
  fails the run.

### Fixed

- A new container's `~/.cv.json` also names the demo user as `DEMO_USER`,
  `DEMO_PASS` and `DEMO_EMAIL`, and adds `ADMIN_EMAIL`, so end-to-end tests that
  act as the demo user (afform's `MockPublicFormTest`, authx, `CliRunnerTest`)
  find it.

## [1.49.0] - 2026-10-21

### Added

- `sibling_repo`: a sibling that declares `policy.dist.build` in its
  civikitchen.yaml is built before the stack boots, in every job that checks
  siblings out (`ci`, `playwright`, `mutation`, `compat`, `upgrade`,
  `schema-parity`, `core-upgrade`, and `playwright-e2e.yml`): `bun install
  --frozen-lockfile` and `bun run build` on the Bun it pins and on
  `node_version`. A failed build or a missing declared output fails the job;
  building siblings must pin the same Bun. The build runs before the
  private-dependency token is written to disk, and a sibling's
  civikitchen.yaml has to validate against the calling civikitchen version.

### Changed

- Only the standalone and standalone-demo images gate a release. The CMS
  flavors (drupal10, drupal11, wordpress, joomla and their `-demo` images)
  build in their own daily workflow, and a release attaches their `-v1` and
  `-vX.Y.Z` tags only to an image built from the release's trees. A CMS
  flavor's release tags can therefore lag behind a release until a re-run
  of that release catches it up
  ([Catching up a CMS flavor](docs/releases.md#catching-up-a-cms-flavor)).
- The `-v1` image tags (`:v1`, `:standalone-v1`, …) move only when the
  release is the newest v1.x.y, as the git `v1` tag does; releasing an older
  patch attaches its patch tags only.
- The moving image tags (`:standalone`, `:drupal10`, `:*-demo`, …) move only
  after the trivy gate passed on the candidate digest. A fixable HIGH or
  CRITICAL finding keeps them on the last image that passed.

### Fixed

- `ckcoretest --e2e` started as root (`exec -u root`, or a service with
  `user: root`) runs the tests as `www-data`. Before, the first form a test
  saved left the site's generated `public/ang` owned by root, and later saves
  through the web site failed with only a PHP warning. A stack that already
  has a root-owned `public/ang` needs to be recreated.
- The `drupal11-demo` image builds again: civicrm-buildkit dropped
  civisualize from its demo sites and its clone cache, and the `drupal11-demo`
  site type follows. That image no longer ships the civisualize extension.

## [1.48.0] - 2026-10-20

### Added

- Seed snapshots: the selected profiles with a `snapshot` block in
  `profile.json` run their scripts once on the standalone image, and the
  database is stored as one snapshot; every later fresh boot restores it with
  its dates moved to today, in under a minute for a demo-size seed. `ck seed` restores, saves, shows the status of, rebuilds
  and prunes snapshots. See [Seed snapshots](docs/seed-snapshots.md) and
  [ADR-0018](docs/adr/0018-seed-data-is-generated-once-and-restored.md).
- `profile-schema` 0.5.0: the optional `snapshot` object (`calendar`, `sizes`,
  `after_restore`).
- `docker/profiles/seed-lib.php` gives profile scripts the generation day
  (`CK_SEED_TODAY`), a seeded RNG, period-safe dates and contributions booked
  through Order and Payment in every status.
- The standalone image ships `zstd` and the store directory
  `/var/lib/civikitchen/snapshots`. The template dev stack mounts
  `.docker/snapshots/` there (gitignored; `ckup` creates it), and the `ci` and
  `playwright` jobs of the extension workflow cache it.

### Changed

- The bundled `verein-data`, `fundraising-data`, `events-data` and
  `mailing-data` profiles are snapshot profiles. Their dates follow the
  generation day, contributions go through Order and Payment (fundraising
  adds one pending, refunded, partially paid, cancelled and failed gift), the
  membership fees fall in the last calendar year, and event and campaign
  titles carry no fixed year.

### Fixed

- `ck_seed_individual` and `ck_seed_org` in `docker/profiles/lib.php` give
  addresses a country again: `country_id:name` takes the ISO code, and the
  country name they passed was written as no country.

## [1.47.2] - 2026-10-20

### Fixed

- `extension-docs` builds the site with system fonts. A web font swapping in
  after the theme decided which code blocks scroll widened a block without
  giving it `tabindex`, so keyboard users could not scroll it and the axe
  `scrollable-region-focusable` check failed whenever the font loaded late.
  The site no longer requests Google Fonts.

## [1.47.1] - 2026-10-18

### Fixed

- `playwright-e2e` checks out the caller before its first step: the compose
  project name step ran in `working_directory` before that directory existed,
  so the job failed on every runner without a tree left from an earlier run.
- The managed `tests/phpunit/ckHeadless.php` installs each extension through
  the current extension manager. Through a manager cached before the first
  install, an extension's template directory could stay unregistered on a
  fresh test database, and Smarty failed with "Unable to load
  'file:<ext>/<name>.tpl'".

## [1.47.0] - 2026-10-18

### Added

- The managed `tests/e2e/lib.sh` takes `cv_scr --user=<name> <file>`, which
  runs the script as that CiviCRM user, for example
  `cv_scr --user=admin /dev/stdin <<< "$code"`. A local helper that wraps
  `cv scr --user` can go; `cv_scr <file>` behaves as before.
- A standalone core patch can name the first core release that needs it on a
  `Since: X.Y.Z` line; the image build skips it on older cores instead of
  failing because the patch does not apply there.

### Fixed

- The SearchKit smart group panel on standalone images with CiviCRM 6.19.1 or
  newer shows its group type options again, through a core patch until a
  release carries the upstream fix.

## [1.46.0] - 2026-10-17

### Added

- The managed `tests/e2e/lib.sh` covers what shell suites kept in their own
  copies:
  - `app` and a flavor-aware `cv` run as the web user from the site root of a
    standalone or buildkit image.
  - `cv_scr <file>` runs a host PHP file, and `site_key` reads the site key.
  - `hit`, `body_lacks`, `cfg_value` and `auth_header` make HTTP calls, and
    `mail_clear` empties maildev.
  - `BASE_URL` and `MAILDEV_URL` default to the published ports of `app` and
    `maildev`, so a stack on other ports needs no override. A maildev that is
    not published leaves `MAILDEV_URL` empty instead of reaching another
    stack's maildev on 1080.

  A repository's own helpers go into `tests/e2e/lib.local.sh`, which lib.sh
  sources last, so a `template_custom` entry for lib.sh is no longer needed.
  After `ckinit --update`, `cv` runs from the site root, both via compose and
  inside the image. On buildkit images it runs as `buildkit` instead of
  `www-data`.
- The seeded `.gitignore` ignores `tests/e2e/**/out/`, where the suites leave
  their evidence. Existing repositories add the line themselves.

### Fixed

- A `sibling_repo` whose committed `composer.lock` carries runtime packages
  gets `composer install --no-dev` on the runner before it is mounted. A
  sibling that loads its own `vendor/autoload.php` failed to enable before.
- The managed `ck_headless()` installs the whole `<requires>` closure, each
  extension after its own dependencies and one per install. An extension
  no longer has to repeat a dependency's requirements in its own `info.xml`
  to get a working test database.
- ckconform's `crm-scope` check ignores PHP comments in `.mgd.php` files. A
  docblock that names `{crmScope}{ts}` was read as an unscoped template body.

## [1.45.0] - 2026-10-17

### Added

- The managed `tests/e2e/lib.sh` has `sql <query>`, which queries the site
  database through the `mariadb` client, via compose or inside the image
  (`CK_IN_IMAGE=1`). MariaDB 11 images ship no `mysql` binary, so a suite's own
  `docker compose exec db mysql …` stops working with the 11.4 template.

### Fixed

- The image builds in CI hand composer the job token as an optional
  `composer_auth` BuildKit secret, so GitHub dist downloads no longer hit the
  anonymous rate limit (HTTP 429). Local builds run without it. buildkit's own
  composer steps run without it: that composer rejects GitHub's newer token format.
- Headless `ckcoretest` runs start phpunit with a sendmail sink and
  `variables_order=EGPCS`, startup-only settings core's suite expects. Mail
  tests no longer fail on the missing sendmail, and tests reading `$_ENV` see it.
- Standalone provisioning recreates the `<db>_test` database before seeding it.
  Seeding over an earlier one kept its `civitest_revs`, so core suites skipped
  their schema rebuild and ran on the site's schema instead of core's.
- The standalone image scans extensions at core's default depth again (3)
  instead of two levels. Two levels hid test fixture extensions under
  `ext/<key>/tests/<fixture>/`, so headless suites installing them failed with
  "Unknown extension". The custom extensions directory still honours the depth
  (civicrm/civicrm-core#37430), so the scan never walks `vendor/` or
  `node_modules/` in full.

## [1.44.0] - 2026-10-16

### Changed

- The extension template, `ckcreate`, the examples and the release workflow's test
  database move from MariaDB 10.11 to 11.4, both LTS lines. A push tests the
  standalone candidate against 11.4; the full matrix keeps 10.11. Existing repos
  pick up the new compose files with `ckinit --update`.

### Fixed

- A scenario's database healthcheck works on MariaDB 11 and later, whose images
  no longer ship `mysqladmin`. Such a scenario never got past "db unhealthy".

## [1.43.0] - 2026-10-15

### Added

- `ck_headless()` takes optional extension keys,
  `ck_headless('org.example.optional')`. They install after the info.xml
  `<requires>` and before the extension itself. Run `ckinit --update` to pick
  up the template.

### Changed

- The standalone image patches core so the custom extensions directory honours
  `ext_max_depth` (civicrm/civicrm-core#37430). Before, every cache flush
  walked each extension's `vendor/` and `node_modules/`; in a headless suite
  that is one flush per test.
- The standalone image patches core's test listener to build the service
  container once per headless test instead of twice
  (civicrm/civicrm-core#37431), about 100 ms less per test.
- The standalone image scans extensions two directory levels deep
  (`ext_max_depth`, core default 3), on the dev site and in headless tests.
  That covers `ext/<key>` and a monorepo's `ext/<repo>/<key>`; an extension
  nested deeper needs `ext_max_depth` raised in `civicrm.settings.php`.
- `CIVIKITCHEN_TEST_DB=0` skips only the `TEST_DB_DSN` write. The boot stub
  is patched either way, so a hand-set `TEST_DB_DSN` takes effect and a
  headless boot without one goes to `<db>_test`, never to the dev database.
- `ck_headless()` hashes the extension's install files once per PHP process
  instead of before every test.

### Fixed

- The template's `phpstanBootstrap.php` puts each required extension's root on
  the include path, as civix does at runtime. Before, phpstan aborted with an
  internal error on the first class of a required extension that loads a
  bundled package by a relative `require_once`. Run `ckinit --update` to pick
  up the template.

## [1.42.0] - 2026-10-14

### Changed

- The extension template's dev stack no longer starts phpMyAdmin by default.
  It sits behind the compose profile `pma`, like `browser`: add
  `COMPOSE_PROFILES=pma` to `.docker/.env` or run
  `docker compose --profile pma up -d`. ckup still assigns `CK_PMA_PORT`, so
  the port stays stable. The quickstart examples follow the template.
- ckup starts the dev stack on the image CI uses: `:standalone-<minor>` when
  `civikitchen.yaml` sets `policy.civicrm`, read with `ckconform --policy
  civicrm`. `CIVIKITCHEN_IMAGE` in the environment or in `.docker/.env` still
  wins, and ckup prints the image it chose.

### Fixed

- The managed test bootstrap's `ck_headless()` rebuilds the scratch test
  database when the extension's install files change: info.xml, the main file,
  the upgrader, any `*.mgd.php`, and `schema/`, `xml/` and `sql/`. Before, a
  new managed entity or schema change kept the old install until a forced
  rebuild, and tests failed on the missing records. `ckinit --update` brings
  the file into an extension.

## [1.41.2] - 2026-10-14

### Changed

- `cklint`'s mago stage no longer applies `too-many-methods` to
  `CRM/*/Upgrader.php`: an upgrader gains one method per upgrade step by
  contract. Other production classes keep the rule.

## [1.41.1] - 2026-10-13

### Fixed

- `extension-docs.yml` makes heading ids the way the handbook extension does,
  so a `page.md#anchor` link to a heading with umlauts, dots or underscores
  resolves on the site and in CiviCRM alike.
- A translation inherits `order`, `section` and its title from the default page
  in the site navigation, as it does in the handbook.
- ckconform's `required-extensions` check accepts an abstract class behind the
  `// ckconform-optional civirules` guard, so an optional CiviRules adapter can
  have a shared base class.
- The same check no longer counts files under `tests/`: a test that extends a
  CiviRules class needs CiviRules in the test setup, not in `<requires>`.
- A digest-pinned extension archive may contain a symlink to a file in the
  same archive; it is installed as a copy of that file, and the copy counts
  toward the extraction size limit. Such archives used to
  fail with "extension ZIP contains a symlink". Any other symlink still fails
  the install.

## [1.41.0] - 2026-10-13

### Added

- `extension-docs.yml` builds an extension's `handbook/` pages into a GitHub
  Pages site, one copy per language with `name.<lang>.md` translations falling
  back to `name.md`, and checks every page against WCAG 2.1 AA.

### Changed

- A push tests the standalone candidate against MariaDB 10.11 only; daily and
  dispatched runs keep the full database matrix.
- A new push or dispatch of Build Dev Images cancels the run in progress
  instead of queueing behind it. A daily run cancels nothing.

## [1.40.0] - 2026-10-13

### Added

- `ckconform` check `moved-repository` warns on every remaining
  `jfilter/civikitchen`, `ghcr.io/jfilter/civikitchen`,
  `jfilter/civikitchen-scenario-schema` or `@jfilter/civicrm-profile-schema`
  outside prose files.
- A documentation site at https://civico-de.github.io/civikitchen/, built
  from `docs/` with Zensical; `make docs` builds it and `make lint` fails on
  a link to a missing page or anchor.

### Changed

- **Breaking:** civikitchen moved to `civico-de/civikitchen`. Callers use
  `civico-de/civikitchen/.github/workflows/extension-ci.yml@v1` (and
  `extension-release.yml@v1`), images come from `ghcr.io/civico-de/civikitchen`,
  the npm schema package is `@civico-de/civicrm-profile-schema` and the
  composer one `civico-de/civikitchen-scenario-schema`. The jfilter images and
  packages stay pullable but no longer update. GitHub Actions does not
  follow the redirect, so a caller still naming `jfilter` fails. In a
  templated repo, `ckinit --update` rewrites the managed files; the seeded
  `.docker/docker-compose.yml` and `civikitchen.yaml` need the new names by
  hand, and `moved-repository` lists what is left.
- The CiviCRM core development guide is its own page,
  `docs/core-development.md`; `docs/images.md`'s "Tags & versions" section is
  now "Tags and versions", so its anchor reads the same on GitHub and on the
  site.
- *Build Dev Images* builds only the standalone flavors on push; the CMS and
  demo flavors build daily or on a manual dispatch. `release.sh` now requires a
  full run (daily or dispatched) covering the image commit — start one with
  `gh workflow run build-dev-images.yml` before releasing right after a push.

### Fixed

- A bind mount into the ext dir without `info.xml`, such as an empty workspace
  or a folder of extensions, no longer logs a warning at provisioning; an
  `info.xml` without a readable key still does.

## [1.39.3] - 2026-10-13

### Fixed

- `cksmarty` fails (exit 2) when CiviCRM does not boot, instead of passing
  without compiling a template: `cv scr` exits 0 when it cannot load the
  settings, so a run outside a booted stack reported a green gate.
- The template's `phpstan.neon.dist` leaves `composer-dependency-analyser.php`
  out of the analysis. Its classes exist only in the image's toolbelt, so a
  repo with that file failed phpstan under the template's `paths: [.]`. The
  file is seeded once: add the line under `analyseAndScan` by hand.
- The template's `phpstan.neon.dist` excludes `node_modules` at any depth
  (`*/node_modules/*`), not only at the repo root, so a nested frontend
  package's PHP no longer fails phpstan under `paths: [.]`.
  A repo moving to `paths: [.]` needs `excludePaths: analyse!: []` in an
  older seeded `phpstan-tests.neon.dist`, as the template has; otherwise it
  inherits `tests (?)` and stops with "No files found to analyse".

### Changed

- Catalogs regenerated from CiviCRM 6.19.0. The APIv4 field check now knows
  Case's `is_my_case`, `is_my_managed_case` and `my_case_role`, Tag's
  `_depth` and `_descendents`, and the `export`/`revert` actions on the four
  Price entities. It reports `OptionValue.domain_id`, which 6.19 drops.
  `hook_civicrm_alterAPIPermissions` warns as deprecated, as core marks it
  (APIv3 only). `Civi\Mailing\*` counts as core for the extension boundary.
- Contact and its contact types accept core's default full-text filter
  field `contact_names` (6.19+). Indices a site or extension adds through
  `civi.schema.fts_indices` are not in the catalog.
- `:standalone-6.18` keeps being rebuilt: 6.18 joins the supported minors in
  `CK_STANDALONE_EXTRA_MINORS`, now that the catalog minor no longer adds it.

## [1.39.2] - 2026-10-12

### Fixed

- The `playwright` job's browser reaches every `localhost` service on the
  runner again, such as a `webServer` the suite starts. 1.39.1 moved the
  browser into a container that reached only the site's port, so those
  pages failed with `net::ERR_CONNECTION_REFUSED`.
- The template's `phpstanBootstrap.php` resolves what an extension with its own
  entities needs at analysis time: the runtime-only `CRM_<Prefix>_DAO_Base`
  alias of the repo's civix DAOs, core's APIv3 helpers (`api/v3/utils.php`)
  and core's `CiviMix\Schema` library behind `E::schema()`. Such repos can keep
  the template's `paths: [.]` instead of narrowing the analysed paths and
  excluding their DAOs and BAOs. Refresh with `ckinit --update`.

## [1.39.1] - 2026-10-12

### Changed

- The `playwright` job runs the test fixtures' browser in a container on the
  stack's network instead of on the runner's host network. Other jobs that
  create or remove Docker networks on the same host no longer abort its
  requests with `net::ERR_NETWORK_CHANGED`. The test runner stays on the
  runner, and `localhost:<port>` still reaches the site from the browser.

### Fixed

- A boot whose selected profiles declare no `apiUsers`, like the template's
  empty `dev` profile, leaves `authx_header_cred` as it is. 1.39.0 fixed
  this only for a profile handed to `configure-api-users.php` directly; the
  first-boot path merges the selected set first, and the merged profile
  still carried an empty AuthX policy that emptied the setting.
- A container recreated on a kept database installs a `<requires>`
  dependency again when the database still lists it but its files are gone
  (`installed-missing`), instead of failing with "extension info.xml key does
  not match".

## [1.39.0] - 2026-10-12

### Added

- `policy.civicrm` in `civikitchen.yaml` names the CiviCRM minor a repo
  targets, once. `extension-ci.yml` and the release smoke test of
  `extension-release.yml` run on its `:standalone-<minor>` image; passing
  their `image` input as well fails the run. Before, a repo that pinned its
  CI image still had its release smoke-tested on the contract image's core.

### Fixed

- `cklint`, `ckfmt` and `ckanalyze` fail when `civikitchen.yaml` cannot be
  read, instead of silently linting the paths `policy.vendored_paths`
  declares as vendored.
- A profile set with neither `apiUsers` nor an `authx` policy no longer
  touches AuthX. Since the template's CI stack selects the empty `dev`
  profile, every such boot emptied `authx_header_cred`, so API-key and JWT
  header authentication stopped working in e2e suites that rely on the
  core default.

## [1.38.1] - 2026-10-12

### Fixed

- `ckanalyze` reads the extensions named in `info.xml` `<requires>` for their
  symbols, as `phpstanBootstrap.php` does, so a class extended from CiviRules
  or another required extension resolves instead of failing as unknown.
- `ckanalyze` no longer reports `missing-magic-method` for a documented
  `@property` of a class that allows dynamic properties, as every CiviCRM DAO
  does through `DB_DataObject`.
- `ckanalyze` accepts what core's `getInfo()` returns (a string-keyed map with
  `type` as a list) and `NULL` or a condition array as the `$bridge` of
  `addJoin()`, where core's docblocks are narrower.
- `.civikitchen/strauss.php` initialises the exit status it passes to
  `passthru()`, which `ckanalyze` reported as an undefined variable.

### Changed

- `ckanalyze` ignores `mixed-assignment`. Every use of a `mixed` value is still
  reported (`mixed-argument`, `mixed-operand`, …), so assigning one and
  narrowing it with an `is_*()` check no longer counts twice.

## [1.38.0] - 2026-10-12

### Changed

- **Breaking:** the profile driver applies the whole schema contract:
  `dependencies` → `config` → `seed` → `scripts`. `config` is written back on
  every apply, so drift is corrected. `seed` contacts and contributions are
  created once by their anchor (`external_identifier` = `ref`, `trxn_id`
  `profile-seed-…`), and later UI edits survive. Until now the driver ignored
  `config` and `seed` and reported the profile as applied.
- **Breaking:** profiles apply in two phases at boot. Dependencies come before
  the mounted extensions and `CIVIKITCHEN_EXTRA_EXTENSIONS`; `config`, `seed`,
  `scripts` and API users come after them, so a seed record or script can use
  the extension under development. Before, the whole profile ran first. A root
  or profile `apply.sh` runs once, in the second phase. The shared driver takes
  the phase as an optional second argument (`all`, `deps`, `content`).
- The template stacks select a dev profile, `.docker/profiles/dev/profile.json`,
  which `ckinit` seeds: the managed CI compose file and `ckboot` mount and
  trust it and create the `admin` user it seeds as. Run
  `ckinit --update` and add the profile lines to the seeded
  `.docker/docker-compose.yml` by hand. `init.d` hooks run after the profile and
  are for the stack's environment; the template's `init.d/README.md` says so.
- **Breaking:** a profile's PHP runs only when its `scripts` list names it.
  A `seeds/` directory fails `ck profile validate` and the boot with a pointer
  to `scripts`; move the files and list them. The bundled profiles have
  migrated.
- **Breaking:** the bundled profiles are split. `verein-data`,
  `fundraising-data`, `events-data` and `mailing-data` hold the records and
  need CiviCRM core only; `verein`, `fundraising`, `events` and `mailing`
  include them and add their extensions and API users, so selecting them gives
  the same site as before. Their PHP moved to the data profiles' `scripts/`.

### Added

- `include` in `profile.json` names profiles that apply first, depth-first,
  each once; a cycle or an unknown name fails before the site changes.
- `config.components` switches core components on without touching the
  others, so composed profiles keep each other's. An unknown component is an
  error.
- A missing prerequisite fails the apply before anything except
  `config.settings` and `config.components` is written, with every problem listed. This covers membership types without CiviMember,
  contributions without CiviContribute, an unknown setting, financial type,
  field or group, a `contact_ref` no contact carries, and a seed contribution
  without `financial_type_id:name`, which the schema now requires.
- `ck profile validate` rejects a `scripts` entry that is no file inside the
  profile directory.
- `config.membership_types[].fixed_period_start_day` (MMDD). A fixed-period
  type without it is an error, since core cannot compute its dates.

### Fixed

- The dev images put the web user's `~/.local/bin` (`/var/www/.local/bin`,
  buildkit flavors `/home/buildkit/.local/bin`) on `PATH`. Since the images
  run as that user, no `PATH` directory was writable, so a `*.sh` init hook
  could not install a tool. The template's `init.d/README.md` no longer
  claims those hooks run as root.
- `ckconform` treats a subdirectory with its own `info.xml` (for example a
  fixture extension the dev stack installs beside the main one) as a separate
  extension. Before, its hooks failed `hook-dispatch-name` under the main
  extension's prefix, and the other source checks judged its files too.

## [1.37.1] - 2026-10-12

### Fixed

- `.civikitchen/strauss.php` downloads Strauss through `HTTPS_PROXY`. PHP's
  stream wrapper ignores the variable, so behind an egress proxy the download
  timed out and `composer install` failed.

## [1.37.0] - 2026-10-11

### Added

- An extension whose `composer.json` declares `extra.strauss` gets the managed
  `.civikitchen/strauss.php`. It runs a pinned, checksum-verified Strauss
  release from the system temp directory, so composer can prefix bundled
  packages without the phar shipping. `.civikitchen/` is left out of release
  archives ([Prefixed dependencies](docs/extension-releases.md#prefixed-dependencies-strauss)).
  The template gate stamps and lints it too.

### Fixed

- `ckmigrate` no longer leaves a doubled blank line, or a blank line opening a
  block, where it drops a phpcs comment, so its result passes `ckfmt --check`.

## [1.36.0] - 2026-10-11

### Added

- `cklint`'s mago stage runs CiviKitchen's own rules as a mago extension
  (`/opt/civikitchen/toolbelt/mago-ext`, codes `civikitchen/<rule>`), wired
  into the baseline `mago.toml` as an extension host. A repo configures them
  in its own `mago.toml` that `extends` the baseline and repeats the
  `[extension-hosts.civikitchen]` table with worker options (`--disable`,
  `--exclude`, `--max-lines`, `--external-actions`; see
  [Extension standards](docs/extension-standards.md)).
- Two lint rules in the baseline: `civikitchen/explicit-visibility` (every
  method and property declares its visibility, no `var`, one property per
  statement) and mago's `block-statement` (braces on every control
  structure). Both carry over checks the phpcs standard ran, with the same
  findings on real extension code.
- Three lint rules ported from mago-drupal (MIT): `civikitchen/curl-ssl-verify`
  (`CURLOPT_SSL_VERIFYPEER` or `CURLOPT_SSL_VERIFYHOST` turned off in
  `curl_setopt()` or a literal `curl_setopt_array()`),
  `civikitchen/remote-address` (a read of `$_SERVER['REMOTE_ADDR']` outside
  `tests/`; use `CRM_Utils_System::ipAddress()`) and
  `civikitchen/case-fall-through` (a non-empty `case` that runs into the next
  one without a comment saying so).
- `ckmigrate` (`ck migrate`) moves an extension repository off a retired
  CiviKitchen tool in place, starting with phpcs; it exits 1 with a list of
  what needs a person.
- `ckanalyze` (`ck analyze`): `mago analyze` with the CiviKitchen analyzer
  plugins over the extension's PHP outside `tests/`, reading CiviCRM core,
  civix-generated files, `vendor/`, `.civikitchen-siblings/` and
  `policy.vendored_paths` for their symbols. `ck ci` runs it after phpstan as
  a shadow gate: findings show as `shadow` and never fail the run, a crash of
  the tool does. It reads core's APIv4 `Result` from a stub that is generic
  over its rows: a `Result` from core's `execute()`, or from `civicrm_api4()`
  without an index, gives rows keyed by field name (`array<string, mixed>`)
  to `foreach`, index access, `first()` and `single()`, a
  `Result<array{id: int}>` annotation gives that shape, and any other
  `Result` takes values of any type. An action that declares its own
  `execute()` keeps its return type. A plugin drops mago's finding on
  `(array) $result`.
- Two lint rules, off in the baseline and run by `ckcompat`:
  `civikitchen/php-compatibility` reports a dynamic class constant fetch
  `C::{$name}` below 8.3 and the functions newer than the floor that mago has
  no data for; `civikitchen/php-deprecation` reports `"${var}"` from 8.2 and
  `get_class()` without an argument from 8.3.

### Changed

- The extension template declares PHP `>=8.2` (was `>=8.1`), so `ckcreate`
  scaffolds `info.xml` from 8.2 and `ckcompat` checks new extensions against
  8.2. Existing extensions keep the floor their `composer.json` declares.
- **Breaking:** the dev images run as the web user (`www-data`; buildkit
  flavors: `buildkit`), and so does `docker exec`: a root `cv` or `ck` run
  left root-owned CiviCRM and tool caches the web server could not write.
  Apache binds port 80 as that user, which Docker allows since 20.10.
  `CIVIKITCHEN_EXTRA_PACKAGES`
  and `/civikitchen-init.d/*.sh` hooks that need root need `user: root` on
  the service. `CIVIKITCHEN_CV_AS_ROOT` and the standalone `cv` wrapper are
  gone. The demo images stay root for their embedded MariaDB.
- **Breaking:** the entrypoints no longer run `composer install` in mounted
  extensions (`CIVIKITCHEN_AUTO_COMPOSER` is gone and warns when set): the
  web user cannot write a host checkout, and a root run left `vendor/` to be
  chowned back. `vendor/` now comes from the checkout's owner before the
  stack starts, through `ck internal composer-install`: `ckup` runs it as the
  calling user, the CI workflows as the runner user, the GitLab jobs before
  `ckboot`. A scenario stack or a `playwright-e2e.yml` `prepare_command` runs
  it itself (`docs/scenarios.md`, `docs/reusable-workflows.md`).
- cklint no longer reports a mixin-only extension's main file as redundant:
  core includes the info.xml `<file>.php` of every module extension, so the
  file must exist even when the mixins register everything.
- **Breaking**: `cklint` lints with `php -l` and mago only; phpcs no longer
  runs in it, and the CiviKitchen phpcs standard, civicrm/coder and Slevomat
  are gone from the images. The CiviKitchen sniffs became mago rules:
  `CiviKitchen.I18n.UseExtensionTs` → `civikitchen/use-extension-ts`, and
  likewise `use-mixins-for-standard-hooks`, `max-file-length`,
  `no-unsafe-unserialize`, `no-tautological-assertion`,
  `no-generic-var-on-action-param`, `no-required-on-external-action`,
  `name-boolean-arguments`; Slevomat's `DeclareStrictTypes` → mago's
  `strict-types`. To migrate:
  - run `ckmigrate`. It deletes `phpcs.xml.dist` / `phpcs.xml` (`ckconform`'s
    `phpcs-config` check now fails while one is left), turns a `phpcs:ignore`
    for a check the baseline still runs into `// @mago-expect lint:<rule>`, drops
    the other `phpcs:` comments and lists what needs a person. Move a
    CiviKitchen sniff setting it lists to worker options in a `mago.toml` that
    extends the baseline: `externalActions` becomes `--external-actions`,
    `maxLines` becomes `--max-lines`.
  - `ckinit --update` rewrites the managed test bootstrap, which drops its
    `phpcs:disable` lines.
  - drop a `phpcs.xml.dist` entry from `policy.template_custom`: ckinit,
    `--check` in CI included, exits 2 on a path that is no longer a
    template file.

  Style checks without a mago counterpart are dropped on purpose (`else if`,
  comment layout, PHP 4 constructor names, Drupal-only checks);
  [Consolidating on mago](docs/mago-consolidation.md) lists each one. Layout
  is `ckfmt`'s alone.
- **Breaking**: `ckconform`'s CI check counts only a `cklint` step as the
  lint step; a workflow that runs phpcs directly gets the "no lint step"
  warning.
- **Breaking**: `name-boolean-arguments` is off in the baseline. mago fails on
  every level it reports, so the former phpcs warning would fail the gate; a
  repo arms it by repeating the extension host table without the baseline's
  `--disable`.
- The GET-mutation finding names an anonymous page class `class@anonymous`
  instead of phpstan's path hash. A class declared inside a handler no longer
  counts as the handler's code: its writes are no longer reported a second
  time against the handler, and its request-method check no longer silences
  the handler. A baseline entry for a hashed name or such a second report
  goes stale.
- The APIv4 check of `civicrm_api4()` follows the function PHP calls: a
  `use function civicrm_api4 as …` alias is checked, and a namespace's own
  function of that name is not.
- mago 1.55.0 (was 1.45.0). `ckfmt` now breaks a wrapped boolean condition
  so it opens and closes on lines of its own, and indents a wrapped
  concatenation after `=` as a block. A repo with such code fails
  `ckfmt --check` until one `ckfmt` run. The new output passes `cklint`
  unchanged, and `cklint` reports nothing new.
- **Breaking**: `ckcompat` checks with mago alone and needs CiviCRM core
  (`CIVICRM_CORE_DIR`, the image's core or `cv`'s), whose symfony polyfills
  count as available: `array_find()` on an 8.3 floor is no longer reported.
  It runs `mago lint` and `mago analyze` at the floor for syntax and missing
  functions, classes and methods, mago's Deprecation rules at the top of
  `--range` (else 8.5), and reports calls to built-in symbols PHP deprecates
  up to that version. A newer function, class or method is not reported in a
  file that guards it with `function_exists()`, `class_exists()` or
  `method_exists()`, or behind a `PHP_VERSION_ID` or
  `version_compare(PHP_VERSION, …)` check above the floor. A floor below 8.0
  (`>=7.4` in `composer.json`), a reversed range or a path that does not exist
  exits 2, so an extension that still declares PHP 7 fails `ck ci` until it
  raises its floor. Constants, enum cases, static calls and static properties
  of a newer class, and newer global constants, are reported too (a
  `defined()` guard counts); `instanceof`, type hints and `::class` stay
  silent, since PHP does not fail on them.
  `ckmigrate` drops a leftover `phpcs:ignore` for a PHPCompatibility sniff.

### Removed

- The `/opt/civikitchen-coder` link and the `CODER_REF` build argument.
- **Breaking**: phpcs, PHPCompatibility and PHPCSUtils leave the images; the
  global composer root (`toolbelt/ckdeps-root`) holds only the `ckdeps`
  analyser. buildkit's own clone keeps its phpcs.

### Fixed

- A release archive no longer ships the development configs the toolbelt
  reads: `mago.toml`, `phpstan-tests.neon(.dist)`, `phpunit-unit.xml.dist`,
  `composer-dependency-analyser.php`, `.oxlintrc.json`,
  `playwright.config.js` and `vitest.config.ts/.js` join the excluded list.

## [1.35.0] - 2026-10-10

### Added

- `extension-ci.yml` takes a `matrix_playwright` input (default off). With
  `playwright: true` and `matrix_images` set, the browser suite also runs on
  every `matrix_images` entry, each leg on its own stack and port, side by
  side. Set without `playwright` or with an empty `matrix_images`, the input
  fails the run.
- `:standalone-master` and `:standalone-beta`: Standalone built daily from
  civicrm-core's `master` and from its newest release branch, the next
  release in beta, to test an extension against what core ships next. They
  pass the smoke tests only, get no release tags and never hold up a
  release. `/usr/local/share/civikitchen/civicrm-source.log` names the core
  and packages commits an image holds.

### Changed

- The `playwright` job runs as a matrix over its images, so its check is
  named `playwright (<image>)`, or `playwright ()` without an `image` input.
  A run with `playwright` on also runs the short `compat-images` job, which
  computes the legs. Without `matrix_playwright` the job still runs once, on
  `image`, and uploads its report as `playwright-<key>`. A branch protection
  rule that requires the `playwright` check needs the new name.
- `matrix_images` must name each image once. A repeat fails the run instead of
  starting two legs that cancel each other.
- `:standalone-6.19` keeps being rebuilt once 6.20 is the stable release:
  6.19 joins the supported minors in `CK_STANDALONE_EXTRA_MINORS`.

### Fixed

- A recreated app container (image update, `docker compose up` after a compose
  change) no longer re-runs the `init.d` hooks against the kept database
  volume, which seeded it a second time. Their run is marked in the database
  (setting `civikitchen_provisioned`); profile, locales and extensions are
  still re-applied, and `docker compose down -v` still provisions afresh.
- A job in `extension-ci.yml` is cancelled only by its own copy in a newer run
  of the same caller workflow on the same ref and image: the concurrency
  groups carry the caller's workflow name and the image, a matrix leg's
  included. The legs of `compat` cancelled each other, runs of one caller with
  different `image` inputs cancelled each other from `key` on, and a scheduled
  caller and the push caller cancelled each other on the same ref. A second
  caller needs a `name:` of its own.
- **Breaking**: the `playwright` job publishes the stack only on a port that
  is free on the runner, not on 8080 or any other port the caller's compose
  file publishes on `app`, and sets `CIVIKITCHEN_SITE_URL` and
  `CIVICRM_BASE_URL` to it. Two browser jobs on one self-hosted runner host,
  from different repos or runs, failed on the taken port. A suite that
  hardcodes `localhost:8080` instead of reading `CIVICRM_BASE_URL` no longer
  reaches the site.
- `ckconform` no longer warns about an extra field outside every fieldset: an
  `af-field` with no `name` and an input type in its `defn`, which core binds
  to the form rather than to an entity. Core renders extra fields from CiviCRM
  6.15 on, and those of input types other than Email, Number, Text, TextArea,
  Toggle and Url from 6.16 on, so an extension with a lower compatibility
  floor gets a warning about them instead. A raw `>` inside an attribute value
  no longer cuts a tag short, which made a fieldset's fields look stray.
- `ckconform`'s `api4-entity` takes the lowest `<compatibility><ver>` as the
  extension's floor, as the other checks do, not the first one listed.
- The template's `ck_headless()` restores the core foreign keys only on a
  core before 6.20. 6.20 keeps them while signing the headless environment,
  and re-adding them stopped every headless suite with "Duplicate key on
  write or update". Run `ckinit --update` to pick up the template.
- `CIVIKITCHEN_LOCALES` works on an alpha or beta core, such as a Standalone
  image built with `CIVICRM_SOURCE=git` from `master` or a release branch.
  Such a core has no `civicrm-<version>-l10n.tar.gz`, so the first boot
  stopped with "no CiviCRM version"; its language files now come from core's
  daily catalogue.

## [1.34.0] - 2026-10-09

### Added

- Extension CI builds the `policy.dist.build` output before the jobs that
  run the suite or the browser tests (`ci`, `mutation`, `compat`,
  `playwright`), so a repo can keep its frontend bundle out of git and still
  test it. A Bun build requires the caller's `bun: true`.
- `extension-release.yml` takes a `node_version` input (default `24`) and sets
  up that Node for the `policy.dist.build` build, as CI does.

## [1.33.0] - 2026-10-09

### Changed

- **Breaking**: `ckconform` fails an extension that ships APIv4 entities
  (`Civi/Api4/*.php`) without the `scan-classes` mixin. Core lists every such
  entity on the site's status page ("APIv4 Entities using Legacy Entity
  Scanner"), so the former warning reached production unnoticed. Fix with
  `civix mixin --enable=scan-classes@1.0.0`; the other mixin gaps still warn.
  Only a file declaring a class counts; a deliberate exception takes a
  `ckconform-ignore-file mixin-declaration -- <reason>` comment.

### Fixed

- `ckconform`'s mixin check counts only files at the paths core's mixins
  load (`schema/*.entityType.php`, `settings/`, `managed/`, `CRM/` and `Civi/`
  for `*.mgd.php`, …). Managed records outside `managed/` now count too. A
  nested example tree such as `solutions/<step>/managed/` no longer reports a
  missing mixin for the enclosing extension.
- The images ship `oxlint-tsgolint` 7.0.2003, built with Go 1.26.8, which
  fixes the six Go stdlib CVEs the image scan excused for 7.0.2001. Three
  newer ones, fixed only in Go 1.26.9, are excused the same way until a
  tsgolint release carries them.
- `ckcoverage` points pcov at the extension root. pcov instruments only its
  `pcov.directory`, which it guesses as `./src`, `./lib` or `./app` when one
  exists, so an extension with a JavaScript `src/` measured 0% of its PHP.

## [1.32.1] - 2026-10-07

### Fixed

- The shared GitLab pipeline runs `civikitchen-ci` in merge request pipelines
  too. GitLab left the job out there, so a merge request showed a green
  pipeline in which only `civikitchen-e2e` had run.
- The managed `phpstanBootstrap.php` finds core extensions by their
  `info.xml` one level deeper as well, so afform's classes
  (`ext/afform/core`) resolve without a `scanDirectories` entry, and a
  `<requires>` entry that names a core extension is no longer reported as
  missing from the ext dir.
- `cksmarty` strips Civi tokens (`{contact.first_name}`, `{form.myFormUrl}`)
  with core's token parser before compiling a managed MessageTemplate body,
  as a real render does; such a body no longer fails as a Smarty syntax error.
- ckconform's ExtensionUtil stub takes `E::SHORT_NAME` from info.xml's
  `<file>`, as civix does, instead of the key's last segment.
- ckconform's `api4-self-entity` reads a string as an entity name only where
  APIv4 receives one: `CRM.api4()` / `crmApi4()`, a literal `ajax/api4/` URL,
  and the repo's own declared wrappers around them, found by following their
  parameters and resolved through imports and re-exports from repo paths. An
  event or action name such as `emit('FormSaved')` is no longer reported, and
  an entity passed in a later argument (`api4(config, 'Foo', 'get')`) is now
  checked. Object and class methods and wrappers imported from a package are
  not followed.

## [1.32.0] - 2026-10-07

### Added

- CI on GitLab: `ci/gitlab/extension-ci.yml` runs `ck ci`, `cklifecycle` and,
  with a `playwright.config.ts` or `.js`, the `test:e2e` npm script inside the
  image, with the database as a service. `ci: gitlab` in `civikitchen.yaml` makes `ckinit` write
  a managed `.gitlab-ci.yml` that includes it, instead of the GitHub callers and
  `renovate.json`; release archives leave the file out. Inside the image the
  managed `tests/e2e/lib.sh` calls `cv` directly.
- `ckboot` (standalone image) provisions the site inside a CI job container
  with the current directory attached as the extension, under info.xml's
  `<file>` like the compose stacks mount it, starts Apache and prints the
  extension's directory. `CIVIKITCHEN_EXTENSION_DIR` names that directory for
  any attached extension; the default stays the key.
- `ckconform` accepts a `.gitlab-ci.yml` that includes the shared pipeline as
  the repository's CI in `ci-workflow`, `ci-coverage`, `config-without-runner`
  and `npm-install`.

### Changed

- `cklint`'s mago stage no longer applies `no-request-variable` under
  `tests/`, like `too-many-methods`: a test that drives a real `preProcess()`
  has to put the simulated query into `$_REQUEST`, which
  `CRM_Utils_Request::retrieve()` reads. Production code keeps the rule.
- `cklint` runs its mago stage at the PHP floor in `composer.json`
  `require.php` instead of the baseline's 8.1, so a repo above 8.1 may use the
  language features of its floor. Rules that only apply from 8.2 on, such as
  `sensitive-parameter`, start reporting in those repos.
- **Breaking**: ckconform's `config-without-runner` no longer counts
  `ck coverage` as the runner of `phpunit-unit.xml.dist`, because ckcoverage
  runs only `phpunit.xml(.dist)`. Unless its test directories lie inside the
  main suite's, a repo with that config needs a step that names it
  (`phpunit -c`, `ckcoverage -c`, `ck ci --extra-phpunit-config`), or
  `extra_phpunit_config` on the shared CI workflow.
- The template's `phpstan.neon.dist` analyses `CRM/*/BAO/*`, and `ckconform`'s
  `test-suite-required` counts BAO classes as source: civix only seeds them,
  the logic in them is the extension's own. A seeded config keeps its old
  exclude; remove that line by hand to have phpstan analyse the BAO classes.
- `ckinit` seeds `composer.json` with the licence from `info.xml` (`Proprietary`
  as composer's `proprietary`) instead of a fixed `AGPL-3.0-or-later`, and stops
  with exit 2 when `info.xml` declares no `<license>`.

### Removed

- **Breaking**: the phpcs sniff `CiviKitchen.Security.PermissionBypass`.
  `Entity::get(FALSE)` is the modern idiom for legitimate system-context
  calls, and the sniff cannot tell those from a dangerous bypass; its only
  remedy, `phpcs:ignore`, conflicts with zero-suppression policies. A
  `<rule ref>` to it in a project ruleset now fails phpcs: remove it, and drop
  the `phpcs:ignore CiviKitchen.Security.PermissionBypass` comments.
- **Breaking**: the phpstan parameter `civikitchen.strictActionParams` and
  the identifier `ck.api4.nullableActionParamWithoutDefault`. Nullable and
  `mixed` APIv4 action parameters without a default are now always reported
  as `ck.api4.uninitializedActionParam`; drop the parameter from a repo's
  `phpstan.neon.dist`.

### Fixed

- `cklifecycle` no longer fails a managed record core keeps on uninstall by
  its `cleanup` policy (`never`, or `unused` while referenced), nor the option
  group, option value, scheduled job or custom group table it points to, nor
  the values of a kept option group.
- An extension attached through `CIVIKITCHEN_EXTENSION_PATH` counts as mounted,
  so provisioning installs its `<requires>` and declared dependency sources.
- `ckconform`'s `mixin-declaration` no longer warns about settings or menu
  files an extension loads through its own `hook_civicrm_alterSettingsFolders`
  or `hook_civicrm_xmlMenu` implementation.
- `ckconform`'s `hook-dispatch-name` no longer reports a correctly named
  `<prefix>_civicrm_postSave_<table>()` or `<prefix>_civicrm_queueRun_<runner>()`
  as never firing; core appends the table or runner to these hook names. A
  bare `<prefix>_civicrm_postSave()` or `_queueRun()` now fails: core never
  dispatches it.
- The oxfmt toolchain lifts tinypool to 2.1.2 (CVE-2026-104848,
  CVE-2026-104849), which oxfmt 0.61.0 locks to 2.1.0 and which failed the
  image vulnerability scan.
- `ckmodernize`, `cklint`, `ckcompat` and ckconform's `php-version-coherence`
  take the lowest PHP version `composer.json` `require.php` admits
  (`^8.2 || ^8.1` is 8.1) instead of the first one it names. `ckmodernize`
  stops on a floor without a rector migration set, such as `>=7.4`, instead of
  rewriting for 8.1.
- `ckdeps`' bundled config ignores the PEAR-era classes core hands to an
  extension as a parent class, return value or hook argument
  (`HTML_QuickForm`, `HTML_Common`, `PEAR`, `DB`, `DB_DataObject`, `Mail`,
  `Mail_mime`, `Net_SMTP`, `Net_Socket`, `Log`, `Pager`, `ezcMail`,
  `ezcBase`, `TCPDF`, `HTMLPurifier` and their core-shipped subclasses) and
  Smarty 5's `Smarty\`; the PEAR names match in any letter case, as PHP
  resolves them. Other PEAR packages under the same prefix, such as
  `HTML_QuickForm2`, `Mail_Queue` or `DB_Table`, stay reportable.
- `CiviKitchen.I18n.UseExtensionTs` flags `TS()`/`Ts()` and a `'ts'` callback
  passed to `array_map()`, `call_user_func()`, `usort()` and friends, and no
  longer flags namespaced functions named `ts`.
- `CiviKitchen.Security.NoUnsafeUnserialize` requires an `allowed_classes` key
  in an options array literal, reads trailing commas, comments, named and
  `match` arguments correctly, flags `'unserialize'` callbacks, and no longer
  flags namespaced functions.
- `CiviKitchen.Tests.NoTautologicalAssertion` also catches
  `assertTrue(TRUE, 'message')`, `\TRUE`, named, nullsafe and `!`-negated
  forms, `assertNull(NULL)`, `assertEmpty([])` and `assertSame`/`assertEquals`
  on two identical literals.
- `CiviKitchen.Extension.UseMixinsForStandardHooks` matches hooks by the
  info.xml `<file>` prefix, checks `function_exists()`-guarded functions,
  ignores namespaced functions, which core never calls,
  covers xmlMenu, caseTypes, themes and alterSettingsFolders, points
  navigationMenu to managed Navigation records, and no longer flags
  `alterSettingsMetaData`, which no mixin replaces.
- `CiviKitchen.Api.NoGenericVarOnActionParam` mirrors core's runtime type
  check (`int[]`, `?string`, `integer`, `object`, pseudo types), checks only
  protected, non-static, non-`_` params, accepts `array{…}` shapes, and
  recognises actions by a core action parent or a `_run(Result $result)`
  method.
- `CiviKitchen.Api.NoRequiredOnExternalAction` checks the class enclosing the
  docblock and accepts namespace-qualified `externalActions` entries.
- `CiviKitchen.Modern.NameBooleanArguments` flags `\TRUE`, matches
  `ignoreCalls` case-insensitively and ignores value positions of
  `array_push`, `addWhere`, `addHaving`, `addValue`, `set`, `assertSame` and
  similar.
- `CiviKitchen.Files.MaxFileLength` measures files that start with `<?=` or
  HTML and reports on line 1.
- ckconform reads PHP through the tokenizer in more checks, so comments, case
  and named arguments no longer cause false findings or hide real ones: hook
  functions match their prefix and catalogued suffix case-insensitively, as
  `function_exists()` does; `raw-sql` judges a named `query:` argument;
  `api4-entity` reads group and comma-separated `use` imports and no longer
  reports `Civi\Api4` sub-namespaces such as `Provider` or `Result` as missing
  entities; `api4-literal-entity` reads upper-case, named and
  `call_user_func()` calls; `psr0-class-path` ignores class names in
  docblocks and judges every declared class; `template-reference` and
  `declared-callback` accept a leading backslash and method names in any
  case; `deprecation-gate` evaluates the whole `error_reporting()` mask and
  ignores commented-out calls; `translation-catalog` reads `E::ts(text: …)`
  and ignores comments inside the call; `covers-nothing` reads docblocks and
  `#[CoversNothing]` only; `upgrader-integrity` ignores commented-out
  `executeSqlFile()` calls.
- ckconform's api4 checks accept core, required-extension and
  `known_api4_entities` entities; `api4-self-entity` ignores Angular
  constant/value/decorator registrations, web-storage keys and constructors; a
  missing entity named like a core sub-namespace still fails.
- ckconform resolves classes through info.xml and composer PSR-4 mappings, and
  a shipped `CRM/<Core>/` directory no longer claims core's namespace.
  `psr0-class-path` judges only top-level declarations, ignores guarded
  polyfills and test fixture classes, and warns, with its own message, on an
  extra class in a shipped file.
- ckconform's `deprecation-gate` evaluates `<ini>` values and
  `ini_set('error_reporting', …)` as PHPUnit and PHP do, and no longer accepts
  a lowercase `e_all`.
- Inline `ckconform-ignore` markers apply to every line-located finding of any
  check.
- ckconform's hook catalog includes the hooks core dispatches outside
  `CRM_Utils_Hook`; own helpers such as `myext_minimum_civicrm_version()` are
  not hooks. `permission-closure` reads a permission catalog generated from
  core, which adds the missing core and `cms:` permissions and drops entries
  core does not declare.
- ckconform's `template-reference` skips traits, AJAX holders and core parents
  that supply a template; `upgrader-integrity` honours core's
  `*_install.sql`/`*_uninstall.sql`; `covers-nothing` matches only the
  attribute name; `hook-style` reads only names after `extends`, `implements`
  and `use`; `deprecated-image-path` ignores YAML comments; `api3-surface`
  sees if-guarded definitions; `headless-builder-applied` reads group imports.
- ckconform's `afform-contract` no longer warns about fieldset-less fields in
  blocks and search forms; `autoload-path` accepts directory paths starting
  with `./`.
- ckconform's `ci-coverage`, `ci-workflow` and `config-without-runner`
  recognise `ck ci` (with `--only`/`--skip`), `ck lint`, `ck coverage` and `ck
  test`, ignore comments, match tool names as whole words and follow
  npm/yarn/pnpm/bun and composer scripts.
- ckconform's `committed-artifact` flags only a `vendor/` beside a
  composer.json and names each path; `compose-floating-tag` reads quoted
  images and skips services with `build:`; `compose-project-name` accepts an
  override beside a named base file; `floating-tag` covers the `container:`
  shorthand, untagged images and `docker://` steps.
- ckconform's `deploy-hygiene` accepts directories in `deploy_hygiene.paths`
  and `.env.sample`/`.env.template`; `coverage-section` requires coverage
  sources where the toolbelt's PHPUnit 9 reads them, in `<coverage><include>`
  or the legacy `<filter><whitelist>`, names a PHPUnit 10 `<source>` as
  ignored and judges a shipped `phpunit.xml` before the `.dist` file, as
  PHPUnit does; `gitignore` and `gitignore-coverage` honour a
  repository-root .gitignore and phpunit's `cacheResult`/`cacheResultFile`.
- **Breaking**: ckconform's `front-end-api3` scans `.ts`/`.tsx`/`.jsx` files
  and `crmApi()` calls, so an APIv3 call in a TypeScript test now fails it.
- `ckcoverage` parses the phpunit config: a commented-out `<coverage>` no
  longer passes, `<filter><whitelist>` does, a malformed config is reported as
  such, and a run whose filter is missing or matches no file says so instead
  of blaming the coverage driver.
- ckconform's `license-skeleton` accepts CRLF and trailing spaces;
  `license-coherence` reads `(A or B)` expressions; `lockfile` honours
  workspaces, platform packages and dependency-free manifests, and flags an
  ignored lockfile only where one is required; `npm-license` skips manifests
  under `vendored_paths`.
- ckconform's `playwright-diagnostics` reads typed, semicolon-free and
  CommonJS configs, accepts the retain trace modes and splits steps correctly
  in jobs with services.
- ckconform's ExtensionUtil stub defines `SHORT_NAME`, `LONG_NAME` and
  `CLASS_PREFIX` and answers `CRM_Core_Component::isEnabled()`;
  `managed-reference-graph` warns about dangling references only while a
  managed file could not be evaluated; `managed-entity-metadata` treats a
  missing `params.version` as the APIv3 default mgd-php applies and warns
  instead of failing.
- ckconform's `container-service-reference` no longer fails core classes under
  civix's `Civi\` classloader, still fails a missing class under an own
  single-segment prefix, and tries every PSR-4 path and prefix that matches;
  `permission-closure` knows the loop-built `… contributions of all types`
  permissions; `lockfile` reads workspace lists like npm: `*`, `?` and `[…]`
  stay within one directory level, also for non-ASCII names, a `**` segment
  also matches none, nested `{a,{b,c}}` lists expand, a leading `/` is
  ignored, a backslash separates paths (in a negation it escapes the next
  character), `a//b` and `a/x/../b` read as `a/b`, a name that is not UTF-8
  matches byte by byte and a later pattern inside a negation lifts it. The CI
  checks run the union of repeated, also quoted, `ck ci --only` lists, end
  them at a subshell or redirection, treat a list that names a variable, a
  backtick command or a brace list as any gate, count `phpunit-extra` only
  with a second config and `phpstan-tests` never for `phpstan.neon.dist`, and
  count `ck phpunit`, `ckphpunit` and `ckcoverage`, also called by an
  absolute, `~/`, `./`, `sbin/`, `.bin/`, variable or `$(…)` path, in
  backticks or named in a YAML list, as phpunit; `floating-tag` ignores
  trailing comments outside quotes and `container:` keys outside a job.
- ckconform's `required-extensions` demands search_kit only for
  SearchDisplays, not for core SavedSearches; `message-template-token` scans
  only message templates, ignores JavaScript `${…}` interpolations and
  Angular `{{…}}`, treats only the short name and a `<shortname>_` prefix as
  the extension's own namespace, and knows the site, group, survey,
  financial_trxn, contribution_product, welcome, subscribe, unsubscribe and
  resubscribe namespaces;
  `mixin-declaration` ignores files under `tests/`.
- The rector rules map named arguments by name and skip calls they cannot
  map (spread, unknown name). `Api4ArrayToOopRector` maps AND/OR/NOT where
  groups holding a literal, unkeyed list of clauses to `addClause()` and skips where rows with an `isExpression` flag
  (only `DAOGetAction::addWhere()` takes one), calls with an `$index` argument, entities
  without their own `Civi\Api4` class (`Custom_*`, `CustomValue`) and magic
  actions with a non-literal `checkPermissions`. The Api3ToApi4 rectors skip
  api3 control keys (`rowCount`, `sort`, `offset`, `option.*`, `return.*`),
  possible array filter values and non-canonical entity names, and rewrite
  a call only where its result is discarded or, inside a function, read
  solely as `$r['values']` or `$r['count']`: those reads become
  `$r->getArrayCopy()` (`$r[$key]` for one row) and `$r->countFetched()`,
  and the api4 call selects `id` and keys the rows by it unless `sequential`
  is 1. A result that is returned, passed on, read as `['id']` or written
  stays api3. The rewritten rows are api4's: its fields and value types, and
  its default filters on `is_deleted`, `is_test` and `is_template` instead
  of api3's per-entity defaults, so a count can change. A comma-list
  `return` becomes a select list and `version` is dropped.
  `CrmUtilsArrayValueToCoalesceRector` rewrites only where `??` is equivalent: no or a NULL default, and a subject that is
  provably an array, `ArrayAccess` or NULL. `CrmCoreErrorFatalToExceptionRector` passes message
  and code to the right constructor slots and skips calls without a message
  or with `$email`. `PositionalDefaultsToNamedArgsRector` skips callees that
  can be overridden or implemented elsewhere, magic and built-in methods, and
  callees that read `func_get_args()`.
- The phpstan rules read named arguments by name and stay silent on
  spreads, and match class names case-insensitively
  (`crm_core_dao::executeQuery()`). An unknown APIv4 entity is reported as a
  slip only when it differs from a core entity in letter case or by two
  swapped neighbouring letters, the same judgement as the oxlint rule.
- The phpstan APIv4 catalog includes `tags`, `_depth` and `_descendents`
  wherever core adds them at runtime. The standalone boot test, on the
  catalog's CiviCRM minor, fails when the field check rejects a field live
  `getFields` reports for `get` or `create`; the image matrix always builds
  that minor. Fluent field checks see aliases selected later in the chain or
  through `setSelect()`, and builders held in variables, and skip orderBy and
  groupBy names when a select is not fully known (spread, merged or
  conditional lists). An explicit join binds its alias whatever its
  conditions; a join whose entity is not known skips the join field checks.
  First-class APIv4 callables are checked.
- The phpstan action-parameter checks cover `?T` and `mixed` properties, trait
  properties, constructor assignments and getters that read the property only
  through `??`, `??=`, `isset()` or `empty()` or in a ternary, if, elseif,
  `&&`/`and`/`||`/`or` operand or `match (true)` arm where it is set, also
  after a negated check, a check of an offset or a comparison with
  `true`/`false`, initialise it first, also through an offset, in every branch
  that does not find it set, or return or throw while it is unset; a write
  through the property as an object no longer counts as guarded; public
  properties are no longer treated as API parameters.
- The phpstan transactional-DDL rule exempts TEMPORARY tables and reports
  custom-field updates and deletes, BAO writes (a custom-group update only when it
  sets `is_multiple`, not when it filters on it, also through `civicrm_api()`
  with version `4` or `'4'`), `self::`/`static::` helpers
  and `tearDown()`. The silent-catch rule accepts `->log()` at error level and
  nullsafe calls, and sees `CRM_Utils_SQL_Select::execute()`. The SQL table
  rule ignores strings, comments (`--` only before whitespace, as MySQL
  reads it), DROP and RENAME (also after a leading
  comment), and reads INSERT/REPLACE with or without INTO, TRUNCATE with or
  without TABLE, and comma joins. The GET-mutation rule accepts
  `validateAjaxRequestMethod()` and reads trait methods.
- The oxlint rule `civikitchen/api4-contract` no longer reports another
  extension's APIv4 entity whose name is close to a core one (`Contract`,
  `Project`, `Groups`). An unknown entity is reported only when it differs
  from a core entity in letter case or by two swapped neighbouring letters
  (`contact`, `Contatc`).

## [1.31.0] - 2026-10-05

### Added

- The standalone image builds from a civicrm-core branch:
  `make build CIVICRM_SOURCE=git CIVICRM_VERSION=master` lays out core,
  civicrm-packages and their dependencies as the release tarball does, for
  testing against `master` before a release exists.
  `/usr/local/share/civikitchen/civicrm-source.log` names the source of every
  standalone image.
- Core and extension browser tests (`Civi\Test\MinkBase`) run on standalone.
  The extension template and the standalone example carry an opt-in `browser`
  service (`docker compose --profile browser up -d`), a headless Chrome in the
  app's network; Apache also serves a `http://localhost:<port>` site URL on that
  port, so the browser opens the URLs CiviCRM generates. A new container's
  `~/.cv.json` names the demo user as `ADMIN_USER`/`ADMIN_PASS`, and
  `ckcoretest --e2e` runs `@group e2e` tests against the dev site.
- `ckconform`'s `riverlea-custom-property` fails on a `var(--crm-…)` that
  neither core nor the repository defines, with file, line and the closest
  existing name (`--crm-c-link` → `--crm-link-color`). A fallback value does
  not excuse the name: it is what renders in every theme, dark mode included.
- `scaffold/ckx <command>` runs a command in an extension's dev stack as
  `www-data`, in the extension's directory (`ckx ck ci`, `ckx civix upgrade`),
  and sets `CK_TOOL_PATH` for an extension below the git root.
- `scaffold/ckinit` runs the checkout's `ckinit.php` in the image
  (`CKINIT_IMAGE`, default `:v1`) with the git root and `CK_DEFAULT_CONFIG`
  mounted, so seeding, `--check` and `--update` need no PHP or composer on the
  host.
- `ckconform`'s `deprecated-image-path` warns on a config, script, workflow or
  compose mount that names a `/opt/civikitchen-<tool>` path or
  `/usr/local/share/civikitchen/profiles`, with the replacement. Prose (`*.md`,
  `*.txt`) is not read.

### Changed

- The images carry the toolbelt, the schema packages and the profiles under
  `/opt/civikitchen/`, laid out as in a checkout, and `/usr/local/bin/ck*`
  link into `/opt/civikitchen/toolbelt/bin/`. Every tool resolves its configs
  and toolchains from its own location; none tries an image path first and a
  checkout path second. The bundled profiles moved from
  `/usr/local/share/civikitchen/profiles` to `/opt/civikitchen/docker/profiles`;
  a profile a stack mounts below the old directory still lands in the new one.
- The template's `phpstan.neon.dist` includes
  `/opt/civikitchen/toolbelt/phpstan-config/civicrm-disallowed.neon`. The file
  is seeded, never managed, so an existing repository changes its include by
  hand; `ckconform` points at the line.
- `ckcreate` runs its PHP, composer and git steps in the image: the host needs
  bash and Docker, and the `composer install` of the scenario schema before the
  first run is gone. The civikitchen checkout has to be mountable by Docker
  (on macOS, under `$HOME`).
- `release-tag-coherence` and `unreleased-shipped-changes` no longer warn in a
  full checkout without tags, the state of every repository before its first
  release. A shallow clone is still reported, and a repository that released
  and lost its tags fails `release-tags`.
- `ckconform`'s `mixin-declaration` warning names the command that fixes it,
  `civix mixin --enable=<mixin>@<version>`, for every missing mixin.
- `cklifecycle`'s settings check fails on a pseudoconstant key core does not
  read (`option_group_name` instead of `optionGroupName`), which core only
  warns about.
- The template's CI caller comment states the versioning rule of ADR-0001 (a
  break ships as a minor marked Breaking) instead of promising `@v2`, and the
  dev compose file's sibling example mounts at the sibling's extension key.
  `ckinit --check` reports the managed `ci.yml` as drifted until
  `ckinit --update` refreshes it.

### Deprecated

- The `/opt/civikitchen-<tool>` paths (`-phpstan-config`, `-phpstan`,
  `-phpstan-ext`, `-psalm`, `-rector`, `-coder`, `-ckconform`, `-oxlint`,
  `-oxfmt`, `-mago`, `-composer-deps.php`) are links to their place under
  `/opt/civikitchen/toolbelt/` and go away in v2.
- `/usr/local/share/civikitchen/profiles` is a link to
  `/opt/civikitchen/docker/profiles` and goes away in v2. Mount a stack's own
  profile at `/opt/civikitchen/docker/profiles/<name>`.

### Fixed

- A new extension from `ckcreate` passes `ck ci`. It used to fail four gates.
  The template's `phpstan.neon.dist` listed `Civi`, `CRM` and `managed`, which
  civix leaves empty or does not create; it now analyses the whole repository
  minus generated and foreign code, and `phpstan-tests.neon.dist` puts `tests/`
  back for its pass. `ckcreate` runs `cklint --fix` over civix's files and adds
  a headless install test. It also creates the git repository.
  `make e2e-ckcreate` and the image workflow check this with real civix.
- `cklint --fix` exits 0 when phpcbf fixed every finding. phpcbf reports that
  case with exit code 1, which cklint passed on as a failure.
- `ckmodernize --fix` stops when `civix upgrade` or `civix convert-entity`
  fails instead of carrying on to the code step, and runs `convert-entity`
  only when the extension has EFv1 schema XML.
- `ck coverage`, `ck mutate`, `ck test`, `ckconform`, `ckcoretest` and
  `cktestreset` answer `--help` with their usage. `cktestreset --help` used to
  reset the test database, and `ck coverage --help` failed inside phpunit.
- `ckcreate` reads `$CKCREATE_*` from the environment over
  `ckcreate.conf`, as its help says; the config file used to win.
- `ckmutate` names `policy.mutation.paths` in its messages instead of an
  internal key.
- `examples/custom-version/` builds again: its build context still pointed at
  the removed `images/` directory.
- `examples/ci/` waits for the image's healthcheck (`up --wait`) instead of
  polling the login page.

## [1.30.2] - 2026-09-29

### Fixed

- A registry download is visible to the next `cv` call again. After an earlier
  `cv ext:enable`, a dependency downloaded later stayed out of the cached
  extension map, so its own `<requires>` could not be resolved.

## [1.30.1] - 2026-09-29

### Fixed

- A dependency the entrypoint or the release smoke test installs gets its own
  `<requires>` resolved the same way before it is enabled, pinned by the
  mounted extension's `civikitchen.yaml`. A private release requiring a
  registry extension failed with "Unknown extension", since `cv ext:enable`
  downloads nothing.

## [1.30.0] - 2026-09-29

### Added

- The standalone image applies core patches from
  `docker/standalone/core-patches/` at build time and lists them in
  `/usr/local/share/civikitchen/core-patches.log`. A patch the release already
  contains is skipped; one that neither applies nor is contained fails the
  build.

### Fixed

- Word replacements and translation replacements apply on the standalone
  image. Core's standalone boot translates settings metadata before the
  database is known and cached an empty replacement list for the whole request
  ([dev/core#5862](https://lab.civicrm.org/dev/core/-/work_items/5862)); the
  image carries the fix from
  [civicrm-core#37148](https://github.com/civicrm/civicrm-core/pull/37148) as a
  core patch.

- `cklint` outside a git checkout exits 2 with "not a git checkout", as `ckfmt`
  does. It used to exit 0 there: with no arguments it reported "no changed PHP
  files", and with `--all` or paths it skipped the mago stage, so the `cklint`
  gate of `ck ci` passed on a directory it had only half checked.

- A profile's registry extensions download under a 120 s `http_timeout`.
  Core bounds the whole archive transfer by that setting, 5 s by default, so a
  multi-MB release from a slow mirror failed the profile.

- The standalone image's first boot gets the healthcheck budget the buildkit
  images have (`start-period=600s`, 18 retries). A cold install plus enabling
  extensions that seed demo data takes minutes and was reported unhealthy
  after about five.

## [1.29.0] - 2026-09-29

### Added

- `ckeslint` checks APIv4 calls in JavaScript. The rule
  `civikitchen/api4-contract` reads entity, action and field names in
  `CRM.api4()` / `crmApi4()` literals, including the batch forms, against the
  same `Api4Catalog` and with the same judgement as the phpstan rules, and
  knows the repo's own entities and action classes and those of checked-out
  siblings. It runs in both baseline configs; a repo with its own
  `.oxlintrc.json` does not get it.
- `policy.javascript.type_check: true` makes `ckeslint` report TypeScript's
  compiler diagnostics for the repo's JavaScript: through the repo's
  `tsconfig.json`, or without one in a second pass over a copy of the tracked
  source beside a CiviKitchen tsconfig (`allowJs`, `checkJs`, not strict).
- `ckeslint --core [dir]` type-checks CiviCRM core's own JavaScript with
  TypeScript's compiler diagnostics (`allowJs`/`checkJs` over a copy of
  core's first-party `.js`), oxlint's correctness category and the APIv4
  contract rule. An analysis tool for upstream bug reports, not a gate.

### Changed

- On the standalone image, `CIVIKITCHEN_DEFAULT_LOCALE` installs the site in
  that language: the requested `CIVIKITCHEN_LOCALES` files are fetched before
  `cv core:install`, which runs with `--lang`. The seed data labels are
  translated — financial types, membership statuses, location types, payment
  instruments — while machine names stay English, and the installer applies
  core's locale defaults (for `de_DE`: EUR, Germany, ISO dates,
  Europe/Berlin). Before, only the UI switched language and every seed label
  stayed English. Existing sites are not reinstalled. The buildkit flavors
  still only set `lcMessages` on their civibuild-created site.

### Fixed

- The phpstan APIv4 rules no longer report the columns of `getFields`,
  `getActions` or custom actions as unknown entity fields: fields are judged
  for `get`, `create`, `update`, `save`, `delete` and `replace` only. Action
  names compare case-insensitively, as APIv4 resolves them (`User::Update`
  runs `update`).
- `civicrm_api4()` with a params array checks the field names in `groupBy`,
  as the fluent `addGroupBy()` already did.
- `ck` commands redirected to a file (`ck … > log`) no longer lose what they
  printed before starting a tool: the tool's output overwrote it from the
  start of the file.
- Headless boots on every image cache in the test database
  (`CIVICRM_DB_CACHE_CLASS=ArrayCache`) even when `civicrm.settings.php`
  selects `FileCache`, Redis or Memcache, which the dev site shares and a
  `Civi\Test` schema rebuild outlives.
- The standalone image writes `TEST_DB_DSN` to `~/.cv.json` and patches the boot
  stub on every boot, not only on first boot. A container recreated on a new
  image over a kept `private/` volume stopped headless boots with
  `$GLOBALS[_CV][TEST_DB_DSN] is not set`. A stub patched by an older image
  gets the current block.
- The phpcs standard no longer flags a boolean literal that is a setter's
  only argument (`setUseTrash(FALSE)`): the method name already names it, and
  APIv4's magic setters cannot take a named argument. A setter with more
  arguments, or a function merely starting with `set`, is still flagged.
- The extension template's `tests/phpunit/ckHeadless.php` passes the standard:
  it names the `reset:` argument of `getStatus()`. Run `ckinit --update` to
  pick it up.

## [1.28.0] - 2026-09-23

### Added

- `ck ci` runs every in-container gate of `extension-ci.yml`'s `ci` job —
  cklint, ckconform, ckcivix, ckfmt, ckcoverage, the extra PHPUnit suite,
  PHPStan and its opt-in test pass, ckcompat, ckdeps, cktaint, cksmarty and
  ckeslint — in the job's order, runs each even after an earlier one failed,
  and ends with a summary table. `--only` and `--skip` take gate names and
  refuse unknown ones. Run it in the dev stack with
  `docker compose exec -u www-data -w /var/www/html/ext/<key> app ck ci`.
  `ckinit` writes that line into the compose files of a newly stamped
  extension; existing repos keep their headers, which lie outside the managed
  blocks. See
  [Running the CI gates locally](docs/extension-development.md#running-the-ci-gates-locally).

### Changed

- First-boot provisioning turns off CiviCRM's calls to civicrm.org on every
  flavor: the `version_check` scheduled job is inactive and `ext_repo_url` is
  `false`. Without outbound network, the admin status check stalled the first
  admin page for over 60 s, and each first page after `cv flush` for about
  10 s, which timed out Playwright tests. The status page shows two notices
  instead; an init hook can switch either back on
  ([No calls to civicrm.org](docs/extension-development.md#no-calls-to-civicrmorg)).
- `:standalone-6.17` is rebuilt again: 6.17 joins 6.16 in
  `CK_STANDALONE_EXTRA_MINORS`, so repos pinned to that line get `ck ci`.
- The `ci` job of `extension-ci.yml` runs its in-container gates through
  `ck ci` in one step, so a local run and CI run the same gates; CI adds only
  the organisation defaults file (`policy_defaults`) as `CK_DEFAULT_CONFIG`.
  The gate table and the taint verdict go to the job summary. Each gate now
  also runs when an earlier one in the same group failed: ckconform and
  ckcivix after a red cklint. **Breaking:** a stack whose image predates
  `ck ci` fails the step with a message naming that: an explicit old image
  tag in the CI compose file or the `image` input, including a
  `:standalone-<minor>` that is no longer rebuilt. Move it to a current image.
  The canary (`@main` with `:standalone`) fails the same way from the merge
  until Build Dev Images has promoted the new `:standalone`.

## [1.27.0] - 2026-09-22

### Added

- The shared profile driver resolves the Composer dependencies of git-sourced
  profile dependencies before `cv ext:enable`: a checkout with a `composer.json`
  and no `vendor/` gets `composer update --no-dev` (`install` when it ships a
  `composer.lock`), and a failed resolution aborts the profile with composer's
  output. Profiles using extensions with runtime Composer requirements (e.g.
  `org.project60.banking`, `org.project60.sepa`) no longer need their own
  `apply.sh` to pre-resolve them.

- Repositories of several extensions can release: one `vX.Y.Z` tag releases
  every extension as one GitHub release. `extension-release.yml` takes
  `working_directory` (default `.`), `stage` (`release`, the default, builds
  and publishes one extension; `build` builds, verifies and smoke-tests one
  extension's archive; `publish` builds nothing and publishes every archive
  of the run) and `dry_run` (build without a tag, publish nothing). A build
  job's smoke test installs the archives of the same-repository extensions it
  `<requires>` from the same run. `ckinit` run at the root stamps a managed
  `.github/workflows/release.yml` with one `stage: build` job per releasing
  extension and a `publish` job needing all of them; `--check` fails on a
  missing or stale job. See
  [Several extensions in one repository](docs/extension-development.md#several-extensions-in-one-repository).

### Changed

- First boot creates, grants and seeds the `<db>_test` headless-test database
  as the database root user (`CIVICRM_DB_ROOT_PASSWORD`, default `root`),
  instead of attempting it as the app user and warning when that was denied.
  A plain `db` service with `MYSQL_USER`/`MYSQL_DATABASE` now yields working
  headless tests with no grant script, and a refused root connection fails
  the boot with the server's error text instead of leaving an unusable
  scratch database behind. Provisioning grants the app user `ALL` on
  `<db>_test` plus `SUPER` — the harness's `SET global
  innodb_flush_log_at_trx_commit` has no narrower privilege on MariaDB 10.11 —
  instead of the old `GRANT ALL PRIVILEGES ON *.* WITH GRANT OPTION`, and sets
  the server's `log_bin_trust_function_creators` as root before
  `cv core:install` and again before seeding the test database, so a
  binlog-enabled server (`mysql:8.0` default) installs without the app user
  holding `SUPER`;
  the example and template db services pass
  `--log-bin-trust-function-creators=1` so the setting survives a database
  restart. **Breaking:** the `db-init/01-grants.sql` grant
  script is gone from the examples and the extension template, and
  `.docker/db-init/01-grants.sql` is no longer a managed file — `ckinit
  --update` rewrites the managed CI compose file without the
  `docker-entrypoint-initdb.d` mount; delete the leftover `.docker/db-init/`
  directory in your repo; a leftover grant script keeps working, it only
  grants what the boot now grants anyway. Stacks whose db service uses a
  non-default root password must pass `CIVICRM_DB_ROOT_PASSWORD` to the app
  service.
- The release archive is uploaded as `ckrelease-dist-<key>` instead of
  `ckrelease-dist`, and the smoke stack's compose project name carries the
  extension, so two build jobs of one run neither collide nor tear down each
  other's stack. The publish job computes the pre-release and Latest flags
  itself.
- **Breaking:** `ckconform`'s `release-workflow` evaluates each extension of a
  repository of several extensions instead of warning "not evaluated": it
  fails when no job calls `extension-release.yml` with the extension's
  `working_directory`, when that job runs another stage than `build`, when it
  does not need the build jobs of the same-repository extensions it requires,
  or when no `stage: publish` job needs it, directly or through other jobs. The template drift check reports the root
  `.github/workflows/release.yml` missing in the same repositories. Run
  `ckinit --update` at the root, or declare `release: none` with a reason in
  every extension that never releases.
- **Breaking:** a `release.yml` left behind by `release: none` is drift for
  `ckinit --check`, in a single extension as at the root of several;
  `--update` deletes it unless it carries lines outside its managed blocks.
  A second workflow calling `extension-release.yml` is drift also when
  `release.yml` exists.
- **Breaking:** `ckconform`'s `release-workflow` fails an extension that
  declares `release: none` while one of its jobs still publishes through
  `extension-release.yml`. A job with `dry_run: true` publishes nothing and
  counts as a release caller neither there nor in `ckinit`.

## [1.26.0] - 2026-09-21

### Added

- **Breaking:** `ckconform` check `version-format`: `info.xml` `<version>`
  must be `X.Y.Z` or `X.Y.Z-<pre-release>` (SemVer 2.0, no build metadata),
  the only shapes the release workflow accepts. `2.2.7.1` or civix's default
  `1.0` fail; move to a SemVer version with the next release. `ckcreate`
  starts a new extension at `0.1.0`.
- `extension-ci.yml` takes a `working_directory` input (default `.`): the
  extension's directory in a repository that holds several extensions. Every
  job runs there, `compose_file`, the cache lockfile, artifact and scan paths
  follow it, concurrency groups and compose project names are per extension,
  and the container paths are derived once — CiviCRM work stays at
  `/var/www/html/ext/<key>`, the git-reading tools run at the same directory
  under the `/civikitchen-repo` mount. The template drift check covers the
  extension directory, and the repository's own managed files when the
  extension is a direct subdirectory of the root. The shared
  private-dependency action takes the directory as an input, because a
  composite step does not inherit the job's working directory.
  `examples/monorepo` plus `.github/workflows/monorepo-self-test.yml` exercise
  a two-extension repository where one extension requires the other.
- Repositories of several extensions — a root without `info.xml`, one
  extension per direct subdirectory — are supported for CI:
  `ckinit` run at the root manages `.gitattributes`, `renovate.json` and a
  root `.github/workflows/ci.yml` with one job per extension, then stamps each
  extension; below the root it skips those root-only files and adds the
  `/civikitchen-repo` mount. New `ckconform` checks `monorepo-requires-mounted`
  (a same-repository `<requires>` must be mounted at `ext/<key>`) and
  `monorepo-version-lockstep` (every `info.xml` carries the same version and
  release date). Releasing such a repository is **not yet supported**:
  `extension-release.yml` has no `working_directory`, and `release-workflow`
  reports itself not evaluated for its extensions. See
  [Several extensions in one repository](docs/extension-development.md#several-extensions-in-one-repository).
- `ckconform`'s `headless-builder-applied` check fails a `setUpHeadless()`
  whose `ck_headless()` or `\Civi\Test::headless()` chain does not end in
  `->apply()`: the test listener discards the returned builder, so nothing is
  installed while the suite stays green.

### Changed

- `ckcreate` seeds `composer.json` with `phpunit/phpunit` in `require-dev`,
  which the seeded `phpstan-tests.neon.dist` needs to resolve `TestCase`.
- `ckconform` check `release-tags` prints an `ok` line when it evaluated the
  history and found nothing, so a pass is distinguishable from a skipped run.
- **Breaking:** `ckconform` check `release-tag-coherence` also fails when
  `info.xml` `<version>` is below the highest `v*` tag reachable from `HEAD`
  (SemVer 2.0 precedence, pre-releases included; non-SemVer tags are ignored).
  A release from that state sorts under the existing tag. Release a version
  above the tag named in the message.
  A repo declaring `release: none` is not evaluated.
- **Breaking:** `.github/workflows/release.yml`, the caller of
  `extension-release.yml`, is a template-managed file with the trigger for
  plain and pre-release tags. The template drift job reports a repo without it
  or with an older trigger; run `ckinit --update`. Inputs and secrets go below
  the `# END CIVIKITCHEN MANAGED caller` marker and survive the update. A
  caller written before the markers keeps its job's `with:` and `secrets:` as
  written; anything else of its own (another trigger, `env:`, a further job)
  makes `--update` refuse without writing and `--check` name it ("would
  drop: …"). `--update` creates no `release.yml` while another workflow already
  calls `extension-release.yml`, and `release-workflow` fails when more than
  one job calls it. A repo declaring `release: none` and a repository of
  several extensions get no release caller.
- **Breaking:** `ckconform`'s `release-workflow` fails instead of warning when
  no workflow calls `extension-release.yml`. Adopt the caller with `ckinit
  --update`, or declare `policy.release: none` with a reason (and list the
  caller under `policy.template_custom`). In a repository of several
  extensions the check reports itself not evaluated.
- `permission-closure` also reads the plural `'permissions' => [...]` lists
  (Angular modules, component info) and the action map an APIv4 entity's
  `permissions()` returns or assigns, so a typo there fails or warns like any
  other permission. Expect new findings where such lists name unknown
  permissions.
- The documented release commit is spelled `Release X.Y.Z`.

### Fixed

- `ckcoverage` and `ckmutate` read a policy value of `0` as configured: a
  `policy.coverage.minimum` of `0` is a floor that is met, no longer an absent
  key reported as "reporting only".
- `ckcoverage` fails with its own message when the run executed no test, and
  with a second one when every test it listed was skipped; phpunit exits 0 on
  "No tests executed!" and on a suite of skips alike. A `--log-junit` the caller
  passes is read instead of a second log of our own.
- `policy.tests` with `mode: optional` also opts a repo out of `ckcoverage`
  when it carries a phpunit config, which every civix scaffold does.
- `cklint` no longer reports Markdown and YAML: the CiviKitchen ruleset refs
  Drupal, whose nested `extensions` arg beats every `ruleset.xml`, so
  `--extensions=php` is passed on the command line even when the repo has its
  own `phpcs.xml.dist`.
- `ckfmt --check` gates untracked files, which are exactly the ones nobody has
  formatted yet.
- A tool that is not on PATH is named in one line (`ck: phpcs was not found on
  PATH (...)`) instead of a raw `proc_open(): posix_spawn() failed` warning; a
  login shell that resets PATH is the usual cause.
- `ckrelease` and `ckmutate` run git through the one guarded entry point, so they
  no longer fail with "dubious ownership" on a differently owned mount.
- The git-reading toolbelt libraries work from an extension subdirectory of a
  multi-extension repository: `cklint`'s changed-file list is scoped to the
  extension and printed relative to it, and the `safe.directory` guard names the
  worktree root, which is the directory git refuses under dubious ownership.

## [1.25.3] - 2026-09-19

### Fixed

- `ckconform`'s `api4-self-entity` check no longer reads an AngularJS
  registration such as `.controller('AcmeCourseList', …)` as a call to a
  missing APIv4 entity.

## [1.25.2] - 2026-09-18

### Fixed

- The template's `tests/phpunit/ckHeadless.php` passes `ckfmt --check`: 1.25.1
  shipped it with parentheses the formatter removes, which turned the *Format
  check* step red. Run `ckinit --update`.
- *Build Dev Images* also runs on `scaffold/**`, and its template gate analyses
  the stamped extension with `phpstan-tests.neon.dist`: a template change is now
  held to cklint, ckfmt and PHPStan before a release can carry it.

## [1.25.1] - 2026-09-18

### Added

- `scripts/release.sh X.Y.Z [--apply]` (`make release VERSION=… [APPLY=1]`):
  the release pre-flight, run locally before the tag exists. It checks branch,
  clean tree, sync with `origin/main`, that the tag is free locally and on
  origin, the changelog and ckinit gates, and that *Build Dev Images* completed
  successfully for the newest commit touching its trigger paths — reruns
  included, the newest run decides. Without `--apply` nothing is created.

### Fixed

- `scripts/release.sh` finds the *Build Dev Images* run on the pushed head: a
  push builds its last commit, so the run may sit on any commit from the newest
  image commit up to `HEAD`, not only on that commit itself.
- The template's `tests/phpunit/ckHeadless.php` passes PHPStan at the level of
  `phpstan-tests.neon.dist`: 1.25.0 shipped it with four type errors, which
  turned the opt-in *PHPStan (tests)* step red in every repo that enables it.
  Run `ckinit --update`.

## [1.25.0] - 2026-09-18

### Added

- `ckconform`'s `settings-metadata` check rejects a `table` pseudoconstant whose
  keys core's settings code does not read. `key_column`/`label_column` and the
  other snake_case spellings leave `keyColumn`/`labelColumn` `NULL`, and the
  first page that loads options for settings fatals with "not of the type
  MysqlColumnNameOrAlias" — `/civicrm/admin/theme` loads them for *every*
  setting, so one malformed extension breaks an unrelated core screen. A
  `table` pseudoconstant without both `keyColumn` and `labelColumn` fails for
  the same reason. Findings are failures, not warnings.
- The lifecycle gate runs the same check against the installed extension: after
  re-enable, `cklifecycle` calls `Civi\Core\SettingsMetadata::getMetadata()`
  with options loading on for every setting in `settings/*.setting.php`. Neither
  install nor the test suite loads options, so this was previously invisible
  until someone opened a settings page. Repos without settings are unaffected.

### Fixed

- The managed headless bootstrap keeps the core foreign keys across runs. A
  second `ckphpunit` against the same warm `civicrm_test` used to leave the
  schema with almost none of them (285 -> 3 constraints), because
  `CiviEnvBuilder::apply()` signs `CoreSchemaStep` before comparing signatures
  and that generator drops every constraint it emits; on the warm path
  `apply()` returns before re-adding them. `ck_headless()` now replays the
  cached core schema SQL once per process. Tests expecting `ON DELETE CASCADE`
  stop failing on every run but the first.
- `ck_headless()` discards the session status messages the environment build
  itself queues — ten "Unknown entity SearchDisplay" errors from the managed
  entity reconciliation during `Data::populate()`. A test asserting status
  messages was red on a cold database and green on a warm one.
- Both land in `tests/phpunit/ckHeadless.php`, so consuming repos need
  `ckinit --update`.

## [1.24.2] - 2026-09-14

### Fixed

- A retried demo image build re-downloads the CMS archive instead of reusing the
  error page the failed attempt cached. buildkit's `extract-url` stores whatever
  the server returned under the URL's hash, so a transient 5xx used to make all
  three `civibuild create` attempts fail identically; unusable archives are now
  dropped between attempts and the failure names the host that was downloading.

## [1.24.1] - 2026-09-14

### Fixed

- The seeded `phpstanBootstrap.php` passes `cklint` and `ckfmt` again, so a repo
  on `ckinit --update` no longer fails its own lint gate; the dev-image test now
  lints and formats the stamped template and keeps it that way.

## [1.24.0] - 2026-09-14

### Added

- `extension-release.yml` ships build output a repo does not commit. The repo
  declares it in `civikitchen.yaml` under `policy.dist.build` (`tool: bun` and
  its `outputs`); the release runs `bun install --frozen-lockfile` and
  `bun run build` on the Bun `package.json` pins, and `ckrelease dist` stages
  exactly those untracked paths into the archived tree, on top of
  `composer_install` too. A missing or tracked output, an output the archive
  leaves out, or a missing `packageManager` pin or `bun.lock` fails the release,
  and `ckrelease verify` requires every declared output in the zip.

### Fixed

- `ckrelease` prints why `ckconform --dist-paths` refused the release layout
  instead of only reporting that it could not read it.

## [1.23.0] - 2026-09-14

### Added

- **Breaking** `release-tags` conformance check: a version bumped past without
  its git tag fails, and the failure names every untagged version. Versions that
  deliberately never got a tag are declared under `policy.untagged_versions` in
  `civikitchen.yaml` (bare `info.xml` version, quoted — an unquoted `1.0` is a
  YAML float).
- **Breaking** `managed-job` conformance check: a scheduled job whose parameters
  `CRM_Core_BAO_Job::parseParameters` rejects fails, as does an APIv4-only
  entity declared without `version=4`. The check recognises the civix
  `api/v3/<Entity>/<Action>.php` layout.
- `sibling_repo` entries may pin a tag, branch or commit (`repo@ref`) instead
  of tracking the default branch.
- MariaDB 12.2 in the database compatibility matrix.
- `extension-release.yml` publishes a `vX.Y.Z-<pre-release>` tag as a GitHub
  pre-release that never takes Latest. Callers extend their tag trigger with
  `'v[0-9]+.[0-9]+.[0-9]+-*'` to release them; a tag the trigger lets through
  that is no SemVer version fails the run before the build.

### Changed

- **Breaking** for callers with hard-coded sibling paths: private
  dependencies are checked out under the extension key, one directory per key.
  A `prepare_command` that spells `.civikitchen-siblings/<repo name>` has to
  read `CK_SIBLING_DIR` or use the key instead.
- Every stack-booting job in `extension-ci.yml` wires `sibling_repo` and
  `composer_install`, so a job that boots a stack can install an extension
  whose `<requires>` names a private sibling. `playwright-e2e` checks siblings
  out through the same private-dependency action.
- `phpstanBootstrap.php` autoloads core's own bundled `ext` packages, so
  analysis resolves classes from core extensions.
- A failed private-dependency fetch says which pinned commit could not be
  fetched and why.

### Fixed

- `api3-surface` recognises APIv4 scheduled jobs configured through JSON
  parameters.
- `ckdeps` treats the Symfony components core ships as core-provided.
- `ckconform` reads `policy.tests=optional` with its mandatory reason in
  `ckcoverage`.
- Image scan: excuse CVE-2026-84375 in core's bundled js-yaml.
- `extension-release.yml` marks a release Latest only when its tag is the
  highest plain `vX.Y.Z` in the repo; a late release of an older version no
  longer takes Latest from the newest one.
- `permission-closure` no longer reads an entity field named `permission` as a
  permission spec, nor array subscripts, string subscripts, call arguments or a
  string that is only part of a concatenation inside a spec, and reads every
  permission of a nested OR list instead of stopping after the first group or
  at an arrow function. Escaped quotes and `\u{…}` escapes in a permission
  string are resolved in specs, `CRM_Core_Permission::check()` calls and
  `hook_civicrm_permission`, and a numeric permission string no longer aborts
  the check.
- `permission-closure` accepts core's `*always deny*` sentinel and afform's
  `@afformPageToken` and `manage own afform`.
- `permission-closure` finds `CRM_Core_Permission::check()` calls in any letter
  case and reads their list and named `permissions:` arguments, and a brace
  inside a string or comment no longer moves the end of
  `hook_civicrm_permission`. A by-reference `hook_civicrm_permission` and every
  `getPermissions()` in a file contribute definitions.

## [1.22.0] - 2026-09-12

### Added

- `sibling_repo` accepts an ordered list of private dependencies, checked out
  in the order given.

### Fixed

- `ckdeps` treats the Symfony components bundled with CiviCRM core as
  core-provided instead of demanding a composer requirement.

## [1.21.6] - 2026-09-07

### Fixed

- The osv-scanner download is retried, and a lint failure points at the local
  fixer commands.

## [1.21.5] - 2026-09-07

### Added

- The template's CI workflow can be dispatched manually.

### Fixed

- Buildkit first boot: the healthcheck reports healthy only once Apache answers
  with a non-error, and provisioning refuses a site whose extension directory
  `cv` cannot name instead of half-installing.
- A sentinel probe distinguishes an unanswerable check from an absent site, so
  a reinstall is not triggered by a slow container.
- `ckmodernize` resolves the core directory through `cv` and stops when there
  is none.
- The dev-tools test prints what `ck` produced when a check fails; the catalog
  preview cron opens an issue when it fails.

## [1.21.4] - 2026-09-07

### Changed

- Catalogs regenerated from CiviCRM 6.18.0.

### Fixed

- Tool and trivy downloads fail and retry instead of hashing an error page.

## [1.21.2] - 2026-09-07

### Fixed

- `extension-release` tears the smoke stack down whatever the outcome.

## [1.21.1] - 2026-09-07

### Fixed

- The private-dependency token action is passed the GitHub App id as
  `client-id`, the input it now expects.
- The `<requires>` resolver survives a caller's `set -e` when a dependency is
  absent.

## [1.21.0] - 2026-09-07

### Changed

- `extension-release` resolves a private dependency from the staged release
  instead of from inside the container.

## [1.20.1] - 2026-09-04

### Fixed

- `extension-release` runs the toolbelt entrypoints with `php`, not `bash`.

## [1.20.0] - 2026-09-03

### Added

- The standalone Playwright E2E workflow supports sibling extensions.

## [1.19.2] - 2026-09-03

### Fixed

- `ckcoverage` no longer reads `policy.tests=optional` as required.

## [1.19.1] - 2026-09-02

### Added

- A coverage gate for the shared PHP code.

### Changed

- Image builds apply Debian security updates and the daily rebuild runs without
  cache.
- `ckconform` in an extension below the repository root reads the root's
  `.github/workflows` and runs git from the root. The shared workflows
  support such layouts from 1.26.0 (`working_directory`).

### Fixed

- `info.xml` `<requires>` is exempt from the foreign-internals PHPStan rule.

## [1.19.0] - 2026-08-31

### Added

- Versioned extension sources and a shared PHP CLI behind the `ck*` tools;
  structured script logic moved from shell into that shared PHP.

## [1.18.1] - 2026-08-31

### Fixed

- Release retagging preserves the verified image digest instead of rewrapping
  the manifest.

## [1.18.0] - 2026-08-31

### Changed

- One validated `civikitchen.yaml` covers both scenario and repository policy
  configuration.
- Contact Layout is limited to the bundled CMS images, kept under core
  ownership, and skipped on Joomla where it is unavailable.

### Fixed

- Demo smoke tests hand long Basic Auth credentials to curl instead of wrapping
  the header.

## [1.17.0] - 2026-08-30

### Added

- Playwright runs on hardened runners.
- Repository secret scanning.

### Fixed

- `ckinit` tests are portable across sed variants.

## [1.16.10] - 2026-08-27

### Fixed

- The `<requires>` resolver asks `Extension.get` locally and aborts on a failed
  query instead of downloading.

## [1.16.9] - 2026-08-27

### Changed

- `ckinit` stamps the CI caller and the CI compose file as managed blocks, so a
  repo's own additions outside the markers survive an update.

## [1.16.8] - 2026-08-27

### Fixed

- Bun jobs install the version the repo pins in `package.json`.

## [1.16.7] - 2026-08-27

### Changed

- Artifact actions moved to their Node 24 releases.

## [1.16.6] - 2026-08-27

### Fixed

- `phpstanBootstrap.php` aliases the civix `CRM_<Prefix>_DAO_Base` to
  `CRM_Core_DAO_Base`.
- `ckdeps` ignores the template files' `simplexml` use; `ck_heal_perms` stays
  off bind mounts.

## [1.16.5] - 2026-08-27

### Fixed

- Releases strip nested `.git` directories from `vendor/` and authenticate
  composer dist downloads; `ckdeps` treats the phpstan bootstrap as dev code.
- The auto-composer step hands `vendor/` and `composer.lock` back to the mount
  owner even after a failed install.

## [1.16.4] - 2026-08-27

### Fixed

- Concurrency groups are keyed per job by literal name, and each
  `playwright-e2e` call gets its own compose project.

## [1.16.3] - 2026-08-27

### Changed

- A superseding push cancels the previous `extension-ci` run on the same ref.

## [1.16.2] - 2026-08-27

### Fixed

- `ck_headless()` installs one step per `info.xml` requires and makes no
  extension-system call before the headless rebuild.

## [1.16.1] - 2026-08-27

### Fixed

- `playwright-e2e` hands root-owned test artefacts back to the runner user.
- The template's `ckHeadless.php` and `phpstanBootstrap.php` pass `cklint` and
  `ckfmt`.

## [1.16.0] - 2026-08-27

### Added

- An organisation-wide `.ckconform` defaults layer under the repo policy, via
  the `policy_defaults` input or the `CK_POLICY_DEFAULTS` variable.
- A managed `renovate.json`, its preset stamped from the `renovate_preset`
  policy key.
- `ckup` picks host ports for the template compose stack and leaves ports
  already in `.docker/.env` alone.

### Changed

- **`extension-ci.yml` reads the extension key from `info.xml`** instead of a
  caller input.
- Bind-mounted extensions are enabled automatically; extra ones are pinned via
  `.ckconform` `extension_source`, and core locales are installed on request.
  The release smoke test resolves `info.xml` requires instead of taking an
  `extra_extensions` input.
- `ck_headless()` takes its `<requires>` from `info.xml`, and
  `phpstanBootstrap.php` autoloads every mounted one.
- `ckconform` trusts the hooks a present `<requires>` extension dispatches
  instead of requiring them in `known_hooks`.

## [1.15.0] - 2026-08-26

### Added

- `profile-schema` 0.4.0: `contentProfile` takes `authx` and `apiUsers`,
  `config` takes `membership_types`.
- `info.xml` requires are resolved at first boot, pinned via `.ckconform`
  `extension_source`.

### Changed

- Private-dependency app tokens are scoped to `contents:read`, with an
  exemption for the owner-wide installation scope.
- CiviBanking pinned to 1.4.1 in the `verein` profile.

### Fixed

- Core test fixtures are restored in the standalone images.
- `ckcoverage` log collisions.

## [1.14.1] - 2026-08-19

### Fixed

- Image scan: the Go stdlib CVEs in the prebuilt tsgolint binary are covered
  and excused until oxlint rebuilds.

## [1.14.0] - 2026-08-19

### Changed

- **Private dependencies are fetched with a GitHub App token over HTTPS**
  instead of deploy keys; the app secrets live in the calling repos. GitHub
  host keys are pinned rather than scanned, which unblocks proxied runners.
- Composer-managed dev tools install from committed lockfiles, and a test
  checks those lockfiles for sync and against the PHP floor.

### Fixed

- psalm resolves on PHP 8.1.31 so `cktaint` runs on older images.

## [1.13.1] - 2026-08-15

### Fixed

- Large extension release archives are handled.

## [1.13.0] - 2026-08-14

### Added

- Composer-backed extension releases.
- Atomic civix extension scaffolding.
- Extension CI installs development dependencies.

## [1.12.0] - 2026-08-14

### Added

- Modular reusable CI workflows beside `extension-ci.yml`, callable
  individually.

## [1.11.2] - 2026-08-14

### Added

- A dedicated annotation for optional dependency guards.

## [1.11.1] - 2026-08-14

### Fixed

- Explicitly guarded optional CiviRules adapters are allowed.

## [1.11.0] - 2026-08-14

### Added

- `ckcivix` reports drift between the civix scaffold and the current format.
- Release integrity checks in `ckconform`.

### Changed

- Test analysis is a reliable default in PHPStan.
- The formatter owns scope indentation; the phpcs standard yields.

## [1.10.3] - 2026-08-14

### Fixed

- `ckfmt` owns array continuation indent.
- Image builds are serialised so the newest commit promotes last.

## [1.10.2] - 2026-08-14

### Fixed

- `ckfmt` owns nested-array layout in the phpcs standard.
- phpcs receives one `--ignore`, so `vendored_paths` survives.
- Node scripts and e2e specs get the node env in `ckeslint`.

## [1.10.1] - 2026-08-13

### Added

- `make doctor` reports every missing host prerequisite in one pass.

### Changed

- A repo whose entity schema ships no DAO stub fails `ckconform`.
- The per-run compose stack is torn down at job end.

## [1.10.0] - 2026-08-11

### Added

- A `civikitchen.yaml` repo may declare vendored source and non-Smarty message
  templates.
- Supported standalone minors are pinned in `versions.env`; a weekly catalog
  preview runs against the latest core release.

### Changed

- **The standalone image installs CiviCRM from the release tarball** instead of
  building `FROM civicrm/civicrm`.
- Repository layout: `images/` split into `docker/`, `toolbelt/` and `tests/`;
  `tools/` and `template/` merged into `scaffold/`. Container paths are
  unchanged.
- Every stack-booting CI job gets its own compose project, so one job's
  `down -v` cannot kill another's containers.
- Catalogs regenerated from CiviCRM 6.17.2; the phpcs floor raised to the
  release fixing CVE-2026-67434.
- `ckconform` gates on tracked files, resolves gitignore through
  `git check-ignore`, and treats fixture entities as not shipped.
- `cktaint` is documented as blocking, matching the bundled config.

### Fixed

- `ck_json_field` no longer warns on a missing file.
- A `.ckconform` line that declares nothing is reported.

## [1.9.0] - 2026-08-05

### Changed

- `cktaint` blocks on SQL, shell, include, unserialize and SSRF taint; the rest
  stays advisory. The `.docker` dev harness is outside the scan scope.

## [1.8.0] - 2026-08-04

### Added

- `ckmutate`: mutation testing (infection) as an opt-in scheduled job.

### Changed

- `cktaint` models PSR-7 request input, the SQL builder, Guzzle and the header
  sinks.
- Image-scan exceptions moved to `trivyignore.yaml`.

### Fixed

- `ckphpunit` generates its canary config in a temp dir with absolutized paths.

## [1.7.0] - 2026-08-04

### Added

- PHPStan gains an APIv4 action-parameter report, implicit-join validation,
  raw-SQL table checks against the core schema catalog, and a data-change
  report for GET-reachable route handlers. Test analysis is opt-in via
  `phpstan-tests.neon.dist`.
- A transaction canary for phpunit and `cklifecycle`.
- The buildkit images ship `ckphpunit` and `cklifecycle`.

### Changed

- `ckeslint`/oxlint baseline widened to the suspicious, perf, promise and
  security rule sets, and skips type-aware rules when the repo has no tsconfig.

## [1.6.0] - 2026-08-04

### Added

- `ckcoretest` runs CiviCRM core's phpunit suites in the standalone image.

### Changed

- **`ckeslint` runs oxlint** instead of ESLint.

## [1.5.2] - 2026-08-04

### Fixed

- PHPStan owns the civix namespace even without a CRM tree.
- Reusable-workflow callers are exempt from the Playwright upload rule.
- Stale stacks are torn down before booting on self-hosted runners.

## [1.5.1] - 2026-08-03

### Fixed

- The test bootstrap is exempt from `DeclareStrictTypes`.
- osv scans pass on lockfiles that lock no packages; the trivy job summary is
  truncated rather than lost.

## [1.5.0] - 2026-08-03

### Added

- **`ckfmt`**: mago formats PHP to the phpcs standard, oxfmt formats JS/TS — a
  hard gate in the shared CI.
- **`cktaint`**: psalm as an advisory taint engine for CiviCRM extensions.
- **`ckdeps`** and PHPStan rule packs shipped from the image: an APIv4 contract
  catalog with entity/action/field rules, phpat architecture boundaries,
  deprecation rules, and analysis on the extension's own PHP floor.
- **`ckcompat`**: parse the code at the PHP floor composer declares.
- Runtime gates: Smarty compile smoke, schema parity, ESLint; runtime
  deprecations fail extension test suites.
- Lockfile scanning with osv-scanner and published-image scanning with trivy;
  workflows SHA-pinned and audited by zizmor, with Renovate for updates.
- New conformance and coder checks: APIv4 permission bypasses, scan-classes
  mixin, Smarty 5 removed tags, modernisation checks (mgd v4, mixin versions,
  schema format, api3 surface, raw SQL), inline ignores that suppressed
  nothing, and eslint-style suppressions with mandatory reasons.

### Changed

- `cklint` reports phpcs warnings without failing the gate; mago lint is the
  second engine with a curated baseline pinned to the 8.1 fleet floor.
- Extension standards require `strict_types` per file and flag unnamed boolean
  arguments; rector names arguments instead of padding with defaults.
- Images run Node 24 LTS with a pinned npm.

## [1.4.0] - 2026-08-03

### Added

- `extension-ci` resolves the runner label from the caller's `CK_RUNS_ON`
  variable.

### Changed

- `{ts}` in `.mgd.php` message templates counts as a source string.

## [1.3.0] - 2026-07-31

### Added

- `runs_on` input: run the whole `extension-ci` pipeline on your own runners.
- Opt-in core-upgrade persistence job (`core_upgrade_from`).

## [1.2.2] - 2026-07-31

### Fixed

- `git check-ignore` gets the same `safe.directory` as `Context::git()`.

## [1.2.1] - 2026-07-31

### Fixed

- `ckconform` works under CI's uid mismatch and never judges a sibling
  checkout.

## [1.2.0] - 2026-07-31

### Added

- Opt-in composer install and private sibling-extension checkout in
  `extension-ci`, with a `bun` input for the frontend steps.

### Changed

- `cklint` never lints a CI-checked-out sibling extension.
- `ckcoverage` honours `tests=optional` when there is no phpunit config.

## [1.1.0] - 2026-07-31

### Added

- **`ckrelease`** and the shared `extension-release.yml`: version check, dist
  archive and a tag-triggered release workflow for extension repos.
- Opt-in `extension-ci` jobs: npm materialization, JS unit tests, Playwright,
  a compatibility matrix, lifecycle smoke, and an upgrade test from the last
  release tag.
- i18n conformance: `{ts}` without a covering `{crmScope}` fails, and a shipped
  `l10n/` is checked for coherence.

### Changed

- The three `extension-ci` gates report independently.
- `ckinit` `template_custom` may cover seeded files, absence included.

## [1.0.0] - 2026-07-31

### Added

- The versioned contract: reusable `extension-ci.yml`, the extension template
  with its managed/seeded split, `ckinit --check`/`--update`, the fleet drift
  gate, and the release tags `v1.x.y` / the moving `v1` and `:v1`.
- `ckconform`, rewritten in PHP with tests: repo-structure, licensing, APIv4,
  mixin, Smarty, compose and lockfile conformance checks.
- `ckcoverage`: coverage with an enforced floor.

### Changed

- The template pins the caller to `@v1` and the compose stack to `:v1`.
- First boot no longer wipes a persistent dev database, and the install is
  retryable.

[Unreleased]: https://github.com/civico-de/civikitchen/compare/v1.49.0...HEAD
[1.49.0]: https://github.com/civico-de/civikitchen/compare/v1.48.0...v1.49.0
[1.48.0]: https://github.com/civico-de/civikitchen/compare/v1.47.2...v1.48.0
[1.47.2]: https://github.com/civico-de/civikitchen/compare/v1.47.1...v1.47.2
[1.47.1]: https://github.com/civico-de/civikitchen/compare/v1.47.0...v1.47.1
[1.47.0]: https://github.com/civico-de/civikitchen/compare/v1.46.0...v1.47.0
[1.46.0]: https://github.com/civico-de/civikitchen/compare/v1.45.0...v1.46.0
[1.45.0]: https://github.com/civico-de/civikitchen/compare/v1.44.0...v1.45.0
[1.44.0]: https://github.com/civico-de/civikitchen/compare/v1.43.0...v1.44.0
[1.43.0]: https://github.com/civico-de/civikitchen/compare/v1.42.0...v1.43.0
[1.42.0]: https://github.com/civico-de/civikitchen/compare/v1.41.2...v1.42.0
[1.41.2]: https://github.com/civico-de/civikitchen/compare/v1.41.1...v1.41.2
[1.41.1]: https://github.com/civico-de/civikitchen/compare/v1.41.0...v1.41.1
[1.41.0]: https://github.com/civico-de/civikitchen/compare/v1.40.0...v1.41.0
[1.40.0]: https://github.com/civico-de/civikitchen/compare/v1.39.3...v1.40.0
[1.39.3]: https://github.com/jfilter/civikitchen/compare/v1.39.2...v1.39.3
[1.39.2]: https://github.com/jfilter/civikitchen/compare/v1.39.1...v1.39.2
[1.39.1]: https://github.com/jfilter/civikitchen/compare/v1.39.0...v1.39.1
[1.39.0]: https://github.com/jfilter/civikitchen/compare/v1.38.1...v1.39.0
[1.38.1]: https://github.com/jfilter/civikitchen/compare/v1.38.0...v1.38.1
[1.38.0]: https://github.com/jfilter/civikitchen/compare/v1.37.1...v1.38.0
[1.37.1]: https://github.com/jfilter/civikitchen/compare/v1.37.0...v1.37.1
[1.37.0]: https://github.com/jfilter/civikitchen/compare/v1.36.0...v1.37.0
[1.36.0]: https://github.com/jfilter/civikitchen/compare/v1.35.0...v1.36.0
[1.35.0]: https://github.com/jfilter/civikitchen/compare/v1.34.0...v1.35.0
[1.34.0]: https://github.com/jfilter/civikitchen/compare/v1.33.0...v1.34.0
[1.33.0]: https://github.com/jfilter/civikitchen/compare/v1.32.1...v1.33.0
[1.32.1]: https://github.com/jfilter/civikitchen/compare/v1.32.0...v1.32.1
[1.32.0]: https://github.com/jfilter/civikitchen/compare/v1.31.0...v1.32.0
[1.31.0]: https://github.com/jfilter/civikitchen/compare/v1.30.2...v1.31.0
[1.30.2]: https://github.com/jfilter/civikitchen/compare/v1.30.1...v1.30.2
[1.30.1]: https://github.com/jfilter/civikitchen/compare/v1.30.0...v1.30.1
[1.30.0]: https://github.com/jfilter/civikitchen/compare/v1.29.0...v1.30.0
[1.29.0]: https://github.com/jfilter/civikitchen/compare/v1.28.0...v1.29.0
[1.28.0]: https://github.com/jfilter/civikitchen/compare/v1.27.0...v1.28.0
[1.27.0]: https://github.com/jfilter/civikitchen/compare/v1.26.0...v1.27.0
[1.26.0]: https://github.com/jfilter/civikitchen/compare/v1.25.3...v1.26.0
[1.25.3]: https://github.com/jfilter/civikitchen/compare/v1.25.2...v1.25.3
[1.25.2]: https://github.com/jfilter/civikitchen/compare/v1.25.1...v1.25.2
[1.25.1]: https://github.com/jfilter/civikitchen/compare/v1.25.0...v1.25.1
[1.25.0]: https://github.com/jfilter/civikitchen/compare/v1.24.2...v1.25.0
[1.24.2]: https://github.com/jfilter/civikitchen/compare/v1.24.1...v1.24.2
[1.24.1]: https://github.com/jfilter/civikitchen/compare/v1.24.0...v1.24.1
[1.24.0]: https://github.com/jfilter/civikitchen/compare/v1.23.0...v1.24.0
[1.23.0]: https://github.com/jfilter/civikitchen/compare/v1.22.0...v1.23.0
[1.22.0]: https://github.com/jfilter/civikitchen/compare/v1.21.6...v1.22.0
[1.21.6]: https://github.com/jfilter/civikitchen/compare/v1.21.5...v1.21.6
[1.21.5]: https://github.com/jfilter/civikitchen/compare/v1.21.4...v1.21.5
[1.21.4]: https://github.com/jfilter/civikitchen/compare/v1.21.2...v1.21.4
[1.21.2]: https://github.com/jfilter/civikitchen/compare/v1.21.1...v1.21.2
[1.21.1]: https://github.com/jfilter/civikitchen/compare/v1.21.0...v1.21.1
[1.21.0]: https://github.com/jfilter/civikitchen/compare/v1.20.1...v1.21.0
[1.20.1]: https://github.com/jfilter/civikitchen/compare/v1.20.0...v1.20.1
[1.20.0]: https://github.com/jfilter/civikitchen/compare/v1.19.2...v1.20.0
[1.19.2]: https://github.com/jfilter/civikitchen/compare/v1.19.1...v1.19.2
[1.19.1]: https://github.com/jfilter/civikitchen/compare/v1.19.0...v1.19.1
[1.19.0]: https://github.com/jfilter/civikitchen/compare/v1.18.1...v1.19.0
[1.18.1]: https://github.com/jfilter/civikitchen/compare/v1.18.0...v1.18.1
[1.18.0]: https://github.com/jfilter/civikitchen/compare/v1.17.0...v1.18.0
[1.17.0]: https://github.com/jfilter/civikitchen/compare/v1.16.10...v1.17.0
[1.16.10]: https://github.com/jfilter/civikitchen/compare/v1.16.9...v1.16.10
[1.16.9]: https://github.com/jfilter/civikitchen/compare/v1.16.8...v1.16.9
[1.16.8]: https://github.com/jfilter/civikitchen/compare/v1.16.7...v1.16.8
[1.16.7]: https://github.com/jfilter/civikitchen/compare/v1.16.6...v1.16.7
[1.16.6]: https://github.com/jfilter/civikitchen/compare/v1.16.5...v1.16.6
[1.16.5]: https://github.com/jfilter/civikitchen/compare/v1.16.4...v1.16.5
[1.16.4]: https://github.com/jfilter/civikitchen/compare/v1.16.3...v1.16.4
[1.16.3]: https://github.com/jfilter/civikitchen/compare/v1.16.2...v1.16.3
[1.16.2]: https://github.com/jfilter/civikitchen/compare/v1.16.1...v1.16.2
[1.16.1]: https://github.com/jfilter/civikitchen/compare/v1.16.0...v1.16.1
[1.16.0]: https://github.com/jfilter/civikitchen/compare/v1.15.0...v1.16.0
[1.15.0]: https://github.com/jfilter/civikitchen/compare/v1.14.1...v1.15.0
[1.14.1]: https://github.com/jfilter/civikitchen/compare/v1.14.0...v1.14.1
[1.14.0]: https://github.com/jfilter/civikitchen/compare/v1.13.1...v1.14.0
[1.13.1]: https://github.com/jfilter/civikitchen/compare/v1.13.0...v1.13.1
[1.13.0]: https://github.com/jfilter/civikitchen/compare/v1.12.0...v1.13.0
[1.12.0]: https://github.com/jfilter/civikitchen/compare/v1.11.2...v1.12.0
[1.11.2]: https://github.com/jfilter/civikitchen/compare/v1.11.1...v1.11.2
[1.11.1]: https://github.com/jfilter/civikitchen/compare/v1.11.0...v1.11.1
[1.11.0]: https://github.com/jfilter/civikitchen/compare/v1.10.3...v1.11.0
[1.10.3]: https://github.com/jfilter/civikitchen/compare/v1.10.2...v1.10.3
[1.10.2]: https://github.com/jfilter/civikitchen/compare/v1.10.1...v1.10.2
[1.10.1]: https://github.com/jfilter/civikitchen/compare/v1.10.0...v1.10.1
[1.10.0]: https://github.com/jfilter/civikitchen/compare/v1.9.0...v1.10.0
[1.9.0]: https://github.com/jfilter/civikitchen/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/jfilter/civikitchen/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/jfilter/civikitchen/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/jfilter/civikitchen/compare/v1.5.2...v1.6.0
[1.5.2]: https://github.com/jfilter/civikitchen/compare/v1.5.1...v1.5.2
[1.5.1]: https://github.com/jfilter/civikitchen/compare/v1.5.0...v1.5.1
[1.5.0]: https://github.com/jfilter/civikitchen/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/jfilter/civikitchen/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/jfilter/civikitchen/compare/v1.2.2...v1.3.0
[1.2.2]: https://github.com/jfilter/civikitchen/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/jfilter/civikitchen/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/jfilter/civikitchen/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/jfilter/civikitchen/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/jfilter/civikitchen/releases/tag/v1.0.0
