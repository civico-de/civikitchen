<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A workflow without a top-level (column 0) `permissions:` block, whose token
 * then inherits the repository default; job-level blocks do not count.
 */
final class WorkflowPermissionsCheck implements Check
{
    public function name(): string
    {
        return 'workflow-permissions';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        foreach ($context->workflows() as $workflow) {
            $contents = $context->read($workflow) ?? '';
            if (!preg_match('/^permissions:/m', $contents)) {
                $reporter->warn(
                    "{$workflow} declares no 'permissions:' block — the job token inherits the repo default"
                );
            }
        }
    }
}
