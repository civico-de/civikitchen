<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\ExtensionUtilStub;
use CiviKitchen\Ckconform\Reporter;

/**
 * Evaluates the extension's tracked `*.mgd.php` files (anywhere in the tree)
 * with a stubbed ExtensionUtil; a file that throws is a warning naming $subject.
 */
final class ManagedFiles
{
    /**
     * The evaluated records of every mgd file, as [file, records] pairs. A
     * non-array file goes to $onNotArray, a throwing one warns and goes to $onUnevaluated.
     *
     * @param  callable(string): void|null            $onNotArray
     * @param  callable(string): void|null            $onUnevaluated
     * @return \Generator<array{string, array<mixed>}>
     */
    public static function records(
        Context $context,
        Reporter $reporter,
        string $subject,
        ?callable $onNotArray = null,
        ?callable $onUnevaluated = null,
    ): \Generator {
        $files = self::files($context);
        if ($files === []) {
            return;
        }

        ExtensionUtilStub::register($context);

        foreach ($files as $relative) {
            try {
                $records = require $context->path($relative);
            } catch (\Throwable $e) {
                $reporter->warn("$relative: could not evaluate managed file outside CiviCRM ({$e->getMessage()}) — $subject");
                if ($onUnevaluated !== null) {
                    $onUnevaluated($relative);
                }
                continue;
            }
            if (!is_array($records)) {
                if ($onNotArray !== null) {
                    $onNotArray($relative);
                }
                continue;
            }
            yield [$relative, $records];
        }
    }

    /** @return list<string> */
    public static function files(Context $context): array
    {
        return $context->isGitRepo()
            ? $context->trackedUnder('', ['.mgd.php'])
            : $context->findFiles('', ['.mgd.php']);
    }
}
