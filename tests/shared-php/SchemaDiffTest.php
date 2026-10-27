<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Cli\SchemaDiffCommand;
use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use PHPUnit\Framework\TestCase;

final class SchemaDiffTest extends TestCase
{
    private string $temporary;

    private string $before;

    protected function setUp(): void
    {
        $this->temporary = Directory::temporary('civikitchen-schemadiff');
        $this->before = (string) getcwd();
        chdir($this->temporary);
        file_put_contents('info.xml', '<extension key="org.example.widget"/>');
    }

    protected function tearDown(): void
    {
        chdir($this->before);
        Directory::remove($this->temporary);
    }

    /** Both schema formats count: entityType files and xml/schema, one table per file or several. */
    public function testTablesListsEveryDeclaredTableOnceAndSorted(): void
    {
        mkdir('schema');
        file_put_contents('schema/Widget.entityType.php', "<?php\nreturn ['name' => 'Widget', 'table' => 'civicrm_widget'];\n");
        file_put_contents('schema/Note.entityType.php', "<?php\nreturn ['name' => 'Note'];\n");
        mkdir('xml/schema/CRM/Widget', 0777, true);
        file_put_contents('xml/schema/CRM/Widget/Gadget.xml', '<table><name>civicrm_gadget</name></table>');
        file_put_contents('xml/schema/CRM/Widget/Legacy.xml', '<schema><table><name>civicrm_widget</name></table><table><name>civicrm_bolt</name></table></schema>');
        file_put_contents('xml/schema/CRM/Widget/Broken.xml', '<table>');

        [$status, $output] = $this->command(new SchemaRunner(), ['tables']);

        self::assertSame(0, $status);
        self::assertSame("civicrm_bolt\ncivicrm_gadget\ncivicrm_widget\n", $output);
    }

    public function testAnEntityTypeThatThrowsStopsTheRun(): void
    {
        mkdir('schema');
        file_put_contents('schema/Widget.entityType.php', "<?php\nthrow new RuntimeException('boom');\n");
        self::assertSame(2, $this->command(new SchemaRunner(), ['tables'])[0]);
    }

    public function testOutsideAnExtensionItRefuses(): void
    {
        unlink('info.xml');
        self::assertSame(2, $this->command(new SchemaRunner(), ['tables'])[0]);
        self::assertSame(2, $this->command(new SchemaRunner(), ['dump', 'civicrm', 'out.sql'])[0]);
    }

    public function testAnExtensionWithoutTablesDumpsAnEmptyFileWithoutMysqldump(): void
    {
        $runner = new SchemaRunner();
        self::assertSame(0, $this->command($runner, ['dump', 'civicrm', 'out.sql'])[0]);
        self::assertSame('', file_get_contents('out.sql'));
        self::assertNull($runner->dumped);
    }

    /** What differs between two installs of the same schema is noise: counters and version comments. */
    public function testDumpWritesTheNormalizedDdlOfItsOwnTables(): void
    {
        $this->widgetTable();
        $runner = new SchemaRunner(stdout: "/*!40101 SET @saved_cs_client = @@character_set_client */;\nCREATE TABLE `civicrm_widget` (  \n  `id` int\n) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4;\n\n");

        self::assertSame(0, $this->command($runner, ['dump', 'civicrm', 'out.sql'])[0]);

        self::assertSame("CREATE TABLE `civicrm_widget` (\n  `id` int\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n", file_get_contents('out.sql'));
        self::assertContains('--no-data', $runner->dumped ?? []);
        self::assertSame(['civicrm', 'civicrm_widget'], array_slice($runner->dumped ?? [], -2));
    }

    public function testAFailedOrEmptyDumpWritesNothing(): void
    {
        $this->widgetTable();
        self::assertSame(3, $this->command(new SchemaRunner(status: 3), ['dump', 'civicrm', 'failed.sql'])[0]);
        self::assertFileDoesNotExist('failed.sql');
        self::assertSame(2, $this->command(new SchemaRunner(stdout: "/*!40101 SET NAMES utf8 */;\n"), ['dump', 'civicrm', 'empty.sql'])[0]);
        self::assertFileDoesNotExist('empty.sql');
    }

    public function testDiffPassesDiffsVerdictOn(): void
    {
        file_put_contents('fresh.sql', "a\n");
        file_put_contents('upgraded.sql', "a\n");
        [$status, $output] = $this->command(new SchemaRunner(diffStatus: 0), ['diff', 'fresh.sql', 'upgraded.sql']);
        self::assertSame(0, $status);
        self::assertStringContainsString('schemas match', $output);
        self::assertSame(1, $this->command(new SchemaRunner(diffStatus: 1), ['diff', 'fresh.sql', 'upgraded.sql'])[0]);
        self::assertSame(2, $this->command(new SchemaRunner(), ['diff', 'fresh.sql', 'missing.sql'])[0]);
    }

    public function testAWrongCallIsAUsageError(): void
    {
        foreach ([[], ['tables', 'extra'], ['dump', 'civicrm'], ['diff', 'one'], ['normalize', 'extra'], ['compare']] as $arguments) {
            self::assertSame(2, $this->command(new SchemaRunner(), $arguments)[0], implode(' ', $arguments));
        }
        self::assertSame(0, $this->command(new SchemaRunner(), ['--help'])[0]);
    }

    private function widgetTable(): void
    {
        mkdir('schema');
        file_put_contents('schema/Widget.entityType.php', "<?php\nreturn ['table' => 'civicrm_widget'];\n");
    }

    /** @param list<string> $arguments @return array{int, string} */
    private function command(SchemaRunner $runner, array $arguments): array
    {
        ob_start();
        try {
            $status = (new SchemaDiffCommand($runner))->run($arguments);
        } finally {
            $output = (string) ob_get_clean();
        }
        return [$status, $output];
    }
}

/** Stands in for mysqldump and diff. */
final class SchemaRunner extends Runner
{
    /** @var list<string>|null */
    public ?array $dumped = null;

    public function __construct(
        private readonly string $stdout = '',
        private readonly int $status = 0,
        private readonly int $diffStatus = 0,
    ) {
    }

    public function captureSeparate(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        $this->dumped = $command;
        return ['status' => $this->status, 'stdout' => $this->stdout, 'stderr' => ''];
    }

    public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
    {
        return $this->diffStatus;
    }
}
