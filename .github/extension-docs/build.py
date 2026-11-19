"""Build an extension's manual into a Zensical site, one per language.

usage: build.py <extension-dir> <site-dir> <lang> [<lang> ...]

The manual is docs/handbook/, or the directory the info.xml tag
handbook:dir=<name> names, with its subdirectories; a subdirectory with pages
is a section. The first language is the default: page.md, served at the site root. Every
further language is built into <site-dir>/<lang>/ from page.<lang>.md where it
exists and page.md where not, the fallback the handbook extension applies in
CiviCRM. Title, order and section come from each page's frontmatter, and a
variant inherits the keys it leaves out from page.md, as there. Sections follow
the extension: a subdirectory's section is its index.md's section or title,
else its name; that page comes first and places the section. Heading ids
follow the extension's renderer, so a page.md#anchor link works in both.
Runs under the Python that has zensical installed.
"""

import os
import re
import shutil
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import unquote, urlsplit

import yaml

LFS_POINTER = b"version https://git-lfs.github.com/spec/v1"
LANG = re.compile(r"^[a-z]{2}(_[A-Z]{2})?$")
LANG_NAMES = {"en": "English", "de": "Deutsch", "fr": "Français", "es": "Español", "it": "Italiano", "nl": "Nederlands"}
# The theme's indigo accent colours the active navigation link below 4.5:1 in both schemes.
CONTRAST_CSS = """[data-md-color-scheme="default"] { --md-accent-fg-color: #3949ab; --md-accent-fg-color--transparent: #3949ab1a; }
[data-md-color-scheme="slate"] { --md-accent-fg-color: #8c9eff; --md-accent-fg-color--transparent: #8c9eff1a; }
.md-typeset video { max-width: 100%; height: auto; }
"""


class References(HTMLParser):
    """The href, src, srcset and poster URLs of a built page."""

    def __init__(self):
        super().__init__()
        self.urls = []

    def handle_starttag(self, tag, attrs):
        self.urls += [value for name, value in attrs if name in ("href", "src", "poster") and value]
        # A srcset candidate's URL runs to the next whitespace, so a data: URL keeps its comma.
        self.urls += [url.rstrip(",") for name, value in attrs if name == "srcset" and value
                      for url in re.findall(r"(?:^|(?<=,))\s*([^\s,]\S*)", value)]


def dangling(site):
    """Relative references in the built site whose file is missing; --strict checks only page links."""
    missing = []
    for page in sorted(site.rglob("*.html")):
        parser = References()
        parser.feed(page.read_text(encoding="utf-8"))
        for url in parser.urls:
            parts = urlsplit(url)
            if parts.scheme or parts.netloc or url.startswith(("/", "#")) or not parts.path:
                continue
            target = (page.parent / unquote(parts.path)).resolve()
            if not (target.is_file() or (target / "index.html").is_file()):
                missing.append(f"{page.relative_to(site).as_posix()} → {url}")
    return missing


def die(message):
    print(f"extension-docs: {message}", file=sys.stderr)
    sys.exit(1)


class Slugify:
    """Stands for slug.slugify in the generated mkdocs.yml."""


yaml.SafeDumper.add_representer(Slugify, lambda dumper, _: dumper.represent_scalar("tag:yaml.org,2002:python/name:slug.slugify", ""))


def frontmatter(path):
    """The page's frontmatter, with its first # heading as title when it sets none."""
    text = path.read_text(encoding="utf-8")
    match = re.match(r"^---\n(.*?)\n---\n", text, re.S)
    data = yaml.safe_load(match.group(1)) if match else {}
    if not isinstance(data, dict):
        die(f"{path}: frontmatter is not a mapping")
    if "title" not in data:
        heading = re.search(r"^# (.+)$", text, re.M)
        if heading:
            data["title"] = heading.group(1).strip()
    return data


def page_meta(source, variants, lang):
    """A variant inherits every key it leaves out from the default page, the title last of all."""
    default = frontmatter(source / variants[None])
    meta = {**default, **frontmatter(source / variants[lang])} if lang in variants else default
    meta.setdefault("title", Path(variants[None]).stem)
    return meta


def split_name(name, languages):
    """('events', 'de') for events.de.md, ('events', None) for events.md."""
    stem = name[:-3]
    base, _, suffix = stem.rpartition(".")
    if base and LANG.match(suffix):
        if suffix not in languages:
            die(f"{name}: language {suffix} is not built; add it to the languages input or rename the file")
        return base, suffix
    return stem, None


def sort_key(meta):
    order = meta.get("order")
    return (0 if isinstance(order, int) else 1, order if isinstance(order, int) else 0, str(meta["title"]).casefold())


def nav(pages, lang, source):
    """Landing page first, then by order and title. A section starts with its subdirectory's
    index.md and sorts where it does, else where its first page sorts."""
    metas = {stem: page_meta(source, variants, lang) for stem, variants in pages.items()}
    dir_sections = {}
    for stem, meta in metas.items():
        folder, _, leaf = stem.rpartition("/")
        if folder and folder not in dir_sections:
            dir_sections[folder] = folder.rpartition("/")[2]
        if folder and leaf == "index":
            dir_sections[folder] = str(meta.get("section") or meta["title"]).strip()
    singles, groups = [], {}
    for stem, meta in metas.items():
        if stem == "index":
            continue
        folder = stem.rpartition("/")[0]
        section = str(meta.get("section") or "").strip() or dir_sections.get(folder, "")
        entry = ((0 if folder and stem.endswith("/index") else 1, sort_key(meta)), {str(meta["title"]): f"{stem}.md"})
        if section:
            groups.setdefault(section, []).append(entry)
        else:
            singles.append((sort_key(meta), entry[1]))
    placed = list(singles)
    for section, entries in groups.items():
        entries.sort(key=lambda e: e[0])
        placed.append((entries[0][0][1], {section: [item for _, item in entries]}))
    placed.sort(key=lambda e: e[0])
    out = [{metas["index"]["title"]: "index.md"}] if "index" in metas else []
    return out + [item for _, item in placed]


def manual_dir(ext, info):
    """docs/handbook, or the one directory the handbook:dir= tag names, as the extension reads it."""
    declared = [t.text[len("handbook:dir="):] for t in info.iter("tag") if (t.text or "").startswith("handbook:dir=")]
    if len(declared) > 1:
        die(f"info.xml declares more than one handbook directory: {', '.join(declared)}")
    source = (ext / (declared[0] if declared else "docs/handbook")).resolve()
    if source == ext or ext not in source.parents or not source.is_dir():
        die(f"{source} is not a directory inside the extension")
    return source


def markdown(source):
    """Markdown files below source, without hidden files and directories."""
    return [p for p in sorted(source.rglob("*.md"))
            if not any(part.startswith(".") for part in p.relative_to(source).parts)]


def main():
    if len(sys.argv) < 4:
        die("usage: build.py <extension-dir> <site-dir> <lang> [<lang> ...]")
    ext, site, languages = Path(sys.argv[1]).resolve(), Path(sys.argv[2]).resolve(), sys.argv[3:]
    for lang in languages:
        if not LANG.match(lang):
            die(f"{lang}: not a language code like de or de_DE")
    info = ET.parse(ext / "info.xml").getroot()
    source = manual_dir(ext, info)
    name = (info.findtext("name") or info.get("key") or ext.name).strip()
    description = " ".join((info.findtext("description") or "").split())

    pages = {}
    for path in markdown(source):
        relative = path.relative_to(source)
        stem, lang = split_name(relative.name, languages[1:])
        folder = relative.parent.as_posix()
        pages.setdefault(stem if folder == "." else f"{folder}/{stem}", {})[lang] = relative.as_posix()
    # The site serves page.md and page/index.md both at page/.
    clashes = [f"{stem}.md" for stem in pages if f"{stem}/index" in pages]
    if clashes:
        die(f"pages that the site would serve at one address as their directory's index.md: {', '.join(clashes)}")
    orphans = [v for p in pages.values() if None not in p for v in p.values()]
    if orphans:
        die(f"translations without a default page: {', '.join(orphans)}")
    if not pages:
        die(f"{source} holds no Markdown pages")
    # A checkout without Git LFS holds pointer files where the images should be.
    pointers = [str(p.relative_to(source)) for p in sorted(source.rglob("*"))
                if p.is_file() and p.stat().st_size < 1024 and p.read_bytes().startswith(LFS_POINTER)]
    if pointers:
        die(f"Git LFS pointers instead of files (check out with lfs: true): {', '.join(pointers)}")

    # The handbook's own logo stands in the header and serves as favicon.
    logo = next((f"images/logo.{ext}" for ext in ("svg", "png") if (source / "images" / f"logo.{ext}").is_file()), None)

    repo = os.environ.get("GITHUB_REPOSITORY", "")
    owner, _, repo_name = repo.partition("/")
    site_url = f"https://{owner}.github.io/{repo_name}/" if repo else None
    if site.exists():
        shutil.rmtree(site)
    with tempfile.TemporaryDirectory() as tmp:
        for index, lang in enumerate(languages):
            docs = Path(tmp) / lang / "docs"
            # Images and other assets are shared; pages are the language's variant.
            shutil.copytree(source, docs, ignore=shutil.ignore_patterns("*.md", ".*"))
            for stem, variants in pages.items():
                shutil.copyfile(source / variants.get(lang, variants[None]), docs / f"{stem}.md")
            (docs / "stylesheets").mkdir(exist_ok=True)
            (docs / "stylesheets" / "civikitchen-contrast.css").write_text(CONTRAST_CSS)
            prefix = "" if index == 0 else f"{lang}/"
            config = {
                "site_name": name,
                "site_description": description,
                "docs_dir": "docs",
                "site_dir": "site",
                "extra_css": ["stylesheets/civikitchen-contrast.css"],
                "nav": nav(pages, lang, source),
                "theme": {
                    "language": lang.split("_")[0],
                    # A web font swapping in late widens code blocks after the theme decided which scroll,
                    # leaving them without tabindex; system fonts never swap.
                    "font": False,
                    "features": ["content.code.copy", "navigation.footer", "navigation.top", "search.highlight", "toc.follow"],
                    "palette": [
                        {"media": "(prefers-color-scheme: light)", "scheme": "default", "primary": "indigo", "accent": "indigo",
                         "toggle": {"icon": "lucide/sun", "name": "Switch to dark mode"}},
                        {"media": "(prefers-color-scheme: dark)", "scheme": "slate", "primary": "indigo", "accent": "indigo",
                         "toggle": {"icon": "lucide/moon", "name": "Switch to light mode"}},
                    ],
                },
                "markdown_extensions": {"tables": {}, "toc": {"permalink": True, "slugify": Slugify()}, "pymdownx.highlight": {},
                                        "pymdownx.inlinehilite": {}, "pymdownx.superfences": {}, "video": {}},
            }
            if logo:
                config["theme"].update(logo=logo, favicon=logo)
            if site_url:
                config["site_url"] = site_url + prefix
                config["repo_url"] = f"https://github.com/{repo}"
                config["repo_name"] = repo
            if len(languages) > 1:
                config["extra"] = {"alternate": [
                    {"name": LANG_NAMES.get(other.split("_")[0], other), "link": (site_url or "/") + ("" if i == 0 else f"{other}/"), "lang": other.split("_")[0]}
                    for i, other in enumerate(languages)]}
            config_file = Path(tmp) / lang / "mkdocs.yml"
            config_file.write_text(yaml.safe_dump(config, allow_unicode=True, sort_keys=False))
            # Zensical keeps site_dir inside the config's directory; PYTHONPATH lets it import slug.py and video.py,
            # without leaving a __pycache__ in the action checkout.
            env = {**os.environ, "PYTHONPATH": str(Path(__file__).resolve().parent), "PYTHONDONTWRITEBYTECODE": "1"}
            subprocess.run([sys.executable, "-m", "zensical", "build", "--strict", "-f", str(config_file)], check=True, env=env)
            shutil.copytree(Path(tmp) / lang / "site", site / prefix, dirs_exist_ok=True)
    missing = dangling(site)
    if missing:
        die("missing files behind relative references (built page → reference):\n  " + "\n  ".join(missing))


if __name__ == "__main__":
    main()
