<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Seed;

use RuntimeException;

/**
 * Move every DATE, DATETIME and TIMESTAMP value of the dumped civicrm_* tables
 * forward by N days, keeping the time of day. Columns come from
 * information_schema, so custom value tables are included.
 *
 * Core's triggers stamp civicrm_contact.modified_date and rebuild
 * civicrm_relationship_cache on UPDATE, so the shift refuses a schema with
 * triggers: restore a dump made with --skip-triggers, shift, then rebuild them.
 */
final class DateShift
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * One UPDATE per table that sets all its date columns at once, so ON UPDATE
     * CURRENT_TIMESTAMP never fires.
     *
     * @param list<array{0: string, 1: string}> $columns [table, column] pairs
     * @return array<string, string> table => statement
     */
    public static function statements(array $columns, int $days): array
    {
        $byTable = [];
        foreach ($columns as [$table, $column]) {
            if (!str_starts_with($table, 'civicrm_') || DumpPolicy::skipsData($table)) {
                continue;
            }
            $byTable[$table][] = '`' . str_replace('`', '``', $column) . '`';
        }
        $statements = [];
        foreach ($byTable as $table => $quoted) {
            $set = implode(', ', array_map(static fn(string $c): string => "{$c} = {$c} + INTERVAL {$days} DAY", $quoted));
            $where = implode(' OR ', array_map(static fn(string $c): string => "{$c} IS NOT NULL", $quoted));
            $statements[$table] = "UPDATE `{$table}` SET {$set} WHERE {$where}";
        }
        return $statements;
    }

    /** @return array{tables: int, rows: int} */
    public function run(int $days): array
    {
        $triggers = $this->database->triggerCount();
        if ($triggers > 0) {
            throw new RuntimeException("{$this->database->name()} has {$triggers} triggers; they would rewrite shifted rows. Restore a dump made without triggers, shift, then rebuild them.");
        }
        $db = $this->database->connection();
        // UTC, so a TIMESTAMP keeps its time across a daylight-saving change.
        $db->query("SET time_zone = '+00:00'");
        $result = $db->execute_query(
            "SELECT c.TABLE_NAME, c.COLUMN_NAME
             FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = ? AND t.TABLE_TYPE = 'BASE TABLE'
               AND c.DATA_TYPE IN ('date', 'datetime', 'timestamp')
             ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION",
            [$this->database->name()],
        );
        $columns = [];
        foreach ($result instanceof \mysqli_result ? $result->fetch_all() : [] as $row) {
            $columns[] = [(string) $row[0], (string) $row[1]];
        }
        $statements = self::statements($columns, $days);
        $rows = 0;
        if ($days !== 0) {
            foreach ($statements as $statement) {
                $db->query($statement);
                $rows += (int) $db->affected_rows;
            }
        }
        return ['tables' => count($statements), 'rows' => $rows];
    }
}
