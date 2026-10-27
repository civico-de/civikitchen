<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Filesystem;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class Directory
{
    /** A new directory under the system temp dir that only this user can open. */
    public static function temporary(string $prefix): string
    {
        $directory = sys_get_temp_dir() . "/{$prefix}-" . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException("cannot create {$directory}");
        }
        return $directory;
    }

    /** Removes $directory with everything in it; a link inside is removed, not followed. */
    public static function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
