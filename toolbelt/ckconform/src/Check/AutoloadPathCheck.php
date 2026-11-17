<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A composer autoload path that matches no tracked file, compared case-exactly
 * through git (macOS resolves `Civi/acme/` for `Civi/Acme/`, Linux does not).
 */
final class AutoloadPathCheck implements Check
{
    public function name(): string
    {
        return 'autoload-path';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }
        $composer = $context->json('composer.json');
        if ($composer === null) {
            return;
        }

        $missing = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            $block = $composer[$section] ?? null;
            if (!is_array($block)) {
                continue;
            }
            foreach ($this->directoryPaths($block) as $path) {
                if (!$this->directoryTracked($context, $path)) {
                    $missing[] = $section . ' → ' . $path;
                }
            }
            foreach ($this->filePaths($block) as $path) {
                if (!$context->isTracked($path)) {
                    $missing[] = $section . ' → ' . $path;
                }
            }
        }

        if ($missing !== []) {
            $reporter->fail(
                'composer autoload points at paths the repo does not track (case-sensitive): '
                . implode(', ', array_unique($missing))
                . ' — classes there never load; a case-only mismatch passes on macOS and fails on Linux'
            );
        } else {
            $reporter->ok('composer autoload paths exist');
        }
    }

    /**
     * Directory paths from psr-4/psr-0 maps and classmap dir entries.
     *
     * @param  array<string, mixed> $block
     * @return list<string>
     */
    private function directoryPaths(array $block): array
    {
        $paths = [];
        foreach (['psr-4', 'psr-0'] as $key) {
            $map = $block[$key] ?? null;
            if (!is_array($map)) {
                continue;
            }
            foreach ($map as $value) {
                foreach (is_array($value) ? $value : [$value] as $path) {
                    if (is_string($path) && $this->isDirectoryPath($path)) {
                        $paths[] = $this->normalise($path);
                    }
                }
            }
        }
        // A classmap entry is a directory when it ends in '/' or has no extension;
        // the rest go through filePaths().
        foreach ($this->stringList($block['classmap'] ?? null) as $path) {
            if ($this->isDirectoryPath($path)) {
                $paths[] = $this->normalise($path);
            }
        }

        return array_values(array_filter(array_unique($paths), static fn (string $p): bool => $p !== ''));
    }

    /**
     * File paths that must exist exactly: autoload.files, and classmap entries
     * that name a file.
     *
     * @param  array<string, mixed> $block
     * @return list<string>
     */
    private function filePaths(array $block): array
    {
        $paths = [];
        foreach ($this->stringList($block['files'] ?? null) as $path) {
            $paths[] = $this->stripDotSlash($path);
        }
        foreach ($this->stringList($block['classmap'] ?? null) as $path) {
            if (!$this->isDirectoryPath($path)) {
                $paths[] = $this->stripDotSlash($path);
            }
        }

        return array_values(array_filter(array_unique($paths), static fn (string $p): bool => $p !== ''));
    }

    /**
     * A path is a directory mapping unless it names a file (has a .php-ish
     * extension). PSR-4/PSR-0 values are always directories.
     */
    private function isDirectoryPath(string $path): bool
    {
        return !preg_match('/\.[a-zA-Z0-9]+$/', rtrim($path, '/'));
    }

    private function directoryTracked(Context $context, string $path): bool
    {
        $prefix = $path . '/';
        foreach ($context->trackedFiles() as $file) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The base dir sans leading './', the trailing slash and the '.' / '' roots,
     * which always exist (PSR-0 "CRM_": ".").
     */
    private function normalise(string $path): string
    {
        $path = rtrim($this->stripDotSlash($path), '/');

        return $path === '.' ? '' : $path;
    }

    /**
     * Strip leading './' segments only; ltrim('./') would eat './.civix.php' and
     * re-root an escaping '../x', which must match nothing.
     */
    private function stripDotSlash(string $path): string
    {
        return preg_replace('#^(\./)+#', '', $path) ?? $path;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
