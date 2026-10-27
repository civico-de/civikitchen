<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Runtime;

use CiviKitchen\Toolbelt\Filesystem\Directory;
use RuntimeException;
use ZipArchive;

final class ExtensionArchiveInstaller
{
    public function __construct(private readonly ExtensionInspector $inspector)
    {
    }

    public function install(string $archive, string $expectedKey, string $target, string $constraint): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $expectedKey) !== 1) {
            throw new RuntimeException('unsafe extension key in digest-pinned source');
        }
        $zip = $this->open($archive);
        try {
            [$top, $links] = $this->validateEntries($zip);
            if (file_exists($target)) {
                throw new RuntimeException('extension target exists');
            }
            $temporary = dirname($target) . '/.civikitchen-extract-' . bin2hex(random_bytes(8));
            if (!mkdir($temporary, 0700)) {
                throw new RuntimeException('could not create extension extraction directory');
            }
            try {
                if (!$zip->extractTo($temporary)) {
                    throw new RuntimeException('could not extract extension ZIP');
                }
                $source = $temporary . '/' . $top;
                // extractTo writes a link entry as a file holding the target path.
                foreach ($links as $link => $file) {
                    if (!copy($temporary . '/' . $file, $temporary . '/' . $link)) {
                        throw new RuntimeException('could not materialise extension ZIP symlink');
                    }
                }
                $xml = $this->inspector->load($source . '/info.xml', $expectedKey);
                $this->inspector->assertVersion($xml, $constraint);
                if (!rename($source, $target)) {
                    throw new RuntimeException('could not install extension ZIP');
                }
            } finally {
                Directory::remove($temporary);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * The archive's info.xml, from the root directory core's CRM_Utils_Zip::guessBasedir
     * picks: the one named after the key, else the only one.
     */
    public function info(string $archive, string $expectedKey): string
    {
        $zip = $this->open($archive);
        try {
            $roots = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                if (preg_match('#^([^/]+)/$#', (string) $zip->getNameIndex($index), $match) === 1 && !in_array($match[1], ['.', '..'], true)) {
                    $roots[] = $match[1];
                }
            }
            $top = in_array($expectedKey, $roots, true) ? $expectedKey : (count($roots) === 1 ? $roots[0] : '');
            if (preg_match('/^[a-zA-Z0-9]/', $top) !== 1) {
                throw new RuntimeException("extension ZIP has no root directory for {$expectedKey}");
            }
            $info = $zip->getFromName($top . '/info.xml', 1 << 20);
            $info = is_string($info) ? $info : '';
            $this->inspector->parse($info, $expectedKey);
            return $info;
        } finally {
            $zip->close();
        }
    }

    private function open(string $archive): ZipArchive
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive is unavailable');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('invalid extension ZIP');
        }
        return $zip;
    }

    /**
     * @return array{string, array<string, string>} root directory, symlink entry => file entry it points to
     */
    private function validateEntries(ZipArchive $zip): array
    {
        $top = null;
        $total = 0;
        $files = [];
        $links = [];
        if ($zip->numFiles > 10000) {
            throw new RuntimeException('extension ZIP exceeds extraction limits');
        }
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = $zip->getNameIndex($index);
            if (!is_string($name) || $name === '' || str_contains($name, '\\') || str_starts_with($name, '/')) {
                throw new RuntimeException('unsafe extension ZIP path');
            }
            $parts = explode('/', rtrim($name, '/'));
            if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
                throw new RuntimeException('unsafe extension ZIP path');
            }
            $top ??= $parts[0];
            if ($top !== $parts[0]) {
                throw new RuntimeException('extension ZIP needs one root directory');
            }
            $size = (int) ($stat['size'] ?? 0);
            $total = $this->addToLimit($total, $size);
            if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
                $kind = ($attributes >> 16) & 0170000;
                if ($kind === 0120000) {
                    $links[$name] = $this->resolveLink(dirname($name), (string) $zip->getFromIndex($index, 4096));
                    continue;
                }
            }
            if (!str_ends_with($name, '/')) {
                $files[$name] = $size;
            }
        }
        if ($top === null) {
            throw new RuntimeException('extension ZIP is empty');
        }
        foreach ($links as $file) {
            if ($file === null || !isset($files[$file])) {
                throw new RuntimeException('extension ZIP contains a symlink that does not point to a file in it');
            }
            $total = $this->addToLimit($total, $files[$file]);
        }
        return [$top, array_filter($links)];
    }

    private function addToLimit(int $total, int $size): int
    {
        $total += $size;
        if ($total > 268435456) {
            throw new RuntimeException('extension ZIP exceeds extraction limits');
        }
        return $total;
    }

    private function resolveLink(string $directory, string $target): ?string
    {
        if ($target === '' || str_starts_with($target, '/') || str_contains($target, '\\')) {
            return null;
        }
        $parts = explode('/', $directory);
        foreach (explode('/', $target) as $part) {
            if ($part === '..') {
                if (count($parts) < 2) {
                    return null;
                }
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }
        return implode('/', $parts);
    }

}
