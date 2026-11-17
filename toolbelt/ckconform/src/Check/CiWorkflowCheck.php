<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A repository without CI.
 */
final class CiWorkflowCheck implements Check
{
    public function name(): string
    {
        return 'ci-workflow';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if ($context->workflows() === [] && $context->gitlabPipeline() === null) {
            $reporter->fail('no CI workflow (.github/workflows/ or .gitlab-ci.yml)');

            return;
        }

        // Reported here only: every workflow-reading check is silent when no
        // caller job names this extension's directory.
        $scopeFailure = $context->workflowScopeFailure();
        if ($scopeFailure !== null) {
            $reporter->fail($scopeFailure);

            return;
        }

        $reporter->ok('CI workflow present');

        // Judged on the jobs that run this extension: a neighbour's lint step
        // says nothing about whether this extension is linted.
        $reachable = CiCommands::reachable($context);
        if (CiCommands::runs($reachable, 'cklint')) {
            return;
        }

        $reporter->warn('CI has no lint step (cklint)');
    }
}
