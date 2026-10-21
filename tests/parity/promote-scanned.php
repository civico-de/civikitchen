<?php

declare(strict_types=1);

/**
 * No moving tag may point at an unscanned digest. Every promote job
 *  - runs only on success: no always(), cancelled() or failure() in its `if`;
 *  - needs, directly, a scan-images.yml call that scans exactly the merged
 *    candidates of its own chain: a merge-manifest.yml call in its transitive
 *    needs (reached without passing through a scan) with the same `tags` and
 *    `prefix`;
 *  - promotes `fromJSON(needs.<scan>.outputs.digests)[matrix.<key>]`, the
 *    digests that scan passed, where matrix.<key> iterates those same tags;
 *  - never names this run's candidate tag (github.sha), which another run on
 *    the same commit can overwrite after the scan.
 *
 * Usage: php promote-scanned.php <workflow.yml> [<workflow.yml> ...]
 */

require_once __DIR__ . '/workflow-jobs.php';

$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "usage: promote-scanned.php <workflow.yml> ...\n");
    exit(2);
}

/** @return list<string> */
function ck_needs(array $job): array
{
    return array_values(array_map('strval', (array) ($job['needs'] ?? [])));
}

function ck_calls(array $job, string $workflow): bool
{
    return is_string($job['uses'] ?? null) && str_ends_with($job['uses'], '/' . $workflow);
}

/**
 * One spelling per tag list: a YAML list or JSON literal becomes its JSON, an
 * expression its context path without the fromJSON/toJSON wrappers, so
 * `toJSON(fromJSON(inputs.variants).*.name)` and `fromJSON(inputs.variants).*.name`
 * agree.
 */
function ck_tag_list(mixed $value): string
{
    if (is_array($value)) {
        return json_encode(array_values($value), JSON_THROW_ON_ERROR);
    }
    $text = trim((string) $value);
    if (preg_match('/^\$\{\{(.*)\}\}$/s', $text, $m) === 1) {
        return (string) preg_replace('/\s+|fromJSON|toJSON|[()]/', '', $m[1]);
    }
    $decoded = json_decode($text, true);

    return is_array($decoded) ? json_encode(array_values($decoded), JSON_THROW_ON_ERROR) : $text;
}

/** @return array{string, string} */
function ck_call_set(array $job): array
{
    $with = (array) ($job['with'] ?? []);

    return [ck_tag_list($with['tags'] ?? ''), (string) ($with['prefix'] ?? '')];
}

$problems = [];
foreach ($files as $file) {
    $jobs = ck_jobs_parsed($file);
    $promotes = array_filter(array_keys($jobs), static fn (string $id): bool => str_starts_with($id, 'promote'));
    if ($promotes === []) {
        $problems[] = "$file: no promote job found";
    }
    foreach ($promotes as $promote) {
        $job = $jobs[$promote];
        $fail = static function (string $why) use (&$problems, $file, $promote): void {
            $problems[] = "$file: job '$promote' $why";
        };

        if (preg_match('/\b(always|cancelled|failure)\s*\(/', ck_scalar_text($job['if'] ?? '')) === 1) {
            $fail('can run after a failed scan (its `if` uses always(), cancelled() or failure())');
        }

        $seen = [];
        $queue = ck_needs($job);
        while ($queue !== []) {
            $id = array_shift($queue);
            if (!isset($seen[$id]) && isset($jobs[$id]) && !ck_calls($jobs[$id], 'scan-images.yml')) {
                $seen[$id] = true;
                array_push($queue, ...ck_needs($jobs[$id]));
            }
        }
        $merges = array_filter(array_keys($seen), static fn (string $id): bool => ck_calls($jobs[$id], 'merge-manifest.yml'));

        $scan = null;
        foreach (ck_needs($job) as $id) {
            if (!isset($jobs[$id]) || !ck_calls($jobs[$id], 'scan-images.yml')) {
                continue;
            }
            foreach (array_intersect(ck_needs($jobs[$id]), $merges) as $merge) {
                if (ck_call_set($jobs[$id]) === ck_call_set($jobs[$merge])) {
                    $scan = [$id, $merge];
                }
            }
        }
        if ($scan === null) {
            $fail('does not need a scan of exactly the candidates it promotes');
            continue;
        }
        [$scanId, $merge] = $scan;

        $text = ck_job_text($job);
        if (preg_match('/github\.sha|GITHUB_SHA/', $text) === 1) {
            $fail('names the candidate tag (github.sha) instead of the scanned digest');
        }
        $lookup = '/fromJSON\(needs\.' . preg_quote($scanId, '/') . '\.outputs\.digests\)\[matrix\.([A-Za-z0-9_-]+)\]/';
        if (preg_match_all($lookup, $text, $m) === 0) {
            $fail("does not promote the digests '$scanId' scanned");
            continue;
        }
        $matrix = (array) ($job['strategy']['matrix'] ?? []);
        foreach (array_unique($m[1]) as $key) {
            if (ck_tag_list($matrix[$key] ?? '') !== ck_call_set($jobs[$merge])[0]) {
                $fail("iterates matrix.$key, which is not the tag list '$scanId' scanned");
            }
        }
    }
}

foreach ($problems as $problem) {
    fwrite(STDERR, "$problem\n");
}
exit($problems === [] ? 0 : 1);
