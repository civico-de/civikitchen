<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Toolbelt\Changelog\Changelog;

/**
 * Every repository keeps one CHANGELOG.md at its root in the grammar ckchangelog
 * defines: its version section is the release notes, so a malformed file is a
 * red build here rather than a failed release later.
 */
final class ChangelogCheck implements Check
{
    public function name(): string
    {
        return 'changelog';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $path = $context->repositoryRoot() . '/' . Changelog::FILE;
        if (!is_file($path)) {
            $reporter->fail(
                'no ' . Changelog::FILE . ' at the repository root — its section for a version is that release\'s notes; '
                . 'start one with "## [Unreleased]" (format: ckchangelog --help)'
            );

            return;
        }
        $changelog = Changelog::read($path);
        foreach ($changelog->errors() as $error) {
            $reporter->fail(substr($error, strlen($context->repositoryRoot()) + 1));
        }

        $version = $context->infoVersion();
        if ($version !== '' && in_array('v' . $version, $context->tags(), true)
            && !in_array($version, $changelog->versions(), true)) {
            $reporter->fail("info.xml {$version} is released as v{$version}, but " . Changelog::FILE . ' has no section for it');
        }
    }
}
