<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Seed;

/**
 * What a snapshot leaves out: the rows of runtime-state tables (caches, queues,
 * logs, sessions) and of SearchKit DB-entity tables, which are rebuilt after a
 * restore. Their structure is dumped, so the schema stays complete.
 */
final class DumpPolicy
{
    /** Exact table names whose rows are runtime state. */
    private const TABLES = [
        'civicrm_cache', 'civicrm_job_log', 'civicrm_system_log', 'civicrm_session',
        'civicrm_acl_cache', 'civicrm_acl_contact_cache', 'civicrm_group_contact_cache', 'civicrm_prevnext_cache',
    ];

    /** Prefixes: queues, logging tables, SearchKit DB entities, durable temp tables. */
    private const PREFIXES = ['civicrm_queue', 'log_', 'civicrm_sk_', 'civicrm_tmp_'];

    public static function skipsData(string $table): bool
    {
        if (in_array($table, self::TABLES, true)) {
            return true;
        }
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * mysqldump arguments for the whole schema without triggers: the restore
     * shifts dates first and rebuilds triggers after.
     *
     * @param list<string> $tables every base table of the schema
     * @return list<string>
     */
    public static function dumpArguments(string $schema, array $tables): array
    {
        $arguments = ['--single-transaction', '--quick', '--routines', '--skip-triggers', '--no-tablespaces', '--skip-dump-date'];
        foreach ($tables as $table) {
            if (self::skipsData($table)) {
                $arguments[] = "--ignore-table-data={$schema}.{$table}";
            }
        }
        return $arguments;
    }
}
