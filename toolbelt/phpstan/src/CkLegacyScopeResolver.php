<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PHPStan\Analyser\Scope;
use PHPStan\Rules\Deprecations\DeprecatedScopeResolver;

/**
 * Treats code marked `@ck-legacy` as a deprecated scope, so calls it makes into
 * deprecated APIs are not reported.
 *
 * The marker counts on the class/trait or on the method/function.
 */
final class CkLegacyScopeResolver implements DeprecatedScopeResolver
{

    /**
     * Exact tag: `@ck-legacy-ish` or `@ck-legacyfoo` must not match.
     */
    private const MARKER_PATTERN = '/@ck-legacy(?![\w-])/';

    public function isScopeDeprecated(Scope $scope): bool
    {
        $function = $scope->getFunction();
        if ($function !== null && $this->hasMarker($function->getDocComment())) {
            return true;
        }

        foreach ([$scope->getClassReflection(), $scope->getTraitReflection()] as $class) {
            if ($class === null) {
                continue;
            }
            $phpDoc = $class->getResolvedPhpDoc();
            if ($phpDoc === null || !$phpDoc->hasPhpDocString()) {
                continue;
            }
            if ($this->hasMarker($phpDoc->getPhpDocString())) {
                return true;
            }
        }

        return false;
    }

    private function hasMarker(?string $docComment): bool
    {
        return $docComment !== null && preg_match(self::MARKER_PATTERN, $docComment) === 1;
    }

}
