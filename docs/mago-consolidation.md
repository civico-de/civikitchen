# Consolidating the PHP toolbelt on mago

The images ship nine PHP quality tools. This plan moves every job that mago
can do onto mago and removes the tool that did it, one gate at a time. A tool
leaves only once mago is shown to find what the tool found, both on fixtures
and on real extension code. Where mago has no equivalent, the tool stays.

The decision is recorded in
[ADR-0017](adr/0017-php-quality-tools-consolidate-on-mago.md). The mago pin
lives in `toolbelt/install-dev-tools.sh` and follows the newest mago release.

## Target state

| Job | Today | Target |
|---|---|---|
| Syntax check of changed files (`cklint`) | `php -l` | `mago lint` + `mago analyze` |
| Layout (`ckfmt`) | mago formatter | unchanged |
| Style and footgun sniffs (`cklint`) | mago linter + CiviKitchen rules as a mago extension (done) | unchanged |
| Version compatibility (`ckcompat`) | mago lint + mago analyze at the floor (done) | unchanged |
| Static analysis (`phpstan` gate) | phpstan, deprecation rules, disallowed-calls, phpat, CiviKitchen rules | `mago analyze` + CiviKitchen plugins as a mago extension |
| Taint analysis (`cktaint`) | psalm | psalm (mago's `tainted-data-to-sink` is a syntactic rule without data flow) |
| Automated refactoring (`ckmodernize`) | rector | rector (mago has no equivalent) |
| Mutation testing (`ckmutate`) | infection | infection |
| Unused and missing dependencies (`ckdeps`) | composer-dependency-analyser | composer-dependency-analyser |

At the end, phpstan, its extensions and the phpstan extension package
leave the images. phpcs, PHPCompatibility and the civicrm/coder clone are
already gone. `ckdeps` comes from the root composer.json.

## Evidence so far

Everything here was measured with mago 1.55.0 inside the standalone image on
2026-10-11.

**php -l.** Of 20 fixtures that `php -l` rejects, `mago lint` rejects 15. The
other five are compile errors that only `mago analyze` reports. They are a
break outside a loop, a continue level that is too deep, a redeclared
function, an assignment to `$this`, and a default value of the wrong type. For
that reason `php -l` can leave `cklint` only once `mago analyze` runs on every
change, which is step 3.

**PHPCompatibility.** We checked 32 version-specific fixtures against an 8.1
floor. mago reports four cases that PHPCompatibility misses. These are
`str_increment()`, `Dom\HTMLDocument`, `ReflectionClass::newLazyGhost()` and
the 8.5 deprecation of `ReflectionProperty::setAccessible()`.

mago misses five cases that PHPCompatibility reports:

- `"${var}"` interpolation, deprecated in 8.2
- `get_class()` without an argument, deprecated in 8.3
- an implicitly nullable parameter, deprecated in 8.4, which mago reports only
  when it runs at 8.4 or later and not at the floor
- `mb_trim()`, new in 8.4, for which mago has no availability data
- the dynamic class constant fetch `C::{$name}`, PHP 8.3 syntax that mago
  parses at an 8.1 floor without a finding

Only `mago analyze` reports new functions, classes and methods, under the
unavailable-* codes. `mago lint` does not. Deprecated functions and constants
are likewise reported only by `mago analyze`, under the deprecated-* codes.

The five gaps are covered by the `civikitchen/php-compatibility` and
`civikitchen/php-deprecation` rules, and by running mago's Deprecation rules at
the newest PHP (step 4). PHPCompatibility also reported functions that
CiviCRM core polyfills through symfony/polyfill-php80 up to -php85, such as
`mb_trim()`, `array_find()` and `json_validate()`. An extension may call those
on any floor. mago reads the polyfills as symbols and stays silent.

**Static analysis.** One extension is clean under phpstan level 10. On it,
`mago analyze` with core as an include ran in 1.2 s, against 4.7 s for
phpstan. mago reported 25 issues:

- 20 mixed-assignment findings, a check stricter than phpstan's level 10
- 2 invalid-array-index findings on `$result[] = …` against
  `Civi\Api4\Generic\Result`, which are false positives because Result is an
  ArrayObject
- 2 invalid-type-cast findings on an array cast of Result, also false
  positives, because casting an ArrayObject returns its rows
- 1 incompatible-return-type finding, where an entity's create method returns
  an action that does not extend the parent's DAOCreateAction, a real defect
  that phpstan misses

**Extension API.** mago 1.47 added an extension SDK in PHP. It supports custom
linter rules on the syntax tree, analyzer plugins and issue filters. The
plugins can be type providers, targeted hooks or codebase scans. The
CiviKitchen phpcs sniffs and phpstan rules port to this SDK. The SDK is young,
so a port pins its mago version exactly.

## Port status

`toolbelt/mago-ext` is one mago extension. Its composer root is pinned to the
mago version in `toolbelt/versions.env`. `make test-mago-ext` runs every case
in `toolbelt/mago-ext/tests/cases` through the real mago binary and compares
the exact findings. The image tests run the same cases against the image's
mago.

**Linter rules** gate in `cklint`. They cover all eight CiviKitchen sniffs and
Slevomat's DeclareStrictTypes, which is mago's own strict-types rule. The
sniffs, their PHPUnit test and Slevomat are gone from the phpcs standard. Over
the 38 local extension repos, the mago rules and the old sniffs agree on 3017
findings. Of the seven differences, six are false positives that the sniff
raised on booleans inside nested array literals. The seventh is one
`phpcs:ignore` that needs to become `@mago-expect`.

**Analyzer plugins** are ported but not yet run by any gate. They cover the
APIv4 contract rules for calls, fluent fields, action properties and their
initialization. They also cover the SQL table rule, GET mutation,
transactional test DDL and silent database catch. The civicrm-disallowed bans
are a plugin too, with one list in `civicrm-disallowed.php` that both tools
read. The `@ck-legacy` scope is an issue filter, and phpat's architecture test
is a plugin. Each plugin shares its contract logic with the phpstan rule
through `toolbelt/phpstan/src`, so the two cannot drift during the shadow
phase. The architecture plugin reproduces phpat's findings on the same
fixtures. It also catches two cases phpat misses, a property's `@var` type and
a static property fetch. A local shadow run over 49 extension repos found every
finding of both tools on the same line once phpstan's suppressions were
lifted. The differences left are the divergences described below.

**Known gaps** come from two limits of mago's extension API. A property
initialization provider sees the trait but not the class using it, so a
trait's defaults count as initialized outside an action too. A hook sees one
class-like's syntax tree, so GET mutation does not trace a handler's call into
a trait method.

**Known divergences.** The fluent APIv4 field check types each
argument where it stands. mago therefore also checks a field name held in a
local variable, which phpstan does not. A select list extended in a branch has
a union type, and mago then skips the alias checks on orderBy and groupBy that
phpstan still runs. The bans apply in every repo, while phpstan applies
them only where `phpstan.neon.dist` includes `civicrm-disallowed.neon`.
A class of a required extension that is not checked out is a boundary finding
in mago, and its help text names the missing extension. phpat skips a class it
cannot resolve.

## Steps

Each step ends with the old tool removed from `install-dev-tools.sh`, the lock
files and the docs. It also ends with a fixture per removed check that fails
without its replacement.

### 1. Follow the newest mago

We bump the pin to the newest release and keep bumping it. Each bump derives
both checksums anew. It then checks two properties on the extension repos.
Formatter output must still be clean under `cklint`, and the lint stage must
produce no new findings.

The five compatibility gaps and the compile errors that only analyze reports
go upstream as reports. A gap fixed there retires its part of the
`civikitchen/php-compatibility` or `civikitchen/php-deprecation` rule.

The 1.45 to 1.55 bump changes formatter output in two places. Wrapped boolean
conditions now open and close on their own lines, and wrapped concatenations
on the right of an assignment change too. Repos with such code fail
`ckfmt --check` until one `ckfmt` run. The new output is phpcs-clean.

### 2. Replace phpcs with mago linter rules (done)

`cklint` runs `php -l` and `mago lint` only. civicrm/coder and the CiviKitchen
phpcs standard are gone, and `ckconform` fails on a leftover `phpcs.xml` or
`phpcs.xml.dist`. `ckmigrate` rewrites a consumer repo's phpcs comments and
deletes its config.

The table lists every check that the CiviKitchen standard ran at error level
and what covers it now. It was measured on a corpus of 1182 extension files,
with both tools run over the same files.

| phpcs check | Now |
|---|---|
| The eight CiviKitchen sniffs, Slevomat `DeclareStrictTypes` | `civikitchen/*` rules, `strict-types` (see Port status) |
| `Drupal.Scope.MethodScope`, `Drupal.Classes.PropertyDeclaration` | `civikitchen/explicit-visibility`; every finding on the same line |
| `Drupal.ControlStructures.InlineControlStructure` | `block-statement`; every finding on the same construct (21) |
| `Drupal.Semantics.FunctionAlias` | `no-alias-function`; every finding on the same line (12) |
| `Drupal.Classes.UnusedUseStatement` (a warning, never gating) | `no-redundant-use`, already gating; mago also reports an unused `ExtensionUtil as E` import, phpcs does not. phpcs counts a name that appears only in docblock prose as used, mago does not |
| Closing tag, `new` without parentheses, line endings, `include` with parentheses, keyword and constant case, tabs, short open tag | `ckfmt` rewrites each of them |
| Every whitespace, indentation, brace and array layout sniff | `ckfmt` |

Some checks were dropped on purpose and have no counterpart:

- else if against elseif, a leading backslash in use statements, and
  function-name case, all style without a bug behind it
- comment layout (PostStatementComment, DocComment.*, ClassComment.*,
  InlineComment)
- ConstructorName, because a PHP 4 constructor is an ordinary method since
  PHP 8
- DeprecatedFunctions, because `ckcompat` owns deprecations
- DiscouragedFunctions, which targets Drupal's devel helpers, while eval is
  covered by no-eval
- Drupal-only checks for t(), watchdog, hook_menu, .info files and
  preg_replace with /e
- CommentedOutCode, a warning that never failed `cklint`
- a byte-order mark, which mago reports through strict-types because no
  declare statement can follow it

The `// phpcs:ignore` and `phpcs:disable` lines in consumer repos become
`@mago-expect` lines. Leftovers need no check of their own. phpcs no longer
runs, so they are plain comments, and the mago finding they meant still
stands.

### 3. Replace phpstan with mago analyze

The analyzer baseline in `ckanalyze` is done. Core and civix files are
includes, core's APIv4 Result is read from a stub, and its rows are typed by a
return-type provider and a patch
([Typing CiviCRM core from outside](core-types.md)). mago's default
strictness already reports every use of a mixed value, under
mixed-argument, mixed-operand and similar codes. phpstan level 10 does not.
The baseline ignores mixed-assignment. Assigning a mixed value and narrowing
it with an is_* check is the house idiom, and the later use is still
reported.

The phpstan rules, the disallowed bans and the phpat architecture test are
done as analyzer plugins (see Port status). The bans are a plugin rather than
disallowed-functions settings because they include static methods and a
per-path allowance. The architecture test is a plugin rather than
`mago guard` because its allowed namespaces come from each repo's `info.xml`
requirements.

The shadow phase is running. `ckanalyze` runs `mago analyze` in `ck ci` after
phpstan without gating. Its findings report as shadow, but a crash still
fails. It analyzes what phpstan's main pass analyzes. It reads core, civix
files, `vendor/`, siblings and `policy.vendored_paths` for their symbols. A
first run over 38 extension repos on 2026-10-11 completed on every one.
mixed-assignment and the other mixed-* codes made up most findings. Next came
classes of required extensions that were not checked out beside the repo.
`ckanalyze` now reads those from the `<requires>` element of `info.xml`.

Every difference across the consumer repos gets triaged into one of three
kinds. A mago false positive means fixing the plugin or reporting upstream. A
phpstan-only finding means porting it or accepting the loss deliberately. A
real defect gets fixed in the repo.

The gate switches once the shadow run is clean on every consumer repo.
`phpstan.neon.dist` and `@phpstan-ignore` lines go in the same release, and
`ckconform` flags leftovers. After that, `cklint` runs `mago analyze` on
changed files in place of `php -l`.

### 4. Drop PHPCompatibility (done)

`ckcompat` runs mago alone. It uses the polyfill directories of CiviCRM core's
`vendor/symfony` as includes, and it runs at two PHP versions.

At the floor, it runs the `civikitchen/php-compatibility` rule. Every
`mago lint` run also reports the syntax that its PHP version cannot parse.
`mago analyze` runs alongside and retains only the unavailable-* and
deprecated-* codes for built-in symbols. Analyzer filters drop two kinds of
finding. One is a deprecation of a `@deprecated` symbol in the analyzed code,
which is the static-analysis gate's question. The other is a newer symbol
that the file guards with function_exists, class_exists or method_exists, or
with a `PHP_VERSION_ID` or `version_compare(PHP_VERSION, …)` check above the
floor. A guard counts per file, so an unguarded second call in a guarded file
goes unreported.

At the top of `--range`, or at 8.5 without one, it runs every rule in mago's
Deprecation category and `civikitchen/php-deprecation`. The Deprecation rules
are read from `mago lint --list-rules`, so a bump brings new ones. A closed
range thus never fails on a deprecation that starts above it. mago reports
deprecated built-in symbols at every PHP version, so they need no second run.
The filter drops one whose `#[Deprecated(since: …)]` in mago's stubs lies
above the top of the range.

A function that PHP removed before 8.0, such as each() or create_function(),
is not `ckcompat`'s question. No supported floor has it, and phpstan reports it
as an unknown function.

phpcs, PHPCompatibility and PHPCSUtils left the images with this step. Four
limits remain open.

A function new in a PHP release that mago's stubs do not mark yet goes
unreported until a mago bump, or until it joins the rule's list.

mago 1.55 checks a newer class only on `new`. The
`civikitchen/unavailable-access` hook adds constant fetches, enum cases,
static calls and global constants from mago's own version data. That data has
no version for a newer constant of an older class, such as
`ReflectionProperty::IS_VIRTUAL`, so that case goes unreported.

A built-in deprecation marked only by a docblock has no since value. A
`--range` that ends below it therefore still reports it, as with lcg_value()
at 8.1 to 8.3.

The polyfills are the image core's. A function that core polyfills only since
a newer CiviCRM than the extension's minimum therefore passes.

## Risks

The extension SDK appeared in 1.47, and mago releases several times a week.
Ported rules tie the toolbelt to that API, so every bump runs their fixtures.

A formatter change in a bump turns `ckfmt --check` red on unchanged code. The
bump procedure in step 1 measures this before a release, and the changelog
names the one `ckfmt` run that consumers need.

Every consumer repo carries a `phpstan.neon.dist` and suppression lines.
Steps 2 and 3 migrate them with a `ckconform` check rather than by hand.
