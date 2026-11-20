#!/usr/bin/env bash
# The image's ckHeadless.php passes phpstan level 10 against the pinned core, the
# level consumers' test code is held to. Usage: CIVICRM_CORE_DIR=<core>
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
core="${CIVICRM_CORE_DIR:?CIVICRM_CORE_DIR is required}"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT

# The type settings of the template's phpstan.neon.dist, with core scanned.
cat > "$work/phpstan.neon" <<NEON
parameters:
	level: 10
	phpVersion: 80200
	treatPhpDocTypesAsCertain: false
	checkUninitializedProperties: true
	tmpDir: $work/cache
	paths:
		- $root/toolbelt/phpunit/ckHeadless.php
	scanDirectories:
		- $core/Civi/Test
		- $core/CRM/Extension
	scanFiles:
		- $core/Civi/Test.php
		- $core/CRM/Core/Session.php
		- $core/CRM/Utils/System.php
NEON
"$root/vendor/bin/phpstan" analyse -c "$work/phpstan.neon" --no-progress --memory-limit=1G ||
  { echo "FAIL: ckHeadless.php does not pass phpstan level 10" >&2; exit 1; }
echo "ck_headless phpstan: ok"
