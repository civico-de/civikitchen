<?php

declare(strict_types=1);

/**
 * Every concurrency group names the caller workflow and the run's image, a
 * matrix job's group also what its legs differ in, and no two jobs share one;
 * otherwise a newer run cancels jobs that are not its own copy.
 *
 * Usage: php concurrency-groups.php <workflow.yml> [<workflow.yml> ...]
 */

require_once __DIR__ . '/workflow-jobs.php';

$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "usage: concurrency-groups.php <workflow.yml> ...\n");
    exit(2);
}

$problems = [];
foreach ($files as $file) {
    $owners = [];
    foreach (ck_jobs_parsed($file) as $job => $definition) {
        $concurrency = $definition['concurrency'] ?? null;
        $group = ck_scalar_text(is_array($concurrency) ? ($concurrency['group'] ?? null) : $concurrency);
        if ($group === '') {
            continue;
        }
        $owners[$group][] = $job;

        foreach (array_merge(['github.workflow', 'inputs.image'], ck_matrix_contexts($definition)) as $context) {
            if (!ck_expression_reads($group, $context)) {
                $problems[] = "$file: job '$job' concurrency group does not name $context";
            }
        }
    }
    if ($owners === []) {
        $problems[] = "$file: no job has a concurrency group";
    }
    foreach ($owners as $jobs) {
        if (count($jobs) > 1) {
            $problems[] = "$file: jobs '" . implode("', '", $jobs) . "' share one concurrency group";
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
