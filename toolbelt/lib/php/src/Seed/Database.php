<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Seed;

use mysqli;
use RuntimeException;

/**
 * The site database as the CIVICRM_DB_* environment names it, with the
 * image's defaults. Raw SQL on a CiviCRM schema lives here and in DateShift.
 */
class Database
{
    private ?mysqli $connection = null;

    /** @param array<string, string> $settings host, port, name, user, password */
    public function __construct(public readonly array $settings)
    {
    }

    public static function fromEnvironment(): self
    {
        $value = static function (string $name, string $default): string {
            $set = getenv($name);
            return is_string($set) && $set !== '' ? $set : $default;
        };
        return new self([
            'host' => $value('CIVICRM_DB_HOST', 'db'),
            'port' => $value('CIVICRM_DB_PORT', '3306'),
            'name' => $value('CIVICRM_DB_NAME', 'civicrm'),
            'user' => $value('CIVICRM_DB_USER', 'civicrm'),
            'password' => $value('CIVICRM_DB_PASSWORD', 'civicrm'),
        ]);
    }

    public function name(): string
    {
        return $this->settings['name'];
    }

    public function connection(): mysqli
    {
        if ($this->connection === null) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $this->connection = new mysqli($this->settings['host'], $this->settings['user'],
                $this->settings['password'], $this->settings['name'], (int) $this->settings['port']);
            $this->connection->set_charset('utf8mb4');
        }
        return $this->connection;
    }

    /** @return list<string> */
    public function column(string $sql, array $parameters = []): array
    {
        $rows = $this->connection()->execute_query($sql, $parameters);
        if (!$rows instanceof \mysqli_result) {
            return [];
        }
        return array_map(static fn(array $row): string => (string) $row[0], $rows->fetch_all());
    }

    /** @return list<string> */
    public function tables(string $type = 'BASE TABLE'): array
    {
        return $this->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
            [$this->name(), $type]);
    }

    public function triggerCount(): int
    {
        return (int) ($this->column('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?', [$this->name()])[0] ?? 0);
    }

    /** The schema version CiviCRM recorded, or '' on a database without one. */
    public function coreVersion(): string
    {
        if (!in_array('civicrm_domain', $this->tables(), true)) {
            return '';
        }
        return $this->column('SELECT version FROM civicrm_domain ORDER BY id LIMIT 1')[0] ?? '';
    }

    /** Empty the schema so a dump loads into nothing it did not write. */
    public function dropEverything(): void
    {
        $db = $this->connection();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->tables('VIEW') as $view) {
            $db->query("DROP VIEW `{$view}`");
        }
        foreach ($this->tables() as $table) {
            $db->query("DROP TABLE `{$table}`");
        }
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Client arguments and environment for mysql/mysqldump; the password goes
     * through MYSQL_PWD so it never shows in a process list.
     *
     * @return array{list<string>, array<string, string>}
     */
    public function client(): array
    {
        $environment = getenv();
        $environment['MYSQL_PWD'] = $this->settings['password'];
        return [[
            '--host=' . $this->settings['host'],
            '--port=' . $this->settings['port'],
            '--user=' . $this->settings['user'],
        ], $environment];
    }
}
