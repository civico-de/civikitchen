#!/usr/bin/env bash
# scaffold/ck: the container it hands ck to, through a fake docker that runs the
# command on the host, so ck playwright really executes behind it.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
work="$(cd "$(mktemp -d)" && pwd -P)"
trap '/bin/rm -rf "$work"' EXIT

fail() { echo "ck wrapper: $*" >&2; exit 1; }

mkdir -p "$work/bin"
cat > "$work/bin/docker" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$FAKE_DOCKER_LOG"
case "$1 $2" in
  "image inspect") [[ -z "${FAKE_IMAGE_MISSING:-}" || "$3" != civikitchen-ck:* ]] ;;
  "run --rm")
    if [[ " $* " == *" --entrypoint stat "* ]]; then echo 991; exit 0; fi
    # The wrapper's container: run its command here, where every mount sits at its own path.
    for ((i = 1; i <= $#; i++)); do
      if [[ "${!i}" == civikitchen-ck:* ]]; then exec "${@:i+1}"; fi
    done
    ;;
esac
EOF
chmod +x "$work/bin/docker"
export PATH="$work/bin:$PATH" FAKE_DOCKER_LOG="$work/docker.log"

# Refused before Docker: other ck commands belong in the dev stack.
if "$root/scaffold/ck" > /dev/null 2>&1; then fail "a bare ck must fail"; fi
if "$root/scaffold/ck" lint > /dev/null 2> "$work/err"; then fail "ck lint must fail"; fi
grep -qF 'ckx ck lint' "$work/err" || fail "no pointer to ckx: $(cat "$work/err")"
"$root/scaffold/ck" --help | grep -qF 'ck playwright npm run test:e2e' || fail "--help prints no usage"
test ! -e "$FAKE_DOCKER_LOG" || fail "a refused command reached Docker"

# A project outside $HOME: home, checkout and work tree are mounted at their own paths.
mkdir -p "$work/home" "$work/tree/.git" "$work/tree/e2e"
printf '{}\n' > "$work/tree/e2e/package.json"
printf '{}\n' > "$work/tree/e2e/package-lock.json"
(cd "$work/tree/e2e" && HOME="$work/home" FAKE_IMAGE_MISSING=1 CK_PROBE=1 DOCKER_HOST=tcp://elsewhere:2375 \
  LANG=C.UTF-8 LC_ALL=C.UTF-8 "$root/scaffold/ck" playwright npm run test:e2e) > /dev/null
log="$(cat "$FAKE_DOCKER_LOG")"
[[ "$log" == *"build -t civikitchen-ck:"* ]] || fail "a missing image is not built"
wrapper="$(grep -E '^run .* civikitchen-ck:' "$FAKE_DOCKER_LOG" | grep -vF -- '--entrypoint stat')"
for expected in "-v $work/home:$work/home" "-v $root:$root" "-v $work/tree:$work/tree" "-w $work/tree/e2e" \
    "--group-add 991" "-e HOME=/tmp" "-e CK_PROBE" "php $root/toolbelt/bin/ck playwright npm run test:e2e"; do
  [[ "$wrapper" == *" $expected"* ]] || fail "the container lacks '$expected': $wrapper"
done
for unexpected in "-e PATH" "-e DOCKER_HOST" "-e LANG" "-e LC_ALL" "-t"; do
  [[ "$wrapper " != *" $unexpected "* ]] || fail "the container gets '$unexpected': $wrapper"
done
grep -qE "^run .* -v $work/tree:$work/tree .*civikitchen-playwright:[0-9a-f]{12} npm run test:e2e$" "$FAKE_DOCKER_LOG" \
  || fail "ck playwright did not run behind the wrapper: $log"

# Inside $HOME the work tree needs no mount of its own, and a built image is reused.
: > "$FAKE_DOCKER_LOG"
(cd "$work/tree/e2e" && HOME="$work" "$root/scaffold/ck" playwright npm run test:e2e) > /dev/null
! grep -qF 'build ' "$FAKE_DOCKER_LOG" || fail "an existing image is rebuilt"
wrapper="$(grep -E '^run .* civikitchen-ck:' "$FAKE_DOCKER_LOG" | grep -vF -- '--entrypoint stat')"
[[ "$wrapper" != *"-v $work/tree:"* ]] || fail "a work tree inside \$HOME is mounted twice: $wrapper"

# ck make runs make itself in the container, not ck.
printf '#!/bin/sh\necho "make $*"\n' > "$work/bin/make"
chmod +x "$work/bin/make"
: > "$FAKE_DOCKER_LOG"
out="$(cd "$root" && "$root/scaffold/ck" make test lint)"
[ "$out" = "make test lint" ] || fail "ck make ran '$out', not make test lint"
grep -qE "^run .* -w $root .*civikitchen-ck:[0-9]+ make test lint$" "$FAKE_DOCKER_LOG" \
  || fail "ck make is not make in the container: $(cat "$FAKE_DOCKER_LOG")"

# One docker CLI for both images.
cli() { sed -n 's/^FROM \(docker:[^ ]*-cli\).*/\1/p' "$1"; }
[ "$(cli "$root/scaffold/ck.Dockerfile")" = "$(cli "$root/toolbelt/playwright/Dockerfile")" ] \
  || fail "scaffold/ck.Dockerfile and toolbelt/playwright/Dockerfile pin different docker CLIs"

echo "ck wrapper: ok"
