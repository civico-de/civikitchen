#!/usr/bin/env bash
# Matrix legs of the shared extension CI workflow: every leg boots its own
# compose project and uploads under its own name (checker plus fixture, as the
# check is silent on success), the compat-images step turns the inputs into
# the compat, browser and core upgrade legs as written, and without
# matrix_playwright or matrix_core_upgrade those jobs keep their single leg on
# `image`.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow="$root/.github/workflows/extension-ci.yml"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
value() { php "$root/tests/parity/workflow-value.php" "$workflow" "$@"; }

# --- one compose project and one artifact name per leg ----------------------
check="$root/tests/parity/matrix-legs.php"
php "$check" "$root"/.github/workflows/*.yml "$root"/scaffold/template/extension/.github/workflows/*.yml \
  || fail "a matrix job shares a compose project or an artifact name between its legs"
out=$(php "$check" "$root/tests/parity/fixtures/workflow.matrix-legs-shared.yml" 2>&1) \
  && fail "checker passed legs that share a compose project and an artifact name"
for job in shared bait; do
  grep -qF "job '$job' boots one compose project for all its legs" <<<"$out" \
    || fail "checker did not report the shared project of job '$job' (got: $out)"
done
for job in shared bait partial; do
  grep -qF "job '$job' uploads an artifact under a name its legs share" <<<"$out" \
    || fail "checker did not report the shared artifact name of job '$job' (got: $out)"
done
for job in indexed hosted; do
  if grep -qF "'$job'" <<<"$out"; then fail "checker flagged job '$job', which tells its legs apart (got: $out)"; fi
done

# --- the legs, computed by the step as written --------------------------------
step='Parse matrix_images into the compat, browser and core upgrade legs'
php "$root/tests/parity/workflow-step.php" "$workflow" compat-images "$step" > "$work/step.sh"

# legs <matrix_images> <image> <playwright> <matrix_playwright>: runs the step (<image>: the key job's resolved image)
# with its own env: mapping, each expression resolved from these inputs.
# upgrade_from and matrix_upgrade stand for core_upgrade_from and matrix_core_upgrade.
upgrade_from=''
matrix_upgrade=false
legs() {
  local -A inputs=(
    ['${{ inputs.matrix_images }}']=$1
    ['${{ needs.key.outputs.image }}']=$2
    ['${{ inputs.playwright }}']=$3
    ['${{ inputs.matrix_playwright }}']=$4
    ['${{ inputs.core_upgrade_from }}']=$upgrade_from
    ['${{ inputs.matrix_core_upgrade }}']=$matrix_upgrade
  )
  local step_env=() name expression
  while IFS=$'\t' read -r name expression; do
    [ -n "${inputs[$expression]+set}" ] || fail "no fixture value for $name=$expression"
    step_env+=("$name=${inputs[$expression]}")
  done < <(php "$root/tests/parity/workflow-step.php" "$workflow" compat-images "$step" env)
  : > "$work/output"
  rc=0
  out=$(env "${step_env[@]}" GITHUB_OUTPUT="$work/output" bash --noprofile --norc -eo pipefail "$work/step.sh" 2>&1) || rc=$?
}
output() { sed -n "s/^$1=//p" "$work/output"; }

# expect <matrix_images> <image> <playwright> <matrix_playwright> <compat legs> <browser legs>
expect() {
  legs "$1" "$2" "$3" "$4"
  [ "$rc" = 0 ] || fail "inputs '$1' / '$2' / $3 / $4 refused (rc=$rc): $out"
  [ "$(output images)" = "$5" ] || fail "compat legs for '$1': $(output images), expected $5"
  [ "$(output playwright_images)" = "$6" ] \
    || fail "browser legs for '$1' / '$2' / $3 / $4: $(output playwright_images), expected $6"
}

# refuse <matrix_images> <image> <playwright> <matrix_playwright> <part of the message>
refuse() {
  legs "$1" "$2" "$3" "$4"
  [ "$rc" != 0 ] || fail "inputs '$1' / '$2' / $3 / $4 accepted"
  grep -qF -- "$5" <<<"$out" || fail "refusal of '$1' / '$2' / $3 / $4 does not say '$5': $out"
}

# The default run: no compat leg, one browser leg on `image` (empty: the compose default).
expect '' '' true false '[]' '[""]'
expect '' ex/run:1 true false '[]' '["ex/run:1"]'
# matrix_images alone adds compat legs, not browser legs.
expect ' ex/a:1 ,, ex/b:2 ' ex/run:1 true false '["ex/a:1","ex/b:2"]' '["ex/run:1"]'
# matrix_playwright adds a browser leg per matrix image, after the run's own.
expect 'ex/a:1, ex/b:2' ex/run:1 true true '["ex/a:1","ex/b:2"]' '["ex/run:1","ex/a:1","ex/b:2"]'
expect 'ex/a:1, ex/b:2' '' true true '["ex/a:1","ex/b:2"]' '["","ex/a:1","ex/b:2"]'
# The run's own image listed in the matrix as well gets one browser leg, not two.
expect 'ex/a:1, ex/run:1' ex/run:1 true true '["ex/a:1","ex/run:1"]' '["ex/run:1","ex/a:1"]'

refuse 'ex/a:1' '' false true 'playwright is off'
refuse '' '' true true 'matrix_images is empty'
refuse ' , ' '' true true 'matrix_images is empty'
refuse 'ex/a:1, ex/a:1' '' false false 'more than once'
refuse 'ex/a b' '' false false 'not an image reference'

# Core upgrade targets: the run's image alone, unless matrix_core_upgrade adds
# the matrix images that are neither it nor the image the upgrade starts from.
# upgrade_targets <matrix_images> <image> <core_upgrade_from> <matrix_core_upgrade> <targets>
upgrade_targets() {
  upgrade_from=$3 matrix_upgrade=$4
  legs "$1" "$2" false false
  [ "$rc" = 0 ] || fail "upgrade inputs '$1' / '$2' / '$3' / $4 refused (rc=$rc): $out"
  [ "$(output core_upgrade_images)" = "$5" ] \
    || fail "upgrade targets for '$1' / '$2' / '$3' / $4: $(output core_upgrade_images), expected $5"
  upgrade_from='' matrix_upgrade=false
}
upgrade_targets '' '' ex/old:1 false '[""]'
upgrade_targets 'ex/a:1, ex/b:2' ex/run:1 ex/old:1 false '["ex/run:1"]'
upgrade_targets 'ex/a:1, ex/b:2' ex/run:1 ex/old:1 true '["ex/run:1","ex/a:1","ex/b:2"]'
upgrade_targets 'ex/old:1, ex/run:1, ex/b:2' ex/run:1 ex/old:1 true '["ex/run:1","ex/b:2"]'
matrix_upgrade=true
refuse 'ex/a:1' '' false false 'core_upgrade_from is empty'
upgrade_from=ex/old:1
refuse '' '' false false 'matrix_images is empty'
upgrade_from='' matrix_upgrade=false

# --- the wiring around the step -------------------------------------------------
[ "$(value on workflow_call inputs matrix_playwright type)" = boolean ] || fail "matrix_playwright is not a boolean"
[ "$(value on workflow_call inputs matrix_playwright default)" = false ] || fail "matrix_playwright is not off by default"
[ "$(value jobs compat-images if)" = "\${{ inputs.matrix_images != '' || inputs.playwright || inputs.matrix_playwright || inputs.core_upgrade_from != '' || inputs.matrix_core_upgrade }}" ] \
  || fail "compat-images condition: $(value jobs compat-images if)"
[ "$(value jobs compat-images outputs playwright_images)" = '${{ steps.parse.outputs.playwright_images }}' ] \
  || fail "compat-images does not output the browser legs"
[ "$(value jobs compat if)" = "\${{ inputs.matrix_images != '' }}" ] \
  || fail "compat must skip itself without matrix_images: $(value jobs compat if)"
[ "$(value jobs playwright if)" = '${{ inputs.playwright }}' ] || fail "playwright condition: $(value jobs playwright if)"
[ "$(value jobs playwright strategy matrix image)" = '${{ fromJSON(needs.compat-images.outputs.playwright_images) }}' ] \
  || fail "the browser legs are not the ones compat-images computes"
[ "$(value jobs playwright env CIVIKITCHEN_IMAGE)" = '${{ matrix.image }}' ] || fail "a browser leg does not boot its own image"
[ "$(value jobs playwright strategy fail-fast)" = false ] || fail "one failing browser leg cancels the others"
# The leg on `image` uploads as playwright-<key>, the later legs add their index.
artifact=$(value jobs playwright steps 'Playwright report + traces on failure' with name)
[ "$artifact" = "playwright-\${{ needs.key.outputs.key }}\${{ strategy.job-index != 0 && format('-{0}', strategy.job-index) || '' }}" ] \
  || fail "browser artifact name: $artifact"

[ "$(value on workflow_call inputs matrix_core_upgrade type)" = boolean ] || fail "matrix_core_upgrade is not a boolean"
[ "$(value on workflow_call inputs matrix_core_upgrade default)" = false ] || fail "matrix_core_upgrade is not off by default"
[ "$(value jobs core-upgrade if)" = "\${{ inputs.core_upgrade_from != '' }}" ] || fail "core-upgrade condition: $(value jobs core-upgrade if)"
[ "$(value jobs core-upgrade strategy matrix image)" = '${{ fromJSON(needs.compat-images.outputs.core_upgrade_images) }}' ] \
  || fail "the upgrade targets are not the ones compat-images computes"
[ "$(value jobs core-upgrade env CIVIKITCHEN_IMAGE)" = '${{ matrix.image }}' ] || fail "an upgrade leg does not end on its own image"
[ "$(value jobs core-upgrade strategy fail-fast)" = false ] || fail "one failing upgrade leg cancels the others"

echo "matrix legs suite OK"
