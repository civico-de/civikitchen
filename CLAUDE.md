# civikitchen

CiviCRM dev images, the `ck*` tool belt baked into them, and the shared CI /
release workflows the extension repos call. User-facing: `README.md`, `docs/`.

## Layout

`toolbelt/` (tools), `docker/` (image definitions) and `packages/` (schemas)
are baked into the images; `scaffold/` and `scripts/` run on the host only. The build context is the repo root.

## Verify

`make test` and `make lint` need no Docker and are literally what
`.github/workflows/lint.yml` runs. Run both before committing. `make help` for
the rest; `make test-images` is the ~1 h Docker round.

## Rules

- **This repository is public.** Code, docs, fixtures and commit messages never
  name a client, a private extension or internal infrastructure, not even as an
  example. Use neutral names: `org.example.myext`, Acme, Widget, Greeter,
  Ledger. `extensions/` and `sites/` are gitignored local checkouts.
- **One parser per format.** `civikitchen.yaml` → `ckconform --policy-env` /
  `--policy <key>`; XML and JSON → `ck internal xml-field` / `json-field`
  (`ck_xml_field` in `toolbelt/lib/ckcommon.sh`). Never `sed`/`grep -o` a
  structured file. The one exception is `scaffold/ckx`: it runs on a host
  without PHP and reads only `info.xml`'s one-line `<file>`, and a miss stops it. A new public YAML key goes into the JSON Schema, and into
  `Policy::KEYS` if a consumer reads its normalized value.
- **Select files by what they are, not where they live.** A directory list
  fails open: the run says "clean" about files it never saw.
- **A fix ships with the fixture that would have failed.** Most checks here are
  silent on success.
- **Container paths are the interface** (`/usr/local/bin/ck*`,
  `/opt/civikitchen/`). The image mirrors the checkout under `/opt/civikitchen/`
  and the tools resolve everything from their own real path, so no code
  branches on image vs. checkout. A path a consumer config names moves only
  with a deprecation link and a `ckconform` warning, like the v1
  `/opt/civikitchen-*` links (removed in v2).
- **One versioned contract**: workflows, template, tools and images release
  together and consumers pin `@v1` (`docs/releases.md`).

## Traps

- In the *reusable* workflows (`extension-ci`, `extension-release`) `uses: ./…`
  resolves against the CALLING repo. Reach this repo by checking it out at
  `ref: ${{ job.workflow_sha }}` — never a floating ref.
- `a && b` as a statement under `set -e` aborts a loop. Use `if`.
- The `ck*` tools carry no `.sh` suffix; a `*.sh` sweep silently skips them all.
- `shellcheck` needs `external-sources` (`.shellcheckrc`) to see `ckcommon.sh`.
- `toolbelt/versions.env` holds only pins with two or more readers.
- `zizmor.yml` exemptions are anchored `file:line:col`. Editing a workflow
  above an exempted step shifts the line and the finding returns — re-pin it,
  never widen the entry to the whole file.
