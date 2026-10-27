# Releasing an extension

This page covers how a conforming extension repo cuts a release. Versioning
civikitchen itself is covered in [releases.md](releases.md).

The pipeline lives here once, in `.github/workflows/extension-release.yml`,
and the extension repo's workflow only calls it. The tool it runs, ckrelease,
ships in the images, so a laptop and GitHub Actions build the same archive.

## What a release is

1. A commit that bumps the version in info.xml, composer.json and, if the repo
   keeps one, CHANGELOG.md. A human writes and reviews it; no tool can derive
   compatibility from a diff
   ([ADR-0002](adr/0002-a-release-is-a-human-version-and-a-tag.md)).
2. A tag named v plus that version.
3. Everything after the tag, automated: the consistency check, the archive, an
   install into a real CiviCRM, and the GitHub release.

The release-tags check in ckconform fails on any version info.xml has carried
that the repo moved past without tagging.

### A version that will never be released

If a bump was re-scoped or taken back, do not tag it late: that publishes the
code of that moment under a version nobody reviewed. Name it instead:

```yaml
policy:
  untagged_versions:
    - version: "1.2.0"
      reason: bump re-scoped into 1.3.0 before anything shipped
```

Quote the version (unquoted, 1.0 is a YAML float and the schema rejects it)
and write it as info.xml carries it, without the leading v. An entry warns as
stale when the version is tagged after all, is the current info.xml version,
or never appeared in info.xml. With release mode none, any entry warns.

## Adopting it in a repo

The caller workflow `.github/workflows/release.yml` is template-managed. Write
it with:

```bash
/path/to/civikitchen/scaffold/ckinit --update .
```

The managed part is the trigger, the permissions and the uses line. The
second tag pattern lets a pre-release such as v1.3.0-rc.1 start a run:

```yaml
on:
  push:
    tags: ['v[0-9]+.[0-9]+.[0-9]+', 'v[0-9]+.[0-9]+.[0-9]+-*']
```

Inputs and secrets go below the marker, where they survive updates:

```yaml
# END CIVIKITCHEN MANAGED caller
    with:
      # The install needs a payment processor no headless site can reach.
      smoke_test: false
      require_changelog: true
```

An older caller without markers is rewritten onto the template, keeping its
with and secrets blocks. If it holds anything else of its own (another
trigger, an env block, a further job), the update writes nothing and lists
what it would drop; move that content or declare the file under
policy.template_custom. The update also refuses to create the caller while
another workflow already calls the release workflow, since one tag push would
publish twice.

Then, once:

- Give info.xml a SemVer version, X.Y.Z or X.Y.Z-pre. civix writes 1.0, which
  ckconform fails; set 0.1.0 (ckcreate does this for you).
- Add `.ckrelease/` to `.gitignore`; local builds land there.
- Run `ckrelease check` and fix what it reports before the first tag.

A repo that never releases declares that instead. ckinit then neither writes
the caller nor reports it missing, and removes an existing one unless it has
lines of your own:

```yaml
policy:
  release:
    mode: none
    reason: internal tooling, never installed on a site
```

ckconform fails a repo that does neither. A repository with several
extensions releases them together from one caller at its root; see
[Several extensions in one repository](extension-development.md#several-extensions-in-one-repository).

### Inputs

| Input | Default | What it is for |
|-------|---------|----------------|
| `working_directory` | `.` | The extension's directory in a repository of several extensions. |
| `stage` | `release` | release builds and publishes. build builds, verifies, smoke-tests and uploads the archive as an artifact. publish builds nothing and publishes one release with every archive of the run. |
| `dry_run` | `false` | Builds the current info.xml version without a tag; the publish job lists the archives instead of publishing. |
| `image` | empty | The smoke-test image. When empty: the standalone image of the minor in policy.civicrm, otherwise the v1 image. Setting both is an error. |
| `smoke_test` | `true` | Disable only for an extension whose install cannot be reached this way, and say why in the caller. |
| `require_changelog` | `false` | Fails when the repo has no CHANGELOG.md. |
| `draft` | `false` | Publishes the GitHub release as a draft. |
| `composer_install` | `false` | Installs the lockfile without dev packages and bundles the vendor tree. |
| `composer_app_repositories` | unset | Repository names within the caller's owner, comma- or newline-separated. Required with the App secrets; it is the token allowlist. |
| `composer_app_id`, `composer_app_private_key` | unset | A GitHub App with contents read, installed on the private dependency repos, for private Composer packages and staged dependency releases. Pass both under secrets; one without the other fails the run. |

## Cutting one

Start on a clean main:

```bash
$EDITOR info.xml composer.json CHANGELOG.md   # the version bump
ckrelease check                               # before committing, not after
git commit -am 'Release 1.3.0'
git tag -a v1.3.0 -m 'v1.3.0' && git push origin main v1.3.0
```

The tag push runs check, dist, the smoke test, and creates the GitHub release
with the zip and its .sha256 file attached.

GitHub's Latest marker:

- A pre-release tag such as v1.3.0-rc.1 is published as a pre-release, never
  Latest. Drafts are never Latest either.
- A plain tag becomes Latest only if it is the highest plain version in the
  repo, so a late release of an older line leaves Latest alone.
- A higher tag whose release never succeeded keeps Latest away from lower ones.

A tag that matches the trigger but is not SemVer, such as v1.2.3-rc..1, fails
before the build. Shapes like v1.3 or v2.2.7.1 start no run.

Locally, `ckrelease dist` builds the same archive from the same commit, so you
can inspect it before pushing. Archives that need composer_install or
[build output](#build-output-git-does-not-track) are built from a temporary
tree instead; they carry the build time and match other builds in content but
not byte for byte.

## What the archive contains

The tracked files at the tag plus declared build output, under one top-level
directory named after the extension key. The development layer is left out:

```
.github/ .docker/ .civikitchen/ .claude/ tests/ node_modules/
.gitattributes .gitignore .editorconfig civikitchen.yaml .phpunit.result.cache
.gitlab-ci.yml renovate.json mago.toml phpcs.xml(.dist)
composer-dependency-analyser.php phpstan.neon(.dist) phpstan-tests.neon(.dist)
phpstanBootstrap.php phpunit.xml(.dist) phpunit-unit.xml.dist .oxlintrc.json
playwright.config.ts/.js vitest.config.ts/.js
package-lock.json bun.lock(b) tsconfig.json
```

Secrets are never packed, whatever the policy says: .git, .env and .env.*,
.envrc, .netrc, .npmrc, .pypirc, auth.json and credentials.json. To print the
effective list for a repo:

```bash
ckconform --dist-paths
```

vendor/ and dist/ are deliberately not excluded. Either commit vendor/ or set
composer_install so the release bundles the locked production tree; with it,
package-level copies of the excluded paths are stripped too. A committed
frontend build in dist/ is the shipped artifact; an uncommitted one is
declared as build output.

Gitignored files are absent anyway. Adjust the list per repo:

```yaml
policy:
  dist:
    exclude:
      - build
      - docs/internal
    include:
      - path: tests
        reason: release fixture needed by this extension
```

`ckrelease verify` re-checks the built zip: one top-level directory named
after the key, info.xml at the released version, every declared build output,
and no excluded name as a path segment at any depth, which catches a nested
tests/ directory. The list lives here and in civikitchen.yaml, not in
export-ignore attributes
([ADR-0003](adr/0003-release-exclusions-live-in-civikitchen.md)); a repo's own
export-ignore entries still apply on top.

### Prefixed dependencies (Strauss)

PHP has one class table per process, so two extensions bundling different
versions of a package both get whichever loads first. Prefix a package other
extensions may bundle too with
[Strauss](https://github.com/BrianHenryIE/strauss), in place in vendor/:

```json
"extra": {
  "strauss": {
    "target_directory": "vendor",
    "namespace_prefix": "Civi\\Myext\\Vendor\\",
    "classmap_prefix": "Civi_Myext_Vendor_",
    "packages": ["acme/lib"]
  }
},
"scripts": {
  "prefix-namespaces": ["@php .civikitchen/strauss.php", "@composer dump-autoload"],
  "post-install-cmd": ["@prefix-namespaces"],
  "post-update-cmd": ["@prefix-namespaces"]
}
```

- Declaring extra.strauss makes `.civikitchen/strauss.php` a managed file that
  ckinit writes. It fetches the pinned, checksum-verified Strauss into the
  temp directory, so the phar never lands in the extension. Removing the
  config leaves the script; delete both together.
- Every composer install runs it, in the release, in CI and in a deploy from
  the tag, so the archive carries the prefixed tree.
- The extension's main file loads `vendor/autoload.php`.
- Packages core ships, such as Guzzle, psr/log and psr/http-message, go under
  provide with a range covering the supported core versions, never a
  wildcard. If the range does not satisfy a requirement, Composer silently
  installs its own copy.
- Strauss's alias file for the unprefixed names stays unreferenced after the
  dump-autoload, so unprefixed names fail in development as in production.
- The outer install's optimize-autoloader flag has no effect; classes resolve
  through PSR-4 and PSR-0 rules.

### Build output git does not track

Declare a frontend bundle or third-party dist that is built, not committed:

```yaml
policy:
  dist:
    build:
      tool: bun
      outputs:
        - ang/example/app.bundle.js
        - dist/vendor-js/
```

On a tag, the release sets up the Bun version package.json pins in
packageManager, runs `bun install --frozen-lockfile` and `bun run build`, and
adds exactly the listed files and directories to the archive. Nothing else
the build leaves behind ships.

The release fails when a listed output is missing after the build (each is
named), is tracked by git, or lies in the development layer or under
dist.exclude, and when package.json has no exact Bun pin or bun.lock is not
committed.

The build runs before any credential is minted on the runner. A build script
that calls Node gets the node_version input, defaulting as in extension CI.

Locally, run the two Bun commands first; `ckrelease dist` never builds. It
takes the outputs from the working tree, not from the ref, and refuses while
any is missing.

Extension CI runs the same build before the ci, mutation, compat and
playwright jobs. upgrade and schema-parity skip it (they mount the previous
release), and core-upgrade runs no suite. The CI caller needs `bun: true` or
CI fails. The GitLab pipeline has no Bun, so a release-only build works on
GitHub only.

## The smoke test

The archive is unzipped into a fresh CiviCRM in the standalone image and
enabled with cv, with no source mount, so the site sees exactly what a user
downloads. The database is policy.database, MariaDB 11.4 without it. Missing requires entries from info.xml are downloaded and enabled
first, from the registry or from a matching policy.extension_sources entry.

It catches what green CI does not: a PHP file the exclude list swallowed, a
requirement nothing serves, an upgrader that fatals on first install.

### A dependency only a credential can reach

A private-repository extension has no stable URL: the browser download
answers 404 without a session, and the REST asset id changes whenever a
release re-run replaces the asset with --clobber. So pin it by release
and asset name plus checksum:

```yaml
policy:
  extension_sources:
    - key: exampleframe
      version: '^0.1'
      release:
        repository: example-org/exampleframe
        tag: v0.1.0
        asset: exampleframe-0.1.0.zip
      sha256: <the release's own .sha256>
      reason: private repository, no registry serves it
```

The runner downloads and verifies the archive; the container gets only the
verified bytes, never the App token. List the dependency's repository in
composer_app_repositories, or the download returns 404.

Outside the release workflow, the archive comes from `CK_DEP_ARCHIVE_DIR`
([configuration](configuration.md)). In a dev stack you normally mount the
dependency instead, and no pin is consulted.

The smoke test costs a few minutes per release. If it turns flaky, turn it
off visibly in the caller with smoke_test: false.

## `ckrelease` outside CI

```
ckrelease check  [--version <v>] [--require-changelog]
ckrelease dist   [--version <v>] [--ref <ref>] [--output <dir>]
ckrelease verify <zip> [--version <v>]
ckrelease info   key|file|version|dist-name
```

It ships in the images, so `docker compose exec app ckrelease check` works in
a dev stack. It also runs from a checkout with bash, git, php and unzip, once
`scaffold/ck make tools` (or a composer install in packages/civikitchen-scenario-schema)
has fetched the YAML parser. Run any declared build first.
