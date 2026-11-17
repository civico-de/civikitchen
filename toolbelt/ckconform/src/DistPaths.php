<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * What a release archive leaves out and the untracked build output it stages
 * (`policy.dist`); `ckrelease` reads it through `ckconform --dist-paths`.
 * `vendor/` and `dist/` are absent on purpose: a committed one ships.
 */
final class DistPaths
{
    /** Paths which policy may never put into a release archive. */
    public const PROTECTED_PATHS = [
        'civikitchen.yaml',
        '.git',
        '.github',
        '.env',
        '.env.*',
        '.envrc',
        '.netrc',
        '.npmrc',
        '.pypirc',
        'auth.json',
        'credentials.json',
    ];

    /**
     * Excluded at the repo root; `ckrelease verify` also rejects them as a path
     * segment at any depth.
     *
     * @var list<string>
     */
    public const DIRS = [
        '.github',
        '.docker',
        '.civikitchen',
        '.claude',
        'tests',
        'node_modules',
    ];

    /** @var list<string> */
    public const FILES = [
        ...self::PROTECTED_PATHS,
        '.gitattributes',
        '.gitignore',
        '.editorconfig',
        'civikitchen.yaml',
        '.gitlab-ci.yml',
        'renovate.json',
        '.phpunit.result.cache',
        'mago.toml',
        'phpcs.xml',
        'phpcs.xml.dist',
        'phpstan.neon',
        'phpstan.neon.dist',
        'phpstan-tests.neon',
        'phpstan-tests.neon.dist',
        'phpstanBootstrap.php',
        'phpunit.xml',
        'phpunit.xml.dist',
        'phpunit-unit.xml.dist',
        'composer-dependency-analyser.php',
        '.oxlintrc.json',
        'playwright.config.ts',
        'playwright.config.js',
        'vitest.config.ts',
        'vitest.config.js',
        'package-lock.json',
        'bun.lock',
        'bun.lockb',
        'tsconfig.json',
    ];

    /**
     * The central list adjusted by this repo's civikitchen.yaml policy; an entry
     * ending in `/` is a directory, everything else a file name.
     *
     * @return array{dirs: list<string>, files: list<string>}
     */
    public static function excluded(Context $context): array
    {
        $dirs = self::DIRS;
        $files = self::FILES;

        foreach (self::listValue($context, 'dist_exclude') as $item) {
            if (self::problem($item, 'dist.exclude') !== null) {
                continue;
            }
            if (str_ends_with($item, '/')) {
                $dirs[] = rtrim($item, '/');
            } else {
                $files[] = $item;
            }
        }

        foreach (self::listValue($context, 'dist_include') as $keep) {
            $keep = rtrim($keep, '/');
            if (self::isProtected($keep)) continue;
            $dirs = array_values(array_filter($dirs, static fn (string $d): bool => $d !== $keep));
            $files = array_values(array_filter($files, static fn (string $f): bool => $f !== $keep));
        }

        return ['dirs' => $dirs, 'files' => $files];
    }

    /**
     * Complaints about the declared values; the caller refuses to build on any.
     *
     * @return list<string>
     */
    public static function problems(Context $context): array
    {
        $problems = [];
        foreach (self::listValue($context, 'dist_exclude') as $item) {
            $problem = self::problem($item, 'dist.exclude');
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }
        foreach (self::listValue($context, 'dist_include') as $item) {
            $problem = self::problem($item, 'dist.include');
            if ($problem !== null) $problems[] = $problem;
            if (self::isProtected(rtrim($item, '/'))) {
                $problems[] = "dist.include may not ship protected development or secret-bearing path: {$item}";
            }
        }
        $excluded = self::excluded($context);
        foreach (self::listValue($context, 'dist_build_output') as $item) {
            $problem = self::problem($item, 'dist.build.outputs');
            if ($problem !== null) {
                $problems[] = $problem;
            } elseif (!self::ships(rtrim($item, '/'), $excluded)) {
                $problems[] = "dist.build.outputs lists a path the archive leaves out: {$item}";
            }
        }
        if ($context->policyValue('dist_build_tool') === 'bun') {
            $pin = $context->json('package.json')['packageManager'] ?? null;
            if (!is_string($pin) || preg_match('/^bun@\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $pin) !== 1) {
                $problems[] = 'dist.build.tool bun needs "packageManager": "bun@x.y.z" in package.json, the Bun the build runs on';
            }
            // Without a lockfile, `bun install --frozen-lockfile` installs unlocked and succeeds.
            if (!$context->exists('bun.lock')) {
                $problems[] = 'dist.build.tool bun needs a committed bun.lock';
            }
        }

        return $problems;
    }

    /**
     * Untracked build output the archive carries, from `policy.dist.build.outputs`.
     *
     * @return list<string>
     */
    public static function staged(Context $context): array
    {
        return array_map(static fn (string $path): string => rtrim($path, '/'), self::listValue($context, 'dist_build_output'));
    }

    /**
     * Would this repo-relative path land in the archive? Root-anchored like the
     * build; file entries are prefix-matched too, as a git pathspec is.
     *
     * @param array{dirs: list<string>, files: list<string>} $excluded
     */
    public static function ships(string $path, array $excluded): bool
    {
        foreach ([...$excluded['dirs'], ...$excluded['files']] as $entry) {
            if ($path === $entry || str_starts_with($path, $entry . '/') || (strpbrk($entry, '*?[') !== false && fnmatch($entry, $path, FNM_PATHNAME))) {
                return false;
            }
        }

        return true;
    }

    /**
     * One comma-separated policy value, reason stripped and whitespace removed.
     *
     * @return list<string>
     */
    private static function listValue(Context $context, string $key): array
    {
        $items = [];
        foreach ($context->policyValues($key) as $raw) {
            $item = $key === 'dist_include' ? Policy::stripReason($raw) : $raw;
            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    private static function isProtected(string $path): bool
    {
        foreach (self::PROTECTED_PATHS as $protected) {
            if ($path === $protected || (strpbrk($protected, '*?[') !== false && fnmatch($protected, $path, FNM_PATHNAME))) return true;
        }
        return false;
    }

    private static function problem(string $item, string $key): ?string
    {
        if ($item === '' || str_starts_with($item, '/') || str_starts_with($item, ':') || str_contains($item, '\\') || preg_match('/[\x00-\x1F\x7F]/', $item)) {
            return "{$key} must be a safe repo-relative path: {$item}";
        }
        foreach (explode('/', rtrim($item, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') return "{$key} must be a safe repo-relative path: {$item}";
        }
        return null;
    }
}
