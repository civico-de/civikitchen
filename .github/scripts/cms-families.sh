#!/usr/bin/env bash
# The CMS families build-cms-images.yml builds, minus those named in $SKIP
# (comma-separated): one table, written as the outputs its matrices read.
set -euo pipefail

table='[
  {"name": "drupal10",  "site_type": "drupal10-demo", "path": "/civicrm"},
  {"name": "drupal11",  "site_type": "drupal11-demo", "path": "/civicrm"},
  {"name": "wordpress", "site_type": "wp-demo",       "path": "/wp-admin/admin.php?page=CiviCRM"},
  {"name": "joomla",    "site_type": "joomla-demo",   "path": "/administrator/index.php?option=com_civicrm"},
  {"name": "backdrop",  "site_type": "backdrop-demo", "path": "/civicrm"}
]'
profiles='["", "verein", "fundraising", "events", "mailing"]'

skip=$(jq -cn --arg s "${SKIP:-}" '$s | split(",") | map(gsub("^\\s+|\\s+$"; "")) | map(select(. != ""))')
unknown=$(jq -cn --argjson t "$table" --argjson s "$skip" '$s - ($t | map(.name))')
if [ "$unknown" != '[]' ]; then
  echo "::error::skip names unknown CMS families: $unknown" >&2
  exit 1
fi
kept=$(jq -c --argjson s "$skip" 'map(select(.name as $n | $s | index($n) | not))' <<<"$table")
if [ "$kept" = '[]' ]; then
  echo "::error::skip leaves no CMS family to build" >&2
  exit 1
fi

{
  echo "families=$(jq -c 'map(.name)' <<<"$kept")"
  echo "buildkit=$(jq -c 'map({name, site_type})' <<<"$kept")"
  echo "e2e=$(jq -c 'map({name, compose: "examples/\(.name)/docker-compose.yml", path})' <<<"$kept")"
  echo "demo_variants=$(jq -c 'map({name: "\(.name)-demo", site_type})' <<<"$kept")"
  echo "demo_smoke=$(jq -c --argjson p "$profiles" '[.[] as $f | $p[] | {tag: "\($f.name)-demo", profile: .}]' <<<"$kept")"
} >> "$GITHUB_OUTPUT"
