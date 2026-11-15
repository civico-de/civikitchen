<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A CI job that runs the suite but never measures it tells you the tests still
 * pass and nothing about whether they still cover anything.
 *
 * Scope is Context::scopedWorkflows(): every workflow file, and in a repository
 * of several extensions only the caller job that runs this one.
 */
final class CiCoverageCheck implements Check
{
    public function name(): string
    {
        return 'ci-coverage';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->hasShippedUnder('tests/phpunit')) {
            return;
        }

        $reachable = CiCommands::reachable($context);
        if ($reachable === '') {
            return;
        }

        $ran = match (true) {
            CiCommands::runs($reachable, 'ckcoverage') => 'ckcoverage',
            str_contains($reachable, '--coverage-') => 'report-only',
            default => '',
        };

        // A declared floor with nothing to enforce it reads like a gate and stops
        // nothing: `phpunit --coverage-text` prints a percentage and always exits 0.
        if ($context->policyValue('min_coverage') !== null && $ran !== 'ckcoverage') {
            $reporter->fail(
                'civikitchen.yaml sets min_coverage but no workflow runs ckcoverage — '
                . ($ran === 'report-only'
                    ? 'phpunit --coverage-* only reports, it never fails'
                    : 'nothing measures coverage at all')
            );

            return;
        }

        if ($ran !== '') {
            return;
        }

        $reporter->warn('CI runs tests without coverage — add ckcoverage');
    }

}
