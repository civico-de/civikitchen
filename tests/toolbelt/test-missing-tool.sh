#!/usr/bin/env bash
set -euo pipefail

# A login shell resets PATH (/etc/profile drops what the image prepends), and
# proc_open then fails with a raw PHP warning about posix_spawn that reads like a
# memory or seccomp problem. The tools must name the missing program instead.

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work=$(mktemp -d)
trap '/bin/rm -rf "$work"' EXIT

php_dir=$(dirname "$(command -v php)")
git_dir=$(dirname "$(command -v git)")

mkdir -p "$work/ext"
cat > "$work/ext/info.xml" <<'EOF'
<extension key="fixture" type="module"><file>fixture</file></extension>
EOF
printf '<?php\n' > "$work/ext/keep.php"
(
  cd "$work/ext"
  git init -q
  git add .
  git -c user.name=Test -c user.email=test@example.test commit -qm fixture
)
printf '<?php\n// changed\n' > "$work/ext/keep.php"

status=0
mkdir -p "$work/core"
touch "$work/core/Civi.php"
err=$(cd "$work/ext" && PATH="$php_dir:$git_dir:/usr/bin:/bin" CIVICRM_CORE_DIR="$work/core" \
  "$root/toolbelt/bin/ckcompat" --php 8.1 2>&1 >/dev/null) || status=$?
test "$status" -ne 0
# The program that is missing, and the PATH it was looked for on.
grep -q 'ck: mago was not found on PATH (' <<<"$err"
if grep -q 'proc_open' <<<"$err"; then exit 1; fi

echo 'ok   a tool missing from PATH is named instead of leaking a proc_open warning'
