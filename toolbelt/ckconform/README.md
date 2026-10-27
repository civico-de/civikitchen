# ckconform

Repo-conformance checks for CiviCRM extensions against the civikitchen template.
Complements `cklint` (style) and `phpstan` (types): this one checks the *repo
structure* — the gaps every extension audit turned up.

Run from an extension root. In a civikitchen container `ckconform` is on PATH; the
shim in `toolbelt/bin/ckconform` execs `bin/ckconform` here.

## Why this is PHP and not a shell script

It was a shell script. Every bug it ever had was the same bug — parsing
structured formats with line-oriented text tools:

| Symptom | Cause |
|---|---|
| `<license>` over two lines read as empty | `sed` needed both tags on one line |
| `<ext version="3.32">…</ext>` invisible | tag-shaped regex assumed no attributes |
| wrong licence compared | first `"license"` line anywhere in composer.json won |
| `ang/afform/*.aff.html` not seen | fixed-depth glob |
| `@since` check passed on everything | `sed`'s `\+` is unsupported by BSD sed, and fails *silently* |

The last one is the instructive one: a check that cannot fail is worse than no
check, because it reports success. So the rules now parse XML as XML and JSON as
JSON, walk directories recursively, and — the actual point — **have tests**.

## Adding a check

1. `src/Check/YourCheck.php` implementing `Check`: `name()` and
   `run(Context, Reporter)`.
2. Register it in `src/Registry.php`. Order matters — it is the output order.
3. `tests/Check/YourCheckTest.php` extending `CheckTestCase`.

`Context` is the only thing that touches the filesystem: `infoXml()` (SimpleXML),
`json()`, `policy()`/`policyValue()` (the repo's `civikitchen.yaml`), `findFiles()`
(recursive), `tracked()`/`isTracked()`/`trackedFiles()` (git), `workflows()`,
`tags()`/`newestTag()`/`isShallowClone()`/`infoVersionHistory()` (release history).
If you find yourself reaching for a regex over a structured file, add a method
there instead.

### A check needs a fixture that makes it fail

Not a style preference. Roughly half of these rules print nothing when they pass,
so a rule that silently never fires looks exactly like a repo that is in good
shape. `CheckTestCase::repo([...], git: true)` builds a throwaway extension —
give every check at least one fixture that FAILs and one that passes.

## Running the tests

```bash
docker run --rm -v "$PWD":/work -w /work \
  ghcr.io/civico-de/civikitchen:standalone phpunit --no-coverage
```

Its own classes need no composer — `src/Autoloader.php` is a dozen lines. Reading
`civikitchen.yaml` needs Symfony YAML from
`packages/civikitchen-scenario-schema/vendor/` (`scaffold/ck make tools`), which the image
ships.

## Policy lives in the consuming repo

Which licence, which coverage floor, whether tests may be skipped — none of that
is this tool's business. It reads an optional `civikitchen.yaml` from the extension
root, so a private policy never has to be published here:

```yaml
version: 1
policy:
  license: Proprietary
  npm_license: UNLICENSED
  copyright: Example Ltd
  tests:
    mode: optional
    reason: Configuration-only extension
  coverage:
    minimum: 70
  mutation:
    minimum_msi: 60
    minimum_covered_msi: 75
    paths:
      - Civi
  known_hooks:
    - acmeConnectors
  hook_style: listener
  template_custom:
    paths:
      - .github/workflows/ci.yml
    reason: Bespoke pipeline
```

`civikitchen.yaml` is the one policy file for the whole ck* family — ckconform,
ckcoverage, ckmutate, ckrelease, cklifecycle and ckinit all read it, so a repo's
deviations from the standard live in one place with their reasons attached.

### Organisation-wide defaults

Keys every repo of an organisation shares (`license`, `npm_license`,
`copyright`, `vendor`) can live once, in a file of the same format that
`CK_DEFAULT_CONFIG` names. Only the keys ckconform and ckinit read
(`Policy::SHARED`) are taken from it — a key another gate reads would apply in
one run and not the next, so `PolicyKeyCheck` rejects it there. Its keys apply
to every repo the variable reaches;
a key the repo's own `civikitchen.yaml` sets replaces the default's values — for the
repeatable keys too, a repo's lines never inherit a fleet-wide one. The merge
happens in `Policy::effective()`, so every reader (the checks, `--policy-env`,
`--policy`) sees the same view. A variable naming an unreadable file is an
error, not an absent layer, and `PolicyKeyCheck` validates the defaults file
under its own name. `ckinit` reads `policy.template_custom` from the repo file alone
(which managed files a repo owns is per-repo by nature) and `renovate_preset`
from the layered view.

### One file, one parser

They read it *through this tool*, which is the only thing that parses the
format:

```bash
eval "$(ckconform --policy-env)"   # CK_POLICY_MIN_COVERAGE='70', … (scalars, reason stripped)
ckconform --policy lifecycle_log_ignore   # every value of one key, one per line, verbatim
```

That is not ceremony. The predecessor format had seven readers which disagreed
on whitespace and reason suffixes. `src/Policy.php` is now the single normalized
view over the schema-validated YAML. Unknown keys and wrong types fail before a
tool can silently proceed without the intended policy.

A new key is added in this order: the JSON Schema
(`packages/civikitchen-scenario-schema/scenario.schema.json`), its mapping in
`Policy::parse()`, `Policy::KEYS` if a consumer reads the normalized value, the
tool that reads it, and the list above.

## Output formats

Human output remains the default. Automation can request stable structured
results without parsing prose:

```bash
ck conform --format=human
ck conform --format=json
ck conform --format=github
ck conform --format=sarif > ckconform.sarif
```

JSON carries the rule name, severity, message, summary counts, and any location
reported structurally by a check. GitHub mode emits escaped workflow commands
with those locations. SARIF 2.1.0 carries the same structured rules and
locations; it never guesses a path from prose. Passing checks are intentionally
omitted from GitHub and SARIF output.

## Suppressing a finding

Three escape levels, from narrow to broad — the reason is never optional:

```php
// ckconform-ignore <check-name> -- <reason>        line-scoped: this line + the next
// ckconform-ignore-file <check-name> -- <reason>   the whole file
```

```yaml
policy:
  ignore_checks:
    checks: [<check-name>, ...]
    reason: <reason>
```

Several checks may be listed comma-separated. A marker without a reason
suppresses nothing and is itself reported, and one naming a check that does
not exist is reported as a dead ignore — a typo'd check name would otherwise
silently narrow nothing.

Inline markers act on every finding a check ties to a source line (those
printed as `file:line:`), whichever check reports it; a finding about the
repository as a whole has no line to sit on and is silenced only through
`ignore_checks`. Markers are read from PHP and JavaScript/TypeScript comments
only; a finding in YAML, XML or any other file takes `ignore_checks` too.

Playwright configs may use the file form as a standalone `//` comment too. The
narrow legitimate case is a manual live-provider suite with reusable
credentials: traces can contain action parameters, request data, tokens,
cookies, and authenticated browser state, so retaining a failed trace would
persist the very credentials the suite is meant to protect. Suppress only that
config, state the security reason, and leave ordinary mock/CI configs subject
to `playwright-diagnostics`:

```ts
// ckconform-ignore-file playwright-diagnostics -- manual live-provider suite uses reusable credentials; traces must not persist them
export default { reporter: 'list', use: { trace: 'off' } };
```

An inline ignore that silenced nothing in the whole run is reported too
(phpstan's `reportUnmatchedIgnoredErrors`): the finding it covered is gone, so
the ignore now only waits to swallow the next one unnoticed — delete it. Each
listed check name is judged on its own. Not reported: a name whose check
`policy.ignore_checks` skipped repo-wide, since nothing ever looked for its finding.
