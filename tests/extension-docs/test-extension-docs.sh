#!/bin/bash
# extension-docs.py: one site per language, translations falling back to the
# default page, nav from frontmatter.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
version=${CK_ZENSICAL_VERSION:?set CK_ZENSICAL_VERSION}
script="$root/.github/extension-docs/build.py"
fixture="$root/tests/extension-docs/fixture"
work=$(mktemp -d)
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
build() {
  if command -v uvx >/dev/null 2>&1; then uvx --from "zensical@$version" python "$script" "$@"
  else pipx run --spec "zensical==$version" python "$script" "$@"; fi
}

GITHUB_REPOSITORY=acme/greeter build "$fixture" "$work/site" en de >/dev/null 2>&1 \
  || fail "the fixture does not build"
site=$work/site
grep -q '<html lang="en"' "$site/index.html" || fail "the root is not English"
grep -q 'fonts.googleapis' "$site/index.html" && fail "the site loads a web font"
grep -q '<html lang="de"' "$site/de/index.html" || fail "de/ is not German"
grep -q 'Installieren' "$site/de/setup/index.html" || fail "de/ does not use setup.de.md"
grep -q 'Use it' "$site/de/usage/index.html" || fail "a page without translation does not fall back"
grep -q 'Grundlagen' "$site/de/index.html" || fail "the German nav does not take the translated section"
[ -f "$site/de/images/shot.png" ] || fail "images are not shared with de/"
[ -d "$site/notes.v1.2" ] || fail "a dotted name that is no language suffix is not a page"
[ ! -e "$site/setup.de" ] && [ ! -e "$site/de/setup.de" ] || fail "a translation is built as a page of its own"
grep -q 'href="https://acme.github.io/greeter/de/" hreflang="de"' "$site/index.html" || fail "no language switch to de/"
# Setup sorts before Usage by order, both under one section.
nav=$(grep -o 'Setup\|Usage' "$site/index.html" | head -2 | tr '\n' ' ')
[ "$nav" = "Setup Usage " ] || fail "nav order is '$nav'"

mkdir -p "$work/plain/handbook"
cp "$fixture/info.xml" "$work/plain/"
cp "$fixture/handbook/usage.md" "$work/plain/handbook/"
printf '# Greeter\n\nSee [usage](usage.md) and [setup](setup.md).\n' > "$work/plain/handbook/index.md"
build "$work/plain" "$work/dangling" en >/dev/null 2>&1 && fail "a link to a missing page builds"
printf '# Greeter\n\nSee [usage](usage.md).\n' > "$work/plain/handbook/index.md"
build "$work/plain" "$work/one" en >/dev/null 2>&1 || fail "one language does not build"
grep -q 'hreflang' "$work/one/index.html" && fail "one language shows a language switch"

# Heading ids as the handbook extension renders them: umlauts kept, dots and underscores dropped.
mkdir -p "$work/ids/handbook"
cp "$fixture/info.xml" "$work/ids/"
printf '# Ids\n\n## Größe & Maße\n\n## `pulse.host_status`\n' > "$work/ids/handbook/ids.md"
printf '# Greeter\n\n[a](ids.md#größe--maße) [b](ids.md#pulsehoststatus)\n' > "$work/ids/handbook/index.md"
build "$work/ids" "$work/i" en >/dev/null 2>&1 || fail "links to the extension's heading ids do not resolve"
printf '# Greeter\n\n[b](ids.md#pulsehost_status)\n' > "$work/ids/handbook/index.md"
build "$work/ids" "$work/i2" en >/dev/null 2>&1 && fail "a heading id the extension never renders resolves"

# A translation inherits order and title from its default page.
mkdir -p "$work/inherit/handbook"
cp "$fixture/info.xml" "$work/inherit/"
printf '# Greeter\n' > "$work/inherit/handbook/index.md"
printf -- '---\ntitle: Zebra\norder: 1\n---\nZ\n' > "$work/inherit/handbook/zebra.md"
printf 'Z de\n' > "$work/inherit/handbook/zebra.de.md"
printf -- '---\ntitle: Apple\norder: 2\n---\nA\n' > "$work/inherit/handbook/apple.md"
printf -- '---\ntitle: Apfel\n---\nA de\n' > "$work/inherit/handbook/apple.de.md"
build "$work/inherit" "$work/n" en de >/dev/null 2>&1 || fail "the inheritance fixture does not build"
nav=$(grep -o 'Zebra\|Apfel' "$work/n/de/index.html" | head -2 | tr '\n' ' ')
[ "$nav" = "Zebra Apfel " ] || fail "German nav order is '$nav'"

mkdir -p "$work/orphan/handbook"
cp "$fixture/info.xml" "$work/orphan/"
printf '# A\n' > "$work/orphan/handbook/index.md"
printf '# B\n' > "$work/orphan/handbook/extra.de.md"
build "$work/orphan" "$work/o" en de 2>"$work/err" && fail "a translation without default page builds"
grep -q 'extra.de.md' "$work/err" || fail "the orphan translation is not named"
build "$fixture" "$work/x" en 2>"$work/err" && fail "an unbuilt language builds"
grep -q 'language de is not built' "$work/err" || fail "the unbuilt language is not named"

mkdir -p "$work/lfs/handbook/images"
cp "$fixture/info.xml" "$work/lfs/"
printf '# Greeter\n' > "$work/lfs/handbook/index.md"
printf 'version https://git-lfs.github.com/spec/v1\noid sha256:4d7a\nsize 12345\n' > "$work/lfs/handbook/images/shot.png"
build "$work/lfs" "$work/l" en 2>"$work/err" && fail "a handbook with Git LFS pointers builds"
grep -q 'images/shot.png' "$work/err" || fail "the LFS pointer is not named"

echo "extension docs: ok"
