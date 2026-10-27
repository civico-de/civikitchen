# Implementation architecture

CiviKitchen has three implementation boundaries
([ADR-0015](adr/0015-php-shell-typescript-boundaries.md)).

PHP owns structured and reusable logic. Parsing YAML, JSON, XML and ZIP
archives is PHP, and so are policy, version constraints, filesystem rules and
reusable process handling. The shared code lives below `toolbelt/lib/php` and
is reached through the `ck` CLI. `ckconform` and `scaffold/ckinit.php` are PHP
programs of their own.

Shell owns bootstrapping. Container entrypoints, user switching, environment
export, pipes and the final exec stay in shell, because there the operating
system is the API. Shell must not embed PHP programs or implement a second
parser for a structured format.

TypeScript owns browser automation only: the Playwright suites and the
recording script that `ck demo` runs. Both run in the project's Playwright
image, which `ck playwright` builds from the lockfile
([ADR-0019](adr/0019-browser-suites-run-in-the-projects-playwright-image.md)),
so the host needs neither Node nor browsers. Bun remains a supported
package-manager choice for consuming extensions, but CiviKitchen's
implementation does not depend on it.

## Command layout

`toolbelt/bin/ck` is the one executable entrypoint of the implementation. Its
command classes under `toolbelt/lib/php` share path discovery, process
execution, errors and output. The older executable names are symlinks to
`ck`. They are compatibility names, not separate programs. They are
`ckprofile`, `ckdeps`, `ckconform`, `ckscenario`, `cklint`, `ckfmt`,
`ckeslint`, `ckcompat`, `ckphpunit`, `ckcoverage`, `ckmutate`, `cklifecycle`,
`ckschemadiff`, `cksmarty`, `ckcivix` and `ckrelease`.

The remaining shell commands are deliberate adapters to the operating system:

- `cktaint` invokes the isolated Psalm runtime with its memory and baseline
  flags.
- `ckmodernize` sequences two independent external rewriters, civix and
  Rector.
- `ckcoretest` materializes a sparse external Git checkout into an installed
  core tree before invoking its suite.
- `cktestreset` streams mysqldump directly into mysql and removes runtime
  cache artifacts.

None of these commands contains a parser for a structured format. If reusable
logic for policy, XML, JSON, archives, file selection or validation is added
to them, that logic belongs in `toolbelt/lib/php` and is called through `ck`.
Replacing a shell file with an isolated PHP script misses the point. The aim of
the migration is shared ownership, not a different file suffix.

The same boundary applies outside `toolbelt/bin`. Image entrypoints, profile
application, `ckcreate`, `ckup`, `ckx`, `scaffold/ck`, the GitHub workflow
adapters and the integration-test drivers stay in shell. They mainly manage processes, users,
containers, pipes or host ports, and their structured operations call the
shared PHP runtime. Test-only PHP snippets may create fixtures or probe a PHP
runtime. They are test inputs, not duplicated product logic.

## Runtime layout

The image copies `toolbelt/` whole
([Toolbelt layout](images.md#toolbelt-layout)). `docker/runtime/provision.sh`
keeps the orchestration that must run as shell, while `ck internal` exposes
the shared PHP operations it needs. The internal commands are not part of the
public toolbelt API for extensions. Their PHP classes are the testable
implementation boundary.
