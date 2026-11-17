<?php

declare(strict_types=1);

/**
 * Regenerate src/SchemaCatalog.php from a CiviCRM checkout.
 *
 * Usage:
 *   php tools/gen-schema-catalog.php <core-dir> [out-file]
 *
 * Reads the `'table' => '...'` key of every schema/*.entityType.php in the
 * source tree. Custom-value, temp, logging and non-core tables are not here;
 * SqlSchema excludes them.
 */

require_once __DIR__ . '/catalog-common.php';

if ($argc < 2) {
    fwrite(STDERR, "usage: gen-schema-catalog.php <core-dir> [out-file]\n");
    exit(64);
}

$coreDir = rtrim($argv[1], '/');

if (!is_file($coreDir . '/Civi.php') || !is_dir($coreDir . '/schema')) {
    fwrite(STDERR, "not a CiviCRM core checkout: $coreDir has no Civi.php/schema\n");
    exit(66);
}

/** @return list<string> */
function entityTypeFiles(string $coreDir): array
{
    $files = [];
    foreach ([$coreDir . '/schema', $coreDir . '/ext'] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getFilename(), '.entityType.php')) {
                $files[] = $entry->getPathname();
            }
        }
    }
    sort($files);

    return $files;
}

$tables = [];
foreach (entityTypeFiles($coreDir) as $file) {
    // Matched, not included: evaluating an entityType file needs a booted CiviCRM.
    if (preg_match("/'table'\s*=>\s*'([A-Za-z0-9_]+)'/", (string) file_get_contents($file), $m) === 1) {
        $tables[] = $m[1];
    }
}

$tables = array_values(array_unique($tables));
sort($tables);

if (count($tables) < 100) {
    fwrite(STDERR, sprintf("only %d tables found in %s — refusing to write a truncated catalog\n", count($tables), $coreDir));
    exit(65);
}

$version = coreVersion($coreDir);
$tableList = renderCatalogList($tables, 3);

$out = <<<PHP
<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

/**
 * CiviCRM core's table names — GENERATED, do not edit.
 *
 * Regenerate with:
 *   php tools/gen-schema-catalog.php <core-dir>
 *
 * Generated from CiviCRM {$version} out of the schema/*.entityType.php
 * declarations of core and its core-shipped extensions. The SQL rule uses
 * this to reject a `civicrm_`-prefixed table name that no core release and
 * no repo-local schema knows.
 */
final class SchemaCatalog
{
    /**
     * The core release this was generated from.
     *
     * CI reads this to fetch the matching source tree, so the drift gate
     * compares against the exact release rather than a moving branch.
     */
    public const CORE_VERSION = '{$version}';

    /**
     * Table names core creates.
     *
     * @var list<string>
     */
    public const TABLES = [
{$tableList}    ];
}

PHP;

$target = $argv[2] ?? dirname(__DIR__) . '/src/SchemaCatalog.php';
if (file_put_contents($target, $out) === false) {
    fwrite(STDERR, "could not write $target\n");
    exit(73);
}

fwrite(STDOUT, sprintf("%s: %d tables (CiviCRM %s)\n", $target, count($tables), $version));
