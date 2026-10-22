# Images

CiviKitchen publishes development and demo image flavors to
`ghcr.io/civico-de/civikitchen`. The first-boot settings are in the
[configuration reference](configuration.md).

## Standalone (dev)

CiviCRM Standalone, installed from the release tarball onto a php:apache base,
plus the development toolchain:

- cv, civix and composer.
- Node 24 with a pinned npm 12. The images re-enable dependency install
  scripts globally, because CiviCRM core, buildkit and most frontend
  toolchains still need postinstall; the reasoning sits at the NPM_VERSION
  pin in `toolbelt/install-dev-tools.sh`.
- phpunit 9, pinned for CiviCRM compatibility, with pcov always on. Xdebug is
  off until `XDEBUG_MODE` is set
  ([IDE step debugging](extension-development.md#ide-step-debugging)).
- phpstan, psalm, rector, mago, oxlint, oxfmt and
  composer-dependency-analyser, each pinned
  ([Bumping a dev tool](building.md#bumping-a-dev-tool)).
- The ck tools:
    - the CI gates that ck ci runs
      ([Extension standards](extension-standards.md#tooling-every-repo-must-have))
    - ckmodernize ([Modernizing](extension-development.md#modernizing))
    - cklifecycle and ckschemadiff for the compatibility jobs
      ([Compatibility](extension-standards.md#compatibility-test-the-range-you-claim))
    - the scheduled ckmutate ([Tests and coverage](extension-standards.md#tests-and-coverage))
    - ckrelease ([Releasing an extension](extension-releases.md))
- On the standalone image only:
    - cktestreset rebuilds the headless test database
      ([Headless tests](extension-development.md#headless-tests))
    - ckcoretest runs core's own suites
      ([CiviCRM core development](core-development.md))
    - ckboot provisions the site inside a GitLab CI job
      ([CI on GitLab](extension-standards.md#ci-on-gitlab))

### Core patches

The image carries fixes for core bugs until a release contains them, one file
per bug in
[docker/standalone/core-patches/](https://github.com/civico-de/civikitchen/tree/main/docker/standalone/core-patches/),
each naming its upstream issue. A patch the release already contains is
skipped; any other mismatch fails the build. A patch for a bug that a later
release introduced carries a `Since: X.Y.Z` line and is skipped on older cores
(the version comes from core's `xml/version.xml`; `6.20.beta1` counts as older
than `6.20.0`). The image lists what it applied
in `/usr/local/share/civikitchen/core-patches.log`. A site on the unpatched
release still shows the bug
([ADR-0016](adr/0016-core-bugs-are-patched-into-the-standalone-image.md)).

### Running it

With auto-install on, CiviCRM installs itself on first start.
[Extension development](extension-development.md) has the full setup.

```yaml
services:
  app:
    image: ghcr.io/civico-de/civikitchen:standalone
    ports: ["8080:80"]
    environment:
      CIVICRM_AUTO_INSTALL: "1"   # DB host/name/user/password default to db/civicrm
    depends_on:
      db: { condition: service_healthy }
    volumes:
      - ../:/var/www/html/ext/myextension   # your extension repo
  db:
    image: mariadb:11.4
    # ... see examples/standalone/docker-compose.yml
```

Ready to run: [examples/standalone/](https://github.com/civico-de/civikitchen/tree/main/examples/standalone/)

## Buildkit flavors (dev)

For CMS-specific behaviour, the drupal10, drupal11, wordpress and joomla
flavors run CiviCRM on a CMS via
[civicrm-buildkit](https://github.com/civicrm/civicrm-buildkit). They share
one Dockerfile and differ only in the default civibuild site type
(drupal10-demo, drupal11-demo, wp-demo, joomla-demo), which
`CIVICRM_SITE_TYPE` overrides. civibuild builds the site on first start, in
about a minute, against an external MariaDB.

They carry the standalone tools except the standalone-only ones. Settings
marked "all" in the [configuration reference](configuration.md) work the
same; those marked "standalone" do not apply, because civibuild provides the
admin users, components and an isolated test database itself.

```yaml
services:
  app:
    image: ghcr.io/civico-de/civikitchen:drupal10
    ports: ["8080:80"]
    environment:
      CIVICRM_DB_HOST: db
      CIVICRM_DB_ROOT_PASSWORD: root
      CIVIKITCHEN_SITE_URL: http://localhost:8080   # must match the port mapping, or assets 404
    depends_on: [db]
  db:
    image: mariadb:11.4
    environment:
      MYSQL_ROOT_PASSWORD: root
```

The databases live on the database volume. A fresh app container that finds
an existing site there refuses to rebuild, because a reinstall would drop the
site databases. Opt in with `CIVIKITCHEN_REINSTALL=1`, or start clean with
`docker compose down -v`.

Ready to run, with each CMS's extension mount path:
[drupal10](https://github.com/civico-de/civikitchen/tree/main/examples/drupal10/),
[drupal11](https://github.com/civico-de/civikitchen/tree/main/examples/drupal11/),
[wordpress](https://github.com/civico-de/civikitchen/tree/main/examples/wordpress/),
[joomla](https://github.com/civico-de/civikitchen/tree/main/examples/joomla/).

### Drupal 11

civibuild ships no drupal11-demo site type, so CiviKitchen vendors one in
[docker/buildkit/site-types/drupal11-demo/](https://github.com/civico-de/civikitchen/tree/main/docker/buildkit/site-types/drupal11-demo/),
adapted from drupal10-demo for Drupal 11.4. It gets the same demo data, admin
users and profile support as the other flavors.

### Joomla

Buildkit's joomla5-empty template is CMS-only and not published as a flavor.
civibuild's joomla-demo install leaves CiviCRM's Joomla component
unregistered. On first boot the image reinstalls against your database and
runs the same
[finish](https://github.com/civico-de/civikitchen/blob/main/docker/buildkit/joomla-finish.sh)
the demo image bakes in: it registers the component and plugins, creates the
CiviCRM ACL asset with least-privilege role permissions, and installs the
ckjoomlaidentity shim so headless api_key requests enforce them. The admin UI,
the API and profiles then behave as on the other flavors.

## Demo images

For demos, evaluation and screenshots, not development: one container with an
embedded MariaDB and baked demo data, running in seconds. The data resets on
docker rm.

```bash
# Pick a flavor: standalone-demo (CMS-less), drupal10-demo, drupal11-demo,
# wordpress-demo, joomla-demo
docker run -d -p 80:80 --name civicrm ghcr.io/civico-de/civikitchen:drupal10-demo

# then open http://localhost  —  login: admin / admin
```

The site is baked at http://localhost. On another port, set the URL you open
and the entrypoint rewrites the base URL at boot:

```bash
docker run -d -p 8080:80 -e CIVIKITCHEN_SITE_URL=http://localhost:8080 \
    --name civicrm ghcr.io/civico-de/civikitchen:drupal10-demo
```

On Joomla (see [Joomla](#joomla)) authx password and basic auth do not work;
use the api_key credential as a bearer token in the X-Civi-Auth header.

## Profiles (`CIVIKITCHEN_PROFILE`)

A profile adds a curated extension stack, configuration, seed data and API
users to a fresh site at first boot, on every flavor. On the standalone dev
image it needs an admin user to seed as, so combine it with
`CIVICRM_AUTO_INSTALL=1` and `CIVIKITCHEN_DEMO_USER=admin`.

Profiles live in
[docker/profiles/](https://github.com/civico-de/civikitchen/tree/main/docker/profiles/),
one directory each with a `profile.json` and the PHP scripts it names. The
driver applies dependencies, config, seed and scripts in that order:

- config is written back wherever it differs; components are only switched
  on, so composed profiles keep each other's.
- seed contacts and contributions are created once per anchor, so UI edits
  survive.
- scripts run in list order. A seeds/ directory is an error.

Profile dependencies are enabled first, then the mounted and extra
extensions, then config, seed, scripts and API users, so seed data can use the
extension under development. init.d hooks run last. An apply.sh in the root or
profile replaces the driver.

A profile with a `snapshot` block generates its data once and restores it on
later fresh boots of the standalone image, with dates moved to today. The four
data profiles below have one. See [Seed snapshots](seed-snapshots.md).

A missing prerequisite, such as membership types without CiviMember or an
unknown setting, fails the apply before anything is written and lists every
problem. The
[schema README](https://github.com/civico-de/civikitchen/blob/main/packages/civicrm-profile-schema/README.md)
has the exact rules.

| Profile | Includes | Extensions | API users |
|---|---|---|---|
| `verein` | `verein-data` | CiviBanking, CiviSEPA, Contract, Twingle, GDPRX, XCM, IdentityTracker, ContactLayout; adds a SEPA creditor + 21 direct-debit mandates | readonly, fundraiser, eventmanager, caseworker, bankimporter |
| `fundraising` | `fundraising-data` | CiviRules, DonRec | readonly, fundraiser |
| `events` | `events-data` | RemoteEvent, EventMessages, RemoteTools, XCM, IdentityTracker | readonly, eventmanager |
| `mailing` | `mailing-data` | Mosaico (+ core FlexMailer) | readonly, mailer |

The data profiles hold the records and need only CiviCRM core:

| Data profile | Components | Seed data |
|---|---|---|
| `verein-data` | CiviContribute, CiviMember | Musterverein e.V.: membership types (Voll-/Förder-/Ehrenmitglied), 24 members with addresses + fee history |
| `fundraising-data` | CiviContribute, CiviCampaign, CiviPledge | 2 campaigns, 18 donors with varied giving history, 6 recurring donors, 4 pledges with installment schedules |
| `events-data` | CiviEvent | 6 past + upcoming events, 18 contacts with participant records in varied statuses |
| `mailing-data` | CiviMail | 3 segmented mailing lists, 30 subscribers, a draft newsletter |

Includes apply first, depth-first, each profile once; a cycle fails the boot.
So verein gives the whole Verein world and verein-data only its records.
RemoteEvent is skipped on Standalone, and ContactLayout on Standalone and
Joomla, via skipUf in their profile.json.

```bash
# German Verein showcase: Drupal 10 + DACH extension stack + seed data + API users
docker run -d -p 80:80 --name civicrm \
    -e CIVIKITCHEN_PROFILE=verein \
    ghcr.io/civico-de/civikitchen:drupal10-demo
```

A comma-separated list such as `verein,mailing` applies left to right without
collisions: present extensions are skipped, config converges, seed records
exist once, and a user declared by several profiles gets the union of
permissions, with the last profile's password and api_key valid. Profiles with
API users must agree on one AuthX header policy, or the boot fails before any
change.

The first boot clones extensions from GitHub, so it needs network and takes a
few minutes; the container turns healthy when done. Follow it with
`docker logs -f civicrm`. API credentials stay out of the logs, in a mode-0600
file (CK_CREDENTIALS_FILE and CK_CREDENTIALS_OUTPUT in
[configuration](configuration.md) move or redirect it):

```bash
docker exec civicrm cat /home/buildkit/api-credentials.txt
```

Profiles can also live outside the image. Mount a root read-only and select a
profile by directory name:

```yaml
services:
  app:
    volumes:
      - ./profiles:/profiles:ro
    environment:
      CIVIKITCHEN_PROFILE_PATH: /profiles
      CIVIKITCHEN_TRUST_EXTERNAL_PROFILES: "1"
      CIVIKITCHEN_PROFILE: my-ngo
```

`ck profile validate ./profiles/my-ngo` checks the data shape with the schema
the boot uses. It does not sandbox scripts: external profiles are trusted code
with CiviCRM admin access, hence the mandatory trust flag. Duplicate profile
names in the selected list are an error.

## Toolbelt layout

Every image carries this repo's toolbelt/, packages/ and docker/profiles/
under `/opt/civikitchen/`, laid out as in a checkout, and the ck commands link
into its toolbelt/bin/. A repo config can name files there, for example
`/opt/civikitchen/toolbelt/phpstan-config/civicrm-disallowed.neon`.

The dev images run as the web user (www-data on standalone, buildkit on the
buildkit flavors), and docker exec uses it too, so caches stay writable. The
demo images run as root for their embedded MariaDB.

No system PATH directory is writable for the web user. An init hook that
fetches a tool installs it into `~/.local/bin`, which is on the PATH for
hooks, docker exec and ck ci.

The v1 paths under /opt/civikitchen-* and the old profile directory are
deprecated links that go away in v2; ckconform names every file still using
them and the replacement.

## Tags and versions

All images rebuild daily against the current CiviCRM stable from
[latest.civicrm.org](https://latest.civicrm.org/stable.php). A push touching
the image inputs rebuilds standalone and standalone-demo (*Build Dev Images*);
the CMS flavors and their demos rebuild daily or on a manual dispatch of
*Build CMS Images*.

Images are tested, then promoted
([ADR-0014](adr/0014-images-are-tested-then-promoted.md)): a build that
fails the boot tests or the vulnerability scan (trivy, fixable HIGH and
CRITICAL findings) never reaches the stable tags. Promotion publishes the
digest the scan passed; the tests pull the candidate tag, so re-running a
failed run's jobs after a later run rebuilt the same commit tests that newer
build. Start a fresh run instead. The daily run
skips the layer cache, so Debian security updates arrive within a day.

The moving tags track current CiviCRM, for local development and the canary
repo:

| Tag | What it points at |
|-----|-------------------|
| `:standalone`, `:standalone-latest` | The most recent build of CiviCRM's latest release. |
| `:standalone-<minor>` | The latest patch of that minor, for example `:standalone-6.16`. The current stable minor always has one; older minors keep rebuilding while listed in `CK_STANDALONE_EXTRA_MINORS` in toolbelt/versions.env, and freeze once dropped. To rebuild another minor once, dispatch *Build Dev Images* with extra_standalone_minors. |
| `:drupal10`, `:drupal11`, `:wordpress`, `:joomla`, `:*-demo` | The current stable at build time; see the version label below. |
| `:<flavor>-php<version>` | The buildkit dev flavors with an explicit PHP version, for example `:drupal10-php8.3`. |
| `:standalone-master` | The head of civicrm-core master, built daily from git by *Build Git Images*. |
| `:standalone-beta` | The head of core's newest X.Y branch, built daily from git. Shortly after a release this may still be the branch that just shipped. |

To read the CiviCRM version of a pulled image without booting it:

```bash
docker inspect <image> --format '{{ index .Config.Labels "org.opencontainers.image.version" }}'
```

The two git-built tags pass only smoke tests, get no vulnerability scan and
are not part of the release contract. Each is promoted on its own, so one
failing does not stop the other. `/usr/local/share/civikitchen/civicrm-source.log`
names the core and packages commits; amd64 and arm64 can differ.

The release tags (v1, v1.2.3 and per-flavor spellings such as standalone-v1)
are what extension repos pin. They move only when a release is cut; see
[Releases](releases.md#what-a-version-names). To keep a minor longer or run a
version not published, see
[Custom or older CiviCRM versions](#custom-or-older-civicrm-versions).

### Database compatibility

Before promotion the standalone candidate gets a real install, extension
provisioning, locale rendering and a boot into the isolated test database.
Every run checks this against MariaDB 11.4, 10.11 and 12.2 and MySQL 8.0. The
template and the examples default to MariaDB 11.4; changing that requires
passing the full matrix. An extension repo picks another verified MariaDB with
[`policy.database`](extension-standards.md#repository-policy-civikitchenyaml).

## Custom or older CiviCRM versions

To run another version, for example to mirror a production server, build the
image yourself with `--build-arg CIVICRM_VERSION=<tag/branch>`:

- Standalone (docker/standalone/) installs a release tarball, so the version
  must be exact (6.15.1, not 6.15) and Standalone exists from about 5.69. The
  [core patches](#core-patches) must apply or already be contained, or the
  build fails. With `--build-arg CIVICRM_SOURCE=git` the version is a core
  branch or tag instead; see
  [Standalone on an unreleased branch](core-development.md#standalone-on-an-unreleased-branch).
- Buildkit (docker/buildkit/) fetches any civicrm tag or branch. The Drupal 10
  site type covers versions such as 5.78.x; below about 5.47, set
  DEFAULT_SITE_TYPE to a Drupal 9 or 7 site type. This also mirrors a real
  Drupal server's CMS.

```bash
docker build -f docker/buildkit/Dockerfile \
    --build-arg DEFAULT_SITE_TYPE=drupal10-demo \
    --build-arg CIVICRM_VERSION=5.78.2 \
    --build-arg PHP_VERSION=8.1 \
    -t civikitchen:drupal10-5.78.2 .
```

Compose can build on demand:
[examples/custom-version/](https://github.com/civico-de/civikitchen/tree/main/examples/custom-version/)
takes CIVICRM_VERSION and PHP_VERSION. More build arguments are in
[Building locally](building.md).
