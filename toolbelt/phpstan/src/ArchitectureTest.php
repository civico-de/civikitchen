<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * phpat architecture rules: the extension boundary (core, itself and its
 * <requires>; see ExtensionBoundary) and the legacy-UI rule. Both are inert
 * outside an extension root.
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
