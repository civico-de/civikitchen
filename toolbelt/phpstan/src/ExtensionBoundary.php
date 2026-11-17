<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

/**
 * Which CRM_/Civi\ classes an extension may depend on: core (CoreNamespaceCatalog),
 * its own prefixes and those of each found <requires> checkout. Shared by the
 * phpat rules and the mago extension.
 */
final class ExtensionBoundary
{
    public const BECAUSE = 'this is another extension\'s internal class — call it via APIv4 (Civi\Api4\*), '
        . 'declare the extension in info.xml <requires> (its checkout must sit beside this one), '
        . 'or declare the coupling with an ignoreErrors entry and a reason';

    public const LEGACY_UI_BASES = ['CRM_Core_Page', 'CRM_Core_Form', 'CRM_Report_Form'];

    public const LEGACY_UI_BECAUSE = 'legacy QuickForm/Smarty UI base — prefer a SearchKit display or Afform for UI and an APIv4 action for data endpoints; grandfathered screens get an ignoreErrors entry';

    private function __construct()
    {
    }

    /**
     * The extension's own and required prefixes as classname regexes, and the
     * required keys whose checkout was not found. Empty regexes: not an extension root.
     *
     * @return array{regexes: list<string>, missing: list<string>}
     */
    public static function allowed(string $extensionDir): array
    {
        $regexes = self::prefixRegexes($extensionDir);
        if ($regexes === []) {
            return ['regexes' => [], 'missing' => []];
        }
        $missing = [];
        foreach (self::requiredExtensionKeys($extensionDir) as $key) {
            if (in_array($key, CoreNamespaceCatalog::CORE_EXTENSION_KEYS, true)) {
                continue;
            }
            $dir = self::locateExtension($extensionDir, $key);
            if ($dir === null) {
                $missing[] = $key;
                continue;
            }
            $regexes = array_merge($regexes, self::prefixRegexes($dir));
        }

        return ['regexes' => array_values(array_unique($regexes)), 'missing' => $missing];
    }

    /** @param list<string> $missing */
    public static function because(array $missing): string
    {
        if ($missing === []) {
            return self::BECAUSE;
        }

        return self::BECAUSE . '; required but not found beside this extension: ' . implode(', ', $missing)
            . ' (looked in ../<key>, .civikitchen-siblings/<key> and ' . self::imageExtensionDir() . '/<key>)';
    }

    /**
     * Where a required extension's checkout is: a sibling checkout, the shared
     * CI's .civikitchen-siblings/ mount, or the image's ext dir; null if none matches.
     */
    public static function locateExtension(string $extensionDir, string $key): ?string
    {
        $candidates = [
            dirname(rtrim($extensionDir, '/')) . '/' . $key,
            rtrim($extensionDir, '/') . '/.civikitchen-siblings/' . $key,
            self::imageExtensionDir() . '/' . $key,
        ];
        foreach ($candidates as $candidate) {
            if (self::infoXmlKey($candidate) === $key) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Keys of the extensions an info.xml declares in <requires><ext>.
     *
     * @return list<string>
     */
    public static function requiredExtensionKeys(string $extensionDir): array
    {
        $info = self::infoXml($extensionDir);
        if ($info === null) {
            return [];
        }
        $keys = [];
        foreach ($info->xpath('/extension/requires/ext') ?: [] as $ext) {
            $key = trim((string) $ext);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Prefixes an extension owns, as classname regexes.
     *
     * @return list<string>
     */
    public static function prefixRegexes(string $extensionDir): array
    {
        $info = self::infoXml($extensionDir);
        if ($info === null) {
            return [];
        }

        $regexes = [];
        foreach (self::topLevelNames($extensionDir . '/CRM') as $name) {
            $regexes[] = '~^CRM_' . preg_quote($name, '~') . '(_|$)~';
        }
        foreach (self::topLevelNames($extensionDir . '/Civi') as $name) {
            $regexes[] = '~^Civi\\\\' . preg_quote($name, '~') . '(\\\\|$)~';
        }

        // Non-standard roots (psr4 Civi\Acme\ => src/) come from the classloader;
        // the generic civix CRM_/Civi\ entries would allow everything.
        foreach ($info->xpath('/extension/classloader/*[@prefix]') ?: [] as $loader) {
            $prefix = trim((string) $loader['prefix'], '\\_');
            if ($prefix === 'CRM' || $prefix === 'Civi' || $prefix === '') {
                continue;
            }
            $regexes[] = '~^' . preg_quote($prefix, '~') . '(_|\\\\|$)~';
        }

        // civix's declared namespace owns generated classes like
        // CRM_Acme_ExtensionUtil even when the repo has no CRM/ tree.
        foreach ($info->xpath('/extension/civix/namespace') ?: [] as $namespace) {
            if (preg_match('~^CRM/([A-Za-z0-9_]+)$~', trim((string) $namespace), $m)) {
                $regexes[] = '~^CRM_' . preg_quote($m[1], '~') . '(_|$)~';
            }
        }

        return array_values(array_unique($regexes));
    }

    /** The image's extension root — the entrypoint exports CK_EXT_DIR. */
    public static function imageExtensionDir(): string
    {
        return rtrim(getenv('CK_EXT_DIR') ?: '/var/www/html/ext', '/');
    }

    private static function infoXml(string $dir): ?\SimpleXMLElement
    {
        $file = $dir . '/info.xml';
        if (!is_file($file)) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $info = simplexml_load_file($file);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $info === false ? null : $info;
    }

    private static function infoXmlKey(string $dir): ?string
    {
        $info = self::infoXml($dir);
        if ($info === null) {
            return null;
        }
        $key = trim((string) $info['key']);

        return $key === '' ? null : $key;
    }

    /** @return list<string> */
    private static function topLevelNames(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $names = [];
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isDir() && !$entry->isDot()) {
                $names[] = $entry->getFilename();
            } elseif ($entry->isFile() && str_ends_with($entry->getFilename(), '.php')) {
                $names[] = substr($entry->getFilename(), 0, -4);
            }
        }

        return $names;
    }

    public static function coreRegex(): string
    {
        $crm = implode('|', array_map(
            static fn (string $c) => preg_quote($c, '~'),
            CoreNamespaceCatalog::CRM_COMPONENTS,
        ));
        $civi = implode('|', array_map(
            static fn (string $n) => preg_quote($n, '~'),
            CoreNamespaceCatalog::CIVI_NAMESPACES,
        ));

        return '~^(CRM_(' . $crm . ')(_|$)|Civi\\\\(' . $civi . ')(\\\\|$))~';
    }
}
