#!/usr/bin/env bash
set -euo pipefail

# ck_git tells git which directory to trust. That has to be the worktree root:
# from an extension subdirectory of a multi-extension repo, a cwd guard leaves
# dubious ownership standing and ls-files then reports nothing at all.

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work=$(mktemp -d)
trap '/bin/rm -rf "$work"' EXIT

# A fake git that prints the safe.directory it was handed, so the value is
# assertable without an ownership mismatch, which needs root to stage.
mkdir -p "$work/bin"
cat > "$work/bin/git" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
while [ "$#" -gt 0 ]; do
  case "$1" in
    -c) shift; printf '%s\n' "$1" ;;
  esac
  shift
done
EOF
chmod +x "$work/bin/git"

probe() {
  # $1 = directory to run from
  (
    cd "$1"
    PATH="$work/bin:$PATH"
    # shellcheck source=toolbelt/lib/ckcommon.sh
    . "$root/toolbelt/lib/ckcommon.sh"
    ck_git rev-parse --is-inside-work-tree
  )
}

# A plain checkout: .git is a directory, the extension sits one level down.
mkdir -p "$work/repo/.git" "$work/repo/base/CRM" "$work/repo/addon/CRM"
got=$(probe "$work/repo/addon/CRM")
test "$got" = "safe.directory=$work/repo"

echo 'ok   ck_git trusts the worktree root from an extension subdirectory'

# A linked worktree: .git is a FILE, which a directory-only test would miss.
mkdir -p "$work/linked/addon"
printf 'gitdir: %s\n' "$work/repo/.git/worktrees/linked" > "$work/linked/.git"
got=$(probe "$work/linked/addon")
test "$got" = "safe.directory=$work/linked"

echo 'ok   ck_git finds a linked worktree root through its .git file'

# Nothing above is a checkout: the cwd stands in, so git keeps reporting
# "not a git checkout" instead of failing differently.
mkdir -p "$work/loose/sub"
got=$(probe "$work/loose/sub")
test "$got" = "safe.directory=$work/loose/sub"

echo 'ok   ck_git falls back to the cwd outside a checkout'
