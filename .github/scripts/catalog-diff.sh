#!/usr/bin/env bash
# Diff the regenerated catalogs, ignoring the CORE_VERSION and "Generated from"
# lines. Fails (with a job summary on $LATEST vs $PINNED) when a bump is due.
set -euo pipefail

if git diff --quiet -I "CORE_VERSION = '" -I 'Generated from CiviCRM ' ; then
  echo "### Catalog preview: no catalog-relevant changes up to ${LATEST} (pinned: ${PINNED})" \
    >> "$GITHUB_STEP_SUMMARY"
  exit 0
fi
{
  echo "### Catalog preview: ${LATEST} would change the catalogs (pinned: ${PINNED})"
  echo
  echo '```'
  git diff --stat -I "CORE_VERSION = '" -I 'Generated from CiviCRM '
  echo '```'
  echo
  echo "Time to regenerate from one release and bump the pins — see the drift gates in \`make test\`."
} >> "$GITHUB_STEP_SUMMARY"
git diff --stat -I "CORE_VERSION = '" -I 'Generated from CiviCRM '
exit 1
