<?php

declare(strict_types=1);

/**
 * Every job of a GitLab pipeline has to carry `rules`, its own or through
 * `extends`: GitLab leaves a job without them out of merge request pipelines,
 * and the merge request then shows a green status for the jobs that did run.
 *
 * Usage: php gitlab-mr-jobs.php <pipeline.yml> [<pipeline.yml> ...]
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

const CK_GITLAB_GLOBAL_KEYS = [
    'after_script', 'before_script', 'cache', 'default', 'image', 'include',
    'services', 'spec', 'stages', 'variables', 'workflow',
];

/**
 * @param array<string, mixed> $document
 * @param list<string> $seen
 */
function ck_has_rules(array $document, string $job, array $seen = []): bool
{
    $definition = $document[$job] ?? null;
    if (!is_array($definition) || in_array($job, $seen, true)) {
        return false;
    }
    if (array_key_exists('rules', $definition)) {
        return true;
    }
    foreach ((array) ($definition['extends'] ?? []) as $parent) {
        if (is_string($parent) && ck_has_rules($document, $parent, [...$seen, $job])) {
            return true;
        }
    }

    return false;
}

$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "usage: gitlab-mr-jobs.php <pipeline.yml> ...\n");
    exit(2);
}

$problems = [];
foreach ($files as $file) {
    $document = Symfony\Component\Yaml\Yaml::parseFile($file);
    if (!is_array($document)) {
        $problems[] = "{$file}: not a YAML mapping";
        continue;
    }
    foreach ($document as $job => $definition) {
        $job = (string) $job;
        if (str_starts_with($job, '.') || in_array($job, CK_GITLAB_GLOBAL_KEYS, true) || !is_array($definition)) {
            continue;
        }
        if (!ck_has_rules($document, $job)) {
            $problems[] = "{$file}: job '{$job}' has no rules, so merge request pipelines leave it out";
        }
    }
}

foreach ($problems as $problem) {
    fwrite(STDERR, $problem . "\n");
}
exit($problems === [] ? 0 : 1);
