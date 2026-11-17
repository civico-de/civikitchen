<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PHPStan\Reflection\PropertyReflection;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;

/**
 * Declares APIv4 action parameters written, read and initialized: the kernel
 * assigns them, so `checkUninitializedProperties` would report every one.
 * Api4ActionPropertyRule judges them instead.
 */
final class Api4ActionPropertiesExtension implements ReadWritePropertiesExtension
{
    public function isAlwaysRead(PropertyReflection $property, string $propertyName): bool
    {
        return self::isApiParameter($property, $propertyName);
    }

    public function isAlwaysWritten(PropertyReflection $property, string $propertyName): bool
    {
        return self::isApiParameter($property, $propertyName);
    }

    public function isInitialized(PropertyReflection $property, string $propertyName): bool
    {
        return self::isApiParameter($property, $propertyName);
    }

    /**
     * A protected property without a leading underscore on an action class;
     * core's getParamInfo() reads IS_PROTECTED only, so a public one is not.
     */
    private static function isApiParameter(PropertyReflection $property, string $propertyName): bool
    {
        if (str_starts_with($propertyName, '_') || $property->isPrivate() || $property->isPublic() || $property->isStatic()) {
            return false;
        }

        for ($class = $property->getDeclaringClass(); $class !== null; $class = $class->getParentClass()) {
            if ($class->getName() === 'Civi\\Api4\\Generic\\AbstractAction') {
                return true;
            }
        }

        return false;
    }
}
