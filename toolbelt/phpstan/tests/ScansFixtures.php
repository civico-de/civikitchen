<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

/** Lets phpstan's reflection see the fixture stubs, which stay off the shipped autoloader. */
trait ScansFixtures
{

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [...parent::getAdditionalConfigFiles(), __DIR__ . '/fixtures.neon'];
    }

}
