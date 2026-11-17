<?php
// Joomla CLI shim in /etc/civicrm.settings.d/post.d/: under cv the boot fatals
// on "Undefined constant JVERSION" and leaves civicrm_install_canary behind, so
// load Joomla's framework first through CiviCRM's loadJoomlaFramework().
if (PHP_SAPI === 'cli' && defined('CIVICRM_UF') && CIVICRM_UF === 'Joomla' && !defined('JVERSION')) {
  try {
    (new CRM_Utils_System_Joomla())->loadJoomlaFramework();

    // Joomla's PharStreamWrapper makes cv, itself a phar, throw "Unexpected file
    // extension"; restore PHP's native wrapper for this CLI process.
    if (class_exists(\TYPO3\PharStreamWrapper\PharStreamWrapper::class, FALSE)) {
      stream_wrapper_restore('phar');
    }
  }
  catch (\Throwable $e) {
    // Don't let a bootstrap hiccup hard-fail the settings include; cv will
    // surface the underlying CiviCRM error on its own if Joomla truly can't load.
    fwrite(STDERR, "[civikitchen] Joomla CLI bootstrap shim failed: {$e->getMessage()}\n");
  }
}
