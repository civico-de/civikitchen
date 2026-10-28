<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\PHPStan\NoSilentDatabaseCatchRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<NoSilentDatabaseCatchRule>
 */
final class NoSilentDatabaseCatchRuleTest extends RuleTestCase
{

    use ScansFixtures;

    private const SILENT = 'Catch around a database call neither rethrows nor logs at error level — a failed query '
        . 'becomes a substituted value the caller cannot tell from real data. '
        . 'Rethrow, or \\Civi::log()->error() with the context.';

    private const EMPTY_CATCH = 'Empty catch around a database call — the query can fail and nothing will say so. '
        . 'Rethrow, or \\Civi::log()->error() with the context.';

    public function testOnlyCatchesThatSwallowQueryFailuresAreReported(): void
    {
        $this->analyse(
            [
                __DIR__ . '/fixtures/api4-stubs.php',
                __DIR__ . '/fixtures/generic-stubs.php',
                __DIR__ . '/fixtures/sql-stubs.php',
                __DIR__ . '/fixtures/silent-database-catch.php',
            ],
            [
                [self::SILENT, 16],
                [self::EMPTY_CATCH, 25],
                [self::SILENT, 34],
                [self::EMPTY_CATCH, 65],
                [self::SILENT, 103],
                [self::EMPTY_CATCH, 113],
                [self::EMPTY_CATCH, 118],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new NoSilentDatabaseCatchRule();
    }
}
