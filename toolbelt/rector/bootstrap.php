<?php

declare(strict_types = 1);

/**
 * Register CiviCRM's class loader so core symbols resolve for rector's reflection.
 */

$coreDir = getenv('CIVICRM_CORE_DIR') ?: '/var/www/html/core';

require_once $coreDir . '/vendor/autoload.php';
require_once $coreDir . '/CRM/Core/ClassLoader.php';
\CRM_Core_ClassLoader::singleton()->register();

// Settings-defined runtime constant; reflection only needs it to exist.
defined('CIVICRM_UF_BASEURL') || define('CIVICRM_UF_BASEURL', 'http://localhost');
