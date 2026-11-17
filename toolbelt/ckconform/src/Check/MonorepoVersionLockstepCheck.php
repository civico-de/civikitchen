<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Extensions of one repository whose info.xml versions or release dates differ:
 * one `vX.Y.Z` tag releases them all.
 */
final class MonorepoVersionLockstepCheck implements Check
{
    public function name(): string
    {
        return 'monorepo-version-lockstep';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isMonorepo()) {
            return;
        }
        $extensions = $context->repositoryExtensions();
        if (count($extensions) < 2) {
            return;
        }

        $stamps = [];
        foreach ($extensions as $directory => $info) {
            $version = isset($info->version) ? trim((string) $info->version) : '';
            $date = isset($info->releaseDate) ? trim((string) $info->releaseDate) : '';
            $stamps[$version . ' of ' . $date][] = $directory;
        }

        if (count($stamps) === 1) {
            $reporter->ok('every extension in this repository carries ' . array_key_first($stamps));

            return;
        }

        $described = [];
        foreach ($stamps as $stamp => $directories) {
            $described[] = implode(', ', $directories) . ': ' . $stamp;
        }

        $reporter->fail(
            'the extensions in this repository carry different versions (' . implode('; ', $described)
            . ') — one tag releases all of them, so a lagging info.xml is tagged with a version it does not carry'
        );
    }
}
