<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

use CiviKitchen\Toolbelt\Version\SemVer;
use PHPUnit\Framework\TestCase;

final class SemVerTest extends TestCase
{
    public function testOrdersBySemverPrecedence(): void
    {
        // semver.org §11 example chain, plus numeric-vs-numeric pre-release ids.
        $ordered = [
            '1.0.0-alpha', '1.0.0-alpha.1', '1.0.0-alpha.2', '1.0.0-alpha.10', '1.0.0-alpha.beta',
            '1.0.0-beta', '1.0.0-beta.2', '1.0.0-beta.11', '1.0.0-rc.1', '1.0.0', '1.0.1', '1.1.0', '2.0.0',
        ];
        foreach ($ordered as $i => $lower) {
            foreach (array_slice($ordered, $i + 1) as $higher) {
                self::assertLessThan(0, SemVer::compare($lower, $higher), "{$lower} < {$higher}");
                self::assertGreaterThan(0, SemVer::compare($higher, $lower), "{$higher} > {$lower}");
            }
            self::assertSame(0, SemVer::compare($lower, $lower));
        }
    }

    public function testOrdersNumbersBeyondTheIntegerRange(): void
    {
        self::assertLessThan(0, SemVer::compare('1.0.0-999999999999999999999', '1.0.0-1000000000000000000000'));
        self::assertLessThan(0, SemVer::compare('99999999999999999999.0.0', '100000000000000000000.0.0'));
    }

    public function testRejectsWhatIsNotSemver(): void
    {
        foreach (['1.0', '2.2.7.1', '01.0.0', '1.0.0-01', '1.0.0-', 'v1.0.0', ''] as $version) {
            self::assertFalse(SemVer::valid($version), $version);
        }
        self::assertTrue(SemVer::valid('0.1.0-alpha3'));
    }
}
