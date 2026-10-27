# Reusable CI building blocks

`extension-ci.yml` stays the complete default for a CiviCRM extension. Some
checks are needed by several extensions beside that stack. Four smaller
reusable workflows cover them, so a repo does not copy the boilerplate for
checkout, toolchain, artifacts, cleanup and runner selection.

An extension with an additional PHPUnit configuration should keep using the
main workflow rather than create a toolbox job:

```yaml
jobs:
  ci:
    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      extra_phpunit_config: phpunit-unit.xml.dist
```

That suite runs after the standard headless coverage suite. It reuses the same
installed Composer dependencies, the optional sibling checkouts and the
container.

## Working directory

Every one of these workflows except `extension-docs.yml`, `extension-ci.yml`
included, takes a `working_directory` input. It defaults to the repository root, except in
`playwright-e2e.yml`, where it defaults to `tests/e2e`. Each job runs there.
The value must be a plain relative path inside the repository.

In `frontend-ci.yml` and `playwright-e2e.yml`, `cache_dependency_path` and
`artifact_paths` stay relative to the repository root.

In `extension-ci.yml` and `extension-release.yml`, the working directory is
the extension's directory in a repository that holds several extensions. The
following are then named relative to it: `compose_file`, the lockfile the
dependency cache keys on, artifact paths and scan targets. Concurrency groups
include the directory, and compose project names use the extension key. One
job per extension in the same caller therefore does not cancel or tear down
its neighbour.

The concurrency groups in `extension-ci.yml` also include the calling
workflow's name and the `image`, including a matrix leg's image. As a result,
none of these cancel each other:

- a scheduled caller next to the push caller,
- runs of one caller on different images,
- the legs of one matrix.

Call `extension-ci.yml` once per extension and run. Pass further images
through `matrix_images`, because two calls for one extension in one run share
their compose project and artifact names. Where each part of the run happens
in this layout, and how the release splits into `build` and `publish` stages,
is described in
[Several extensions in one repository](extension-development.md#several-extensions-in-one-repository).

## Runner selection

All jobs except those of `extension-docs.yml` pick their runner in this order:

1. the workflow's `runs_on` input,
2. the caller repository's `CK_RUNS_ON` variable,
3. `ubuntu-latest`.

GitHub-hosted runners bring every tool the workflows call. A self-hosted
runner needs these on the host; Node and Bun come from their setup actions:

| Workflow | Host tools |
|---|---|
| `extension-ci.yml` | Docker with the compose plugin, git, php-cli with the xml and posix extensions, composer, curl, jq |
| `extension-release.yml` | Docker with the compose plugin, git, php-cli with the xml, posix and zip extensions, composer, gh, unzip |
| `playwright-e2e.yml` | Docker, git, php-cli with the xml and posix extensions; composer when `sibling_repo` is set |
| `frontend-ci.yml`, `command-check.yml` | whatever the caller's commands run |

Never attach a self-hosted runner to a public repository: a pull request from
a fork runs its own code on it.

## Frontend CI

`frontend-ci.yml` installs an npm project. It then runs the typecheck, lint,
test, build and output-verification commands in that order, skipping any that
are empty. Commands are passed through environment variables, not interpolated
into shell source.

```yaml
jobs:
  frontend:
    uses: civico-de/civikitchen/.github/workflows/frontend-ci.yml@v1
    with:
      working_directory: frontend
      cache_dependency_path: frontend/package-lock.json
      typecheck_command: npx tsc --noEmit
      lint_command: npm run lint
      test_command: npm test
      build_command: npm run build
```

## Standalone Playwright E2E

`playwright-e2e.yml` is for repositories whose browser suite owns its stack.
It runs these phases in order: install, browser install, prepare, test,
diagnostics and artifacts. A cleanup phase always runs. Commands run inside
`working_directory`. A diagnostic that needs paths from the repository root
needs an explicit `cd`.

```yaml
jobs:
  e2e:
    uses: civico-de/civikitchen/.github/workflows/playwright-e2e.yml@v1
    with:
      prepare_command: bash setup.sh
      test_command: php "$CK" playwright npx playwright test
      cleanup_command: bash teardown.sh
```

`$CK` is the `ck` of the civikitchen checkout the workflow makes.
`ck playwright` runs the command in an image with the browsers of the
Playwright version the lockfile resolves and the npm or Bun tree, so neither
an install nor a browser install runs on the runner
([ADR-0019](adr/0019-browser-suites-run-in-the-projects-playwright-image.md)).
It uses the host network, so the suite reaches a published port on
localhost. On a shared self-hosted Docker host, `--network container:<id>` of
the stack's web container keeps other jobs' interfaces out of the browser's
network. The image sees the work tree only, not `$RUNNER_TEMP`: layer
`CK_SIBLING_OVERRIDE` on the stack in `prepare_command`, on the runner.
`install_command` and `browser_install_command` remain for suites that install
on the runner; an `install_command` also turns on the npm cache.

When the suite uses the standard CiviKitchen compose stack, prefer the
`playwright` input on `extension-ci.yml`. This standalone workflow is for
suites with their own setup and teardown. The images do not run
`composer install` on a mounted extension. A `prepare_command` that boots one
must install `vendor/` first, as the runner user. `ckup` does that, as does
the `docker compose run` line from
[Extension development](extension-development.md).

## Repository command checks

`command-check.yml` checks out the caller and runs one cheap command that the
repository owns. It is meant for guards that need no CiviCRM stack, such as
public-safety or generated-file checks.

```yaml
jobs:
  guard:
    uses: civico-de/civikitchen/.github/workflows/command-check.yml@v1
    with:
      command: bash tools/qa/check-public-safe.sh
```

Deployment credentials and product-specific orchestration do not belong in
these workflows. Deploys, image fleets, tenant discovery and bespoke service
topologies stay in the repository that owns them.

## Documentation site

`extension-docs.yml` turns the extension's `handbook/` directory into a
documentation site on GitHub Pages. These are the same pages the handbook
extension shows inside CiviCRM, so one set of Markdown files serves both.

The site takes its name and description from `info.xml`. Each page's
frontmatter sets its title, its position (`order`) and its group in the
navigation (`section`), just as it does in the handbook. Links between pages
are relative, such as `setup.md#first-steps`, and the build fails on a link to
a missing page or heading. Heading ids are made the way the handbook makes
them: lower case, dashes for spaces, umlauts kept, dots and underscores
dropped. A heading `pulse.host_status` is linked as `#pulsehoststatus`.

A site can have several languages. The first one in `languages` is the default:
its pages are the plain `name.md` files, served at the site root. Every other
language gets its own copy under `/<lang>/`, built from `name.<lang>.md` where
that file exists and from `name.md` where it does not. A translation takes the
frontmatter keys it leaves out, such as `order`, from its default page. A language switch in the
header links the copies. A translation without its default page fails the
build, and so does a translation for a language the caller did not list. A
dotted name that is not a language suffix, such as `notes.v1.2.md`, is an
ordinary page.

Every page of every language is checked against WCAG 2.1 AA in light and dark
mode before anything is deployed.

```yaml
name: Docs
on:
  push:
    branches: [main]
    paths: ['handbook/**', 'info.xml']
  pull_request:
    paths: ['handbook/**', 'info.xml']
jobs:
  docs:
    uses: civico-de/civikitchen/.github/workflows/extension-docs.yml@v1
    permissions:
      contents: read
      pages: write
      id-token: write
    with:
      languages: en de
      deploy: ${{ github.event_name == 'push' }}
```

Set the repository's Pages source to GitHub Actions once. GitHub serves Pages
from a private repository only on paid plans. The workflow always runs on
GitHub-hosted runners and takes neither `runs_on` nor `working_directory`.
