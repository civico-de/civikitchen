#!/usr/bin/env bash
# Map the image variant ($VARIANT) to its civibuild site type and run the
# first-boot test of the candidate image ($IMG) against an external DB.
set -euo pipefail

case "$VARIANT" in
  drupal10)  SITE_TYPE=drupal10-demo ;;
  drupal11)  SITE_TYPE=drupal11-demo ;;
  wordpress) SITE_TYPE=wp-demo ;;
  joomla)    SITE_TYPE=joomla-demo ;;
  backdrop)  SITE_TYPE=backdrop-demo ;;
  *) echo "unknown variant: $VARIANT" >&2; exit 1 ;;
esac
bash tests/images/boot-test.sh "$IMG" "$SITE_TYPE"
