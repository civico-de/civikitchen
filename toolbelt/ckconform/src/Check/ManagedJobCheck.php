<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\Scalar;

/**
 * Managed Job defects: missing api_entity/api_action, unknown run_frequency,
 * malformed `parameters`, `is_active => TRUE` with update `always` (re-enables
 * on every reconcile), an APIv4-only entity without version=4, an unshipped own API.
 */
final class ManagedJobCheck implements Check
{
    private const FREQUENCIES = ['Always', 'Hourly', 'Daily', 'Weekly', 'Monthly', 'Quarter', 'Yearly'];

    public function name(): string
    {
        return 'managed-job';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $repoFiles = $context->isGitRepo() ? $context->trackedFiles() : $context->findFiles('');
        $prefix = $context->shortName();

        foreach (ManagedFiles::records($context, $reporter, 'managed jobs unchecked') as [$relative, $records]) {
            foreach ($records as $index => $record) {
                if (!is_array($record) || ($record['entity'] ?? null) !== 'Job') {
                    continue;
                }
                $params = $record['params'] ?? null;
                if (!is_array($params)) {
                    continue;
                }
                $label = is_string($record['name'] ?? null)
                    ? "$relative job '{$record['name']}'"
                    : "$relative job #$index";

                // v4 puts the record under 'values'; v3 spreads it into params.
                $values = ((int) ($params['version'] ?? 4) === 4)
                    ? (is_array($params['values'] ?? null) ? $params['values'] : null)
                    : $params;
                if ($values === null) {
                    continue;
                }

                foreach (['api_entity', 'api_action'] as $key) {
                    if (!isset($values[$key]) || !is_string($values[$key]) || $values[$key] === '') {
                        $reporter->fail("$label: no $key — the job throws on every cron pass");
                    }
                }

                if (isset($values['run_frequency'])
                    && !in_array($values['run_frequency'], self::FREQUENCIES, true)
                ) {
                    $reporter->fail("$label: run_frequency '" . Scalar::describe($values['run_frequency']) . "' is not one of " . implode('|', self::FREQUENCIES));
                }

                if (isset($values['parameters']) && !is_string($values['parameters'])) {
                    $reporter->fail("$label: 'parameters' is " . get_debug_type($values['parameters']) . ", not a string — Civi parses it line by line, so the job would run without its arguments");
                } elseif (is_string($values['parameters'] ?? null)) {
                    foreach (self::parameterErrors($values['parameters']) as $problem) {
                        $reporter->fail("$label: $problem");
                    }
                }

                $update = $record['update'] ?? null;
                if (($update === null || $update === 'always')
                    && in_array($values['is_active'] ?? null, [true, 1, '1'], true)
                ) {
                    $reporter->warn("$label: is_active is set and update is '" . ($update ?? 'always (default)') . "' — an upgrade/reconcile re-enables a job an admin disabled; set update => 'never' or omit is_active");
                }

                $entity = $values['api_entity'] ?? null;
                if (is_string($entity) && $entity !== '' && $prefix !== null
                    && str_starts_with(strtolower($entity), strtolower($prefix))
                    && !self::shipsApi($repoFiles, $entity)
                ) {
                    $reporter->warn("$label: api_entity '$entity' looks like this extension's own API but neither Civi/Api4/$entity.php nor an api/v3/$entity*.php or api/v3/$entity/*.php is in the repo");
                }

                if (is_string($entity) && $entity !== '' && self::shipsApi4Only($repoFiles, $entity)) {
                    $parameters = $values['parameters'] ?? null;
                    if ($parameters === null || is_string($parameters)) {
                        $parsed = self::parseParameters($parameters);
                        $version = $parsed['version'] ?? 3;
                        if (!is_scalar($version) || (int) $version !== 4) {
                            $reporter->fail("$label: api_entity '$entity' is APIv4-only but parameters do not set version=4 — the runner calls APIv3 and the job fails on every cron pass");
                        } elseif (!self::disablesPermissions($parsed['checkPermissions'] ?? null)) {
                            $reporter->warn("$label: api_entity '$entity' is APIv4-only and parameters do not set checkPermissions=0" . self::truthyPermissionHint($parsed['checkPermissions'] ?? null) . " — APIv4 checks permissions by default and the cron user usually has none");
                        }
                    }
                }
            }
        }
    }

    /**
     * @param list<string> $repoFiles
     */
    private static function shipsApi(array $repoFiles, string $entity): bool
    {
        foreach ($repoFiles as $file) {
            if (str_ends_with($file, "Civi/Api4/$entity.php")) {
                return true;
            }
            if (self::isApi3File($file, $entity)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The three APIv3 layouts for one entity: api/v3/<Entity>.php, the
     * suffixed api/v3/<Entity>Something.php, and civix's per-action directory
     * api/v3/<Entity>/<Action>.php.
     */
    private static function isApi3File(string $file, string $entity): bool
    {
        $quoted = preg_quote($entity, '#');

        return preg_match('#(^|/)api/v3/' . $quoted . '([^/]*|/[^/]+)\.php$#', $file) === 1;
    }

    /**
     * Ships Civi/Api4/<Entity>.php and no APIv3 file for the same entity.
     *
     * @param list<string> $repoFiles
     */
    private static function shipsApi4Only(array $repoFiles, string $entity): bool
    {
        $api4 = false;
        foreach ($repoFiles as $file) {
            if (self::isApi3File($file, $entity)) {
                return false;
            }
            if (str_ends_with($file, "Civi/Api4/$entity.php")) {
                $api4 = true;
            }
        }

        return $api4;
    }

    /**
     * Everything CRM_Core_BAO_Job::parseParameters() rejects, in its own terms
     * (a blank line too); core throws before the API is called.
     *
     * @return list<string>
     */
    private static function parameterErrors(string $parameters): array
    {
        $parameters = trim($parameters);
        if ($parameters === '') {
            return [];
        }
        if ($parameters[0] === '{') {
            json_decode($parameters, true);

            return json_last_error() === JSON_ERROR_NONE
                ? []
                : ["parameters start with '{' but are not valid JSON (" . json_last_error_msg() . ") — Civi throws 'Job parameters error' and the job never runs"];
        }

        $problems = [];
        foreach (explode("\n", $parameters) as $line) {
            $pair = explode('=', $line);
            if (count($pair) !== 2 || !trim($pair[0]) || trim($pair[1]) === '') {
                $problems[] = "parameters line '" . trim($line) . "' is not a single key=value pair — Civi throws 'Malformed API parameters in scheduled job' and the job never runs";
            }
        }

        return $problems;
    }

    /**
     * Whether the value turns setCheckPermissions() off under PHP coercion: 0, '0'
     * and false do; the strings 'false'/'FALSE' coerce to TRUE.
     */
    private static function disablesPermissions(mixed $value): bool
    {
        if (is_bool($value)) {
            return !$value;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value === 0.0;
        }

        return $value === '0';
    }

    private static function truthyPermissionHint(mixed $value): string
    {
        if (!is_string($value) || !in_array(strtolower(trim($value)), ['false', 'no', 'off'], true)) {
            return '';
        }

        return " ('$value' is a non-empty string, which APIv4 coerces to TRUE — only 0 turns the check off)";
    }

    /**
     * CRM_Core_BAO_Job::parseParameters(): '{' starts JSON, anything else is
     * key=value lines defaulting to version 3. Malformed input is skipped here.
     *
     * @return array<string, mixed>
     */
    private static function parseParameters(?string $parameters): array
    {
        $parameters = trim($parameters ?? '');
        if ($parameters !== '' && $parameters[0] === '{') {
            $decoded = json_decode($parameters, true);

            return is_array($decoded) ? $decoded : [];
        }

        $result = ['version' => 3];
        foreach ($parameters === '' ? [] : explode("\n", $parameters) as $line) {
            $pair = explode('=', $line);
            if (count($pair) !== 2 || trim($pair[0]) === '' || trim($pair[1]) === '') {
                continue;
            }
            $result[trim($pair[0])] = trim($pair[1]);
        }

        return $result;
    }
}
