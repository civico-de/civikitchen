# Declarative scenarios

Each extension keeps its whole CiviKitchen configuration in one file,
`civikitchen.yaml`. It holds the repository and toolbelt policy and the
scenario that runs locally or in CI. Profiles stay in their own `profile.json`
files, and the config selects them by name.

CiviKitchen parses the file with the pinned Symfony YAML component and then
validates the result against the published JSON Schema. JSON input is
rejected, so one filename and one syntax work the same on laptops and in CI
([ADR-0010](adr/0010-one-yaml-configuration-file.md)).

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

Validate the file and inspect the normalized plan, then render a Docker
Compose model and boot it:

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

## The rendered model

The output is Compose JSON on purpose. Docker Compose accepts JSON as a form
of the Compose model, and the generator can use a real JSON encoder.

Relative extension and profile paths are resolved against the scenario file
and written out as absolute mount sources. A materialized model therefore
stays correct when moved to another directory.

The extension source is mounted at `/civikitchen-extension`. Each image links
that mount into the extension directory its CiviCRM discovers, so one scenario
model works across the Standalone, Drupal, WordPress and Joomla layouts.

## Extension and trust rules

- The extension directory must contain `info.xml`.
- `extension.key` must match the `key` attribute of the root `<extension>`
  element, not the usually shorter `<file>` prefix.
- Bind mounts are read-only unless `extension.writable` is set.
- The images never run `composer install` on the mount. An extension without
  a committed `vendor/` gets it from the `run` line above, which needs a
  writable mount.
- An extension path outside the config directory requires
  `trust_external_extension: true`.
- External profile paths require `trust_external_profiles: true`, because
  their drivers and scripts run as trusted application code.
- `credentials_output: log` or `both` requires `allow_secret_logging: true`,
  so a committed config cannot start disclosing generated credentials by
  accident.

## Profiles, users and ports

When profiles are selected, the generator creates the Standalone `admin` demo
user that the shared profile driver needs. A scenario mounts no `init.d`
hooks. Data an extension needs belongs in a profile, and the driver applies
it after the extension is enabled.

If `site_url` is omitted, it is derived from `http_port`. Ports outside
1 to 65535 are rejected before Compose is rendered.

A git-sourced dependency (`repo`) ships without `vendor/`, so the driver
resolves its Composer requirements before enabling it. For every checkout
with a `composer.json` and no `vendor/`, it runs `composer update --no-dev`,
or `composer install` when the repository ships a `composer.lock`. If that
fails, the profile aborts with composer's output.

## Checks

The `checks` list is an enum of CiviKitchen operations and cannot contain
shell code. `ck scenario commands` emits only fixed `ck` invocations. The
generated throwaway stack uses the database admin account, so CiviKitchen can
create its isolated headless scratch database without a separate grant file.

## Reproducibility

A scenario is declaratively repeatable, but moving image tags and runtime
downloads do not give identical artifacts. Pin both the application and the
database image by digest.

Bundled profiles pin Git sources to full commits. A profile that uses
`registry: true` still resolves the registry's current packaged release at
first boot, because CiviCRM's registry interface exposes no artifact digest in
this format. For byte-for-byte reproduction, use only commit-pinned
dependencies, or provide a separately checksummed package source, and avoid
registry-backed profile dependencies.

Convenience tags such as `:v1` follow maintained updates on purpose.
