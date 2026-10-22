<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

use CiviKitchen\Ckconform\Policy;

/**
 * policy.database picks the stacks' database image, so it only offers what
 * the image build verifies and has to agree with the scenario's database.
 */
final class DatabasePolicyTest extends CheckTestCase
{
    public function testAVerifiedMariadbIsKept(): void
    {
        self::assertSame(['mariadb:10.11'], Policy::parse("version: 1\npolicy:\n  database: mariadb:10.11\n")['database']);
    }

    public function testAnAbsentKeyLeavesTheDefaultToTheConsumer(): void
    {
        self::assertArrayNotHasKey('database', Policy::parse("version: 1\npolicy:\n  license: proprietary\n"));
    }

    public function testMysqlIsRejected(): void
    {
        $this->expectExceptionMessage('$.policy.database: value is not one of');
        Policy::parse("version: 1\npolicy:\n  database: mysql:8.0\n");
    }

    public function testAnUnverifiedMariadbIsRejected(): void
    {
        $this->expectExceptionMessage('$.policy.database: value is not one of');
        Policy::parse("version: 1\npolicy:\n  database: mariadb:10.6\n");
    }

    public function testAScenarioOnAnotherDatabaseIsRejected(): void
    {
        $this->expectExceptionMessage('policy.database is mariadb:10.11, but scenario.database.image is mariadb:11.4');
        Policy::parse(self::document('mariadb:11.4', 'mariadb:10.11'));
    }

    /** @return array<string, array{string}> */
    public static function pinsOfTheSameImage(): array
    {
        return [
            'digest' => ['mariadb:10.11@sha256:' . str_repeat('a', 64)],
            'patch' => ['mariadb:10.11.19'],
            'variant' => ['mariadb:10.11-jammy'],
            'patch, variant and digest' => ['mariadb:10.11.19-jammy@sha256:' . str_repeat('a', 64)],
        ];
    }

    /** @dataProvider pinsOfTheSameImage */
    public function testAScenarioPinningTheSameImageMoreTightlyIsAccepted(string $pin): void
    {
        self::assertSame(['mariadb:10.11'], Policy::parse(self::document($pin, 'mariadb:10.11'))['database']);
    }

    public function testAScenarioOnALongerMinorIsRejected(): void
    {
        $this->expectExceptionMessage('policy.database is mariadb:11.4, but scenario.database.image is mariadb:11.40');
        Policy::parse(self::document('mariadb:11.40', 'mariadb:11.4'));
    }

    private static function document(string $scenarioDatabase, string $policyDatabase): string
    {
        return "version: 1\nscenario:\n  name: acme\n  image: ghcr.io/civico-de/civikitchen:v1\n"
            . "  database:\n    image: {$scenarioDatabase}\n"
            . "  extension:\n    key: org.example.myext\n    path: .\n"
            . "policy:\n  database: {$policyDatabase}\n";
    }
}
