<?php

declare(strict_types=1);

/**
 * Matrix legs that may share a Docker host each boot their own compose project,
 * and every leg uploads under its own artifact name.
 *
 * Usage: php matrix-legs.php <workflow.yml> [<workflow.yml> ...]
 */

require_once __DIR__ . '/workflow-jobs.php';

$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "usage: matrix-legs.php <workflow.yml> ...\n");
    exit(2);
}

$problems = [];
foreach ($files as $file) {
    foreach (ck_jobs_parsed($file) as $job => $definition) {
        if (!isset($definition['strategy']['matrix'])) {
            continue;
        }

        // The stack-boot and runner tests of compose-project-isolation.php.
        $boots = preg_match('/docker compose\\b[^\\n]*\\bup\\b/', ck_job_text($definition)) === 1;
        $runsOn = ck_scalar_text($definition['runs-on'] ?? null);
        $hosted = $runsOn !== '' && !str_contains($runsOn, '${{');
        $project = ck_scalar_text($definition['env']['COMPOSE_PROJECT_NAME'] ?? null);
        if ($boots && !$hosted && !ck_expression_reads($project, 'strategy.job-index')) {
            $problems[] = "$file: job '$job' boots one compose project for all its legs (name it per leg with strategy.job-index in the job env)";
        }

        $legs = ck_matrix_contexts($definition);
        foreach ((array) ($definition['steps'] ?? []) as $step) {
            if (!is_array($step) || !str_contains((string) ($step['uses'] ?? ''), 'actions/upload-artifact')) {
                continue;
            }
            $name = ck_scalar_text($step['with']['name'] ?? null);
            $unnamed = array_filter($legs, static fn (string $context): bool => !ck_expression_reads($name, $context));
            if (!ck_expression_reads($name, 'strategy.job-index') && $unnamed !== []) {
                $problems[] = "$file: job '$job' uploads an artifact under a name its legs share (name it with strategy.job-index or every matrix key)";
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
