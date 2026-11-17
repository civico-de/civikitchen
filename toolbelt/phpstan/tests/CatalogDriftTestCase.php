<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The committed catalog must match what its generator produces. Skipped when
 * no core checkout is around; CI provides one pinned to CORE_VERSION.
 */
abstract class CatalogDriftTestCase extends TestCase
{
    /** Path of the generator script. */
    abstract protected function generator(): string;

    /** Path of the committed catalog the generator writes. */
    abstract protected function committed(): string;

    /** Core file whose presence proves a usable checkout. */
    protected function coreMarker(): string
    {
        return 'Civi.php';
    }

    final public function testTheCommittedCatalogMatchesTheGenerator(): void
    {
        $coreDir = getenv('CIVICRM_CORE_DIR') ?: '/var/www/html/core';
        if (!is_file($coreDir . '/' . $this->coreMarker())) {
            self::markTestSkipped("no CiviCRM core at $coreDir (set CIVICRM_CORE_DIR)");
        }

        $generator = $this->generator();
        $committed = $this->committed();
        $fresh = tempnam(sys_get_temp_dir(), pathinfo($committed, PATHINFO_FILENAME));

        try {
            exec(sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($generator),
                escapeshellarg($coreDir),
                escapeshellarg($fresh),
            ), $output, $status);
            self::assertSame(0, $status, 'generator failed: ' . implode("\n", $output));

            // The header records the core version, which legitimately differs
            // between the checkout that generated the file and this one.
            self::assertSame(
                $this->withoutHeader((string) file_get_contents($fresh)),
                $this->withoutHeader((string) file_get_contents($committed)),
                basename(dirname($committed)) . '/' . basename($committed)
                    . ' is stale — regenerate: php tools/' . basename($generator) . ' ' . $coreDir,
            );
        } finally {
            @unlink($fresh);
        }
    }

    private function withoutHeader(string $source): string
    {
        return (string) preg_replace('#^.*?\nfinal class#s', 'final class', $source);
    }
}
