<?php

declare(strict_types=1);

/**
 * Parsed-YAML access to a workflow's jobs, shared by the workflow-structure
 * checkers in this directory.
 */

/**
 * The whole workflow document, parsed as YAML.
 *
 * @return array<mixed>
 */
function ck_workflow_parsed(string $file): array
{
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

    $document = Symfony\Component\Yaml\Yaml::parseFile($file);

    return is_array($document) ? $document : [];
}

/**
 * The workflow's `jobs:` mapping, parsed as YAML.
 *
 * Comments are gone by construction, so a check built on this cannot be
 * talked into passing by a comment that mentions what the job does not do.
 *
 * @return array<string, array<mixed>>
 */
function ck_jobs_parsed(string $file): array
{
    $document = ck_workflow_parsed($file);
    if (!is_array($document['jobs'] ?? null)) {
        return [];
    }

    $jobs = [];
    foreach ($document['jobs'] as $name => $job) {
        if (is_array($job)) {
            $jobs[(string) $name] = $job;
        }
    }

    return $jobs;
}

/**
 * Every scalar of a parsed job, one per line, backslash continuations joined.
 *
 * A compose invocation routinely wraps, so `up` lands on a later line than
 * `docker compose`. Join them first or the boot goes unseen.
 */
function ck_job_text(array $job): string
{
    $text = ck_scalar_text($job);

    return preg_replace('/\\\\\n\s*/', ' ', $text) ?? $text;
}

/**
 * Every scalar of one parsed YAML value, one per line.
 *
 * A key like `runs-on` carries a string, a list of labels or a group mapping;
 * all three answer the same question and this flattens them to one text.
 */
function ck_scalar_text(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_scalar($value)) {
        return (string) $value;
    }
    if (!is_array($value)) {
        return '';
    }

    $scalars = [];
    array_walk_recursive($value, static function ($leaf) use (&$scalars): void {
        if (is_scalar($leaf)) {
            $scalars[] = (string) $leaf;
        }
    });

    return implode("\n", $scalars);
}

/**
 * Whether a `${{ }}` expression in $text reads $context, a context or one of
 * its properties (`matrix`, `github.workflow`).
 *
 * Text outside the expressions and quoted strings inside them do not count,
 * and neither does a longer name: `github.workflow_ref` is not `github.workflow`.
 */
function ck_expression_reads(string $text, string $context): bool
{
    preg_match_all('/\$\{\{(.*?)\}\}/s', $text, $expressions);
    $reference = '/(?<![\w.-])' . preg_quote($context, '/') . '(?![\w-])/';
    foreach ($expressions[1] as $expression) {
        if (preg_match($reference, preg_replace("/'(?:[^']|'')*'/", "''", $expression) ?? $expression) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * What the legs of a matrix job differ in: `matrix.<key>` for each vector key,
 * or `matrix` itself where the YAML does not spell the keys out. Empty without a matrix.
 *
 * @return list<string>
 */
function ck_matrix_contexts(array $job): array
{
    $matrix = $job['strategy']['matrix'] ?? null;
    if ($matrix === null) {
        return [];
    }
    $vectors = is_array($matrix) ? array_diff(array_map('strval', array_keys($matrix)), ['include', 'exclude']) : [];
    if ($vectors === []) {
        return ['matrix'];
    }

    return array_values(array_map(static fn (string $key): string => "matrix.$key", $vectors));
}

/**
 * The `uses:` values of the job's steps, in order.
 *
 * @return list<string>
 */
function ck_job_uses(array $job): array
{
    $uses = [];
    foreach ($job['steps'] ?? [] as $step) {
        if (is_array($step) && is_string($step['uses'] ?? null)) {
            $uses[] = $step['uses'];
        }
    }

    return $uses;
}
