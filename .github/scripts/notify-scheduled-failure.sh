#!/usr/bin/env bash
# Open (or bump) a labelled issue in $REPO pointing at $RUN_URL. Needs $GH_TOKEN.
# Defaults describe the image rebuild; override LABEL/LABEL_DESCRIPTION/TITLE/BODY/AGAIN.
set -euo pipefail

LABEL="${LABEL:-ci-scheduled-failure}"
LABEL_DESCRIPTION="${LABEL_DESCRIPTION:-Weekly image rebuild failed}"
TITLE="${TITLE:-Scheduled image build is failing}"
BODY="${BODY:-$(printf 'The weekly image rebuild failed: %s\n\nThe stable tags keep serving the last good images (test-then-promote), but no new CiviCRM release reaches users until this is fixed.' "$RUN_URL")}"
AGAIN="${AGAIN:-The scheduled image build failed again: $RUN_URL}"

gh label create "$LABEL" --repo "$REPO" \
  --description "$LABEL_DESCRIPTION" --color B60205 2>/dev/null || true
existing=$(gh issue list --repo "$REPO" --label "$LABEL" \
  --state open --json number --jq '.[0].number // empty')
if [ -n "$existing" ]; then
  gh issue comment "$existing" --repo "$REPO" --body "$AGAIN"
else
  gh issue create --repo "$REPO" --label "$LABEL" --title "$TITLE" --body "$BODY"
fi
