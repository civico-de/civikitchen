# Configuration

The images read their settings from environment variables. This page lists
every one of them, with its default and the image family that reads it, and
explains the ones that need more than a line below the table.

## Naming

- `CIVIKITCHEN_` variables control CiviKitchen's own behaviour.
- `CIVICRM_` is reserved for three things: the contract with CiviCRM itself
  (`CIVICRM_AUTO_INSTALL` and the `CIVICRM_DB_*` variables, from which core
  builds its DSN at boot), the description of the CiviCRM or civibuild target
  (`CIVICRM_VERSION`, `CIVICRM_SITE_TYPE`), and CiviCRM's own variables such as
  `CIVICRM_UF`.
- `CK_` variables tune toolbelt internals: seed snapshots, credentials and
  dependency archives.

Older kitchen variables spelled with `CIVICRM_`, and `SITE_URL`, still work
but print a deprecation warning.

## All variables

The Image column marks variables that only make sense for one image family:
`standalone`, or `buildkit` for the Drupal, WordPress, Joomla and Backdrop
flavors.

| Env var | Default | Image | Purpose |
|---------|---------|-------|---------|
| `CIVICRM_DB_HOST` | `db` | all | Database host name. [Database](#database) |
| `CIVICRM_DB_PORT` | `3306` | all | Database port. |
| `CIVICRM_DB_NAME` | `civicrm` | standalone | Database name. |
| `CIVICRM_DB_USER` | `civicrm` | standalone | Database user of the running CiviCRM. |
| `CIVICRM_DB_PASSWORD` | `civicrm` | standalone | Password for `CIVICRM_DB_USER`. |
| `CIVICRM_DB_ROOT_PASSWORD` | `root` | all | Database admin password. |
| `CIVIKITCHEN_TEST_DB` | `1` | standalone | Set up the headless test database `<db>_test`. |
| `CIVIKITCHEN_REINSTALL` | `0` | buildkit | Rebuild over an existing site in the external database, for one boot. |
| `CIVIKITCHEN_SITE_URL` | derived | all | The URL the browser opens. [Site and install](#site-and-install) |
| `HTTPD_DOMAIN` / `HTTPD_PORT` | `localhost` / `80` | buildkit | Parts of the derived `CIVIKITCHEN_SITE_URL`. |
| `CIVICRM_AUTO_INSTALL` | `0` | standalone | `1` installs CiviCRM on first start. |
| `CIVIKITCHEN_DEMO_USER` | _(unset)_ | standalone | Admin login created during auto-install. |
| `CIVIKITCHEN_DEMO_PASS` | `admin` | standalone | Password for the demo user. |
| `CIVIKITCHEN_DEMO_EMAIL` | `admin@example.org` | standalone | Email address on the demo user's contact record. |
| `CIVIKITCHEN_COMPONENTS` | all standard | standalone | Components enabled at install. |
| `CIVICRM_SITE_TYPE` | tag default | buildkit | The civibuild site type. |
| `CIVIKITCHEN_EXTRA_EXTENSIONS` | _(unset)_ | all | Extension keys to download and enable after install. [Extensions](#extensions) |
| `CIVIKITCHEN_ENABLE_EXTENSIONS` | _(unset)_ | all | Enable order of extensions. |
| `CIVIKITCHEN_EXTENSION_UNDER_TEST` | _(unset)_ | all | The mounted extension whose dependency pins win. |
| `CK_DEP_ARCHIVE_DIR` | _(unset)_ | all | Staged release archives for `release:` dependency pins. |
| `CIVIKITCHEN_EXTENSION_PATH` / `_KEY` / `_DIR` | _(unset)_ | all | Attach an extension mounted at a neutral path. |
| `CIVIKITCHEN_PROFILE` | _(unset)_ | all | Profiles applied at first boot. [Profiles and seed data](#profiles-and-seed-data) |
| `CIVIKITCHEN_PROFILE_PATH` | _(unset)_ | all | Colon-separated external profile roots. |
| `CIVIKITCHEN_TRUST_EXTERNAL_PROFILES` | `0` | all | `1` allows profiles from outside the bundled root. |
| `CK_SEED_SIZE` / `CK_SEED_TODAY` / `CK_SEED_STORE` | `demo` / today / `/var/lib/civikitchen/snapshots` | standalone | Size, generation day and store of seed snapshots. |
| `CK_CREDENTIALS_FILE` | web user's `$HOME/api-credentials.txt` | all | Where generated API credentials go. [Credentials](#credentials) |
| `CK_CREDENTIALS_OUTPUT` | `file` | all | How generated credentials are disclosed: `file`, `log`, `both`, `none`. |
| `CIVIKITCHEN_LOCALES` | _(unset)_ | all | Core language files installed at first boot. [Locales](#locales) |
| `CIVIKITCHEN_DEFAULT_LOCALE` | _(unset)_ | all | The site language. |
| `CIVIKITCHEN_SMTP_HOST` | _(unset)_ | all | SMTP host for CiviCRM's mailing backend. [Mail, packages and debugging](#mail-packages-and-debugging) |
| `CIVIKITCHEN_SMTP_PORT` | `1025` | all | Port for `CIVIKITCHEN_SMTP_HOST`. |
| `CIVIKITCHEN_EXTRA_PACKAGES` | _(unset)_ | standalone | Debian packages installed on container start. |
| `XDEBUG_MODE` (+ `XDEBUG_CLIENT_HOST`, `XDEBUG_CLIENT_PORT`, `XDEBUG_START_WITH_REQUEST`, `XDEBUG_DISCOVER_CLIENT_HOST`, `XDEBUG_IDEKEY`) | off | all | Turns Xdebug on. |

## Database

- The standalone image connects as the app user `CIVICRM_DB_USER`. Buildkit
  creates the database and user itself.
- `CIVICRM_DB_ROOT_PASSWORD`: civibuild uses it to create the site database
  and user on the first-run build. The standalone image uses it to create,
  grant and seed the `<db>_test` database.
- `CIVIKITCHEN_TEST_DB=1` sets up the isolated headless test database
  `<db>_test`, for example `civicrm_test`. On first install it is created,
  granted and seeded as the database root user. `TEST_DB_DSN` is written on
  every boot. Set `0` to manage `TEST_DB_DSN` yourself. The boot-stub patch
  applies either way. See
  [Headless tests](extension-development.md#headless-tests).
- `CIVIKITCHEN_REINSTALL=1`, for one boot, lets a fresh app container rebuild
  over an existing civikitchen site in the external database. The marker is a
  sentinel in `civikitchen_state`; see
  [Buildkit flavors](images.md#buildkit-flavors-dev). Without it, the boot
  also refuses when it cannot read the sentinel at all, for example on denied
  root access or a lost connection, rather than assume there is no site.

## Site and install

- `CIVIKITCHEN_SITE_URL` must match the host and port you open, because
  CiviCRM bakes it into asset paths; otherwise assets return 404. On the demo
  images it rewrites the baked base URL at boot
  ([Demo images](images.md#demo-images)). On buildkit it defaults to
  `http://<HTTPD_DOMAIN>[:<HTTPD_PORT>]`; setting it overrides both.
- `CIVIKITCHEN_DEMO_USER` creates a CiviCRM login with this username and the
  admin role during auto-install. It requires `CIVICRM_AUTO_INSTALL=1`, which
  installs CiviCRM on first start when `civicrm.settings.php` is missing.
- `CIVIKITCHEN_COMPONENTS` is a comma-separated list. The default is the full
  set: CiviEvent, CiviContribute, CiviMember, CiviMail, CiviPledge, CiviCase,
  CiviReport and CiviCampaign. Narrow the list, or pass an empty string to get
  cv's core-only default.
- `CIVICRM_SITE_TYPE` defaults per tag: `drupal10-demo` on `:drupal10`,
  `drupal11-demo` on `:drupal11`, `wp-demo` on `:wordpress`, `joomla-demo` on
  `:joomla`, `backdrop-demo` on `:backdrop`. Any
  [civibuild template](https://docs.civicrm.org/dev/en/latest/tools/civibuild/)
  works. `drupal11-demo` is not an upstream template; CiviKitchen vendors it
  in
  [`docker/buildkit/site-types/drupal11-demo/`](https://github.com/civico-de/civikitchen/tree/main/docker/buildkit/site-types/drupal11-demo/).

## Extensions

- Every extension bind-mounted into `/var/www/html/ext` is enabled after
  install without being listed anywhere. `CIVIKITCHEN_ENABLE_EXTENSIONS` sets
  the order. Each extension's `<requires>` in `info.xml` is resolved first: a
  missing dependency comes from the registry, or from its
  `policy.extension_sources` entry (Composer version constraint, HTTPS and
  SHA-256), and is enabled before the extension. CiviCRM downloads an
  extension only once its own requirements are installed, so these are
  installed and enabled before the download.
- `CIVIKITCHEN_EXTRA_EXTENSIONS` lists extension keys to download and enable,
  for example `de.systopia.xcm,de.systopia.twingle`. It runs once during
  first-boot provisioning. A bare key comes from its verified source if a
  mounted extension's `civikitchen.yaml` declares a version-constrained,
  digest-pinned entry for it under `policy.extension_sources`. An explicit
  `key@URL` value is an operator override and does not count as pinned. The
  requirements an entry declares and the site lacks take such a pin the same
  way, else come from the registry, and are installed and enabled before the
  entry.
- `CIVIKITCHEN_EXTENSION_UNDER_TEST` is the key of the extension mounted at
  `/var/www/html/ext/<key>` whose `civikitchen.yaml` pins take precedence over
  every other mounted extension's, for a dependency both pin. A sibling's pin
  still applies to a key this extension does not pin. The shared CI workflow
  sets it when it mounts `sibling_repo`. Without an `info.xml` at that path,
  provisioning fails.
- `CK_DEP_ARCHIVE_DIR` is read when a `policy.extension_sources` entry pins a
  dependency to a `release:` instead of a `url:`. The entrypoint checks each
  archive against its pinned SHA-256. A release pin without its archive here
  is an error and never falls back to the registry. The web user (`www-data`,
  `buildkit` on the buildkit images) reads the archives, so they have to be
  readable for it. See
  [A dependency only a credential can reach](extension-releases.md#a-dependency-only-a-credential-can-reach).
- `CIVIKITCHEN_EXTENSION_PATH` names a directory holding an extension, and
  `CIVIKITCHEN_EXTENSION_KEY` its key, which must match `info.xml`. Provisioning
  links the directory into the image's extension directory under
  `CIVIKITCHEN_EXTENSION_DIR` (default: the key). Rendered
  [scenarios](scenarios.md#the-rendered-model) and `ckboot` set them.

## Profiles and seed data

- `CIVIKITCHEN_PROFILE` takes one or more comma-separated profiles, applied in
  order once at first boot: `common`, `dach`, `membership`, `fundraising`,
  `events`, `mailing`, or one found via `CIVIKITCHEN_PROFILE_PATH`. It needs
  network access and takes a few minutes. See
  [Profiles](images.md#profiles-civikitchen_profile).
- `CIVIKITCHEN_PROFILE_PATH` roots each hold `<name>/profile.json`.
- `CIVIKITCHEN_TRUST_EXTERNAL_PROFILES` must be `1` before any profile from
  outside the bundled root may run. It trusts the whole mounted root,
  including its shell drivers and PHP seeds.
- `CK_SEED_TODAY` (`Y-m-d`) fakes the day a profile generates for and a
  restore shifts to. The three `CK_SEED_*` variables are explained in
  [Seed snapshots](seed-snapshots.md#ck-seed).

## Credentials

Profile API-user provisioning (`configure-api-users.php`) writes the generated
credentials to `CK_CREDENTIALS_FILE`, one `username:password:api_key` line per
API user. The default home is `/home/buildkit/` on buildkit flavors and
`~www-data/` on the standalone dev image. Profile seeds may write sibling
files next to it, such as a site-key export, so copying the whole directory
out with `docker cp` picks them up too.

`CK_CREDENTIALS_OUTPUT` decides how generated passwords and API keys are
disclosed:

| Value | Effect |
|---|---|
| `file` | Writes only the mode-0600 credentials file. |
| `log` | Removes any stale file and prints to the container log only. |
| `both` | Writes the file and prints to the log. |
| `none` | Removes any stale file; the new credentials exist only in the accounts. |

Logging is opt-in because CI often archives container logs. An unknown value
aborts before API users are created.

## Locales

With `CIVIKITCHEN_LOCALES` set, for example `de_DE,fr_FR`, first boot
downloads the `civicrm-<version>-l10n.tar.gz` that matches the installed
version from download.civicrm.org and unpacks only the requested locales into
`[civicrm.l10n]`. The tarball is about 100 MB, so the variable is off by
default. An alpha or beta core, such as `:standalone-master` or
`:standalone-beta`, takes core's daily `civicrm-l10n-daily.tar.gz` instead. A
stalled download is aborted and retried; an unknown locale fails the boot
rather than leaving English behind.

Without core language files, core strings stay English. An extension's own
`l10n/<locale>/LC_MESSAGES/<file>.mo` still translates, so testing an
extension's catalogue needs no core language files.

`CIVIKITCHEN_DEFAULT_LOCALE` must be one of `CIVIKITCHEN_LOCALES`; a default
without its language files is an error, not a silent fallback to English. It
acts at install time only, and installs the standalone site in that language.
The language files are fetched before `cv core:install --lang=<locale>` runs.
The seed data labels, such as financial types, membership statuses, location
types and payment instruments, come out translated while their machine names
stay English. The installer also applies core's defaults for the locale from
`settings/l10n/<locale>/settings.default.json`; for `de_DE` that means EUR,
Germany, ISO dates and Europe/Berlin. An existing site keeps its data. The
buildkit flavors create their site with civibuild and only set `lcMessages`
afterwards, so their seed labels stay English.

## Mail, packages and debugging

- `CIVIKITCHEN_SMTP_HOST` points CiviCRM's mailing backend at this SMTP host
  after install. The example compose stack uses maildev, so outbound mail
  shows up in its UI on port 1080. On the buildkit flavors it also removes
  buildkit's `app/civicrm.settings.d/100-mail.php`. That file cannot see the
  database-level backend and would set `CIVICRM_MAIL_LOG=/dev/null`, which
  swallows all outbound mail.
- `CIVIKITCHEN_EXTRA_PACKAGES` takes comma- or space-separated Debian
  packages, for example `libreoffice-writer,unoconv` for CiviOffice rendering,
  and keeps heavy or niche dependencies out of the image. Restarts skip
  packages already present. apt needs root, so the service sets `user: root`,
  and `docker compose exec` then needs `-u www-data` for cv and the ck tools.
- Xdebug is installed but off until `XDEBUG_MODE` is set, for example to
  `debug,develop`. Defaults: client `host.docker.internal:9003`, start with
  request on trigger, client host discovery off, IDE key `VSCODE`. See
  [IDE step debugging](extension-development.md#ide-step-debugging).
