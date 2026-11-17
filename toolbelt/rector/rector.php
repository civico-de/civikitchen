<?php

declare(strict_types = 1);

use CiviKitchen\Rector\Rules\Api3ToApi4AssistRector;
use CiviKitchen\Rector\Rules\Api3ToApi4OopAssistRector;
use CiviKitchen\Rector\Rules\Api4ArrayToOopRector;
use CiviKitchen\Rector\Rules\CrmCoreErrorFatalToExceptionRector;
use CiviKitchen\Rector\Rules\CrmUtilsArrayValueToCoalesceRector;
use CiviKitchen\Rector\Rules\PositionalDefaultsToNamedArgsRector;
use CiviKitchen\Toolbelt\Repository\PhpFloor;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\ValueObject\PhpVersion;

/**
 * CiviKitchen's default rector config for `ckmodernize`: rector's PHP-version
 * and quality sets plus CiviKitchen's CiviCRM rules. Target PHP: CK_PHP_VERSION,
 * else composer.json's require.php floor, else 8.1; one without a set stops the run.
 */

require_once __DIR__ . '/../lib/php/bootstrap.php';

$target = getenv('CK_PHP_VERSION') ?: PhpFloor::ofComposerJson((string) getcwd()) ?? '8.1';
[$phpVersion, $phpSetFlag] = match ($target) {
  '8.0' => [PhpVersion::PHP_80, 'php80'],
  '8.1' => [PhpVersion::PHP_81, 'php81'],
  '8.2' => [PhpVersion::PHP_82, 'php82'],
  '8.3' => [PhpVersion::PHP_83, 'php83'],
  '8.4' => [PhpVersion::PHP_84, 'php84'],
  '8.5' => [PhpVersion::PHP_85, 'php85'],
  default => throw new RuntimeException("ckmodernize: no PHP migration set for target {$target}; declare an 8.x floor in composer.json require.php or pass --php"),
};

$rules = [
  CrmUtilsArrayValueToCoalesceRector::class,
  CrmCoreErrorFatalToExceptionRector::class,
  // No off-the-shelf set does named arguments; rector's own is an open request.
  PositionalDefaultsToNamedArgsRector::class,
];
// Default: api4 array calls to the OO builder. `--api` adds api3 -> api4 (OOP),
// `--api=array` migrates api3 to the array form and leaves api4 arrays alone.
$apiStyle = getenv('CK_API_MIGRATE');
if ($apiStyle === 'array') {
  $rules[] = Api3ToApi4AssistRector::class;
}
else {
  $rules[] = Api4ArrayToOopRector::class;
  if ($apiStyle === 'oop') {
    $rules[] = Api3ToApi4OopAssistRector::class;
  }
}

// Without core on the autoloader rector's type inference is blind, so a missing
// core is an error; ckmodernize derives CIVICRM_CORE_DIR from cv.
$coreDir = getenv('CIVICRM_CORE_DIR') ?: '/var/www/html/core';
if (!is_file($coreDir . '/CRM/Core/ClassLoader.php')) {
  throw new RuntimeException("no CiviCRM core at $coreDir (set CIVICRM_CORE_DIR)");
}
$bootstrapFiles = [__DIR__ . '/bootstrap.php'];

return RectorConfig::configure()
  ->withBootstrapFiles($bootstrapFiles)
  // PHP version migration — rector-maintained, we write none of it.
  ->withPhpVersion($phpVersion)
  ->withPhpSets(...[$phpSetFlag => TRUE])
  // Generic modernization — also off-the-shelf.
  ->withSets([
    SetList::CODE_QUALITY,
    SetList::DEAD_CODE,
    SetList::EARLY_RETURN,
  ])
  // The only custom part: CiviCRM footgun fixes (no package ships these).
  ->withRules($rules)
  // Never rewrite generated / vendored code.
  ->withSkip([
    '*/vendor/*',
    '*/node_modules/*',
    '*.civix.php',
    '*/CRM/*/DAO/*',
    '*/mixin/*',
  ]);
