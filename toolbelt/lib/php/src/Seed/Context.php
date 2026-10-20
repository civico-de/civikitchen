<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Seed;

use CiviKitchen\Toolbelt\Runtime\ExtensionInspector;
use CiviKitchen\Toolbelt\Runtime\ProfileData;
use DateTimeImmutable;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Everything a snapshot depends on. The selected profile set has one snapshot:
 * restored before its first profile with a `snapshot` block, dumped after its
 * last, so it holds every profile applied up to there.
 */
final class Context
{
    /** Boot settings written into the database before any profile applies. */
    public const ENVIRONMENT = [
        'CIVIKITCHEN_COMPONENTS', 'CIVIKITCHEN_DEFAULT_LOCALE', 'CIVIKITCHEN_LOCALES',
        'CIVIKITCHEN_DEMO_USER', 'CIVIKITCHEN_DEMO_PASS', 'CIVIKITCHEN_DEMO_EMAIL',
        'CIVIKITCHEN_ENABLE_EXTENSIONS', 'CIVIKITCHEN_EXTRA_EXTENSIONS',
        'CIVIKITCHEN_SMTP_HOST', 'CIVIKITCHEN_SMTP_PORT',
    ];

    /** Bumped when the dump or restore format changes, so old snapshots stop matching. */
    private const FORMAT = 2;

    /** Calendar units from finest to coarsest: a set expires with its finest. */
    private const FINEST_FIRST = ['month', 'year', 'none'];

    /**
     * @param list<string> $profiles every selected profile directory, in apply order
     * @param array<string, array{calendar: string, sizes: list<string>, after_restore: list<string>}> $snapshots
     *        the snapshot blocks of the profiles that have one, by directory, in apply order
     * @param list<string> $extensions mounted extension directories
     * @param array<string, string> $environment
     * @param list<string> $libraries shared helper files the profile scripts load
     */
    public function __construct(
        public readonly array $profiles,
        public readonly array $snapshots,
        public readonly array $extensions,
        public readonly string $size,
        public readonly array $environment,
        private readonly array $libraries,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $size) !== 1) {
            throw new RuntimeException("invalid seed size '{$size}'");
        }
        $sizes = array_values(array_unique(array_merge([], ...array_values(array_column($snapshots, 'sizes')))));
        if ($snapshots !== [] && !in_array($size, $sizes, true)) {
            throw new RuntimeException("no snapshot profile of {$this->name()} has the size '{$size}' (sizes: " . implode(', ', $sizes) . ')');
        }
    }

    /**
     * From the profile driver's environment: CK_SEED_PROFILES and
     * CK_SEED_EXTENSION_DIRS are newline lists, CK_SEED_SIZE defaults to demo.
     *
     * @param list<string> $libraries
     */
    public static function fromEnvironment(string $profile, array $libraries): self
    {
        $directory = self::directory($profile);
        $profiles = array_map(self::directory(...), self::lines('CK_SEED_PROFILES'));
        $profiles = $profiles === [] ? [$directory] : array_values(array_unique($profiles));
        if (!in_array($directory, $profiles, true)) {
            throw new RuntimeException("{$directory} is not one of the selected profiles (CK_SEED_PROFILES)");
        }
        $size = getenv('CK_SEED_SIZE');
        return self::build($profiles, self::lines('CK_SEED_EXTENSION_DIRS'), is_string($size) && $size !== '' ? $size : 'demo', $libraries);
    }

    /**
     * @param array<string, mixed> $state a context saved by toArray()
     * @param list<string> $libraries
     */
    public static function fromState(array $state, array $libraries): self
    {
        $strings = static fn(mixed $list): array => array_values(array_map('strval', is_array($list) ? $list : []));
        return self::build(array_map(self::directory(...), $strings($state['profiles'] ?? [])), $strings($state['extensions'] ?? []),
            (string) ($state['size'] ?? 'demo'), $libraries);
    }

    /**
     * @param list<string> $profiles
     * @param list<string> $extensions
     * @param list<string> $libraries
     */
    private static function build(array $profiles, array $extensions, string $size, array $libraries): self
    {
        $data = new ProfileData();
        $snapshots = [];
        foreach ($profiles as $directory) {
            $snapshot = $data->snapshot($directory . '/profile.json');
            if ($snapshot !== null) {
                $snapshots[$directory] = $snapshot;
            }
        }
        $environment = [];
        foreach (self::ENVIRONMENT as $name) {
            $value = getenv($name);
            $environment[$name] = is_string($value) ? $value : '';
        }
        return new self($profiles, $snapshots, $extensions, $size, $environment, $libraries);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['profiles' => $this->profiles, 'extensions' => $this->extensions, 'size' => $this->size];
    }

    /** Whether any selected profile declares a snapshot. */
    public function hasSnapshot(): bool
    {
        return $this->snapshots !== [];
    }

    /** The snapshot profiles' names, joined by '+'. */
    public function name(): string
    {
        return implode('+', array_map('basename', array_keys($this->snapshots)));
    }

    /** The profile before whose content the snapshot is restored. */
    public function first(): string
    {
        return (string) array_key_first($this->snapshots);
    }

    /** The profile after whose content the snapshot is dumped. */
    public function last(): string
    {
        return (string) array_key_last($this->snapshots);
    }

    /** Whether $profile's content lies inside the snapshot. */
    public function covers(string $profile): bool
    {
        $position = array_search(self::directory($profile), $this->profiles, true);
        return $position !== false && $position <= array_search($this->last(), $this->profiles, true);
    }

    public function calendar(): Calendar
    {
        $units = array_column($this->snapshots, 'calendar');
        foreach (self::FINEST_FIRST as $unit) {
            if (in_array($unit, $units, true)) {
                return new Calendar($unit);
            }
        }
        throw new RuntimeException('no calendar unit in ' . implode(', ', $units));
    }

    /** @return list<string> every snapshot profile's after_restore scripts, in apply order */
    public function afterRestore(): array
    {
        return array_values(array_merge([], ...array_values(array_column($this->snapshots, 'after_restore'))));
    }

    /**
     * The snapshot key for a generation on $today, and the inputs it hashes.
     *
     * @return array{key: string, inputs: array<string, mixed>}
     */
    public function key(DateTimeImmutable $today): array
    {
        $calendar = $this->calendar();
        $inputs = [
            'format' => self::FORMAT,
            'profiles' => array_map(fn(string $dir): array => ['name' => basename($dir), 'files' => self::tree($dir)], $this->profiles),
            'libraries' => array_map(static fn(string $file): string => basename($file) . ':' . hash_file('sha256', $file), $this->libraries),
            'size' => $this->size,
            'extensions' => array_map(fn(string $dir): array => $this->extension($dir), $this->extensions),
            'environment' => hash('sha256', json_encode($this->environment, JSON_THROW_ON_ERROR)),
            'calendar' => $calendar->unit,
            'period' => $calendar->period($today),
        ];
        usort($inputs['extensions'], static fn(array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));
        $key = substr(hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 0, 16);
        return ['key' => $key, 'inputs' => $inputs];
    }

    /** @return array<string, string> */
    private function extension(string $directory): array
    {
        $inspector = new ExtensionInspector('');
        $schema = [];
        foreach (['schema', 'xml/schema', 'sql'] as $sub) {
            if (is_dir($directory . '/' . $sub)) {
                $schema[$sub] = self::tree($directory . '/' . $sub);
            }
        }
        return [
            'key' => $inspector->key($directory . '/info.xml') ?: basename($directory),
            // Version and <requires> from info.xml, dependency pins from civikitchen.yaml: both decide what boot installs.
            'info' => (string) hash_file('sha256', $directory . '/info.xml'),
            'pins' => is_file($directory . '/civikitchen.yaml') ? (string) hash_file('sha256', $directory . '/civikitchen.yaml') : '',
            'schema' => hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /**
     * sha256 of every regular file below $directory by relative path, sorted.
     *
     * @return array<string, string>
     */
    public static function tree(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile()) {
                $path = $entry->getPathname();
                $files[substr($path, strlen($directory) + 1)] = (string) hash_file('sha256', $path);
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /** The real profile directory for a profile directory or its profile.json. */
    public static function directory(string $path): string
    {
        $real = realpath(str_ends_with($path, '/profile.json') ? dirname($path) : $path);
        if ($real === false || !is_file($real . '/profile.json')) {
            throw new RuntimeException("no profile directory at {$path}");
        }
        return $real;
    }

    /** @return list<string> */
    private static function lines(string $name): array
    {
        $value = getenv($name);
        return is_string($value) ? array_values(array_filter(explode("\n", $value), static fn(string $l): bool => $l !== '')) : [];
    }
}
