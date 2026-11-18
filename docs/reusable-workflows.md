# Reusable CI building blocks

civikitchen ships six reusable workflows. `extension-ci.yml` is the complete
default for a CiviCRM extension; the four smaller ones cover checks several
extensions need beside that stack, so a repo does not copy the boilerplate for
checkout, toolchain, artifacts, cleanup and runner selection.

| Workflow | Purpose | Inputs |
|---|---|---|
| `extension-ci.yml` | Boots the extension's CiviCRM stack and runs every gate | [`extension-ci.yml` inputs](#extension-ciyml-inputs) |
| `extension-release.yml` | Builds, smoke-tests and publishes a release | [Releasing an extension](extension-releases.md#inputs) |
| `frontend-ci.yml` | Install, typecheck, lint, test and build an npm project | [Frontend CI](#frontend-ci) |
| `playwright-e2e.yml` | A browser suite that owns its stack | [Standalone Playwright E2E](#standalone-playwright-e2e) |
| `command-check.yml` | One cheap command the repository owns | [Repository command checks](#repository-command-checks) |
| `extension-docs.yml` | The `docs/handbook/` directory as a site on GitHub Pages | [Documentation site](#documentation-site) |

[Working directory](#working-directory) and [Runner selection](#runner-selection)
apply to all of them except `extension-docs.yml`.

Deployment credentials and product-specific orchestration do not belong in
these workflows. Deploys, image fleets, tenant discovery and bespoke service
topologies stay in the repository that owns them.

## `extension-ci.yml` inputs

Every input is off or empty by default unless the table says otherwise. What
the gates check is in [Extension standards](extension-standards.md).

| Input | Default | Effect | Details |
|---|---|---|---|
| `key` | empty | Cross-check of info.xml's `<file>`; a different value fails the run | — |
| `working_directory` | `.` | The extension's directory in a repository of several extensions | [Working directory](#working-directory) |
| `compose_file` | `.docker/docker-compose.ci.yml` | The stack's compose file, relative to `working_directory` | — |
| `check_template` | `true` | The template drift job (`ckinit --check`) | [Managed and seeded template files](extension-development.md#managed-and-seeded-template-files) |
| `image` | empty | Overrides the stack's image. Empty takes `:standalone-<minor>` of `policy.civicrm`, else the compose file's default; setting both is an error | [Repository policy](extension-standards.md#repository-policy-civikitchenyaml) |
| `matrix_images`, `lifecycle`, `upgrade_from_last_release`, `schema_parity`, `core_upgrade_from`, `matrix_core_upgrade` | off | Compatibility jobs | [Compatibility](extension-standards.md#compatibility-test-the-range-you-claim) |
| `npm_ci`, `js_tests`, `bun`, `playwright`, `matrix_playwright` | off | Frontend install, JS tests and the browser job | [Frontend](extension-standards.md#frontend-js-dependencies-js-tests-and-browser-tests) |
| `node_version` | `24` | Node for the frontend steps | [Frontend](extension-standards.md#frontend-js-dependencies-js-tests-and-browser-tests) |
| `e2e`, `e2e_compose` | empty | Shell suites and extra compose files for them | [The shell e2e job](extension-standards.md#the-shell-e2e-job) |
| `mutation` | off | ckmutate as its own job | [Mutation testing](extension-standards.md#mutation-testing) |
| `extra_phpunit_config` | empty | A second PHPUnit suite, below | — |
| `runs_on` | empty | Runner label | [Runner selection](#runner-selection) |
| `composer_install`, `sibling_repo`, `composer_app_repositories` | off / empty | Private Composer packages and sibling extensions | [Private dependencies](extension-standards.md#private-dependencies) |
| `policy_defaults` | empty | The owner/repo whose root civikitchen.yaml holds organisation-wide policy; empty falls back to the `CK_POLICY_DEFAULTS` variable | [Repository policy](extension-standards.md#repository-policy-civikitchenyaml) |

The secrets `composer_app_id` and `composer_app_private_key` serve
`composer_install`, `sibling_repo` and a private `policy_defaults` repository.

An extension with an additional PHPUnit configuration keeps using the main
workflow rather than a toolbox job:

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
included, takes a `working_directory` input. It defaults to the repository
root, except in `playwright-e2e.yml`, where it defaults to `tests/e2e`. Each
job runs there. The value must be a plain relative path inside the repository.

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

- a scheduled caller next to the push caller, as long as its `name:` differs,
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

In `extension-ci.yml`, the jobs that boot no stack (`key`, `template`, `osv`,
`security`) read the `CK_RUNS_ON_SMALL` variable before `CK_RUNS_ON`.

GitHub-hosted runners bring every tool the workflows call. A self-hosted
runner needs these on the host; Node and Bun come from their setup actions:

| Workflow | Host tools |
|---|---|
| `extension-ci.yml` | Docker with the compose plugin, git, php-cli with the xml and posix extensions, composer, curl, jq |
| `extension-release.yml` | Docker with the compose plugin, git, php-cli with the xml, posix and zip extensions, composer, gh, unzip |
| `playwright-e2e.yml` | Docker, git, php-cli with the xml and posix extensions; composer when `sibling_repo` is set |
| `frontend-ci.yml`, `command-check.yml` | whatever the caller's commands run |

Never attach a self-hosted runner to a public repository: a pull request from
a fork runs its own code on it. The workflows refuse it in their first step
(`runner.environment` is `self-hosted` and the repository is public). This
catches a misconfigured `runs_on` or `CK_RUNS_ON`, not a fork that rewrites
the calling workflow, so the runner must still not be attached.

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

| Input | Default |
|---|---|
| `working_directory` | `.` (holds package.json and the lockfile) |
| `node_version` | `24` |
| `cache_dependency_path` | `package-lock.json`, relative to the repository root |
| `install_command` | `npm ci` |
| `typecheck_command`, `lint_command`, `build_command`, `verify_command` | empty |
| `test_command` | `npm test` |
| `runs_on` | empty |

## Standalone Playwright E2E

`playwright-e2e.yml` is for repositories whose browser suite owns its stack.
When the suite uses the standard CiviKitchen compose stack, use the
`playwright` input on `extension-ci.yml` instead.

The workflow runs these phases in order: install, browser install, prepare,
test, diagnostics and artifacts. A cleanup phase always runs. Commands run
inside `working_directory`. A diagnostic that needs paths from the repository
root needs an explicit `cd`.

```yaml
jobs:
  e2e:
    uses: civico-de/civikitchen/.github/workflows/playwright-e2e.yml@v1
    with:
      prepare_command: bash setup.sh
      test_command: php "$CK" playwright npx playwright test
      cleanup_command: bash teardown.sh
```

| Input | Default | Use |
|---|---|---|
| `working_directory` | `tests/e2e` | The Playwright project |
| `node_version` | `24` | Node on the runner |
| `cache_dependency_path` | `tests/e2e/package-lock.json` | The npm cache key |
| `install_command`, `browser_install_command` | empty | Install on the runner; an `install_command` also turns on the npm cache |
| `prepare_command` | empty | Build or stack setup |
| `test_command` | `npx playwright test` | The suite |
| `failure_command` | empty | Diagnostics after a failure |
| `cleanup_command` | empty | Always runs last |
| `base_url` | empty | Exported as `BASE_URL` |
| `artifact_paths` | `tests/e2e/playwright-report/` and `tests/e2e/test-results/` | Newline-separated, relative to the repository root |
| `artifact_retention_days` | `7` | |
| `timeout_minutes` | `30` | The job's limit |
| `runs_on` | empty | [Runner selection](#runner-selection) |
| `sibling_repo`, `composer_app_repositories` | empty | [Private dependencies](extension-standards.md#private-dependencies); the secrets are `composer_app_id` and `composer_app_private_key` |

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

The images do not run `composer install` on a mounted extension. A
`prepare_command` that boots one must install `vendor/` first, as the runner
user. `ckup` does that, as does the `docker compose run` line from
[The dev stack](extension-development.md#the-dev-stack).

## Repository command checks

`command-check.yml` checks out the caller and runs one cheap command that the
repository owns. It is meant for guards that need no CiviCRM stack, such as
public-safety or generated-file checks. Its inputs are `command` (required),
`working_directory` (default `.`) and `runs_on`.

```yaml
jobs:
  guard:
    uses: civico-de/civikitchen/.github/workflows/command-check.yml@v1
    with:
      command: bash tools/qa/check-public-safe.sh
```

## Documentation site

`extension-docs.yml` turns the extension's `docs/handbook/` directory, or the
one its info.xml tag `handbook:dir=<name>` names, into a documentation site on
GitHub Pages. These are the same pages the handbook extension shows inside
CiviCRM, so one set of Markdown files serves both. The rest of `docs/` is
developer documentation and is not published
([ADR-0020](adr/0020-extension-docs-live-in-docs.md)). Its
inputs are `languages` (space-separated, default `en`) and `deploy` (default
`false`; the caller decides when).

```yaml
name: Docs
on:
  push:
    branches: [main]
    paths: ['docs/handbook/**', 'info.xml']
  pull_request:
    paths: ['docs/handbook/**', 'info.xml']
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

An extension whose tag names another directory sets both `paths:` filters to
that directory.

Set the repository's Pages source to GitHub Actions once. GitHub serves Pages
from a private repository only on paid plans. The workflow always runs on
GitHub-hosted runners and takes neither `runs_on` nor `working_directory`.

**Pages.** The site takes its name and description from `info.xml`. A
`docs/handbook/images/logo.svg` (or `logo.png`) stands in the header next to the
name and serves as favicon; without one the header shows the theme's icon.
Each page's frontmatter sets its title, its position (`order`) and its group
in the navigation (`section`), just as it does in the handbook. A
subdirectory with pages is a section, named after its `index.md`'s title or
after the directory; that page comes first and places the section. Links between
pages are relative, such as `setup.md#first-steps`, and the build fails on a
link to a missing page or heading. Heading ids are made the way the handbook
makes them: lower case, dashes for spaces, umlauts kept, dots and underscores
dropped. A heading `widget.max_items` is linked as `#widgetmaxitems`.

**Languages.** The first one in `languages` is the default: its pages are the
plain `name.md` files, served at the site root. Every other language gets its
own copy under `/<lang>/`, built from `name.<lang>.md` where that file exists
and from `name.md` where it does not. A translation takes the frontmatter keys
it leaves out, such as `order`, from its default page. A language switch in
the header links the copies. A translation without its default page fails the
build, and so does a translation for a language the caller did not list. A
dotted name that is not a language suffix, such as `notes.v1.2.md`, is an
ordinary page.

**Accessibility.** Every page of every language is checked against WCAG 2.1
AA in light and dark mode before anything is deployed.
