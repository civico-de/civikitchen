#!/bin/bash
# extension-docs.py: one site per language, translations falling back to the
# default page, nav from frontmatter and subdirectories.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
version=${CK_ZENSICAL_VERSION:?set CK_ZENSICAL_VERSION}
script="$root/.github/extension-docs/build.py"
fixture="$root/tests/extension-docs/fixture"
work=$(mktemp -d)
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
# uvx is the Makefile's pinned one.
build() { uvx --from "zensical@$version" python "$script" "$@"; }

GITHUB_REPOSITORY=acme/greeter build "$fixture" "$work/site" en de >/dev/null 2>&1 \
  || fail "the fixture does not build"
site=$work/site
grep -q '<html lang="en"' "$site/index.html" || fail "the root is not English"
grep -q 'fonts.googleapis' "$site/index.html" && fail "the site loads a web font"
grep -q '<html lang="de"' "$site/de/index.html" || fail "de/ is not German"
grep -q '<img src="./images/logo.svg" alt="Greeter"' "$site/index.html" || fail "the handbook logo is not in the header"
grep -q '<link rel="icon" href="./images/logo.svg"' "$site/index.html" || fail "the handbook logo is not the favicon"
grep -q '<img src="../images/logo.svg"' "$site/de/setup/index.html" || fail "a German subpage does not show the logo"
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

mkdir -p "$work/plain/docs/handbook"
cp "$fixture/info.xml" "$work/plain/"
cp "$fixture/docs/handbook/usage.md" "$work/plain/docs/handbook/"
printf '# Greeter\n\nSee [usage](usage.md) and [setup](setup.md).\n' > "$work/plain/docs/handbook/index.md"
build "$work/plain" "$work/dangling" en >/dev/null 2>&1 && fail "a link to a missing page builds"
printf '# Greeter\n\nSee [usage](usage.md).\n\n![Gone](images/gone.png)\n' > "$work/plain/docs/handbook/index.md"
build "$work/plain" "$work/noimage" en 2>"$work/err" && fail "a missing image builds"
grep -q 'index.html → images/gone.png' "$work/err" || { cat "$work/err" >&2; fail "the missing image is not named"; }
mkdir -p "$work/plain/docs/handbook/images" && cp "$fixture/docs/handbook/images/shot.png" "$work/plain/docs/handbook/images/"
printf '# Greeter\n\nSee [usage](usage.md).\n\n<img src="images/shot.png" srcset="images/shot.png, images/shot@2x.png 2x" alt="Shot">\n' > "$work/plain/docs/handbook/index.md"
build "$work/plain" "$work/nosrcset" en 2>"$work/err" && fail "a missing srcset candidate builds"
grep -q 'images/shot@2x.png' "$work/err" || fail "the missing srcset candidate is not named"
printf '# Greeter\n\nSee [usage](usage.md).\n\n<img src="images/shot.png" srcset="data:image/png;base64,AAAA 1x,images/shot.png 2x" alt="Shot">\n' > "$work/plain/docs/handbook/index.md"
build "$work/plain" "$work/datasrcset" en 2>"$work/err" || { cat "$work/err" >&2; fail "a data: URL in srcset does not build"; }
printf '# Greeter\n\nSee [usage](usage.md) and the [form](files/form.pdf).\n' > "$work/plain/docs/handbook/index.md"
build "$work/plain" "$work/nofile" en 2>"$work/err" && fail "a link to a missing file builds"
grep -q 'files/form.pdf' "$work/err" || fail "the missing file is not named"
printf '# Greeter\n\nSee [usage](usage.md).\n' > "$work/plain/docs/handbook/index.md"
build "$work/plain" "$work/one" en >/dev/null 2>&1 || fail "one language does not build"
grep -q 'hreflang' "$work/one/index.html" && fail "one language shows a language switch"
grep -q 'logo.svg' "$work/one/index.html" && fail "a handbook without a logo names one"

# Heading ids as the handbook extension renders them: umlauts kept, dots and underscores dropped.
mkdir -p "$work/ids/docs/handbook"
cp "$fixture/info.xml" "$work/ids/"
printf '# Ids\n\n## Größe & Maße\n\n## `pulse.host_status`\n' > "$work/ids/docs/handbook/ids.md"
printf '# Greeter\n\n[a](ids.md#größe--maße) [b](ids.md#pulsehoststatus)\n' > "$work/ids/docs/handbook/index.md"
build "$work/ids" "$work/i" en >/dev/null 2>&1 || fail "links to the extension's heading ids do not resolve"
printf '# Greeter\n\n[b](ids.md#pulsehost_status)\n' > "$work/ids/docs/handbook/index.md"
build "$work/ids" "$work/i2" en >/dev/null 2>&1 && fail "a heading id the extension never renders resolves"

# A translation inherits order and title from its default page.
mkdir -p "$work/inherit/docs/handbook"
cp "$fixture/info.xml" "$work/inherit/"
printf '# Greeter\n' > "$work/inherit/docs/handbook/index.md"
printf -- '---\ntitle: Zebra\norder: 1\n---\nZ\n' > "$work/inherit/docs/handbook/zebra.md"
printf 'Z de\n' > "$work/inherit/docs/handbook/zebra.de.md"
printf -- '---\ntitle: Apple\norder: 2\n---\nA\n' > "$work/inherit/docs/handbook/apple.md"
printf -- '---\ntitle: Apfel\n---\nA de\n' > "$work/inherit/docs/handbook/apple.de.md"
build "$work/inherit" "$work/n" en de >/dev/null 2>&1 || fail "the inheritance fixture does not build"
nav=$(grep -o 'Zebra\|Apfel' "$work/n/de/index.html" | head -2 | tr '\n' ' ')
[ "$nav" = "Zebra Apfel " ] || fail "German nav order is '$nav'"

mkdir -p "$work/orphan/docs/handbook"
cp "$fixture/info.xml" "$work/orphan/"
printf '# A\n' > "$work/orphan/docs/handbook/index.md"
printf '# B\n' > "$work/orphan/docs/handbook/extra.de.md"
build "$work/orphan" "$work/o" en de 2>"$work/err" && fail "a translation without default page builds"
grep -q 'extra.de.md' "$work/err" || fail "the orphan translation is not named"
build "$fixture" "$work/x" en 2>"$work/err" && fail "an unbuilt language builds"
grep -q 'language de is not built' "$work/err" || fail "the unbuilt language is not named"

# A linked poster of an https video plays in place; its poster path stays relative to the page.
mkdir -p "$work/video/docs/handbook/images"
cp "$fixture/info.xml" "$work/video/"
cp "$fixture/docs/handbook/images/shot.png" "$work/video/docs/handbook/images/poster.png"
printf '# Greeter\n\nSee [usage](usage.md).\n' > "$work/video/docs/handbook/index.md"
{
  printf '# Usage\n\n[![Video: greeting](images/poster.png)](https://media.example.org/greet.mp4)\n\n'
  printf '[![Wave](images/poster.png)](https://media.example.org/wave.webm?v=2)\n\n'
  printf '[![Shot](images/poster.png)](https://media.example.org/greet.html)\n\n'
  printf '[![Host](images/poster.png)](https://media.example.mp4)\n\n'
  printf '[![](images/poster.png)](https://media.example.org/unnamed.mp4)\n'
} > "$work/video/docs/handbook/usage.md"
build "$work/video" "$work/v" en >/dev/null 2>&1 || fail "the video fixture does not build"
page=$work/v/usage/index.html
grep -q '<video[^>]* src="https://media.example.org/greet.mp4"' "$page" || fail "a linked video poster is no player"
grep -q '<video[^>]* poster="../images/poster.png"' "$page" || fail "the poster path is not relative to the page"
grep -q '<video[^>]* aria-label="Video: greeting"' "$page" || fail "the player has no label"
grep -q '<video[^>]* src="https://media.example.org/wave.webm?v=2"' "$page" || fail "a webm with a query is no player"
grep -q '<a href="https://media.example.org/greet.html"' "$page" || fail "a linked image of a page is no link"
grep -q '<a href="https://media.example.mp4"' "$page" || fail "a host ending in .mp4 is no link"
grep -q '<a href="https://media.example.org/unnamed.mp4"' "$page" || fail "a poster without text is no link"
[ "$(grep -c '<video' "$page")" = 2 ] || fail "a link that is no named video became a player"

mkdir -p "$work/lfs/docs/handbook/images"
cp "$fixture/info.xml" "$work/lfs/"
printf '# Greeter\n' > "$work/lfs/docs/handbook/index.md"
printf 'version https://git-lfs.github.com/spec/v1\noid sha256:4d7a\nsize 12345\n' > "$work/lfs/docs/handbook/images/shot.png"
build "$work/lfs" "$work/l" en 2>"$work/err" && fail "a handbook with Git LFS pointers builds"
grep -q 'images/shot.png' "$work/err" || fail "the LFS pointer is not named"

# Subdirectories are sections: the index.md's title names one and places it, else the directory's name.
n=$work/nested/docs/handbook
mkdir -p "$n/setup" "$n/faq" "$n/.drafts"
cp "$fixture/info.xml" "$work/nested/"
printf '# Greeter\n\nSee [rights](setup/rights.md).\n' > "$n/index.md"
printf -- '---\norder: 20\n---\n# Installation\n' > "$n/setup/index.md"
printf -- '---\norder: 1\n---\n# Rights\n\nBack to [the start](../index.md).\n' > "$n/setup/rights.md"
printf -- '---\nsection: Elsewhere\n---\n# Odd\n' > "$n/setup/odd.md"
printf -- '---\norder: 10\n---\n# Question\n' > "$n/faq/question.md"
printf '# Draft\n' > "$n/.drafts/draft.md"
build "$work/nested" "$work/ne" en >/dev/null 2>&1 || fail "the nested fixture does not build"
[ -f "$work/ne/setup/rights/index.html" ] || fail "a page in a subdirectory is not built at its path"
[ ! -e "$work/ne/.drafts" ] || fail "a hidden directory is built"
nav=$(grep -o -E '^ *(Question|faq|Installation|Rights|Elsewhere|Odd) *$' "$work/ne/index.html" | tr -d ' ' | awk '!seen[$0]++' | tr '\n' ' ')
[ "$nav" = "faq Question Installation Rights Elsewhere Odd " ] || fail "nested nav is '$nav'"

printf '# Setup page\n' > "$n/setup.md"
build "$work/nested" "$work/nc" en 2>"$work/err" && fail "a page beside its directory's index.md builds"
grep -q 'setup.md' "$work/err" || fail "the clashing page is not named"

# The handbook:dir= tag names the manual, as the extension reads it.
mkdir -p "$work/tagged/manual"
sed 's#</extension>#<tags><tag>handbook:dir=manual</tag></tags></extension>#' "$fixture/info.xml" > "$work/tagged/info.xml"
printf '# Greeter\n' > "$work/tagged/manual/index.md"
build "$work/tagged" "$work/t" en >/dev/null 2>&1 || fail "a manual named by the tag does not build"
mkdir -p "$work/old/handbook"
cp "$fixture/info.xml" "$work/old/"
printf '# Greeter\n' > "$work/old/handbook/index.md"
build "$work/old" "$work/od" en 2>"$work/err" && fail "a manual in handbook/ builds without the tag"
grep -q 'docs/handbook is not a directory' "$work/err" || fail "the missing docs/handbook is not named"

echo "extension docs: ok"
