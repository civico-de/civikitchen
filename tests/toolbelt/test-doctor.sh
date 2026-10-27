#!/usr/bin/env bash
# doctor's whole job is to be right about a host it does not run on, so every
# verdict is exercised against a synthesised one: PATH is replaced by a
# directory of stubs, which makes "absent" mean absent rather than "absent on
# the machine that happened to run the suite".
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

# Absolute, so the stub PATH cannot decide which bash interprets the script.
real_bash="$(command -v bash)"

# The utilities doctor itself uses. Resolved now, while PATH is still real.
real_awk="$(command -v awk)"
real_grep="$(command -v grep)"
real_head="$(command -v head)"
real_uname="$(command -v uname)"

# A host is a directory of stubs: $1 names it, then name=banner pairs.
host() {
  local name="$1" dir pair tool banner
  shift
  dir="$work/$name"
  mkdir -p "$dir"
  for pair in awk="$real_awk" grep="$real_grep" head="$real_head" uname="$real_uname"; do
    printf '#!/bin/sh\nexec %s "$@"\n' "${pair#*=}" > "$dir/${pair%%=*}"
    chmod +x "$dir/${pair%%=*}"
  done
  for pair in "$@"; do
    tool="${pair%%=*}"
    banner="${pair#*=}"
    printf '#!/bin/sh\necho "%s"\n' "$banner" > "$dir/$tool"
    chmod +x "$dir/$tool"
  done
  # php -m reads $dir/modules, which a case may rewrite.
  if [ -f "$dir/php" ]; then
    printf '%s\n' "${MODULES[@]}" > "$dir/modules"
    banner="$(sed -n 's/^echo "\(.*\)"$/\1/p' "$dir/php")"
    printf '#!/bin/sh\ncase "$1" in -m) exec %s "%s" ;; *) echo "%s" ;; esac\n' \
      "$(command -v cat)" "$dir/modules" "$banner" > "$dir/php"
  fi
  echo "$dir"
}

# Everything a complete host has; a case drops or overrides what it is about.
COMPLETE=(
  'make=GNU Make 4.4.1'
  'bash=GNU bash, version 5.2.15(1)-release'
  'php=PHP 8.3.10 (cli)'
  'phpdbg=PHP 8.3.10 (phpdbg)'
  'git=git version 2.45.0'
  'curl=curl 8.7.1'
  'zip=Zip 3.0 (July 5th 2008)'
  'unzip=UnZip 6.00 of 20 April 2009'
  'docker=Docker version 27.1.1'
)
# A Homebrew php -m, trimmed to the sections and what the suites load.
MODULES=('[PHP Modules]' Core ctype dom filter json libxml mbstring openssl Phar posix SimpleXML tokenizer xmlwriter zip '' '[Zend Modules]')

# COMPLETE without the entry for $1.
except() {
  local entry
  for entry in "${COMPLETE[@]}"; do
    [ "${entry%%=*}" = "$1" ] || printf '%s\n' "$entry"
  done
}

# Sets `out` and `status`. Not via command substitution: that runs in a
# subshell, where the exit code assignment would be discarded.
out=''
status=0
run_doctor() {
  local dir="$1"
  set +e
  PATH="$dir" "$real_bash" "$root/scripts/doctor.sh" > "$work/out" 2>&1
  status=$?
  set -e
  out="$(cat "$work/out")"
}

expect() { # label, needle, haystack
  case "$3" in
    *"$2"*) ;;
    *) fail "$1: expected '$2' in output:\n$3" ;;
  esac
}

refute() { # label, needle, haystack
  case "$3" in
    *"$2"*) fail "$1: did not expect '$2' in output:\n$3" ;;
  esac
}

# --- a complete host is silent about problems --------------------------------
# It has no composer, uv, pipx, Node or npm: the Makefile fetches those pinned.
run_doctor "$(host complete "${COMPLETE[@]}")"
[ "$status" -eq 0 ] || fail "complete host: expected exit 0, got $status"
refute 'complete host' 'MISSING' "$out"
for tool in composer uvx pipx npm; do
  refute 'complete host' "$tool" "$out"
done

# --- Apple's make with a modern gmake beside it is usable, not broken --------
run_doctor "$(host apple-make "${COMPLETE[@]}" 'make=GNU Make 3.81' 'gmake=GNU Make 4.4.1')"
[ "$status" -eq 0 ] || fail "apple make + gmake: expected exit 0, got $status"
expect 'apple make + gmake' 'WARN     make' "$out"
expect 'apple make + gmake' 'gmake is 4.4.1' "$out"

# --- Apple's make alone is a hard stop ---------------------------------------
run_doctor "$(host apple-make-only "${COMPLETE[@]}" 'make=GNU Make 3.81')"
[ "$status" -eq 1 ] || fail "apple make alone: expected exit 1, got $status"
expect 'apple make alone' 'MISSING  make' "$out"
expect 'apple make alone' '3.81 is too old' "$out"

# --- a missing interpreter is a hard stop ------------------------------------
mapfile -t without_php < <(except php)
run_doctor "$(host no-php "${without_php[@]}")"
[ "$status" -eq 1 ] || fail "no php: expected exit 1, got $status"
expect 'no php' 'MISSING  php' "$out"

# --- line coverage is a hard prerequisite of the fast PHP suite -------------
mapfile -t without_coverage < <(except phpdbg)
run_doctor "$(host no-coverage "${without_coverage[@]}")"
[ "$status" -eq 1 ] || fail "no coverage driver: expected exit 1, got $status"
expect 'no coverage driver' 'MISSING  coverage' "$out"

# --- a module list beyond a pipe buffer still yields its coverage driver ----
# grep -q exits on the first match; a producer still writing then dies of
# SIGPIPE, which pipefail reports as "no match".
long_modules="$(host long-modules "${without_coverage[@]}")"
{ echo pcov; printf '%s\n' "${MODULES[@]}"; "$real_awk" 'BEGIN { for (i = 0; i < 200000; i++) print "module_" i }'; } \
  > "$long_modules/modules"
run_doctor "$long_modules"
[ "$status" -eq 0 ] || fail "long module list: expected exit 0, got $status"
expect 'long module list' 'ok       coverage' "$out"

# --- PHP 8.0 cannot parse the tool belt --------------------------------------
run_doctor "$(host php80 "${COMPLETE[@]}" 'php=PHP 8.0.30 (cli)')"
[ "$status" -eq 1 ] || fail "php 8.0: expected exit 1, got $status"
expect 'php 8.0' '8.0.30 is too old, needs >= 8.1' "$out"

# --- Debian's php-cli without php-xml, php-mbstring and php-zip ---------------
php_cli_only="$(host php-cli-only "${COMPLETE[@]}")"
printf '%s\n' '[PHP Modules]' Core ctype filter json libxml openssl Phar posix tokenizer > "$php_cli_only/modules"
printf '#!/bin/sh\necho Linux\n' > "$php_cli_only/uname"
run_doctor "$php_cli_only"
[ "$status" -eq 1 ] || fail "php-cli only: expected exit 1, got $status"
expect 'php-cli only' 'MISSING  extensions php lacks dom mbstring simplexml xmlwriter zip' "$out"
expect 'php-cli only' 'apt install php-xml php-mbstring php-zip' "$out"

# --- the archive tools are hard prerequisites --------------------------------
for tool in zip unzip; do
  mapfile -t without < <(except "$tool")
  run_doctor "$(host "no-$tool" "${without[@]}")"
  [ "$status" -eq 1 ] || fail "no $tool: expected exit 1, got $status"
  expect "no $tool" "MISSING  $tool" "$out"
done

# --- Docker is the slow loop only: absent must not fail the fast one ---------
mapfile -t without_docker < <(except docker)
run_doctor "$(host no-docker "${without_docker[@]}")"
[ "$status" -eq 0 ] || fail "no docker: expected exit 0, got $status"
expect 'no docker' 'WARN     docker' "$out"

echo "doctor suite OK"
