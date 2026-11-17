<?php

declare(strict_types=1);

/**
 * Regenerate src/CoreNamespaceCatalog.php from a CiviCRM checkout.
 *
 * Usage:
 *   php tools/gen-core-namespace-catalog.php <core-dir> [out-file]
 *
 * Collects the CRM_* and Civi\* top-level namespaces core owns, from the
 * directory names of each classloader root (core plus ext/ dirs with an
 * info.xml, so templates/CRM/ stays out). Core-shipped extensions count as core.
 */

require_once __DIR__ . '/catalog-common.php';

if ($argc < 2) {
    fwrite(STDERR, "usage: gen-core-namespace-catalog.php <core-dir> [out-file]\n");
    exit(64);
}

$coreDir = rtrim($argv[1], '/');

if (!is_file($coreDir . '/Civi.php') || !is_dir($coreDir . '/CRM')) {
    fwrite(STDERR, "not a CiviCRM core checkout: $coreDir has no Civi.php/CRM\n");
    exit(66);
}

/**
 * Top-level names under a classloader dir: subdirectories plus bare classes
 * (Civi/Test.php declares both the class Civi\Test and its namespace).
 */
function topLevelNames(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $names = [];
    foreach (new DirectoryIterator($dir) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if ($entry->isDir()) {
            $names[] = $entry->getFilename();
        } elseif ($entry->isFile() && str_ends_with($entry->getFilename(), '.php')) {
            $names[] = substr($entry->getFilename(), 0, -4);
        }
    }

    return $names;
}

/**
 * Namespaces an ext maps via info.xml psr0/psr4 instead of CRM_/Civi\ dirs —
 * flexmailer's Civi\FlexMailer => src/ is invisible to the directory scan.
 */
function classloaderPrefixes(string $root, array &$crm, array &$civi): void
{
    $infoXml = $root . '/info.xml';
    if (!is_file($infoXml)) {
        return;
    }
    if (!preg_match_all('/<psr[04]\s[^>]*prefix="([^"]+)"/', (string) file_get_contents($infoXml), $m)) {
        return;
    }
    foreach ($m[1] as $prefix) {
        if (preg_match('/^CRM_([^_\\\\]+)_?$/', $prefix, $p)) {
            $crm[] = $p[1];
        } elseif (preg_match('/^Civi\\\\([^\\\\]+)\\\\?$/', $prefix, $p)) {
            $civi[] = $p[1];
        }
        // Generic CRM_/Civi\ prefixes carry no name — the dir scan covers them.
    }
}

/**
 * The key a core-shipped extension declares in its info.xml, or null for
 * core itself and roots without a parseable key.
 */
function extensionKey(string $root): ?string
{
    $infoXml = $root . '/info.xml';
    if (!is_file($infoXml)) {
        return null;
    }
    $info = @simplexml_load_file($infoXml);
    $key = $info === false ? '' : trim((string) $info['key']);

    return $key === '' ? null : $key;
}

$crm = [];
$civi = [];
$keys = [];
foreach (classloaderRoots($coreDir) as $root) {
    $crm = array_merge($crm, topLevelNames($root . '/CRM'));
    $civi = array_merge($civi, topLevelNames($root . '/Civi'));
    classloaderPrefixes($root, $crm, $civi);
    $key = extensionKey($root);
    if ($key !== null) {
        $keys[] = $key;
    }
}

$crm = array_values(array_unique($crm));
$civi = array_values(array_unique($civi));
$keys = array_values(array_unique($keys));
sort($crm);
sort($civi);
sort($keys);

$version = coreVersion($coreDir);
$crmList = renderCatalogList($crm, 5);
$civiList = renderCatalogList($civi, 5);
$keyList = renderCatalogList($keys, 3);

$out = <<<PHP
<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

/**
 * CiviCRM core's CRM_/Civi\\ namespace surface — GENERATED, do not edit.
 *
 * Regenerate with:
 *   php tools/gen-core-namespace-catalog.php <core-dir>
 *
 * Generated from CiviCRM {$version}. The boundary rule in ArchitectureTest
 * allows these prefixes (plus the extension's own); every other CRM_/Civi\\
 * symbol is another extension's internals. Core-shipped extensions (ext/)
 * are included — SearchKit, Afform etc. count as core.
 */
final class CoreNamespaceCatalog
{
    /**
     * The core release this was generated from.
     *
     * CI reads this to fetch the matching source tree, so the drift gate
     * compares against the exact release rather than a moving branch.
     */
    public const CORE_VERSION = '{$version}';

    /**
     * X in CRM_X_* / CRM_X owned by core.
     *
     * @var list<string>
     */
    public const CRM_COMPONENTS = [
{$crmList}    ];

    /**
     * X in Civi\\X\\* / Civi\\X owned by core.
     *
     * @var list<string>
     */
    public const CIVI_NAMESPACES = [
{$civiList}    ];

    /**
     * info.xml keys of the extensions core ships in ext/.
     *
     * A <requires> on one of these needs no lookup by the boundary rule —
     * their namespaces are already in the two lists above.
     *
     * @var list<string>
     */
    public const CORE_EXTENSION_KEYS = [
{$keyList}    ];
}

PHP;

$target = $argv[2] ?? dirname(__DIR__) . '/src/CoreNamespaceCatalog.php';
if (file_put_contents($target, $out) === false) {
    fwrite(STDERR, "could not write $target\n");
    exit(73);
}

fwrite(STDOUT, sprintf(
    "%s: %d CRM components, %d Civi namespaces, %d core extension keys (CiviCRM %s)\n",
    $target,
    count($crm),
    count($civi),
    count($keys),
    $version,
));
