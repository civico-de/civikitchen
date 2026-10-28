<?php

declare(strict_types = 1);

/**
 * The rules run through rector's own fixture harness, which `composer install`
 * in this directory provides; PHPUnit itself comes from the pinned phar.
 */
spl_autoload_register(static function (string $class): void {
  $prefix = 'CiviKitchen\\Rector\\Tests\\';
  if (str_starts_with($class, $prefix)) {
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
      require_once $file;
    }
  }
});

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
// The API rules check the target class before they emit it.
require_once __DIR__ . '/stubs/api4.php';
