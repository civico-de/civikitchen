<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\DistPaths;
use CiviKitchen\Ckconform\Reporter;

/**
 * Shipped changes (DistPaths) behind the newest tag whose oldest commit has
 * waited longer than `max_unreleased_days`; a warning.
 */
final class UnreleasedShippedChangesCheck implements Check
{
    private const DEFAULT_MAX_DAYS = 30;

    public function name(): string
    {
        return 'unreleased-shipped-changes';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $maxDays = $this->maxDays($context, $reporter);
        if ($maxDays === null) {
            return;
        }

        $history = ReleaseHistory::read($context);
        if (!$history->evaluable($this->name(), $reporter)) {
            return;
        }

        $tag = (string) $history->tag;
        $commits = $context->commitsSince($tag);
        if ($commits === null) {
            $reporter->warn("{$this->name()} not evaluated: git could not read {$tag}..HEAD");

            return;
        }

        $excluded = DistPaths::excluded($context);
        $oldest = null;
        foreach ($commits as $commit) {
            foreach ($commit['files'] as $file) {
                if (DistPaths::ships($file, $excluded)) {
                    // The log is newest first, so the last match is the oldest.
                    $oldest = $commit;
                    break;
                }
            }
        }
        if ($oldest === null) {
            return;
        }

        $age = $this->ageInDays($oldest['date']);
        if ($age === null || $age < $maxDays) {
            return;
        }

        $reporter->warn(sprintf(
            'shipped code has been unreleased for %d days — %s..HEAD changes what an install gets, '
            . 'oldest %s (%s), and no site has any of it until the next tag',
            $age,
            $tag,
            substr($oldest['hash'], 0, 8),
            substr($oldest['date'], 0, 10),
        ));
    }

    /** The repo's threshold, or null when it declared one that is not a number. */
    private function maxDays(Context $context, Reporter $reporter): ?int
    {
        $declared = $context->policyValue('max_unreleased_days');
        if ($declared === null) {
            return self::DEFAULT_MAX_DAYS;
        }
        if (preg_match('/^[1-9]\d*$/', $declared) !== 1) {
            $reporter->fail(
                "civikitchen.yaml: max_unreleased_days must be a whole number of days, got '{$declared}'"
            );

            return null;
        }

        return (int) $declared;
    }

    private function ageInDays(string $isoDate): ?int
    {
        $timestamp = strtotime($isoDate);

        return $timestamp === false ? null : intdiv(time() - $timestamp, 86400);
    }
}
