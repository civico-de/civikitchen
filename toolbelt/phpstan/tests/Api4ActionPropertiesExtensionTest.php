<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\Fixtures\Actions\FarewellAction;
use CiviKitchen\Fixtures\Actions\PlainService;
use CiviKitchen\Fixtures\Actions\PublicFieldAction;
use CiviKitchen\PHPStan\Api4ActionPropertiesExtension;
use PHPStan\Testing\PHPStanTestCase;

/**
 * Only API parameters are declared initialized: protected properties of an
 * action. A public one is plain state that property.uninitialized must see.
 */
final class Api4ActionPropertiesExtensionTest extends PHPStanTestCase
{

    use ScansFixtures;

    public function testOnlyProtectedActionPropertiesAreApiParameters(): void
    {
        $reflection = self::createReflectionProvider();
        $extension = new Api4ActionPropertiesExtension();
        $initialized = static fn (string $class, string $property): bool => $extension->isInitialized(
            $reflection->getClass($class)->getNativeProperty($property),
            $property,
        );

        self::assertTrue($initialized(FarewellAction::class, 'mode'));
        self::assertTrue($initialized(FarewellAction::class, 'fromTrait'));
        self::assertFalse($initialized(PublicFieldAction::class, 'notAParam'));
        self::assertFalse($initialized(PlainService::class, 'channel'));
    }
}
