<?php

declare(strict_types=1);

/**
 * Write the GET-reachable route catalog of one extension.
 *
 * Usage:
 *   php tools/gen-route-catalog.php <extension-dir> [out-file]
 *
 * Optional: `ck.route.mutationOnGet` parses xml/Menu/*.xml itself and prefers
 * this file only when it exists. Default output is <extension-dir>/.ck-routes.json.
 */

require_once dirname(__DIR__) . '/src/RouteCatalog.php';

use CiviKitchen\PHPStan\RouteCatalog;

if ($argc < 2) {
    fwrite(STDERR, "usage: gen-route-catalog.php <extension-dir> [out-file]\n");
    exit(64);
}

$extensionDir = rtrim($argv[1], '/');
$routes = RouteCatalog::fromMenuXml($extensionDir);

$target = $argv[2] ?? $extensionDir . '/.ck-routes.json';
$json = json_encode(
    ['routes' => $routes],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
) . "\n";

if (file_put_contents($target, $json) === false) {
    fwrite(STDERR, "could not write $target\n");
    exit(73);
}

fwrite(STDOUT, sprintf("%s: %d routes\n", $target, count($routes)));
