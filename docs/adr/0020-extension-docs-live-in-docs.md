# ADR-0020: An extension's documentation lives in docs/

*Status: accepted · 2026-11-18*

## Context

A published extension keeps its user manual in `handbook/` and its developer
documentation in `docs/`. The handbook extension shows `handbook/` inside
CiviCRM, `extension-docs.yml` builds it into the documentation site, and the
ckconform check `handbook` asks for `handbook/index.md`.

Two top-level directories for documentation confuse readers and contributors:
`docs/` is where GitHub, MkDocs and CiviCRM's own guide for extension authors
expect the documentation, and a reader who finds the developer pages there
does not look for a second directory. The manual also reads only the files
directly in its directory, so a long manual cannot be split into folders.

## Decision

All documentation of an extension lives in `docs/`:

- `docs/handbook/` is the user manual. The handbook extension shows it in
  CiviCRM, and the documentation site is built from it.
- Everything else in `docs/` is developer documentation: architecture,
  testing, reference, upstream bugs, and the decision records in `docs/adr/`.
- A topic is written once. The README links the manual's site and `docs/`.

The manual may have subdirectories:

- A subdirectory that holds Markdown pages is a section. Its name is the
  section's default title; an `index.md` in it sets the title and is the
  section's first page. A page's `section` key still overrides the title.
- A page's id carries its path below the manual's directory:
  `org.example.greeter/setup/install`. Links between pages and to images are
  relative to the linking page.
- A directory without Markdown, such as `images/`, is no section.

The handbook extension reads `docs/handbook/` by default. An extension can
still name another directory with the `handbook:dir=<name>` tag. `handbook/`
is not read anymore (no fallback).

## Consequences

- The handbook extension's release that switches the default is a breaking
  change for an extension that keeps a manual in `handbook/`. Its changelog
  names the move; such an extension can also keep its directory with the tag
  `handbook:dir=handbook`.
- Every extension with a `handbook/` moves it to `docs/handbook/` in one
  commit; its existing `docs/` files stay where they are.

## Migration

The handbook extension and civikitchen go first. Each step is its own commit
and release.

1. **handbook:** default directory `docs/handbook`, nested pages and
   sections, its own manual moved; a breaking release.
2. **civikitchen:**
   - `extension-docs.yml` / `build.py`: source `docs/handbook/`, nested pages,
     the same ids and sections as the extension.
   - ckconform `handbook`: asks for `docs/handbook/index.md`; any other check
     that names `handbook/` follows.
   - `extension-standards.md` and `reusable-workflows.md` describe
     `docs/handbook/`; the build and the check read the `handbook:dir=` tag
     as the extension does.
   - A `v1` tag move, since the extensions call `@v1`.
3. Tools that write into `handbook/images/` write into
   `docs/handbook/images/`.
4. **Each extension**, one commit: `git mv handbook docs/handbook`, the
   README's cover path and links, `docs.yml`'s `paths:` filter, then
   ckconform and the docs build.

Steps 2 and 4 land together: once `@v1` builds from `docs/handbook/`, the docs
build of an extension still on `handbook/` fails until it moves. A site shows
a moved extension's manual only once it runs the handbook release from step 1,
so that release reaches the sites before the extensions' next releases.

The documentation sites keep their addresses (`<org>.github.io/<repo>/`); a
page's address on the site gains its section path.
