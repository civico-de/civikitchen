<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Seed;

use RuntimeException;

/**
 * Snapshot files: <profile>-<size>-<key>.sql.zst with a .json metadata file
 * beside it. Metadata is written last, so a dump without one is incomplete.
 */
final class Store
{
    public const DEFAULT_DIRECTORY = '/var/lib/civikitchen/snapshots';

    public function __construct(public readonly string $directory)
    {
    }

    public static function fromEnvironment(): self
    {
        $directory = getenv('CK_SEED_STORE');
        return new self(is_string($directory) && $directory !== '' ? rtrim($directory, '/') : self::DEFAULT_DIRECTORY);
    }

    public function dump(string $profile, string $size, string $key): string
    {
        return "{$this->directory}/{$profile}-{$size}-{$key}.sql.zst";
    }

    /** @return array<string, mixed>|null */
    public function find(string $profile, string $size, string $key): ?array
    {
        $meta = $this->metaFile($this->dump($profile, $size, $key));
        if (!is_file($meta) || !is_file($this->dump($profile, $size, $key))) {
            return null;
        }
        return $this->read($meta);
    }

    /** @return list<array<string, mixed>> every complete snapshot, newest first */
    public function all(): array
    {
        $snapshots = [];
        foreach (glob($this->directory . '/*.sql.zst.json') ?: [] as $meta) {
            $data = $this->read($meta);
            if (is_file(substr($meta, 0, -5))) {
                $snapshots[] = $data;
            }
        }
        usort($snapshots, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return $snapshots;
    }

    /**
     * A writable temporary path in the store for a dump in progress, unique per writer, so two
     * stacks generating the same key each publish a complete dump.
     */
    public function prepare(string $profile, string $size, string $key): string
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true)) {
            throw new RuntimeException("cannot create the snapshot store {$this->directory}");
        }
        if (!is_writable($this->directory)) {
            throw new RuntimeException("the snapshot store {$this->directory} is not writable; a bind mount needs to be writable by the web user");
        }
        return $this->dump($profile, $size, $key) . '.' . bin2hex(random_bytes(6)) . '.partial';
    }

    /** @param array<string, mixed> $meta */
    public function commit(string $partial, array $meta): void
    {
        $dump = preg_replace('/\.[0-9a-f]{12}\.partial$/', '', $partial);
        if ($dump === null || $dump === $partial) {
            throw new RuntimeException("{$partial} is not a path from prepare()");
        }
        // Old metadata goes first: until the new one lands, the pair counts as incomplete and restores miss.
        @unlink($this->metaFile($dump));
        if (!rename($partial, $dump)) {
            throw new RuntimeException("cannot move {$partial} into place");
        }
        $meta['bytes'] = filesize($dump);
        $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $metaPartial = $this->metaFile($dump) . '.' . bin2hex(random_bytes(6)) . '.partial';
        if (file_put_contents($metaPartial, $json) === false || !rename($metaPartial, $this->metaFile($dump))) {
            throw new RuntimeException("cannot write the metadata of {$dump}");
        }
    }

    public function remove(string $profile, string $size, string $key): bool
    {
        $dump = $this->dump($profile, $size, $key);
        $found = is_file($dump);
        @unlink($this->metaFile($dump));
        @unlink($dump);
        return $found;
    }

    /**
     * Keep the newest $keep snapshots per profile and size.
     *
     * @return list<string> removed dump files
     */
    public function prune(int $keep, ?string $profile = null, ?string $size = null): array
    {
        $seen = [];
        $removed = [];
        foreach ($this->all() as $meta) {
            $group = $meta['profile'] . '/' . $meta['size'];
            if (($profile !== null && $meta['profile'] !== $profile) || ($size !== null && $meta['size'] !== $size)) {
                continue;
            }
            $seen[$group] = ($seen[$group] ?? 0) + 1;
            if ($seen[$group] > $keep) {
                $this->remove((string) $meta['profile'], (string) $meta['size'], (string) $meta['key']);
                $removed[] = $this->dump((string) $meta['profile'], (string) $meta['size'], (string) $meta['key']);
            }
        }
        return $removed;
    }

    private function metaFile(string $dump): string
    {
        return $dump . '.json';
    }

    /** @return array<string, mixed> */
    private function read(string $meta): array
    {
        $data = json_decode((string) file_get_contents($meta), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['profile'], $data['size'], $data['key'], $data['generated_on'], $data['core_version'])) {
            throw new RuntimeException("{$meta} is not snapshot metadata");
        }
        return $data;
    }
}
