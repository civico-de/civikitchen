#!/usr/bin/env bash
# The working_directory input ($1) normalized to a plain path below the checkout
# root ('.' for the root) that holds an info.xml. Run from the checkout root.
set -euo pipefail

input="${1-}"
case "${input}" in
  '') echo "working_directory is empty — '.' is the repository root" >&2; exit 1 ;;
  /*) echo "working_directory must be relative to the repository root (got: '${input}')" >&2; exit 1 ;;
esac
wd=${input#./}
wd=${wd%/}
wd=${wd:-.}
case "${wd}" in
  ..|../*|*/..|*/../*) echo "working_directory must stay inside the repository (got: '${input}')" >&2; exit 1 ;;
  *//*|*/./*) echo "working_directory must be a plain relative path (got: '${input}')" >&2; exit 1 ;;
esac
# The value becomes a container path in `cd`, a compose path and a
# $GITHUB_OUTPUT line; ckinit refuses whitespace in a directory name too.
[ "${wd}" = . ] || [[ "${wd}" =~ ^[A-Za-z0-9._][A-Za-z0-9._/-]*$ ]] \
  || { echo "working_directory may hold only letters, digits, '.', '_', '-' and '/' (got: '${input}')" >&2; exit 1; }
[ -d "${wd}" ] \
  || { echo "working_directory '${input}' is not a directory in this repository" >&2; exit 1; }
[ -f "${wd}/info.xml" ] \
  || { echo "no info.xml in '${input}' — this is not a CiviCRM extension" >&2; exit 1; }
echo "${wd}"
