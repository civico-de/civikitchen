# ckcommon.sh — the plumbing every ck* tool needs, defined once.
#
# Sourced from ../lib relative to the tool's real path. Structured files are
# parsed elsewhere: `civikitchen.yaml` by ckconform, XML and JSON by php.
#
# shellcheck shell=bash

ck_tool="${ck_tool:-$(basename "$0")}"

# The toolbelt root, where every toolchain and config the tools use lives.
ck_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Exit 2 is "cannot run", distinct from 1 = "ran, found something". CI treats
# those differently; override with ck_exit.
ck_die() {
    echo "${ck_tool}: $*" >&2
    exit "${ck_exit:-2}"
}

ck_require_extension_root() {
    [ -f info.xml ] || ck_die "no info.xml here — run from the extension root."
}

# safe.directory per call, set to the worktree root: under dubious ownership
# `ls-files` silently reports nothing. Walked, because `rev-parse
# --show-toplevel` is refused too; `.git` is a file in a linked worktree.
ck_git_root() {
    local dir="$PWD"
    while [ -n "$dir" ]; do
        if [ -e "$dir/.git" ]; then printf '%s' "$dir"; return 0; fi
        [ "$dir" != / ] || break
        dir=$(dirname "$dir")
    done
    printf '%s' "$PWD"
}

ck_git() { git -c safe.directory="$(ck_git_root)" "$@"; }

# --- info.xml -----------------------------------------------------------------
# XML is parsed as XML: a line-oriented `tr | grep -o | sed` reads a <license>
# spanning two lines as empty and is blind to attributes.

# $1 = file, $2 = 'key' (the root attribute) or a child element name.
ck_xml_field() {
    "$ck_root/bin/ck" internal xml-field "$1" "$2"
}

