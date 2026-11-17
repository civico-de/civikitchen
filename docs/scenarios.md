# Declarative scenarios

Each extension keeps its whole CiviKitchen configuration in one file,
`civikitchen.yaml`: the repository and toolbelt `policy`, and the `scenario`
that runs locally or in CI. This page covers the `scenario` part and the
`ck scenario` commands that turn it into a Docker Compose stack. The `policy`
keys are in
[Repository policy](extension-standards.md#repository-policy-civikitchenyaml).
Profiles stay in their own `profile.json` files, and the config selects them
by name.

CiviKitchen parses the file with the pinned Symfony YAML component and
validates it against the published JSON Schema
([`scenario.schema.json`](https://github.com/civico-de/civikitchen/blob/main/packages/civikitchen-scenario-schema/scenario.schema.json)).
JSON input is rejected, so one filename and one syntax work the same on
laptops and in CI ([ADR-0010](adr/0010-one-yaml-configuration-file.md)).

## Commands

| Command | Does |
|---|---|
| `ck config validate [file]` | Validates the file against the schema and the cross-field rules. `ck scenario validate` is the same. |
| `ck scenario plan [file]` | Prints the normalized scenario as JSON, defaults filled in and paths resolved. |
| `ck scenario commands [file]` | Prints the `ck` invocation for each entry in `checks`. |
| `ck scenario compose [file]` | Prints the Compose model as JSON. |
| `ck scenario materialize [file] [output]` | Writes the Compose model, by default to `civikitchen.compose.json`. |

`file` defaults to `civikitchen.yaml`.

```bash
ck config validate
ck scenario plan
ck scenario commands

ck scenario compose > civikitchen.compose.json
# or write it directly:
ck scenario materialize civikitchen.yaml civikitchen.compose.json
# vendor/ for an extension with Composer dependencies, as its owner:
docker compose -f civikitchen.compose.json run --rm --no-deps -T \
  -u "$(id -u):$(id -g)" -e HOME=/tmp --entrypoint ck app \
  internal composer-install /civikitchen-extension
docker compose -f civikitchen.compose.json up -d --wait
```

## Keys

```yaml
"$schema": https://raw.githubusercontent.com/civico-de/civikitchen/main/packages/civikitchen-scenario-schema/scenario.schema.json
version: 1
policy:
  license: AGPL-3.0-or-later
  coverage:
    minimum: 80
  mutation:
    minimum_msi: 60
    paths:
      - Civi
  vendored_paths:
    - path: resources/vendor
      reason: Unmodified upstream assets
scenario:
  name: myextension
  image: ghcr.io/civico-de/civikitchen:v1
  database:
    image: mariadb:11.4
  extension:
    key: myextension
    path: .
    writable: false
  locales:
    - de_DE
  default_locale: de_DE
  profiles:
    - common
  profile_paths:
    - ./profiles
  trust_external_profiles: true
  credentials_output: file
  checks:
    - conform
    - lint
    - format
    - test
    - lifecycle
```

| Key under `scenario` | Default | Meaning |
|---|---|---|
| `name` | required | Compose project name: lower case, digits, `_` and `-`. |
| `image` | required | The app image. |
| `database.image` | required | The database image. When `policy.database` is set, it names the same image (a patch, variant or digest of it counts). |
| `extension.key` | required | Must match `info.xml`; see [Extension and trust rules](#extension-and-trust-rules). |
| `extension.path` | required | The extension directory, relative to the config file. |
| `extension.writable` | `false` | Mount the extension read-write. |
| `trust_external_extension` | `false` | Allow an `extension.path` outside the config directory. |
| `http_port` | `8080` | Host port of the app, 1 to 65535. |
| `site_url` | `http://localhost:<http_port>` | See [Profiles, users and ports](#profiles-users-and-ports). |
| `locales` | `[]` | Core language files to install (`CIVIKITCHEN_LOCALES`). |
| `default_locale` | _(none)_ | Site language; must also appear in `locales`. |
| `profiles` | `[]` | Profiles applied at first boot (`CIVIKITCHEN_PROFILE`). |
| `profile_paths` | `[]` | Directories holding further profiles. |
| `trust_external_profiles` | `false` | Required with any `profile_paths`. |
| `credentials_output` | `file` | `file`, `log`, `both` or `none` (`CK_CREDENTIALS_OUTPUT`). |
| `allow_secret_logging` | `false` | Required with `credentials_output: log` or `both`. |
| `checks` | `conform`, `lint`, `format`, `compatibility`, `javascript`, `test` | See [Checks](#checks). |

The environment variables in brackets are described in
[Configuration](configuration.md).

## The rendered model

The output is Compose JSON: Docker Compose accepts JSON as a form of the
Compose model, and the generator writes it with a real JSON encoder.

Relative extension and profile paths are resolved against the scenario file
and written out as absolute mount sources. A materialized model therefore
stays correct when moved to another directory.

The extension source is mounted at `/civikitchen-extension`. Each image links
that mount into the extension directory its CiviCRM discovers, so one scenario
model works across the Standalone, Drupal, WordPress and Joomla layouts.
Profile paths are mounted read-only under `/civikitchen-profiles/<n>`.

## Extension and trust rules

- The extension directory must contain `info.xml`.
- `extension.key` must match the `key` attribute of the root `<extension>`
  element, not the usually shorter `<file>` prefix.
- Bind mounts are read-only unless `extension.writable` is set.
- The images never run `composer install` on the mount. An extension without
  a committed `vendor/` gets it from the `run` line under
  [Commands](#commands), which needs a writable mount.
- An extension path outside the config directory requires
  `trust_external_extension: true`.
- Any `profile_paths` entry requires `trust_external_profiles: true`, because
  the drivers and scripts of those profiles run as trusted application code.
- `credentials_output: log` or `both` requires `allow_secret_logging: true`,
  so a committed config cannot start disclosing generated credentials by
  accident.

## Profiles, users and ports

When profiles are selected, the generator creates the Standalone `admin` demo
user that the shared profile driver needs. A scenario mounts no `init.d`
hooks. Data an extension needs belongs in a profile, and the driver applies
it after the extension is enabled.

If `site_url` is omitted, it is derived from `http_port`. A `site_url` is a
plain `http://` URL without a path, credentials, query or fragment; on
`localhost` or a `127.x` address its port must equal `http_port`.

A git-sourced dependency (`repo`) ships without `vendor/`, so the driver
resolves its Composer requirements before enabling it. For every checkout
with a `composer.json` and no `vendor/`, it runs `composer update --no-dev`,
or `composer install --no-dev` when the repository ships a `composer.lock`.
If that fails, the profile aborts with composer's output.

## Checks

The `checks` list is an enum of CiviKitchen operations and cannot contain
shell code: `conform`, `lint`, `format`, `compatibility`, `dependencies`,
`javascript`, `test`, `coverage`, `lifecycle`, `smarty`, `taint`, `mutate`.
`ck scenario commands` emits only fixed `ck` invocations: `ck lint --all` for
`lint`, `ck format --check` for `format`, and `ck <check>` for the rest.

The generated throwaway stack uses the database admin account, so CiviKitchen
can create its isolated headless scratch database without a separate grant
file.

## Reproducibility

Moving image tags and runtime downloads mean that the same scenario does not
give identical artifacts. Pin both the application and the database image by
digest.

Bundled profiles pin Git sources to full commits. A profile that uses
`registry: true` still resolves the registry's current packaged release at
first boot, because CiviCRM's registry interface exposes no artifact digest.
For byte-for-byte reproduction, use only commit-pinned dependencies, or
provide a separately checksummed package source, and avoid registry-backed
profile dependencies.

Convenience tags such as `:v1` follow maintained updates.
