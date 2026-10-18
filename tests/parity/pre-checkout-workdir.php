<?php

declare(strict_types=1);

/**
 * A `run` step before the job's checkout cannot start in a subdirectory of the
 * workspace: on a fresh runner the directory does not exist yet.
 *
 * Usage: php pre-checkout-workdir.php <workflow.yml> [<workflow.yml> ...]
 */

require_once __DIR__ . '/workflow-jobs.php';

$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "usage: pre-checkout-workdir.php <workflow.yml> ...\n");
    exit(2);
}

/** The step's working directory, or null for the workspace root. */
function ck_step_workdir(array $step, mixed $default): ?string
{
    $dir = array_key_exists('working-directory', $step) ? $step['working-directory'] : $default;
    if (!is_scalar($dir) || in_array(trim((string) $dir), ['', '.', './', '${{ github.workspace }}'], true)) {
        return null;
    }

    return (string) $dir;
}

$problems = [];
foreach ($files as $file) {
    $workflowDefault = ck_workflow_parsed($file)['defaults']['run']['working-directory'] ?? null;
    foreach (ck_jobs_parsed($file) as $job => $definition) {
        $default = $definition['defaults']['run']['working-directory'] ?? $workflowDefault;
        foreach ((array) ($definition['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }
            if (str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@')) {
                break;
            }
            $dir = ck_step_workdir($step, $default);
            if (array_key_exists('run', $step) && $dir !== null) {
                $name = (string) ($step['name'] ?? $step['run']);
                $problems[] = "$file: job '$job' step '$name' runs in '$dir' before the checkout creates it";
            }
        }
    }
}

if ($problems !== []) {
    foreach ($problems as $problem) {
        fwrite(STDERR, "$problem\n");
    }
    exit(1);
}

exit(0);
