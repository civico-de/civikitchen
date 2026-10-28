<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Fleet-wide phpat architecture rules, auto-registered from the image.
 *
 * Boundary rule: an extension may depend on CiviCRM core, on itself and on
 * every extension its info.xml declares in <requires><ext> (ExtensionBoundary).
 * Every other CRM_/Civi\ symbol is another extension's internals, and the
 * supported way across that line is APIv4.
 *
 * Legacy-UI rule: because phpat resolves the full ancestry, a class extending
 * an own intermediate base or a concrete core report is caught too.
 *
 * Both rules derive the extension from the working directory and stay inert
 * outside an extension root. Grandfathered code gets an ignoreErrors entry in
 * the repo's phpstan.neon.dist with a reason.
 */
final class ArchitectureTest
{
    private string $extensionDir;

    public function __construct(string $currentWorkingDirectory)
    {
        $this->extensionDir = $currentWorkingDirectory;
    }

    public function testOnlyCoreAndOwnCivicrmDependencies(): Rule
    {
        $allowed = ExtensionBoundary::allowed($this->extensionDir);
        if ($allowed['regexes'] === []) {
            return self::inertRule();
        }

        return PHPat::rule()
            ->classes(Selector::all())
            ->shouldNot()->dependOn()
            ->classes(Selector::classname('~^(CRM_|Civi\\\\)~', true))
            ->excluding(
                Selector::classname(ExtensionBoundary::coreRegex(), true),
                ...array_map(static fn (string $regex) => Selector::classname($regex, true), $allowed['regexes']),
            )
            ->because(ExtensionBoundary::because($allowed['missing']));
    }

    public function testNoLegacyUiBases(): Rule
    {
        if (ExtensionBoundary::prefixRegexes($this->extensionDir) === []) {
            return self::inertRule();
        }

        return PHPat::rule()
            ->classes(Selector::all())
            ->shouldNot()->extend()
            ->classes(...array_map(static fn (string $class) => Selector::classname($class), ExtensionBoundary::LEGACY_UI_BASES))
            ->because(ExtensionBoundary::LEGACY_UI_BECAUSE);
    }

    /** A rule whose subject can never match — \0 is not valid in a classname. */
    private static function inertRule(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname('~\x00~', true))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname('~\x00~', true))
            ->because('inert outside an extension root');
    }
}
