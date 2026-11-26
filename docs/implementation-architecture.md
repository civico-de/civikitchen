# Implementation architecture

CiviKitchen's implementation is split across three languages, each with its
own job ([ADR-0015](adr/0015-php-shell-typescript-boundaries.md)):

| Language | Owns | Lives in |
|---|---|---|
| PHP | Structured and reusable logic: parsing YAML, JSON, XML and ZIP archives, policy, version constraints, filesystem rules, reusable process handling | `toolbelt/lib/php`, reached through the `ck` CLI; `ckconform` and `scaffold/ckinit.php` are PHP programs of their own |
| Shell | Bootstrapping: container entrypoints, user switching, environment export, pipes and the final exec, where the operating system is the API | Image entrypoints, provisioning, `scaffold/`, the [shell adapters](#command-layout) in `toolbelt/bin` |
| TypeScript | Browser automation only: the Playwright suites and the scripts that `ck demo` and `ck steps` run | The project's Playwright image |

Shell must not embed PHP programs or implement a second parser for a
structured format.

The TypeScript runs in the project's Playwright image, which `ck playwright`
builds from the lockfile
([ADR-0019](adr/0019-browser-suites-run-in-the-projects-playwright-image.md)),
so the host needs neither Node nor browsers. Bun is a supported
package-manager choice for consuming extensions, but CiviKitchen's
implementation does not depend on it.

## Command layout

`toolbelt/bin/ck` is the one executable entrypoint of the implementation. Its
command classes under `toolbelt/lib/php` share path discovery, process
execution, errors and output. The other `ck*` names in `toolbelt/bin` that are
symlinks to `ck` are compatibility names, not separate programs:
`ckanalyze`, `ckchangelog`, `ckcivix`, `ckcompat`, `ckconform`, `ckcoverage`,
`ckdeps`, `ckeslint`, `ckfmt`, `cklifecycle`, `cklint`, `ckmigrate`,
`ckmutate`, `ckphpunit`, `ckprofile`, `ckrelease`, `ckscenario`,
`ckschemadiff` and `cksmarty`.

The remaining commands are shell adapters to the operating system:

| Command | Does |
|---|---|
| `ckboot` | Provisions the site inside the current container, with the extension in the current directory attached, for CI jobs that run inside the image with the database as a service. |
| `cktaint` | Invokes the isolated Psalm runtime with its memory and baseline flags. |
| `ckmodernize` | Sequences two independent external rewriters, civix and Rector. |
| `ckcoretest` | Materializes a sparse external Git checkout into an installed core tree before invoking its suite. |
| `cktestreset` | Streams mysqldump directly into mysql and removes runtime cache artifacts. |

None of these commands contains a parser for a structured format. Reusable
logic for policy, XML, JSON, archives, file selection or validation that they
need belongs in `toolbelt/lib/php` and is called through `ck`, not in a PHP
script of its own beside them.

The same boundary applies outside `toolbelt/bin`. Image entrypoints, profile
application, `ckcreate`, `ckup`, `ckx`, `scaffold/ck`, the GitHub workflow
adapters and the integration-test drivers stay in shell. They mainly manage
processes, users, containers, pipes or host ports, and their structured
operations call the shared PHP runtime. Test-only PHP snippets may create
fixtures or probe a PHP runtime. They are test inputs, not duplicated product
logic.

## Runtime layout

The image copies `toolbelt/` whole
([Toolbelt layout](images.md#toolbelt-layout)). `docker/runtime/provision.sh`
keeps the orchestration that must run as shell, while `ck internal` exposes
the shared PHP operations it needs. The internal commands are not part of the
public toolbelt API for extensions. Their PHP classes are the testable
implementation boundary.
