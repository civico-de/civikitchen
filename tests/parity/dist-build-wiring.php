<?php

declare(strict_types=1);

/**
 * policy.dist.build output is not in git, yet the extension's tests and pages
 * load it. Every job that runs them has to build it first, in a step gated on
 * the key job's dist_build_tool output, and the key job has to provide it.
 *
 * Usage: php dist-build-wiring.php <workflow.yml> [<workflow.yml> ...]
 */

require_once __DIR__ . '/workflow-jobs.php';

$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "usage: dist-build-wiring.php <workflow.yml> ...\n");
    exit(2);
}

/** A step that runs the extension's PHP suite, its gates or its browser tests. */
function ck_runs_suite(array $step): bool
{
    return preg_match('/\b(ck ci|ckphpunit|ckmutate|test:e2e)\b/', (string) ($step['run'] ?? '')) === 1;
}

/** A step that builds the declared release output. */
function ck_builds(array $step): bool
{
    if (!str_contains((string) ($step['if'] ?? ''), 'needs.key.outputs.dist_build_tool')) {
        return false;
    }
    return str_contains((string) ($step['uses'] ?? ''), '/release-build')
        || preg_match('/^\s*(php "\$CK" playwright )?bun run build\s*$/m', (string) ($step['run'] ?? '')) === 1;
}

$problems = [];
foreach ($files as $file) {
    $jobs = ck_jobs_parsed($file);
    $wired = false;
    foreach ($jobs as $job => $definition) {
        $built = false;
        foreach ((array) ($definition['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }
            if (ck_builds($step)) {
                $built = $wired = true;
            }
            if (ck_runs_suite($step) && !$built) {
                $problems[] = "$file: job '$job' runs the suite without building the declared release output first";
                break;
            }
        }
    }
    if ($wired && !str_contains(ck_scalar_text($jobs['key']['outputs'] ?? null), 'dist-build')) {
        $problems[] = "$file: jobs build the declared release output, but the key job does not output dist_build_tool";
    }
}

if ($problems !== []) {
    foreach ($problems as $problem) {
        fwrite(STDERR, "$problem\n");
    }
    exit(1);
}

exit(0);
