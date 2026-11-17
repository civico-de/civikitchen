<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Registry;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\Suppressions;

/**
 * Keeps the inline ignores honest.
 *
 * Three failure modes, all silent by nature: a `ckconform-ignore` without the
 * mandatory reason, one naming an unknown check, and one that silenced nothing.
 * Must run last: the other checks mark the shared Suppressions as consumed.
 */
final class SuppressionHygieneCheck implements Check
{
    public function name(): string
    {
        return 'suppression-hygiene';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $known = null;

        $files = $context->isGitRepo() ? $context->trackedFiles() : $context->findFiles('');
        foreach ($files as $file) {
            $contents = $context->read($file);
            if ($contents === null || !str_contains($contents, 'ckconform-ignore')) {
                continue;
            }
            $suppressions = preg_match('/\.(?:[cm]?[jt]sx?)$/', $file) === 1
                ? Suppressions::ofLineComments($contents)
                : Suppressions::of($contents);

            foreach ($suppressions->missingReason() as $line) {
                $reporter->warnAt($file, $line, sprintf(
                    '%s:%d: ckconform-ignore without a reason suppresses nothing — write `ckconform-ignore <check> -- <reason>`',
                    $file,
                    $line,
                ));
            }

            $known ??= array_map(static fn (Check $c): string => $c->name(), Registry::all());
            foreach ($suppressions->entries() as $entry) {
                foreach (array_diff($entry['names'], $known) as $unknown) {
                    $reporter->warnAt($file, $entry['line'], sprintf(
                        "%s:%d: ckconform-ignore names an unknown check '%s' — a dead ignore never matches",
                        $file,
                        $entry['line'],
                        $unknown,
                    ));
                }
            }

            $skipped = $context->skippedChecks();
            foreach ($suppressions->unconsumed() as $unused) {
                if (!in_array($unused['name'], $known, true)
                    || in_array($unused['name'], $skipped, true)
                ) {
                    continue;
                }
                $reporter->warnAt($file, $unused['line'], sprintf(
                    "%s:%d: ckconform-ignore%s for '%s' suppressed nothing — the finding it silenced is gone, remove it",
                    $file,
                    $unused['line'],
                    $unused['file'] ? '-file' : '',
                    $unused['name'],
                ));
            }
        }
    }
}
