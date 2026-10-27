# civikitchen

[![Build Dev Images](https://github.com/civico-de/civikitchen/actions/workflows/build-dev-images.yml/badge.svg)](https://github.com/civico-de/civikitchen/actions/workflows/build-dev-images.yml)
[![Lint](https://github.com/civico-de/civikitchen/actions/workflows/lint.yml/badge.svg)](https://github.com/civico-de/civikitchen/actions/workflows/lint.yml)
[![GHCR](https://img.shields.io/badge/GHCR-ghcr.io%2Fcivico--de%2Fcivikitchen-24292f?logo=github)](https://github.com/civico-de/civikitchen/pkgs/container/civikitchen)
[![License: AGPL-3.0](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE.md)

CiviCRM Docker images with the development toolchain built in, an extension
template, and reusable GitHub workflows that test, check and release CiviCRM
extensions. Documentation: <https://civico-de.github.io/civikitchen/>

| You want to … | Start with |
|---------------|------------|
| Develop an extension | [Extension development](#extension-development) |
| Test and release it on GitHub | [CI and releases](#ci-and-releases) |
| Work on CiviCRM core | [Core development](#core-development) |
| Try or demo CiviCRM | [Demo instances](#demo-instances) |

The host needs Docker with the compose plugin and bash; the rest is in the image.
Browser suites and `ck demo` also need PHP 8.1 or newer and the buildx plugin.

## Extension development

```bash
git clone https://github.com/civico-de/civikitchen
civikitchen/scaffold/ckcreate myext \
    --author "Example Maintainer" --email dev@example.org --copyright "Example Org"
cd myext
git add -A && git commit -m "Scaffold myext"   # after reviewing it
../civikitchen/scaffold/ckup --wait --wait-timeout 900   # dev stack on free ports; waits out the first boot
../civikitchen/scaffold/ckx ck ci                         # tests and every check, as CI runs them
```

ckcreate generates the module with civix and adds the template: compose
files, CI and release workflows, phpstan and phpunit config, and a first test.
For an existing civix extension, `scaffold/ckinit <dir>` adds the same files.

ckup starts CiviCRM Standalone with MariaDB and Maildev and enables the
extension; phpMyAdmin is opt-in (`COMPOSE_PROFILES=pma` in `.docker/.env`). ckx runs a command in the stack, in the extension's
directory. The image carries cv, civix, composer, node, phpunit, phpstan, mago,
xdebug and the ck tools. Browser suites run in Docker as well:
`../civikitchen/toolbelt/bin/ck playwright npm run test:e2e` builds an image
with the browsers and the repository's npm or Bun tree, so the host needs
Docker and PHP, and no Node.

→ [Extension development](docs/extension-development.md): test loop, civix,
analysis, Playwright, debugging, several extensions in one repository.

## CI and releases

```yaml
# .github/workflows/ci.yml of a templated extension
jobs:
  ci:
    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1
```

CI boots the stack, runs PHPUnit with coverage and the gates below, and fails
when template-managed files have drifted. Matrix images, upgrade tests, JS
tests, Playwright and mutation testing are opt-in inputs of
[extension-ci.yml](.github/workflows/extension-ci.yml). A `vX.Y.Z` tag
releases the extension ([Releasing an extension](docs/extension-releases.md)).
Run `ckx ck ci` for the same gates locally, in this order:

| Gate | Checks |
|------|--------|
| cklint | PHP syntax, mago lint with CiviCRM-specific rules |
| ckconform | Repository conventions: config files, licence and PHP floor, test bootstrap, workflow permissions, managed entities |
| ckcivix | civix format is current |
| ckfmt | Formatting: mago for PHP, oxfmt for JS/TS |
| ckcoverage | PHPUnit line coverage against the floor |
| phpunit-extra | A second PHPUnit config, if one is named |
| phpstan | Level 10 with civikitchen's CiviCRM rules |
| phpstan-tests | phpstan over the tests, if its config exists |
| ckanalyze | mago analyze against core; reported, not gating |
| ckcompat | PHP compatibility with the declared floor |
| ckdeps | composer.json matches what the code uses |
| cktaint | Taint analysis with psalm |
| cksmarty | Every Smarty template compiles |
| ckeslint | oxlint for JS/TS |

Per-repository policy, such as licence and coverage floor, lives in
`civikitchen.yaml`; suppressions always carry a reason. Pinning `@v1` pins
workflows, template, tools and image as one version
([Releases](docs/releases.md)).

→ [Extension standards](docs/extension-standards.md),
[Declarative scenarios](docs/scenarios.md)

## Core development

ckcoretest runs core's own PHPUnit suites in a standalone stack, fetching the
tests the release tarball leaves out:

```bash
docker compose exec app ckcoretest tests/phpunit/api/v4/Query
docker compose exec app ckcoretest --ext search_kit
```

The standalone-master and standalone-beta images are built daily from core's
master and newest release branch. To test a core change, mount the directory
it touches from a civicrm-core checkout over the image's copy:

```yaml
services:
  app:
    image: ghcr.io/civico-de/civikitchen:standalone-master
    volumes:
      - ../civicrm-core/ext/afform:/var/www/html/core/ext/afform
```

`ck demo <PR>` records a core change before and after, on one CMS or all of
them, as videos side by side on one page.

→ [Core development](docs/core-development.md): other branches, e2e, demos, limits.

## Demo instances

```bash
docker run -d -p 80:80 --name civicrm ghcr.io/civico-de/civikitchen:standalone-demo
```

Open http://localhost and log in as admin / admin. For another host port, set
`CIVIKITCHEN_SITE_URL` to the URL you open.

A profile adds extensions, seed data and API users on first boot: verein,
fundraising, events or mailing:

```bash
docker run -d -p 80:80 --name civicrm -e CIVIKITCHEN_PROFILE=verein \
    ghcr.io/civico-de/civikitchen:drupal10-demo
docker logs -f civicrm   # first boot installs the profile, takes a few minutes
docker exec civicrm cat /home/buildkit/api-credentials.txt
```

→ [Demo images](docs/images.md#demo-images), [Profiles](docs/images.md#profiles-civikitchen_profile)

## Images

`ghcr.io/civico-de/civikitchen` is multi-arch. The standalone image is the
main one; drupal10, drupal11, wordpress and joomla carry the same tools on a
CMS site, each with a demo variant. Moving tags are rebuilt daily and move only
after their tests pass. Extensions pin the v1 release tags.
→ [Images](docs/images.md), [Tags and versions](docs/images.md#tags-and-versions)

## Documentation

| Document | Contents |
|----------|----------|
| [Images](docs/images.md) | Flavors, demo images, profiles, tags, database compatibility, custom versions |
| [Configuration](docs/configuration.md) | Environment variables the images read |
| [Core development](docs/core-development.md) | ckcoretest, unreleased core branches, mounting a core checkout, before/after recordings |
| [Extension development](docs/extension-development.md) | Dev stack, tests, civix, analysis, Playwright, debugging |
| [Extension standards](docs/extension-standards.md) | What the gates enforce and why |
| [Declarative scenarios](docs/scenarios.md) | civikitchen.yaml: policy and stack scenarios |
| [Reusable workflows](docs/reusable-workflows.md) | Frontend CI, Playwright e2e, command checks |
| [Releasing an extension](docs/extension-releases.md) | ckrelease and the release workflow |
| [Releases](docs/releases.md) | How civikitchen itself is versioned |
| [Building locally](docs/building.md) | Build arguments, tool pins, image tests, make doctor |
| [Architecture](docs/implementation-architecture.md), [ADRs](docs/adr/) | Design decisions; [mago](docs/mago-consolidation.md) and [core types](docs/core-types.md) background |
| [Changelog](CHANGELOG.md) | Changes per release |

## License

AGPL-3.0. See [LICENSE.md](LICENSE.md).
