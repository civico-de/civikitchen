#!/usr/bin/env bash
# phpstanBootstrap.php autoloads core's ext packages and every info.xml <requires>
# under CK_EXT_DIR. Core is stubbed to the three files the bootstrap requires.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

core="$work/core"
mkdir -p "$core/vendor" "$core/CRM/Core" "$core/api"
# Core's autoloader also serves CRM_Core_DAO_Base, the class civix's DAO_Base alias points at.
mkdir -p "$core/CRM/Core/DAO"
echo '<?php class CRM_Core_DAO_Base {}' > "$core/CRM/Core/DAO/Base.php"
echo '<?php require __DIR__ . "/../CRM/Core/DAO/Base.php";' > "$core/vendor/autoload.php"
echo '<?php' > "$core/api/api.php"
mkdir -p "$core/api/v3"
echo '<?php function civicrm_api3_create_success() {}' > "$core/api/v3/utils.php"
# Core loads CiviMix\Schema by pathload; the newest version wins.
mkdir -p "$core/mixin/lib/civimix-schema@5/src" "$core/mixin/lib/civimix-schema@10/src"
echo '<?php namespace CiviMix\Schema; interface SchemaHelperInterface {}' > "$core/mixin/lib/civimix-schema@5/src/SchemaHelperInterface.php"
echo '<?php namespace CiviMix\Schema; interface SchemaHelperInterface { const V = 10; }' > "$core/mixin/lib/civimix-schema@10/src/SchemaHelperInterface.php"
# Core component extension: ships with core, off its classloader path.
mkdir -p "$core/ext/civi_member/Civi/Api4"
echo '<?php namespace Civi\Api4; class Membership {}' > "$core/ext/civi_member/Civi/Api4/Membership.php"
echo '<extension key="civi_member" type="module"><file>civi_member</file></extension>' > "$core/ext/civi_member/info.xml"
# Afform ships its extensions one level down, keyed unlike the directory.
mkdir -p "$core/ext/afform/core/Civi/Afform/Event"
echo '<?php namespace Civi\Afform\Event; class AfformSubmitEvent {}' > "$core/ext/afform/core/Civi/Afform/Event/AfformSubmitEvent.php"
echo '<extension key="org.civicrm.afform" type="module"><file>afform</file></extension>' > "$core/ext/afform/core/info.xml"
# A package whose classes sit off the civix layout stays uncovered.
mkdir -p "$core/ext/flexmailer/src/Civi/Flexmailer"
echo '<?php namespace Civi\Flexmailer; class Sender {}' > "$core/ext/flexmailer/src/Civi/Flexmailer/Sender.php"
cat > "$core/CRM/Core/ClassLoader.php" <<'PHP'
<?php
class CRM_Core_ClassLoader {
  public static function singleton(): self { return new self(); }
  public function register(): void {}
}
PHP

ext="$work/ext"
mkdir -p "$ext/org.example.dep/CRM/Dep" "$ext/org.example.dep/Civi/Dep" "$ext/org.example.dep/vendor" "$ext/repo"
echo '<?php class CRM_Dep_Base {}' > "$ext/org.example.dep/CRM/Dep/Base.php"
# civix DAO: extends the CRM_<Prefix>_DAO_Base alias that only exists at runtime.
mkdir -p "$ext/org.example.dep/CRM/Dep/DAO"
echo '<?php class CRM_Dep_DAO_Thing extends CRM_Dep_DAO_Base {}' > "$ext/org.example.dep/CRM/Dep/DAO/Thing.php"
echo '<?php namespace Civi\Dep; interface Contract {}' > "$ext/org.example.dep/Civi/Dep/Contract.php"
echo '<?php define("CK_DEP_VENDOR_LOADED", 1);' > "$ext/org.example.dep/vendor/autoload.php"
# A class that requires a bundled package relative to the extension root, as at runtime.
mkdir -p "$ext/org.example.dep/packages/lib"
echo '<?php function ck_dep_bundled() {}' > "$ext/org.example.dep/packages/lib/bundled.php"
echo '<?php require_once "packages/lib/bundled.php"; class CRM_Dep_Bundled {}' > "$ext/org.example.dep/CRM/Dep/Bundled.php"
cp "$root/scaffold/template/extension/phpstanBootstrap.php" "$ext/repo/"
cat > "$ext/repo/info.xml" <<'XML'
<?xml version="1.0"?>
<extension key="org.example.repo" type="module">
  <file>repo</file>
  <requires>
    <ext>org.example.dep</ext>
    <ext>org.example.absent</ext>
    <ext>org.civicrm.afform</ext>
  </requires>
</extension>
XML

probe='require $argv[1];
  echo class_exists("CRM_Dep_Base") ? "crm" : "-", " ",
    interface_exists("Civi\\Dep\\Contract") ? "civi" : "-", " ",
    defined("CK_DEP_VENDOR_LOADED") ? "vendor" : "-", " ",
    is_subclass_of("CRM_Dep_DAO_Thing", "CRM_Core_DAO_Base") ? "dao" : "-", " ",
    class_exists("CRM_Dep_Bundled") && function_exists("ck_dep_bundled") ? "bundled" : "-", "\n";'
coreprobe='require $argv[1];
  echo class_exists("Civi\\Api4\\Membership") ? "member" : "-", " ",
    class_exists("Civi\\Flexmailer\\Sender") ? "flexmailer" : "-", " ",
    class_exists("Civi\\Afform\\Event\\AfformSubmitEvent") ? "afform" : "-", "\n";'
out="$(CIVICRM_CORE_DIR="$core" CK_EXT_DIR="$ext" php -r "$probe" "$ext/repo/phpstanBootstrap.php" 2>"$work/stderr")"
[[ "$out" == "crm civi vendor dao bundled" ]] || fail "required extension classes did not resolve: '$out'"
grep -q 'org.example.absent is not under' "$work/stderr" \
  || fail "a required extension with no directory must be noted on stderr: $(cat "$work/stderr")"
if grep -qE 'org.example.dep|org.civicrm.afform' "$work/stderr"; then
  fail "a present or core extension must not be reported: $(cat "$work/stderr")"
fi

# No <requires>: nothing registered, nothing said.
printf '%s\n' '<extension key="org.example.repo" type="module"><file>repo</file></extension>' > "$ext/repo/info.xml"
out="$(CIVICRM_CORE_DIR="$core" CK_EXT_DIR="$ext" php -r "$probe" "$ext/repo/phpstanBootstrap.php" 2>"$work/stderr")"
[[ "$out" == "- - - - -" ]] || fail "without <requires> nothing may be autoloaded: '$out'"
[[ ! -s "$work/stderr" ]] || fail "without <requires> stderr must stay empty: $(cat "$work/stderr")"

# Core component extensions need no <requires> entry; a package off the civix
# layout is not covered and stays a scanDirectories entry.
out="$(CIVICRM_CORE_DIR="$core" CK_EXT_DIR="$ext" php -r "$coreprobe" "$ext/repo/phpstanBootstrap.php" 2>"$work/stderr")"
[[ "$out" == "member - afform" ]] || fail "core ext autoloading is wrong: '$out'"

# The repo's own civix DAOs, core's APIv3 helpers and the newest CiviMix\Schema
# resolve without <requires>; another prefix's DAO_Base stays unknown.
mkdir -p "$ext/repo/CRM/Repo/DAO"
echo '<?php class CRM_Repo_DAO_Item extends CRM_Repo_DAO_Base {}' > "$ext/repo/CRM/Repo/DAO/Item.php"
ownprobe='require $argv[1]; require dirname($argv[1]) . "/CRM/Repo/DAO/Item.php";
  echo is_subclass_of("CRM_Repo_DAO_Item", "CRM_Core_DAO_Base") ? "dao" : "-", " ",
    class_exists("CRM_Other_DAO_Base") ? "other" : "-", " ",
    function_exists("civicrm_api3_create_success") ? "api3" : "-", " ",
    interface_exists("CiviMix\\Schema\\SchemaHelperInterface") ? "schema" . CiviMix\Schema\SchemaHelperInterface::V : "-", "\n";'
out="$(CIVICRM_CORE_DIR="$core" CK_EXT_DIR="$ext" php -r "$ownprobe" "$ext/repo/phpstanBootstrap.php" 2>"$work/stderr")"
[[ "$out" == "dao - api3 schema10" ]] || fail "own DAO, APIv3 helpers or CiviMix\\Schema did not resolve: '$out'"

echo "phpstan bootstrap: ok"
