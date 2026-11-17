<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Context;

/**
 * Which class-name prefixes belong to the extension under inspection, from
 * info.xml (<key>/<file>) and the shipped CRM/<X>/ and Civi/<X>/ directories,
 * compared case-insensitively.
 */
final class ExtensionNamespace
{
    /**
     * @return list<string> Shortnames, lower-cased.
     */
    public static function all(Context $context): array
    {
        $names = [];

        $info = $context->infoXml();
        if ($info !== null) {
            foreach ([(string) $info['key'], (string) ($info->file ?? '')] as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '') {
                    continue;
                }
                $parts = explode('.', $candidate);
                $names[] = strtolower((string) end($parts));
            }
        }

        foreach (array_keys(self::psr4($context)) as $prefix) {
            if (preg_match('/^(?:Civi|CRM)\\\\([A-Za-z0-9]+)\\\\/', $prefix, $match) === 1) {
                $names[] = strtolower($match[1]);
            }
        }

        // A shipped directory vouches for a namespace only where core has none:
        // a payment processor in CRM/Core/Payment/ does not make CRM_Core_ ours.
        $files = $context->isGitRepo() ? $context->trackedFiles() : $context->findFiles('');
        foreach ($files as $file) {
            if (preg_match('#^(CRM|Civi)/([A-Za-z0-9]+)/#', $file, $match) === 1 && $context->coreDir !== null
                && !is_dir($context->coreDir . '/' . $match[1] . '/' . $match[2])
            ) {
                $names[] = strtolower($match[2]);
            }
        }

        return array_values(array_unique(array_filter($names, static fn (string $n): bool => $n !== '')));
    }

    /**
     * PSR-4 prefixes (`Civi\Myext\` => [`src`]) from the info.xml classloader,
     * which core honours, and from composer.json's autoload section.
     *
     * @return array<string, list<string>>
     */
    private static function psr4(Context $context): array
    {
        $map = [];
        foreach ($context->infoXml()?->xpath('//classloader/psr4') ?: [] as $entry) {
            $map[trim((string) $entry['prefix'], '\\') . '\\'][] = trim((string) $entry['path'], '/');
        }
        $composer = json_decode($context->read('composer.json') ?? '', true);
        foreach ((array) ($composer['autoload']['psr-4'] ?? []) as $prefix => $paths) {
            foreach ((array) $paths as $path) {
                $map[trim((string) $prefix, '\\') . '\\'][] = trim((string) $path, '/');
            }
        }
        unset($map['\\']);

        return $map;
    }

    /**
     * Is this a CRM_<Shortname>_… class of the extension itself?
     *
     * @param list<string> $namespaces
     */
    public static function isOwnClass(string $class, array $namespaces): bool
    {
        if (preg_match('/^\\\\?CRM_([A-Za-z0-9]+)_/', $class, $match) !== 1) {
            return false;
        }

        return in_array(strtolower($match[1]), $namespaces, true);
    }

    /**
     * The PSR-0/PSR-4 files one of which must ship a class of this extension,
     * or [] for a foreign class.
     *
     * @param  list<string> $namespaces
     * @return list<string>
     */
    public static function ownClassFiles(Context $context, string $class, array $namespaces): array
    {
        $class = ltrim(str_replace('\\\\', '\\', $class), '\\');

        $files = [];
        foreach (self::psr4($context) as $prefix => $paths) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $rest = substr($class, strlen($prefix));
            // A bare `Civi\` (civix's classloader) maps core's classes too; only an own sub-namespace is ours.
            if ($prefix === 'Civi\\' && !in_array(strtolower(strtok($rest, '\\')), $namespaces, true)) {
                continue;
            }
            foreach ($paths as $path) {
                $files[] = ltrim($path . '/', '/') . str_replace('\\', '/', $rest) . '.php';
            }
        }
        if ($files !== []) {
            return array_values(array_unique($files));
        }

        if (self::isOwnClass($class, $namespaces)) {
            return [str_replace('_', '/', $class) . '.php'];
        }
        if (preg_match('#^Civi\\\\([A-Za-z0-9]+)\\\\#', $class, $match) === 1
            && in_array(strtolower($match[1]), $namespaces, true)
        ) {
            return [str_replace('\\', '/', $class) . '.php'];
        }

        return [];
    }
}
