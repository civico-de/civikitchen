<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The committed hook and permission catalogs must match their generators.
 * Skipped when no core checkout is around.
 */
final class HookCatalogDriftTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function catalogs(): iterable
    {
        yield 'hooks' => ['gen-hook-catalog.php', 'HookCatalog.php'];
        yield 'permissions' => ['gen-permission-catalog.php', 'PermissionCatalog.php'];
    }

    /** @dataProvider catalogs */
    public function testTheCommittedCatalogMatchesTheGenerator(string $tool, string $catalog): void
    {
        $coreDir = getenv('CIVICRM_CORE_DIR') ?: '/var/www/html/core';
        if (!is_file($coreDir . '/CRM/Utils/Hook.php')) {
            self::markTestSkipped("no CiviCRM core at $coreDir (set CIVICRM_CORE_DIR)");
        }

        $generator = dirname(__DIR__) . '/tools/' . $tool;
        $committed = dirname(__DIR__) . '/src/' . $catalog;
        $fresh = tempnam(sys_get_temp_dir(), 'hookcatalog');

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
                "src/$catalog is stale — regenerate: php tools/$tool $coreDir",
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
