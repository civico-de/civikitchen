<?php

declare(strict_types = 1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/**
 * Default config for `ckdeps` (shipmonk/composer-dependency-analyser). Core's
 * symbols come from the runtime classloader and are ignored by pattern; a repo
 * may ship its own composer-dependency-analyser.php.
 */
// Libraries core hands to an extension as a parent class, return value or hook
// argument; enumerated, so any other library stays reportable.
$configuration = (new Configuration())
  ->ignoreUnknownClassesRegex(
    '~^(CRM_|Civi(?:\\\\|$)|CiviCRM|CiviMix\\\\|API_(?:Exception|Wrapper)$|GuzzleHttp\\\\|Psr\\\\'
    . '|Symfony\\\\Component\\\\(?:Config|DependencyInjection|EventDispatcher|Filesystem|Finder|Process|VarDumper)\\\\'
    . '|Symfony\\\\Contracts\\\\'
    . '|(?i:HTML_(?:Common|QuickForm)(?:_|$)|PEAR(?:_(?:Error|ErrorStack|Exception))?$'
    . '|DB(?:_(?:Error|common|result|row|storage|mysqli?|DataObject(?:_(?:Cast|Error|Generator|Links))?))?$'
    . '|Mail(?:_(?:RFC822|mail|mime|mimeDecode|mimePart|mock|null|sendmail|smtp|smtpmx))?$)|Net_(?:SMTP|Socket)$|Log(?:_|$)'
    . '|Pager(?:_|$)|ezc(?:Base|Mail)|TCPDF|HTMLPurifier(?:_|$)|Smarty\\\\)~',
  )
  ->ignoreUnknownFunctionsRegex('~^(civicrm_|civi|CiviMix\\\\)~')
  // PHP extensions every CiviCRM site has: civicrm-core's composer.json requires
  // the first row, PHP enables the second by default. Optional ones stay findings.
  ->ignoreErrorsOnExtensions([
    'ext-bcmath', 'ext-curl', 'ext-fileinfo', 'ext-intl', 'ext-mbstring', 'ext-mysqli', 'ext-openssl', 'ext-xml', 'ext-zip',
    'ext-ctype', 'ext-dom', 'ext-filter', 'ext-iconv', 'ext-libxml', 'ext-session', 'ext-simplexml', 'ext-tokenizer', 'ext-xmlreader', 'ext-xmlwriter',
  ], [ErrorType::SHADOW_DEPENDENCY])
  // Paths are validated against the CWD, so a shared config cannot name
  // directories (node_modules) that only some repos have.
  ->disableReportingUnmatchedIgnores();

// A `CRM_` => `.` autoload root would count vendor/ as production code.
// Guarded, because Configuration rejects a missing path.
if (is_dir('vendor')) {
  $configuration->addPathToExclude('vendor');
}
// Extension CI checks sibling repositories (civikitchen itself among them) out
// here; their code is not the extension's.
if (is_dir('.civikitchen-siblings')) {
  $configuration->addPathToExclude('.civikitchen-siblings');
}
if (is_dir('tests')) {
  $configuration->addPathToScan('tests', true);
}
// The phpstan bootstrap only runs under phpstan, so its uses are dev dependencies.
if (is_file('phpstanBootstrap.php')) {
  $configuration->addPathToScan('phpstanBootstrap.php', true);
}

return $configuration;
