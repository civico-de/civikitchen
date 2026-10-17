# Extension standards: what a modern extension looks like

This is the target state the civikitchen tooling enforces or expects: cklint
with the CiviKitchen mago baseline, ckmodernize, phpstan and the extension
template. Use it for audits and as the goal when you modernize an existing
extension. ckcreate and ckinit apply the template, which sets most of this up;
see [Creating an extension](extension-development.md#creating-an-extension).

## UI: declarative before imperative

New screens use the declarative building blocks core provides:

- Listings and reports are a managed SearchKit SavedSearch with a
  SearchDisplay, not a CRM_Core_Page or CRM_Report_Form with Smarty. Custom
  reports are deprecated in core.
- Forms are Afform/FormBuilder (`ang/*.aff.html`), not QuickForm. Custom
  handling goes through hook_civicrm_afformSubmit or APIv4 actions.
- Data endpoints are APIv4 actions, not page callbacks.

The phpat rule testNoLegacyUiBases enforces this in every phpstan run. It
resolves the full class ancestry, so an extension's own intermediate base
class or a core report subclass is caught too. Audits should still grep for
.tpl templates and page routes.

Raw callback endpoints, iframe hosts, third-party bases that mandate
QuickForm, and grandfathered screens get an ignoreErrors entry for
`phpat.testNoLegacyUiBases` in phpstan.neon.dist, with a reason.

Write the permission key in an afform's .aff.json as a list,
`["access foo"]`, as all of core's afforms do. A plain string is tolerated,
but core splits it on commas, so a permission name containing a comma becomes
two.

Theming uses Riverlea's --crm-* custom properties under the names core
declares in its variables file and in each stream's dark stylesheet. A wrong
name renders the fallback of `var(--crm-x, #hex)` in every theme, so the
ckconform check riverlea-custom-property fails on any --crm-* name that
neither core nor the repository declares. A name only some streams declare
passes.

## Code

### APIv3 is banned

Use APIv4 only, through civicrm_api4() or the object-oriented builders. The
ban is enforced by phpstan through
`/opt/civikitchen/toolbelt/phpstan-config/civicrm-disallowed.neon`, which the
template's phpstan.neon.dist includes. The same file bans removed or
deprecated core helpers: CRM_Utils_Array::value(), CRM_Core_Error::fatal()
and the CRM_Core_Error::debug_*() family. Because phpstan resolves types, an
indirect `$class::value()` is caught too. An exception is an allowIn or
allowInMethods entry in the repo's own phpstan.neon.dist, with the reason
next to it.

ckconform also rejects CRM.api3 in JavaScript and Smarty. Annotate a genuine
exception there with `ck-allow-api3 -- <reason>`.

An extension's own `civicrm_api3_<entity>_<action>()` export raises the
ckconform warning api3-surface. A scheduled job does not need one: core
forces version 3 only for key=value job parameters, so a managed job with
`'parameters' => '{"version":4,"checkPermissions":false}'` calls the APIv4
action directly. Keep an APIv3 export only for a documented external
contract, and mark it with `ckconform-ignore api3-surface -- <reason>`.

### APIv4 entities must exist in the supported core

Entities resolve at runtime, so a reference to an entity your oldest
supported core lacks passes phpstan and the tests and then fatals in
production. Check the entity's @since against `<compatibility><ver>`. Entities
from bundled core extensions such as civi_mail also put that extension into
`<requires>`.

ckconform verifies that each referenced entity exists in the core it runs
against. An entity supplied by a required third-party extension is declared
under `policy.known_api4_entities` in civikitchen.yaml, with its entities and
a reason naming the provider. Unused entries and entries without a provider
fail.

### The strings in an APIv4 call are checked

Entity, action and field names are string literals, so a typo in a select
otherwise survives the whole pipeline and returns wrong data on a live site.
The phpstan rules check civicrm_api4() and the fluent form against a catalog
generated from the pinned core, and report:

- `ck.api4.unknownEntity`, only for a near-miss of a real entity, since other
  extensions' entities are not in the catalog
- `ck.api4.unknownAction`, compared case-insensitively and including actions
  inherited from base classes and traits
- `ck.api4.unknownField` in select, where, orderBy, groupBy and values, for
  the record actions get, create, update, save, delete and replace

Whatever the source cannot settle is skipped silently: non-literal names,
dotted names (joins, custom fields, price-set fields), SQL expressions and
their aliases, SearchKit segment fields, camelCase BAO pass-through params,
and entities whose fields are not table-backed (Setting, Afform, ECK,
SearchKit and custom entities). A finding is therefore worth reading rather
than baselining. A builder held in a variable is judged through its type;
only its orderBy and groupBy are skipped.

The right-hand side of an implicit join is checked too. Every field with an
entity reference is a joinable of the same name, plus the primary and billing
links on Contact. In `addSelect('address_primary.street_address')` the right
side is checked against Address's fields, so `address_primary.no_such_field`
is reported. The join check stays silent on an explicit join alias, a
custom-group name, another extension's entity, a rebound name, a multi-level
path, a builder held in a variable, and a target without table-backed fields.
Writes are out of scope, because a join is not a write target.

ckeslint applies the same contract to JavaScript with its
civikitchen/api4-contract rule
([ADR-0008](adr/0008-the-apiv4-contract-in-javascript-is-a-lint-rule.md)).
It reads the literal forms of CRM.api4 and crmApi4, including their batch
forms, and knows the repo's own entities and actions plus those of checked-out
siblings. It runs in the baseline config only; a repo with its own
.oxlintrc.json does not get it.

### Parameters of custom APIv4 actions

A protected property on a custom APIv4 action is an API parameter. If it is
typed, non-nullable and has no default, a caller who omits it gets PHP's
"must not be accessed before initialization" from inside the kernel instead
of a validation error. The rule `ck.api4.uninitializedActionParam` reports
this.

@required does not rescue that form, because the kernel reads every
parameter through its getter before it checks which are required. The form
that works is core's own, and the rule's message points at it: an untyped
property, no default, a @var docblock for the type, and @required. A nullable
type does not help; any typed property without a default starts
uninitialized. Properties from traits count too.

The reverse, @required next to a default other than null, empty string,
empty array or FALSE, is reported as
`ck.api4.requiredActionParamWithDefault`: it promises a validation that can
never fire.

### Tests must not run DDL inside a transaction

A transactional test runs inside a transaction, and MySQL commits implicitly
on DDL. Creating or saving a CustomField or CustomGroup, installing or
enabling an extension, or running a literal CREATE, ALTER, DROP, TRUNCATE or
RENAME in setUp() or a test method ends the transaction, and the rows stay
behind for the next run. The rules `ck.test.customFieldInTransaction`,
`ck.test.extensionInTransaction` and `ck.test.ddlInTransaction` report it,
following the test's own helper methods. The fix is setUpHeadless(), never a
suppression. The rules only fire when the test code is analysed, which the
next section covers.

### Analysing the test suite

Analysing tests is opt-in through a second config,
`scaffold/template/extension/phpstan-tests.neon.dist`, which includes the main
one, sets its paths to tests/phpunit and keeps level 10. When the file exists,
CI runs `phpstan analyse -c phpstan-tests.neon.dist` as its own gate. Adopt it
once the run is clean, by fixing the test code rather than adding a baseline;
until then the test-only rules above are inert. ckinit seeds it into new
extensions and does not count it missing as drift.

### Database queries

A catch around a direct database call (CRM_Core_DAO::executeQuery(),
singleValueQuery() or a DAO's find()) must rethrow or log at error level. An
empty catch, or one that logs at debug or info, turns a failed query into a
value the caller cannot tell from real data. The rule is `ck.db.silentCatch`.
APIv4 calls are out of scope.

A table name in raw SQL is checked against the schema. The rule
`ck.sql.unknownTable` reads the literal SQL passed to executeQuery(),
singleValueQuery(), executeUnbufferedQuery(), a DAO's query(), and from() and
join() on CRM_Utils_SQL_Select, and reports a table the pinned core does not
have. Only names with the civicrm_ prefix are judged. These count as known:

- custom-value and temp tables (`civicrm_value_*`, `civicrm_tmp_*`)
- tables declared in the repo's own schema/, xml/schema/ or sql/
- anything listed under `parameters.civikitchen.sqlKnownTables` in
  phpstan.neon

The template seeds that stanza empty; an existing repo adds the key when it
needs it. Non-literal SQL (interpolated or placeholder table names, statements
built in a variable) is skipped. Other extensions' civicrm_-prefixed tables
are therefore the one thing to declare rather than baseline.

### Routes that change data on GET

A route a browser can GET must not change data. Prefetchers, link scanners and
mail-security proxies issue GETs nobody typed, and an image tag on a hostile
page fires one with the victim's session. The rule `ck.route.mutationOnGet`
checks the page_callback methods declared in xml/Menu/*.xml and run() of a
CRM_Core_Page subclass, following writes two hops through the class's own
methods. A write is an APIv4 create, save, update, delete or replace
(including custom actions built on them), an APIv3 write, a DAO or BAO
save(), delete() or create(), or a literal INSERT, UPDATE or DELETE. A
handler that answers non-POST requests with a 405 stays silent.

The rule parses xml/Menu/*.xml itself, so a new route is covered at once. A
generated .ck-routes.json is honoured as an override for routes the parser
cannot find; generate it with
`php /opt/civikitchen/toolbelt/phpstan/tools/gen-route-catalog.php .`.

A GET that writes on purpose, such as a redirect flow linked from a menu with
no qfKey available that refuses cross-site requests, gets a narrow
`@phpstan-ignore ck.route.mutationOnGet` naming the mitigation, never a
baseline entry.

### Conventions

- Use E::ts(), never bare ts() (civikitchen/use-extension-ts).
- Use the standard mixins for managed entities, menus, settings and Angular
  instead of bespoke hooks (civikitchen/use-mixins-for-standard-hooks).
- Ship configuration as managed entities in `managed/*.mgd.php`, not as
  install-time code.
- A managed Job on an entity the repo ships only under Civi/Api4/ sets
  version=4 in its parameters, or it fails on every cron pass. The ckconform
  check is managed-job.
- Code is phpstan level 10 clean with the template's config, and files are at
  most 1000 lines (civikitchen/max-file-length).
- Every file has `declare(strict_types = 1)`, checked by mago's strict-types
  rule everywhere except the test bootstrap. `cklint --fix` inserts it, and
  ckfmt sets its spacing.

### One PHP floor

The PHP floor is stated once, as require.php in composer.json.
`<php_compatibility>` in info.xml and phpstan's phpVersion must agree with
it, which the ckconform check php-version-coherence verifies. Without
phpVersion, phpstan analyses with the image's PHP, and syntax only a newer PHP
has passes CI and fatals on the older site.

ckcompat adds what phpstan does not know, using mago against that floor:

- `mago lint --semantics` parses the code as the floor version, so newer
  syntax such as property hooks is a hard error. The image ships a single PHP,
  so `php -l` cannot catch this.
- `mago analyze` reports functions, classes and methods the floor lacks.
- Two CiviKitchen rules cover dynamic class constant fetch below 8.3 and
  `"${var}"` interpolation from 8.2.

Functions core polyfills count as available, and so does a newer symbol in a
file that guards it with function_exists(), class_exists() or
method_exists(). Deprecations come from mago's rules and PHP's own deprecated
symbols, up to the newest PHP. ckmodernize reads the same floor, so rector
never rewrites past it.

### `@ck-legacy` for code that must touch a deprecated API

phpstan reports every call into a deprecated symbol outside deprecated scopes.
The test of a deprecated method or a fixture feeding a shim is living code
that should not be marked @deprecated. Mark such a class, trait or method with
@ck-legacy instead, and nothing inside it is reported. Use the exact tag, as
narrowly as possible, and delete it with the shim or test it annotates. Code
that goes away together with the deprecated path, private helpers included,
gets @deprecated itself. Production code that calls a deprecated API still has
to migrate.

### Call-site arguments

Do not pad calls with positional arguments that repeat a default; name the
arguments after them instead. ckmodernize rewrites
`retrieve('delete', 'String', NULL, FALSE, NULL, 'POST')` to
`retrieve('delete', 'String', method: 'POST')`.

A bare TRUE or FALSE at a call site is reported by
civikitchen/name-boolean-arguments. The baseline leaves the rule off, since
only a human knows which parameter is meant; a repo arms it once its call
sites are named.

## Tooling every repo must have

### cklint and the mago baseline

cklint runs `php -l` on changed files and mago lint with the bundled baseline.
The baseline covers bug patterns such as loose comparison, empty catch blocks,
error suppression, complexity ceilings, aliased functions and unused imports,
and requires declared visibility and braces on every control structure.
Every finding fails the gate.

The baseline is mago's defaults minus what another gate owns (ckfmt, Rector,
cktaint, the disallowed-calls bans) and minus CiviCRM's idiom of isset() and
empty() on API arrays. It lints at the floor from composer.json. ckconform
fails on a leftover phpcs.xml or phpcs.xml.dist. A single deliberate deviation
is a `// @mago-expect lint:<rule>` line in the code. A committed mago.toml
replaces the baseline unless it starts with
`extends = ["/opt/civikitchen/toolbelt/mago/mago.toml"]`.

The baseline also runs CiviKitchen's own rules, with codes of the form
`civikitchen/<rule>`: use-extension-ts, use-mixins-for-standard-hooks,
max-file-length, no-unsafe-unserialize, no-tautological-assertion,
no-generic-var-on-action-param, no-required-on-external-action,
curl-ssl-verify, remote-address (outside tests/), case-fall-through, and
name-boolean-arguments (off by default). A repo configures them with worker
options in a mago.toml that extends the baseline:

```toml
extends = ["/opt/civikitchen/toolbelt/mago/mago.toml"]

[extension-hosts.civikitchen]
command = ["php", "/opt/civikitchen/toolbelt/mago-ext/worker.php",
  "--exclude=civikitchen/max-file-length:tests/**",
  "--external-actions=Civi\\Api4\\Action\\Widget\\Intake"]
```

- `--disable=<code>,…` turns rules off.
- `--exclude=<code>:<glob>,…` scopes them to repo-relative paths.
- `--max-lines=<n>` moves the file-length ceiling.
- `--ignore-calls=<name>,…` replaces the calls name-boolean-arguments leaves
  alone.
- `--external-actions` arms no-required-on-external-action. @required is
  forbidden on those APIv4 actions, because an empty parameter there has to
  get a verdict, not an exception.

Repeating the table replaces the baseline's command, so leaving out its
--disable arms name-boolean-arguments.

### Tests and phpstan config

Each repo has a phpunit.xml.dist and headless tests as in the template
(`scaffold/template/extension/`), including the TEST_DB_DSN bootstrap guard.
Its phpstan.neon.dist is at level 10, with no baseline, phpVersion at the
floor, and the include line for the CiviCRM ban list.

### Architecture rules

Architecture rules belong in phpstan, not in review. phpat runs inside the
normal phpstan analyse, and the fleet-wide rules ship in the image:

- The extension boundary. An extension may depend on core, on itself, and on
  every extension its info.xml declares in `<requires>`. Any other CRM_ or
  Civi\ symbol is another extension's internals, and the supported way across
  is APIv4.
- The legacy-UI-base ban.
- The APIv4 contract rules above.

They derive the extension from its own info.xml and classloader layout, so no
repo configures anything. A repo can add its own phpat.test-tagged classes for
repo-specific boundaries.

A declared requirement counts as own code for the boundary rule only when its
checkout is found: in a sibling directory `../<key>`, in the CI sibling mount
`.civikitchen-siblings/<key>` (see sibling_repo), or in the image's ext
directory (CK_EXT_DIR, default /var/www/html/ext). Checkouts are matched by
the key in their info.xml, not the directory name. Core-shipped extensions
need no checkout. A required extension that is not found is reported by name;
mount it rather than adding an ignoreErrors entry, which is for couplings to
extensions the repo deliberately does not require.

### ckdeps

ckdeps checks composer.json against what the code uses and reports shadow,
unused and dev-in-prod dependencies. The bundled config knows that CiviCRM is
not a composer dependency, down to the Guzzle, PSR and Symfony packages core
ships, so an extension typing against those must not require a second copy. A
repo's own composer-dependency-analyser.php replaces the bundled config and
has to restate those patterns.

### cksmarty

cksmarty compiles every .tpl in the repo with the real CRM_Core_Smarty in the
booted CI stack, plus the bodies of the managed MessageTemplates the extension
installed, as a step in the normal ci job. Whether a template compiles
depends on core's Smarty version and plugins, which no static check knows. A
repo with no .tpl passes with a log line.

A managed MessageTemplate the extension renders itself, without Smarty, is
declared by its managed name with a reason:

```yaml
policy:
  smarty_skip_templates:
    - template: example_receipt
      reason: Rendered by strtr() over {placeholder} tokens, not by Smarty
```

### ckeslint

ckeslint lints JS and TS with a toolchain pinned in the image; see
[Frontend](#frontend-js-dependencies-js-tests-and-browser-tests). It is an
unconditional step in ci, and extensions without JS pass with a log line.

### ckfmt

ckfmt formats PHP with [mago](https://mago.carthage.software/) and JS and TS
with [oxfmt](https://oxc.rs/). `ckfmt --check` is a hard gate in ci. The mago
baseline owns layout (the drupal preset plus declare spacing) and the linter
carries no layout rule, so a red check means "run ckfmt and commit", never a
style debate.

Vendored trees, minified bundles and civix- or DAO-generated PHP are
excluded, so civix upgrade and the gate never fight. A committed mago.toml or
.oxfmtrc.* wins over the baseline for its half.

ckfmt and ckeslint read the git index, so a new file is invisible to both
until you `git add` it.

### Rule packs come from the image

The mago baseline, CiviKitchen's rules and the phpstan extensions
(deprecation rules, disallowed-calls, strict-rules) ship in the image from a
committed lockfile. An extension carries no dev dependencies for them.
Third-party rules are cherry-picked, never a whole foreign standard. A pack
that would turn the fleet red at once, such as phpstan-strict-rules, is
installed but not auto-registered; a repo opts in with one includes line.

### CI and releases

CI follows `scaffold/template/extension/.github/workflows/ci.yml`, a thin
caller of the reusable extension-ci.yml in civikitchen. Its in-container gates
are `ck ci`, and `ck ci --help` lists them; a developer runs the same command
in the local stack.
[Running the CI gates locally](extension-development.md#running-the-ci-gates-locally)
names what runs on the runner instead. A stack whose image predates ck ci
fails with an error saying so. The caller pins @v1 and the CI stack the
matching :v1 image ([releases.md](releases.md)).

Releases go through the shared extension-release.yml ([Releasing an
extension](extension-releases.md)); the check release-workflow fails when no
workflow calls it, unless `policy.release.mode: none` is set with a reason.

### Versions and tags

The `<version>` in info.xml is X.Y.Z or `X.Y.Z-<pre-release>`: SemVer 2.0
without build metadata or leading zeros. The ckconform check version-format
fails on anything else, such as 2.2.7.1, 1.0 or 1.3.0+build.5, because the
release workflow accepts no other tag.

Every version the repo has moved past carries its `v<version>` tag, checked by
release-tags. A re-scoped number goes under `policy.untagged_versions`; see
[Releasing an extension](extension-releases.md#a-version-that-will-never-be-released).
On a shallow clone the check reports itself as unevaluated, which is why the
shared CI fetches full history and tags.

release-tag-coherence fails when the version is ahead of the newest tag (the
bump was committed but never tagged) or below the highest v* tag reachable
from HEAD, such as info.xml back at 0.1.0 after v1.0.0 was tagged. A release
from there would sort under what is already published, so release a version
above that tag. Ordering follows SemVer precedence, and tags that are not
SemVer are ignored.

### Committed files

Each repo has a composer.json with the extension metadata. Do not commit
node_modules, vendor or build artifacts; a frontend build commits only dist/,
or declares the build under `policy.dist.build` so the release zip carries it.
.gitignore covers every artifact the repo can produce, such as the phpunit
result cache, vendor/, node_modules/ and *.tsbuildinfo.

Lockfiles are committed and never ignored. Every tracked package.json needs
its package-lock.json, bun.lock or equivalent, and a composer.json with real
dependencies needs its composer.lock. CI installs with the frozen form, so the
shipped build is reproducible.

`<requires>` in info.xml names every extension actually used, such as
SearchKit, Afform or CiviRules. A missing entry only surfaces on a fresh site.

### Dev stack and workflow permissions

The dev stack is `.docker/docker-compose.yml` on a civikitchen image, and
every image in it is pinned, since a moved floating tag can stop CI with no
diff to point at. The exception is the project's own image, referenced through
`${CIVIKITCHEN_IMAGE:-…}` and meant to track its tag.

Every workflow declares a permissions block, since the default token is
write-all on older repos. Grant write permissions per job where a step needs
them.

### CI on GitLab

A repository hosted on GitLab declares `ci: gitlab` under `policy:` in
civikitchen.yaml. ckinit then writes a managed .gitlab-ci.yml instead of the
GitHub callers and renovate.json, including civikitchen's GitLab template at
v1:

```yaml
include:
  - remote: https://raw.githubusercontent.com/civico-de/civikitchen/v1/ci/gitlab/extension-ci.yml
```

The jobs run inside the civikitchen image with the database as a GitLab
service, so a plain docker executor is enough. ckboot provisions the site with
the repository's init hooks and dev profile, and the gates run as the web
user:

- civikitchen-ci runs `ck ci`, then cklifecycle.
- civikitchen-e2e runs `npm run test:e2e` when the repository has a
  playwright.config.ts or .js. The site has the admin/admin login of the
  GitHub job. CK_IN_IMAGE=1 is set, so the managed tests/e2e/lib.sh runs cv,
  `app` and `sql` directly instead of through compose. Helpers in
  tests/e2e/lib.local.sh that build on them need no check of their own.

Both jobs run in branch and merge request pipelines. A project that wants
only one adds GitLab's workflow rules below the managed block, where these
variables also override the defaults:

| Variable | Default | Effect |
|---|---|---|
| `CK_IMAGE` | `ghcr.io/civico-de/civikitchen:v1` | the job image |
| `CK_DB_IMAGE` | `mariadb:11.4` | keep it equal to the database of `.docker/docker-compose.ci.yml` |
| `CK_LIFECYCLE` | `"1"` | `"0"` skips cklifecycle |
| `CK_NPM_CI` | `"0"` | `"1"` installs node_modules before the gates |
| `CK_JS_TESTS` | `"0"` | `"1"` also runs npm test |

Dependencies install with `npm ci --ignore-scripts`; the image has no Bun.
The web user writes into the checkout through the docker executor's default
umask, so leave `FF_DISABLE_UMASK_FOR_DOCKER_EXECUTOR` off. GitLab has no
shared release pipeline, so such a repository declares release mode none with
its reason. The mode covers one extension at the repository root, and ckinit
refuses it anywhere else.

### Several extensions in one repository

In this layout the repository root has no info.xml and its direct
subdirectories are extensions
([layout](extension-development.md#several-extensions-in-one-repository)).
ckconform detects it from the filesystem; nothing is declared.

The workflow-reading checks (ci-coverage, npm-install,
playwright-diagnostics, config-without-runner and the lint step of
ci-workflow) judge the job that runs this extension: the caller job whose
`with.working_directory` names its directory, or the steps job whose
`defaults.run.working-directory` does. ci-workflow fails when no job names the
directory at all. floating-tag and workflow-permissions still judge the whole
file.

monorepo-requires-mounted covers a `<requires>` key that is another extension
of the same repository. It must be bind-mounted at `/var/www/html/ext/<key>`
(the key, not the `<file>`) into the app service of the CI compose file: the
compose_file the scoped job passes, or `.docker/docker-compose.ci.yml`. Mounts
in the dev compose file or another service do not count. A compose_file given
as an expression, or a mount source with a variable, is reported as not
evaluated. The order of the volume lines does not matter.

monorepo-version-lockstep requires every info.xml to carry the same
`<version>` and `<releaseDate>`, since one vX.Y.Z tag releases all of them.
The release checks read each extension's own directory, so a commit that
touches only a neighbour is not an unreleased change.

### Third-party source a repo carries verbatim

Every ck* file list skips vendor/ and node_modules/. Someone else's code kept
byte-identical elsewhere in the tree, such as an upstream service vendored
under .docker/, is declared with a reason in civikitchen.yaml:

```yaml
policy:
  vendored_paths:
    - path: .docker/civiproxy/proxy
      reason: Unmodified SYSTOPIA CiviProxy 1.0.0-beta
```

cklint, ckfmt and ckeslint then skip it. Use one entry per path; each prefix
is anchored at the repo root. It is not an escape hatch for your own code, and
phpstan keeps its own paths.

### Formatter and linter

ckfmt owns layout and cklint carries no layout rule, so formatted code is
always lint-clean; the image tests assert this on the hardest layouts.

A class-level @mago-expect goes above the docblock, separated by a blank
line, or inside it as a ` * @mago-expect lint:<rule>` line. Never put it as a
// line between docblock and class declaration, where formatter and pragma
scanner disagree about what it is attached to. On a method, that // line is
the form in use.

Turning a rule off retires its suppressions: a leftover @mago-expect for a
disabled rule is reported as unfulfilled-expect and fails the gate, so remove
them in the same change. Run phpstan once after `cklint --fix`, because one
safe fix can take a @var annotation with it.

## Known vulnerabilities and secrets

The extension CI scans what a repo declares and the image pipeline what it
runs on; neither replaces the other.

### Dependencies: `osv-scanner` in `extension-ci.yml`

The dependency scan runs as its own job, without booting a stack. It reads
the committed composer.lock, package-lock.json and bun.lock in the extension
directory, not recursively, so dependencies' own lockfiles are ignored. A
repo with none of the three passes and says so in the log.

There is no input to turn the scan off, so an advisory published overnight can
turn a repo red without a commit. The escape hatch is per finding: an
osv-scanner.toml beside the lockfiles, which the scanner picks up on its own.
It excuses one advisory or a whole package and requires a reason:

```toml
[[IgnoredVulns]]
id = "GHSA-xxxx-xxxx-xxxx"
ignoreUntil = 2026-12-31T00:00:00Z
reason = "only reachable through the CLI entry point, which this extension never calls"

[[PackageOverrides]]
name = "vendor/package"
version = "1.2.3"
ecosystem = "Packagist"
ignore = true
reason = "transitive dev-only dependency of the test harness"
```

Past ignoreUntil the finding is back, and an entry that no longer matches is
reported as unused.

### Repository secrets: `trivy` in `extension-ci.yml`

A separate mandatory job scans the checked-out source with Trivy's secret
scanner. Tests and fixtures stay in scope, since a credential committed as
test data still needs rotation. vendor/, node_modules/ and the workflow's
helper checkout are excluded.

A false positive is scoped in .trivyignore.yaml in the extension directory, by
rule and path, with a statement and an enforced expiry. No switch turns the
scanner off for a repository.

```yaml
secrets:
  - id: generic-api-key
    paths:
      - tests/fixtures/example-token.txt
    statement: Synthetic non-routable fixture generated from the public example.
    expired_at: 2026-11-01
```

It scans the current checkout after the push, not Git history.

### Images: `trivy` in `build-dev-images.yml`

The image scan runs on the pushed candidate digest, which is exactly what the
promote jobs publish. The gate is
`--ignore-unfixed --severity HIGH,CRITICAL --exit-code 1` with only the vuln
scanner; unfixed findings do not block, because no rebuild here can clear
them. A second, non-blocking run writes every finding into the job summary for
triage.

The scan does not hold the release, since a base-image CVE is fixed by an
upstream rebuild. On the daily cron it feeds the failure notification, so a
finding on an unchanged image still opens an issue.

Central exceptions go in trivyignore.yaml in the civikitchen repository root,
passed with --ignorefile. Trivy enforces expired_at, and paths scopes an entry
to the tree the vulnerable copy lives in:

```yaml
vulnerabilities:
  - id: CVE-0000-00000
    paths:
      - "home/buildkit/buildkit/**"
    statement: <what makes this finding not apply to these images>
    expired_at: 2026-11-01
```

The file excuses only upstream trees the images install and do not own, such
as core's bundled frontend assets and the buildkit clone's own dependencies.
Anything this repo can move is fixed instead: npm is upgraded in
install-dev-tools.sh (NPM_VERSION), and the oxlint and oxfmt lockfiles must
stay clean.

### Secrets in Git history

A deploy key or token can live forever in a deleted blob. Run a history-wide
secret scan over each private repo, with `gitleaks detect`, `trufflehog git`
or GitHub's secret scanning, and switch on GitHub Push Protection
organisation-wide so the next one is refused at push time.

## Tests and coverage

Every extension with PHP source needs tests/phpunit. A config-only extension
may opt out with `policy.tests` set to mode optional and a mandatory reason.
The opt-out holds even when the repo carries the phpunit config civix
scaffolds; ckcoverage then has nothing to measure.

phpunit.xml.dist declares a `<coverage>` section scoped to real extension
code, excluding the civix shim and DAO boilerplate. Without it, coverage
measures nothing while the gate looks green.

### Runtime deprecations

Runtime deprecations are test failures. Core announces them with
E_USER_DEPRECATED, which PHPUnit ignores unless it is in the error mask. The
template has both settings that make it a red build:

- `convertDeprecationsToExceptions="true"` in phpunit.xml.dist
- `error_reporting(E_ALL)` in `tests/phpunit/bootstrap.php`

The ckconform check deprecation-gate warns when a repo drops either.

### Transactions and headless builders

A transactional test that lost its transaction is a failure. The phpstan rules
under [Code](#code) catch the DDL they can see, and ckphpunit catches the rest
at runtime: it writes a marker inside the transaction and looks for it over a
second connection after the rollback. A surviving marker was committed. The
fix is the same: move schema work into setUpHeadless(), which runs before the
transaction opens.

The test listener discards the return value of setUpHeadless(), so
`return ck_headless();` builds an environment that never runs. In a
single-extension repo the suite still stays green, because provisioning
already enabled the extension. Call ->apply() on every chain, or later on the
variable holding the builder:

```php
return ck_headless()->sqlFile(__DIR__ . '/fixtures.sql')->apply();
```

The ckconform check is headless-builder-applied.

Give the whole suite one environment. Civi\Test keeps the test database while
the step list matches the previous test's, so a class with a different chain
reinstalls it, and the next class reinstalls it back. Put the shared chain in
one helper that every class calls, and keep a different chain only for a test
that needs it, such as one without an optional extension.

### Coverage

CI runs the suite with coverage through ckcoverage, or at minimum
`phpunit --coverage-text`. ckcoverage runs through ckphpunit, so the
transaction check above comes with it.

ckcoverage fails when the run executed no test or skipped every test, which
phpunit itself exits 0 on; only the tests opt-out allows that. It also fails
below the line-coverage floor in `policy.coverage.minimum`.
`minimum: 0` is always met, which differs from an absent key. Measure first,
set the floor to what you have, then ratchet it up. Never lower a floor to
turn a build green.

### Mutation testing

A covered line can be executed by a test that asserts nothing about it.
ckmutate, built on infection, rewrites the code under the suite and reports
the mutants no test killed.

It reads `policy.mutation.minimum_msi`, and optionally
`policy.mutation.minimum_covered_msi`; without the first it is a no-op. Adopt
it like coverage: measure, set the floor, ratchet. Switch it on in the
scheduled compat.yml caller with mutation: true, never in the push run, where
it is slow and its score moves with behaviour-neutral refactors. Scope it with
`policy.mutation.paths` to the domain logic. Without that key it mutates only
lines changed against CK_MUTATE_BASE (default origin/main), which suits a
branch but is empty on main.

## Repository policy: `civikitchen.yaml`

The licence declarations in info.xml, composer.json and every package.json
must agree. Pin the expected values in an optional civikitchen.yaml in the
extension root, and ckconform enforces them.

```yaml
version: 1
policy:
  license: Proprietary
  npm_license: UNLICENSED
  copyright: Example Ltd
  template_custom:
    paths:
      - phpstan.neon.dist
    reason: Deliberate template deviation
  renovate_preset: github>example/renovate
  extension_sources:
    - key: org.example.dep
      version: ^1.2
      url: https://example.org/dep-1.2.0.zip
      sha256: 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
      reason: Dependency is not served by the registry
```

In extension_sources, version takes a Composer constraint, which the
info.xml version in the downloaded archive must satisfy; the SHA-256 pins the
exact tested bytes. A dependency in a private repository names a release
(repository, tag and asset) instead of a url; see
[A dependency only a credential can reach](extension-releases.md#a-dependency-only-a-credential-can-reach).
An entry carries exactly one of the two. The archive has one root directory;
a symlink in it must point to a file in the same archive and is installed as a
copy of that file, any other symlink fails the install.

A repo targeting one CiviCRM minor names it as `civicrm: "6.17"`. Quote it: unquoted YAML reads 6.10 as 6.1.
extension-ci.yml and the release smoke test then run on that minor's
standalone image, and passing their image input as well fails the run. The
minor must be on the supported list in
[images.md](images.md#tags-and-versions). GitLab picks its job image before
the repository is read, so there CK_IMAGE still names it.

Values shared across an organisation's repos can sit in one file of the same
format, named by `CK_DEFAULT_CONFIG`; a repo's own file overrides it per key.
In CI, the policy_defaults input of extension-ci.yml names the owner/repo
whose root civikitchen.yaml is that file, or set it once as the
CK_POLICY_DEFAULTS Actions variable. ckinit reads it too, for
renovate_preset.

SPDX disjunctive licensing, such as `["MIT", "GPL-2.0"]`, is allowed in both
manifests and satisfies the policy when the expected licence is a member.
ckconform also fails when the Licensing URL civix scaffolds into info.xml
disagrees with `<license>`, since readers trust the link. A closed-source
package also sets "private": true in package.json, which is what makes npm
publish refuse.

## Taint analysis: `cktaint`

cktaint asks whether request input reaches a dangerous sink unescaped. It runs
Psalm purely as a taint engine, ships in the image, needs no per-repo config,
and is one of the ck ci gates.

The gate blocks on TaintedSql, TaintedShell, TaintedInclude,
TaintedUnserialize and TaintedSSRF, where a true positive is an outright
vulnerability. The noisier classes (file paths, headers, cookies, callables,
eval, LDAP, secrets) are info level: shown with --show-info, never part of the
exit code. A repo's own psalm.xml or psalm.xml.dist replaces the bundled
config.

Run `cktaint`, or `cktaint CRM/Foo.php` for one file or directory.
`cktaint --baseline` accepts today's findings, so only new ones show.

### What it finds

Psalm cannot see CiviCRM core, so CiviKitchen supplies a deliberately small
set of stubs ([ADR-0011](adr/0011-checks-report-only-what-they-are-sure-of.md)),
verified against core:

- Sources: everything readable from a PSR-7 request in a route handler (query,
  body, cookies, server params, uploads, attributes, headers, URI parts, the
  body stream, an upload's client filename and media type), and
  CRM_Utils_Request's retrieve, retrieveValue, exportValues and
  retrieveComponent. Psalm covers the superglobals natively.
- SQL sinks: the query argument of CRM_Core_DAO's executeQuery,
  executeUnbufferedQuery, singleValueQuery and composeQuery,
  CRM_Utils_File::runSqlQuery, and every raw fragment of CRM_Utils_SQL_Select.
- Path, header and SSRF sinks: CRM_Utils_File's file helpers, an upload's
  moveTo, CRM_Utils_System's redirect, setHttpHeader and download, and the URI
  argument of CRM_Utils_HttpClient and Guzzle's request methods.
- Escapes: CRM_Utils_Type::escape and CRM_Core_DAO::escapeString (and their
  plural forms) for SQL only, so a value escaped for the database is still
  tainted at a shell or path sink. CRM_Utils_File's makeFileName functions for
  paths, CRM_Utils_String::purifyHTML for HTML, and CRM_Utils_String::munge for
  all of them.

PHP's own functions, such as exec, include, unserialize, header and
file_get_contents, with escapeshellarg and htmlspecialchars as escapes, are
Psalm's own. Some modelling decisions decide what you see:

- The params array of executeQuery is not a sink: its placeholders are
  validated and escaped, and it is the path this standard asks for. The same
  holds for the interpolation arguments of the SQL builder.
- CRM_Utils_Type::validate() is not an escape, because for string types it
  returns the value unchanged.
- CRM_Utils_String::stripPathChars() is not an escape, because it leaves
  `/`, `.` and `..` alone.
- The request method is not a source; it is compared, never concatenated.
- Guzzle's options are not an SSRF sink. Only the URI is.
- Hooks, APIv4 parameters and Smarty assignments are not sources, because
  everything would be tainted.

### What it does not see

Psalm does not see a value that leaves PHP and comes back through a hook, the
API kernel or a Smarty template. It does not check HTML or XSS: TaintedHtml is
off, because CiviCRM escapes output in templates Psalm never analyses.

It does not see values leaving a function through a by-reference parameter.
In practice that is `parse_str($request->getUri()->getQuery(), $query)`, after
which the query array is clean as far as Psalm knows. json_decode() does
propagate taint.

It reports one flow per source and sink pair, so a fix can reveal the next
call site in the following run. A clean run after a fix is not proof there was
only one.

False positives occur. A retrieve() with a validating type such as Positive is
safe, but the type is a runtime string, so Psalm taints it anyway. Expect
findings of exactly this shape.

### Handling a finding

1. Fix the flow. This is almost always right and small: parameterize the query
   with placeholders and params, escape the value with
   `CRM_Utils_Type::escape($value, 'String')`, or validate the path.
2. Or record a justified exception: `@psalm-suppress TaintedSql` on the line
   with a comment saying why it is safe, or `cktaint --baseline` to write
   psalm-baseline.xml. A baseline entry states that the finding was read and
   accepted.

Never fix a finding by weakening the stubs. For every modelled source and sink
the image tests hold a fixture that must be reported and one with the escape
in between that must stay silent, so a weakened stub shows up as a red test.

## Frontend: JS dependencies, JS tests and browser tests

For an extension with a package.json, the default CI run does not install the
tree, run the JS unit suite, or drive the UI in a browser. Six opt-in inputs
on extension-ci.yml add these, all off by default:

| Input | What it adds |
|---|---|
| `npm_ci` | `npm ci --ignore-scripts` on the runner before the stack boots. The stack bind-mounts the checkout, so node_modules/ is there for PHP code that reads an asset bundle. |
| `js_tests` | Runs npm test. Implies npm_ci. Fails when package.json has no test script. |
| `bun` | Uses Bun instead of npm for all of the above. Implies the install. Needs a committed bun.lock and a packageManager entry pinning the Bun version, which is the Bun the jobs install. |
| `playwright` | Own job: boots the stack on a free host port with an admin/admin demo user and runs npm run test:e2e from the runner, on image. The fixtures' browser runs in its own container matching the checkout's playwright-core, with localhost tunnelled to the runner. A browser the suite launches itself stays on the runner. Report and traces are uploaded on failure. |
| `matrix_playwright` | Runs that job on every matrix_images entry as well. Needs playwright and matrix_images. |
| `node_version` | Node for all of the above. Default '24', the major the dev images ship. Still applies under bun. |

Playwright keeps traces on failure, and traces can contain request data,
tokens, cookies and authenticated state. A manual live-provider suite with
reusable credentials sets trace off and puts a file suppression with a reason
in that one config:

```ts
// ckconform-ignore-file playwright-diagnostics -- live-provider credentials must not persist in traces
```

### Linting JavaScript

Linting needs none of these inputs. ckeslint runs in the ci job on every push
with a toolchain pinned in the image: no Node setup, no install, no linter
devDependency. The engine is [oxlint](https://oxc.rs), and the baseline
targets mistakes, not style
([ADR-0011](adr/0011-checks-report-only-what-they-are-sure-of.md)):

- oxlint's correctness, suspicious and perf categories, plus its promise
  plugin
- Mozilla's no-unsanitized plugin for innerHTML and its family
- the type-aware TypeScript rules, when the repo has a tsconfig.json, which
  is where no-floating-promises and no-misused-promises come from

The type-aware rules apply to .ts and .tsx only; the APIv4 contract rule (see
[Code](#code)) runs on JS too. CiviCRM's globals are declared, and built,
vendored and minified files are ignored.

ckeslint links the image's pinned Node types into node_modules for the run if
the repo has none, so e2e code touching process.env does not trip the unsafe-
any rules. The type-aware engine does not auto-include them, so your
tsconfig.json still has to:

```json
{ "compilerOptions": { "types": ["node"] } }
```

TypeScript's compiler diagnostics are opt-in
([ADR-0009](adr/0009-type-checking-javascript-from-outside.md)):

```yaml
policy:
  javascript:
    type_check: true
```

ckeslint then also reports what `tsc --checkJs` would, through the repo's
tsconfig.json if it has one, or else in a second pass with checkJs on, strict
off and CiviCRM's globals typed as any. Plain scripts share one global scope
there, so two declaring the same top-level const are a redeclaration unless an
IIFE or module separates them. On older code expect findings in bulk, mostly
outgrown JSDoc.

A repo with its own .oxlintrc.json sets `"options": {"typeCheck": true}`
there; ckeslint stops with an error rather than ignoring the policy key. That
config wins outright: the baseline is not merged into it. Its jsPlugins
resolve against your own node_modules, so such a repo also turns npm_ci on.

An eslint.config.* alone is not usable, because ESLint is not in the image.
ckeslint fails with a pointer; translate it to .oxlintrc.json or drop it and
take the baseline.

### Test scripts

The script names are the contract:

- `test` is the JS unit suite, in any runner (vitest, bun, node --test). For
  pure functions this is most of the frontend's coverage, so have it first.
- `test:e2e` is the Playwright suite, kept separate so js_tests never starts a
  browser run.

--ignore-scripts is fixed, not an input: an install hook is arbitrary code
from the dependency tree, running against a writable checkout with a token in
the environment. A package that must build before its tests does it in its
own test script.

npm_ci and js_tests cost seconds and belong in the push run:

```yaml
    with:
      js_tests: true      # implies npm_ci
      # bun: true         # same steps, run with Bun instead of npm
```

### Bun instead of npm

`bun: true` switches the package manager for every frontend step at once (the
install, js_tests and the Playwright job), so no repo tests two different
dependency trees. The install is `bun install --frozen-lockfile`, which needs
the committed bun.lock; the npm dependency cache is off on this path.

Node is still installed at node_version for the tools your scripts call. Bun
runs no dependency lifecycle scripts
unless listed as trusted, which matches --ignore-scripts, but it does run your
own package's install scripts. Keep the build in the test script and both
paths behave the same.

### The browser job

The browser job is the slowest thing in the pipeline, so put
`playwright: true` in the scheduled compat.yml caller below, not the push run.
It assumes the following.

The stack is the template's standalone CI stack. The job publishes it on a
free runner port, dropping any port the compose file publishes (Docker Compose
2.24.4 or later), and sets CIVIKITCHEN_SITE_URL to that localhost URL, because
CiviCRM bakes the URL into assets and redirects. A CMS compose file boots, but
the suite has to handle the CMS login itself; the demo user is a Standalone
user.

The suite reads CIVICRM_BASE_URL, DEMO_USER and DEMO_PASS from the
environment; a hardcoded `localhost:8080` reaches nothing. All browsers are
installed, with system dependencies on GitHub-hosted runners. Self-hosted
runner images must provide those libraries, because CI has no passwordless
sudo. A starter to copy is
[`examples/extension-with-playwright/`](https://github.com/civico-de/civikitchen/tree/main/examples/extension-with-playwright/).

By default the job runs once, on image. `matrix_playwright: true` together
with playwright and a non-empty matrix_images adds one leg per image, each on
its own stack and port. If either is missing, the run fails instead of testing
nothing. Each leg is named `playwright (<image>)`, and every report artifact
after the first carries its index.

## Private dependencies

The shared workflow needs no token, but an extension's dependencies may be
private. Two opt-in inputs and one optional pair of secrets cover that:

| Input / secret | What it adds |
|---|---|
| `composer_install` | `composer install --no-interaction --no-progress` on the runner, before the stack boots. |
| `sibling_repo` | Comma- or newline-separated owner/repo list of further extensions, each optionally pinned as owner/repo@ref. Each is checked out to `.civikitchen-siblings/<key>`, bind-mounted read-only and enabled, left to right, before this extension. |
| `composer_app_repositories` | Comma- or newline-separated repository names within the caller's owner. Required with the App secrets; scopes the token to exactly these repos. |
| `composer_app_id`, `composer_app_private_key` (secrets) | A GitHub App with contents: read on the private dependency repos. The workflow mints a short-lived token from it for the composer install and the sibling checkout. |

### composer_install

The extension's main .php file requires the autoloader, so a repo without a
committed vendor/ needs an install before the stack boots. The workflow
installs public packages in the image before every boot. Private packages need
composer_install, which installs on the runner first so the token never
enters a container. CI installs the locked development tree, because static
analysis covers the tests; release packaging stays --no-dev.

### sibling_repo

sibling_repo is for an extension that implements another extension's
interfaces, which must exist at boot for the enable to succeed. The mount
target is each sibling's key from its info.xml, not the repo name, and a
sibling another sibling requires is listed first. List the sibling in
`<requires>` as well: the managed phpstan bootstrap autoloads it and the
boundary rule accepts it as own code (see
[Tooling](#tooling-every-repo-must-have)).

Pin an entry as owner/repo@ref with a tag, branch or full commit. Without a
pin the sibling's default branch is used, so a break there turns every
consumer red and old runs stop reproducing; pin a tag and bump it
deliberately. Two entries declaring the same key fail the run, as do the keys
ci and policy, which the workflow uses for its own checkouts.

Each sibling is mounted as is, with no composer install, so a sibling that
keeps its vendor/ out of git is not supported yet. The checkouts are not
treated as your code, and no .gitignore entry is needed.

playwright-e2e.yml takes the same input for a single repo. That workflow never
boots the stack itself; it passes the checkout to your own prepare, test and
cleanup commands through three variables, all unset when sibling_repo is
empty:

| Variable | Value |
|----------|-------|
| `CK_SIBLING_DIR` | Absolute path of the checkout, `$GITHUB_WORKSPACE/.civikitchen-siblings/<key>`. |
| `CK_SIBLING_KEY` | The extension key alone, for an enable list or a mount target. |
| `CK_SIBLING_OVERRIDE` | A compose override that bind-mounts the sibling read-only; add it with a second -f on your up. It only mounts, so CIVIKITCHEN_ENABLE_EXTENSIONS stays yours. |

### Caller example

```yaml
jobs:
  ci:
    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      composer_install: true
      sibling_repo: myorg/othersibling@v1.4.0
    secrets:
      composer_app_id: ${{ secrets.COMPOSER_APP_ID }}
      composer_app_private_key: ${{ secrets.COMPOSER_APP_PRIVATE_KEY }}
```

With two siblings, list the required one first, one per line or
comma-separated:

```yaml
    with:
      sibling_repo: |
        myorg/base
        myorg/othersibling
```

Name the two secrets rather than writing `secrets: inherit`, which would hand
every repo secret to a workflow that moves when it is retagged.

### The GitHub App

1. Create a GitHub App in the organisation with no webhook and only the
   repository permission Contents: Read-only, and generate a private key.
2. Install it restricted to the private dependency repositories. Its tokens
   reach only those, expire within the hour and belong to no person.
3. Store the App ID as `COMPOSER_APP_ID` and the key as
   `COMPOSER_APP_PRIVATE_KEY` in each calling repo. Organisation secrets for
   private repos need a paid plan. Nothing goes into the dependency repos.

The token works over HTTPS only. Declare private Composer packages by HTTPS
URL in composer.json, such as `"url": "https://github.com/myorg/mypackage"`,
and re-resolve composer.lock so its source URLs match. A git@github.com URL
cannot use the token and fails with "repository not found".

### Which jobs use them

Both inputs apply to every job that boots a stack: ci, matrix_images,
mutation, upgrade_from_last_release, schema_parity, core_upgrade_from and
playwright. The two jobs that move the working tree between commits re-run
the install after each move.

composer.lock must be committed. The install runs on the runner's PHP and
installs the lock as it stands, without update or --ignore-platform-reqs, so
pin `config.platform.php` in composer.json to the PHP the extension runs on.

## Compatibility: test the range you claim

`<compatibility><ver>` in info.xml is a promise, and the default CI run
checks one CiviCRM version, installed from scratch. Five opt-in inputs on
extension-ci.yml close that gap, all off by default:

| Input | What it adds |
|---|---|
| `matrix_images` | One extra job per comma-separated image tag: boots the stack and runs the PHPUnit suite against that CiviCRM, plus the lifecycle gate with lifecycle. With matrix_playwright every tag also gets a browser leg. |
| `lifecycle` | After the suite: disable, enable, uninstall, install, then the checks described [below](#the-lifecycle-gate-what-cklifecycle-adds-to-the-cycle). |
| `upgrade_from_last_release` | Installs the newest reachable tag, swaps to the tested commit, runs `cv upgrade:db --mode=ext` and asserts no upgrade stayed pending. |
| `schema_parity` | Own job: diffs the extension's tables after upgrading from the last release against a fresh install. See [below](#schema-parity-does-the-upgrader-arrive-where-install-does). |
| `core_upgrade_from` | Own job: installs on an older image, then upgrades that database to the run's image. See [below](#core-upgrade-what-an-existing-site-goes-through). |

Pick the ends of your claimed range: the oldest minor you support and current
stable. The tags are `:standalone-<minor>` and the moving `:standalone`; see
[images.md](images.md#tags-and-versions). A minor tag is rebuilt only while it
is on the supported list there. `:standalone-beta` and `:standalone-master`
are built daily from core's branches; a red leg there can be a core
regression, so they belong in the scheduled caller.

The matrix jobs run the suite, not the full ci pass, since static gates on an
older image would grade that image's tooling.

### The lifecycle gate: what `cklifecycle` adds to the cycle

The ext commands are only the setup; the checks around them are the gate.

After uninstall, the database must be back where it started. The gate asserts
that no table survived that the extension declares (in sql/ or xml/schema/)
or that carries its `<file>` prefix, that civicrm_managed holds no row for
the module, and that no option group, option value or scheduled job with that
prefix is left behind. A managed record core keeps by policy (cleanup never,
or unused while referenced) is not a leftover.

Nothing may fail quietly during the cycle. The gate scans every step's output
and the new CiviCRM log lines for SQLSTATE, DB Error, missing or unknown
tables, unknown or duplicate columns, syntax or access violations, and PHP
Fatal, Parse, Warning and Recoverable lines. Notices and deprecations are not
matched; core emits them on every version, and the phpunit config and phpstan
cover deprecations. The scan is scoped to this job, because a negative test in
the suite is supposed to log errors.

Settings options must load. After the re-enable, the gate loads the options
of every setting the extension declares and reports each failing one by name:
a table pseudoconstant without key or label column, a callback that does not
resolve, or a key core does not read. The ckconform check settings-metadata is
the static version.

The findings land in the job summary. A genuinely expected line is declared
with a reason:

```yaml
policy:
  lifecycle:
    log_ignore:
      - pattern: "Unknown column 'is_legacy'"
        reason: Dropped in the 5.x upgrader
```

### Schema parity: does the upgrader arrive where install() does?

upgrade_from_last_release proves the upgrader runs, not where it arrives.
install() and the upgrade chain are two independent descriptions of one
schema. A column nullable in one, a different varchar length, or an index only
the installer creates leaves every upgraded site quietly different, and no
later upgrader knows to reconcile it. Switch the check on with
`schema_parity: true`.

The job installs the last tag, moves to this commit, runs
`cv upgrade:db --mode=ext` and dumps the schema. It then recreates the stack
for a fresh install of this commit, dumps again and diffs, over the tables the
extension declares in `schema/*.entityType.php` and xml/schema/.

The diff is mysqldump --no-data with a small normalisation, run inside the app
container because the CI stack publishes no database port. It drops the
AUTO_INCREMENT counter, mysqldump's version wrappers, trailing whitespace and
blank lines. Since it compares DDL text, a semantically empty difference is
possible; the fix is one more normalisation rule. ckschemadiff exposes the
steps (tables, dump, diff, normalize) for reproducing a finding by hand in the
app container.

The job skips itself with a log line when the extension declares no tables or
nothing has been released yet.

### Core upgrade: what an existing site goes through

Neither matrix_images nor upgrade_from_last_release covers records created
on core N surviving a move to N+1, where managed entities reconcile
differently and saved searches' stored params stop being valid.

Configure it with the oldest minor you claim:

```yaml
      core_upgrade_from: ghcr.io/civico-de/civikitchen:standalone-6.16
```

The target is the run's image, or the compose file's default. If both are the
same version, the job fails rather than pass over an upgrade that never
happened. In one stack it:

1. boots on core_upgrade_from, installing CiviCRM and your extension,
2. runs `tests/upgrade/seed.php` if present,
3. recreates the containers on the target image, keeping the database and
   site directory,
4. runs `cv upgrade:db` and asserts that the database reached the code's
   version, the version moved, no extension upgrade is pending, and your
   extension is still installed,
5. runs `tests/upgrade/assert.php` if present.

Both fixtures run with cv scr. The seed creates the records whose survival
matters; any exception or non-zero exit from the assert script fails the job.

```php
// tests/upgrade/seed.php — on the old core
\Civi\Api4\Contact::create(FALSE)
  ->addValue('contact_type', 'Individual')
  ->addValue('last_name', 'UpgradeFixture')
  ->addValue('my_group.my_field', 'teal')
  ->execute();

// tests/upgrade/assert.php — after the upgrade
$rows = \Civi\Api4\Contact::get(FALSE)
  ->addSelect('my_group.my_field')
  ->addWhere('last_name', '=', 'UpgradeFixture')
  ->execute();
if (($rows->single()['my_group.my_field'] ?? NULL) !== 'teal') {
  throw new RuntimeException('the custom field value did not survive the upgrade');
}
```

They are scripts because the headless harness uses a scratch database the
upgrade never touches ([ADR-0012](adr/0012-upgrade-fixtures-are-scripts.md)).
Without them the job runs the four assertions and logs that it found no
fixtures. It is the slowest single check and runs once, on image.

### The scheduled caller

lifecycle runs in the push run's stack. The matrix, upgrade, schema-parity
and core-upgrade jobs each cost at least one more stack boot, so keep ci.yml
fast for PRs and automerge and add a second caller on a schedule:

```yaml
# .github/workflows/compat.yml
name: Compatibility
on:
  schedule:
    - cron: '0 5 * * 1'   # Mondays, after that day's image rebuild
  workflow_dispatch:      # and on demand, before a release

permissions:
  contents: read

jobs:
  compat:
    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      # The default ci job runs here too, on the moving edge.
      image: ghcr.io/civico-de/civikitchen:standalone
      # Oldest minor from info.xml <compatibility>.
      matrix_images: ghcr.io/civico-de/civikitchen:standalone-6.16
      lifecycle: true
      upgrade_from_last_release: true
      # Skips itself when the extension owns no tables.
      schema_parity: true
      # Installed on the oldest minor, then upgraded to the image above.
      core_upgrade_from: ghcr.io/civico-de/civikitchen:standalone-6.16
      # playwright: true          # browser tests, needs a test:e2e script
      # matrix_playwright: true   # one more boot per matrix image
      # No-op without policy.mutation.minimum_msi; set .paths too.
      mutation: true
      # The drift job already runs on every push in ci.yml.
      check_template: false
```

A red weekly run means the released state is broken. Concurrency groups in
extension-ci.yml carry the calling workflow's name and the image, so the
weekly run and a push run leave each other alone as long as the second
caller's `name:` differs from the template's CI.

compat.yml is your policy, so ckinit neither stamps nor checks it.
