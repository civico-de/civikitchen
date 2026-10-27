# ADR-0015: PHP for logic, shell for bootstrapping, TypeScript for the browser

*Status: accepted · 2026-08-31 · amended 2026-10-27*

## Context

The tool belt mixes shell scripts, PHP, a Playwright suite and an oxlint
plugin. Without a rule for which language does what, structured formats end up
parsed in shell. The same format then gets read in several places, each a
little differently.

## Decision

PHP owns structured and reusable logic. That covers YAML, JSON, XML, ZIP
archives, policy, version constraints, filesystem rules and process handling.
The shared code lives below `toolbelt/lib/php` and is reached through the `ck`
CLI. `ckconform` and `scaffold/ckinit.php` are PHP programs of their own.

Shell owns bootstrapping, where the operating system is the API. That covers
container entrypoints, user switching, environment export, pipes and the final
exec. Shell does not embed PHP programs or implement a second parser for a
structured format.

TypeScript owns browser automation only: the Playwright suites and the script
that `ck demo` runs to record a page, both in the project's Playwright image
(ADR-0019). The PHP side builds that image, starts the container and reads
its result. JavaScript appears only where a tool
demands it, such as the oxlint plugin that carries the APIv4 contract rule
(ADR-0008).

## Rationale

PHP is already in every image, so structured logic costs no extra runtime. It
can also be unit-tested with the coverage floor that the repo applies to
itself.

Each format gets one parser. A sed or grep -o on a structured file breaks on
the first valid input it did not expect, and two parsers disagree on edge
cases.

Shell stays where it is the natural tool, and only there.

## Consequences

Bun remains a supported package-manager choice for consuming extensions, but
it is not a dependency of CiviKitchen itself.

New tools that need structured input are written as `ck` subcommands, not as
shell scripts.
