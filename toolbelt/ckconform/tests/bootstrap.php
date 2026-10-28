<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once __DIR__ . '/../src/Autoloader.php';
CiviKitchen\Ckconform\Autoloader::register();

spl_autoload_register(static function (string $class): void {
    $prefix = 'CiviKitchen\\Ckconform\\Tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
