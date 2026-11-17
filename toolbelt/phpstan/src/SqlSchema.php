<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Table names in literal SQL, judged against the schema. Only `civicrm_`
 * names are judged; custom-value, temp and the repo's own tables are known,
 * other extensions' go into `parameters.civikitchen.sqlKnownTables`.
 */
final class SqlSchema
{
    /**
     * Table families that exist per site, not per release: custom-value and
     * temp tables.
     */
    private const DYNAMIC_PREFIXES = ['civicrm_value_', 'civicrm_tmp_', 'civicrm_temp'];

    /** SQL keywords a table name follows; INSERT's INTO and TRUNCATE's TABLE are optional. */
    private const TABLE_KEYWORDS = 'FROM|JOIN|INTO|UPDATE|TABLE|(?:INSERT|REPLACE)(?:\s+(?:IGNORE|LOW_PRIORITY|DELAYED|HIGH_PRIORITY))*(?:\s+INTO)?|TRUNCATE(?:\s+TABLE)?';

    /** String literals and comments, whose words are not SQL; `--` opens a comment only before whitespace. */
    private const LITERALS_AND_COMMENTS = '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|--(?=\s|$)[^\n]*|#[^\n]*|\/\*.*?\*\//s';

    /** Where the table list of a FROM clause ends. */
    private const FROM_LIST_END = 'WHERE|GROUP|ORDER|HAVING|LIMIT|UNION|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|NATURAL|STRAIGHT_JOIN|ON|USING|SET|FOR|LOCK|INTO';

    private string $extensionDir;

    /** @var list<string> */
    private array $configuredTables;

    /** @var list<string>|null */
    private ?array $ownTables = null;

    /**
     * @param list<string> $knownTables tables the repo declares in phpstan.neon
     */
    public function __construct(string $currentWorkingDirectory, array $knownTables = [])
    {
        $this->extensionDir = $currentWorkingDirectory;
        $this->configuredTables = array_map('strtolower', $knownTables);
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function checkSql(string $sql, string $context): array
    {
        return self::errors($this->sqlMessages($sql, $context));
    }

    /**
     * @return list<string>
     */
    public function sqlMessages(string $sql, string $context): array
    {
        $messages = [];
        foreach (self::tablesIn($sql) as $table) {
            if (!$this->isKnown($table)) {
                $messages[] = self::message($table, $context);
            }
        }

        return $messages;
    }

    /**
     * A `from()`/`join()` argument: `civicrm_contact c` or `civicrm_email e`.
     *
     * @return list<IdentifierRuleError>
     */
    public function checkTableClause(string $clause, string $context): array
    {
        return self::errors($this->tableClauseMessages($clause, $context));
    }

    /**
     * @return list<string>
     */
    public function tableClauseMessages(string $clause, string $context): array
    {
        $name = strtok(trim($clause), " \t\n");
        if ($name === false) {
            return [];
        }
        $name = trim($name, '`');

        return !self::isPlainName($name) || $this->isKnown($name) ? [] : [self::message($name, $context)];
    }

    private static function message(string $table, string $context): string
    {
        return sprintf('SQL table %s does not exist in CiviCRM %s — %s', $table, SchemaCatalog::CORE_VERSION, $context);
    }

    /**
     * @param  list<string>              $messages
     * @return list<IdentifierRuleError>
     */
    private static function errors(array $messages): array
    {
        return array_map(
            static fn (string $message): IdentifierRuleError => RuleErrorBuilder::message($message)->identifier('ck.sql.unknownTable')->build(),
            $messages,
        );
    }

    /**
     * The `civicrm_`-prefixed table names a literal statement mentions.
     *
     * @return list<string>
     */
    public static function tablesIn(string $sql): array
    {
        $names = [];
        // A literal becomes an empty one, a comment whitespace, so a leading comment keeps `^DROP` intact.
        $stripped = (string) preg_replace_callback(
            self::LITERALS_AND_COMMENTS,
            static fn (array $m): string => in_array($m[0][0], ["'", '"'], true) ? "''" : ' ',
            $sql,
        );
        foreach (explode(';', $stripped) as $statement) {
            // A DROP or RENAME names a table that is about to stop existing.
            if (preg_match('/^\s*(DROP|RENAME)\b|^\s*ALTER\s+TABLE\b.*\bRENAME\b/is', $statement) === 1) {
                continue;
            }
            preg_match_all('/\b(?:' . self::TABLE_KEYWORDS . ')\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?([A-Za-z0-9_{}$%.\\\\]+)`?/i', $statement, $matches);
            $names = array_merge($names, $matches[1]);
            // `FROM a x, b y`: every item of the list, not just the first.
            preg_match_all('/\bFROM\s+(.*?)(?=\b(?:' . self::FROM_LIST_END . ')\b|\)|$)/is', $statement, $lists);
            foreach ($lists[1] as $list) {
                foreach (array_slice(explode(',', $list), 1) as $item) {
                    $names[] = trim((string) strtok(trim($item), " \t\n"), '`');
                }
            }
        }

        return array_values(array_unique(array_filter($names, self::isPlainName(...))));
    }

    /**
     * Is this a name we may judge? Not with interpolation (`{$table}`, `%1`),
     * a database qualifier or a prefix that is not core's.
     */
    private static function isPlainName(string $name): bool
    {
        return preg_match('/^civicrm_[a-z0-9_]+$/i', $name) === 1;
    }

    private function isKnown(string $table): bool
    {
        $table = strtolower($table);
        if (in_array($table, SchemaCatalog::TABLES, true)) {
            return true;
        }
        foreach (self::DYNAMIC_PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return true;
            }
        }

        return in_array($table, $this->configuredTables, true)
            || in_array($table, $this->ownTables(), true);
    }

    /**
     * Tables the analysed extension installs itself, from schema/*.entityType.php,
     * xml/schema/**\/*.xml and sql/auto_install.sql.
     *
     * @return list<string>
     */
    private function ownTables(): array
    {
        if ($this->ownTables !== null) {
            return $this->ownTables;
        }

        $tables = [];
        foreach (self::globRecursive($this->extensionDir . '/schema', '.entityType.php') as $file) {
            if (preg_match("/['\"]table['\"]\\s*=>\\s*['\"]([A-Za-z0-9_]+)['\"]/", (string) file_get_contents($file), $m) === 1) {
                $tables[] = $m[1];
            }
        }
        foreach (self::globRecursive($this->extensionDir . '/xml/schema', '.xml') as $file) {
            if (preg_match('#<name>\s*([A-Za-z0-9_]+)\s*</name>#', (string) file_get_contents($file), $m) === 1) {
                $tables[] = $m[1];
            }
        }
        foreach (self::globRecursive($this->extensionDir . '/sql', '.sql') as $file) {
            if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', (string) file_get_contents($file), $m) > 0) {
                $tables = array_merge($tables, $m[1]);
            }
        }

        return $this->ownTables = array_values(array_unique(array_map('strtolower', $tables)));
    }

    /**
     * @return list<string>
     */
    private static function globRecursive(string $dir, string $suffix): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $found = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getFilename(), $suffix)) {
                $found[] = $entry->getPathname();
            }
        }

        return $found;
    }
}
