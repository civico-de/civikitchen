<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use PHPStan\BetterReflection\SourceLocator\Type\AggregateSourceLocator;
use PHPStan\BetterReflection\SourceLocator\Type\SourceLocator;
use PHPStan\Reflection\BetterReflection\SourceLocator\OptimizedDirectorySourceLocatorRepository;
use PHPStan\Testing\TestCaseSourceLocatorFactory;

/** Puts the fixture stubs ahead of the test defaults, for phpstan's static reflection only. */
final class FixtureSourceLocatorFactory
{

    public static function create(OptimizedDirectorySourceLocatorRepository $directories, TestCaseSourceLocatorFactory $defaults): SourceLocator
    {
        return new AggregateSourceLocator([
            $directories->getOrCreate(__DIR__ . '/fixtures'),
            $defaults->create(),
        ]);
    }

}
