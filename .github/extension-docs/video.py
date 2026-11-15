"""A poster image linked to an https video file plays in place, as the handbook extension renders it.

The image's text names the player; a poster without text stays a plain link."""

import re
import xml.etree.ElementTree as etree

from markdown.extensions import Extension
from markdown.treeprocessors import Treeprocessor

VIDEO = re.compile(r"^https://[^/?#]+/[^?#]*\.(mp4|webm)([?#].*)?$", re.I)


class VideoTreeprocessor(Treeprocessor):
    def run(self, root):
        for parent in root.iter():
            for index, link in enumerate(list(parent)):
                poster = link[0] if link.tag == "a" and len(link) == 1 else None
                if (poster is None or poster.tag != "img" or (link.text or "").strip() or (poster.tail or "").strip()
                        or not VIDEO.match(link.get("href", ""))):
                    continue
                label = poster.get("alt", "").strip()
                if not label:
                    continue
                video = etree.Element("video", {"controls": "controls", "preload": "none", "playsinline": "playsinline",
                                                "poster": poster.get("src", ""), "src": link.get("href"), "aria-label": label})
                fallback = etree.SubElement(video, "a", {"href": link.get("href")})
                fallback.text = label
                video.tail = link.tail
                parent.remove(link)
                parent.insert(index, video)


class VideoExtension(Extension):
    def extendMarkdown(self, md):
        # Last, so the poster takes the image source after the site rewrote it relative to the page.
        md.treeprocessors.register(VideoTreeprocessor(md), "civikitchen_video", -100)


def makeExtension(**kwargs):
    return VideoExtension(**kwargs)
