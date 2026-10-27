<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Cli\PhpUnitCommand;
use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use PHPUnit\Framework\TestCase;

final class PhpUnitTest extends TestCase
{
    private string $temporary;

    private string $before;

    private string|false $canary;

    protected function setUp(): void
    {
        $this->canary = getenv('CK_TX_CANARY');
        putenv('CK_TX_CANARY');
        $this->temporary = (string) realpath(Directory::temporary('civikitchen-phpunit'));
        $this->before = (string) getcwd();
        chdir($this->temporary);
        file_put_contents('info.xml', '<extension key="org.example.widget"/>');
    }

    protected function tearDown(): void
    {
        putenv($this->canary === false ? 'CK_TX_CANARY' : "CK_TX_CANARY={$this->canary}");
        chdir($this->before);
        Directory::remove($this->temporary);
    }

    /** The generated copy lives in the temp directory, so a path left relative would resolve there. */
    public function testEveryPathPhpunitResolvesAgainstTheConfigIsMadeAbsolute(): void
    {
        file_put_contents('phpunit.xml.dist', <<<'XML'
<?xml version="1.0"?>
<phpunit bootstrap="tests/bootstrap.php" printerFile="tests/Printer.php" cacheResultFile="/var/cache/result"
  testSuiteLoaderFile="tests/Loader.php" extensionsDirectory="tools/extensions" cacheDirectory=".phpunit.cache">
  <testsuites><testsuite name="w"><directory>./tests</directory><exclude>tests/Slow</exclude></testsuite></testsuites>
  <groups><exclude><group>slow</group></exclude></groups>
  <coverage cacheDirectory="build/cache"><include><directory>Civi</directory><file>widget.php</file></include>
    <report><clover outputFile="build/clover.xml"/><html outputDirectory="build/html"/></report></coverage>
  <logging><junit outputFile="build/junit.xml"/><testdoxText outputFile="php://stdout"/><log type="coverage-clover" target="build/legacy.xml"/></logging>
  <php><includePath>lib</includePath></php>
  <listeners><listener class="Acme\Listener" file="tests/Listener.php"><arguments><file>fixtures/a.json</file><string>plain</string></arguments></listener>
    <listener class="Acme\Shared" file="Acme/Shared.php"/></listeners>
  <extensions><extension class="Acme\Extension" file="tests/Extension.php"/></extensions>
</phpunit>
XML);
        mkdir('tests');
        touch('tests/Listener.php');
        touch('tests/Extension.php');
        $runner = new PhpUnitRunner();

        self::assertSame(0, $this->phpunit($runner, []));

        $xpath = new DOMXPath($runner->config());
        $value = static fn(string $query): string => trim((string) $xpath->evaluate("string({$query})"));
        $root = $this->temporary;
        self::assertSame("{$root}/tests/bootstrap.php", $value('/phpunit/@bootstrap'));
        self::assertSame("{$root}/tests/Printer.php", $value('/phpunit/@printerFile'));
        self::assertSame('/var/cache/result', $value('/phpunit/@cacheResultFile'));
        self::assertSame("{$root}/tests/Loader.php", $value('/phpunit/@testSuiteLoaderFile'));
        self::assertSame("{$root}/tools/extensions", $value('/phpunit/@extensionsDirectory'));
        self::assertSame("{$root}/.phpunit.cache", $value('/phpunit/@cacheDirectory'));
        self::assertSame("{$root}/./tests", $value('//testsuite/directory'));
        self::assertSame("{$root}/tests/Slow", $value('//testsuite/exclude'));
        self::assertSame('slow', $value('//groups/exclude/group'));
        self::assertSame("{$root}/build/cache", $value('//coverage/@cacheDirectory'));
        self::assertSame("{$root}/Civi", $value('//coverage/include/directory'));
        self::assertSame("{$root}/widget.php", $value('//coverage/include/file'));
        self::assertSame("{$root}/build/clover.xml", $value('//clover/@outputFile'));
        self::assertSame("{$root}/build/html", $value('//html/@outputDirectory'));
        self::assertSame("{$root}/build/junit.xml", $value('//junit/@outputFile'));
        self::assertSame('php://stdout', $value('//testdoxText/@outputFile'));
        self::assertSame("{$root}/build/legacy.xml", $value('//log/@target'), 'the pre-9.3 schema phpunit 9 still reads');
        self::assertSame("{$root}/lib", $value('//php/includePath'));
        self::assertSame("{$root}/tests/Listener.php", $value('//listener[@class="Acme\Listener"]/@file'));
        self::assertSame('Acme/Shared.php', $value('//listener[@class="Acme\Shared"]/@file'), 'phpunit looks it up on the include path');
        self::assertSame("{$root}/fixtures/a.json", $value('//listener/arguments/file'));
        self::assertSame('plain', $value('//listener/arguments/string'));
        self::assertSame("{$root}/tests/Extension.php", $value('//extension/@file'));
        self::assertSame(dirname(__DIR__, 2) . '/toolbelt/lib/tx-canary.php', $value('//listener[@class="Civi\CkTest\TransactionCanaryListener"]/@file'));
    }

    public function testTheGeneratedConfigReplacesTheOneNamedOnTheCommandLine(): void
    {
        file_put_contents('custom.xml', '<phpunit/>');
        foreach ([['-c', 'custom.xml'], ['--configuration', 'custom.xml'], ['--configuration=custom.xml']] as $option) {
            $runner = new PhpUnitRunner();
            $this->phpunit($runner, ['--filter', 'testWidget', ...$option, '--stop-on-failure']);
            self::assertSame(['phpunit', '-c', $runner->generated, '--filter', 'testWidget', '--stop-on-failure'], $runner->command, implode(' ', $option));
            self::assertNotSame("{$this->temporary}/custom.xml", $runner->generated);
        }
    }

    /** phpunit takes a directory for -c and reads its phpunit.xml, else its phpunit.xml.dist. */
    public function testADirectoryNamedOnTheCommandLineHoldsTheConfig(): void
    {
        mkdir('sub');
        file_put_contents('sub/phpunit.xml.dist', '<phpunit bootstrap="tests/bootstrap.php"/>');
        $runner = new PhpUnitRunner();

        self::assertSame(0, $this->phpunit($runner, ['-c', 'sub', '--filter', 'x']));
        self::assertSame(['phpunit', '-c', $runner->generated, '--filter', 'x'], $runner->command);
        $xpath = new DOMXPath($runner->config());
        self::assertSame("{$this->temporary}/sub/tests/bootstrap.php", $xpath->evaluate('string(/phpunit/@bootstrap)'));
    }

    /**
     * phpunit keeps its result cache and looks up listener files next to the
     * config, so the copy gets a directory of its own, gone after the run.
     */
    public function testTheGeneratedConfigLivesAloneInAPrivateDirectory(): void
    {
        file_put_contents('phpunit.xml', '<phpunit/>');
        $runner = new PhpUnitRunner();
        $this->phpunit($runner, []);

        $directory = dirname($runner->generated);
        self::assertNotSame(realpath(sys_get_temp_dir()), realpath($directory));
        self::assertSame(['phpunit.xml'], $runner->siblings);
        self::assertSame(0700, $runner->mode);
        self::assertDirectoryDoesNotExist($directory);
    }

    public function testWithoutAConfigOrWithTheCanaryOffItRunsPlainPhpunit(): void
    {
        $runner = new PhpUnitRunner();
        $this->phpunit($runner, ['--filter', 'x']);
        self::assertSame(['phpunit', '--filter', 'x'], $runner->command);

        file_put_contents('phpunit.xml', '<phpunit/>');
        putenv('CK_TX_CANARY=0');
        $this->phpunit($runner, []);
        self::assertSame(['phpunit'], $runner->command);
    }

    public function testPhpunitsStatusIsTheResult(): void
    {
        file_put_contents('phpunit.xml', '<phpunit/>');
        self::assertSame(1, $this->phpunit(new PhpUnitRunner(status: 1), []));
    }

    public function testRefusesBeforePhpunitRuns(): void
    {
        file_put_contents('phpunit.xml', '<phpunit>');
        $runner = new PhpUnitRunner();
        self::assertSame(2, $this->phpunit($runner, []));
        unlink('info.xml');
        self::assertSame(2, $this->phpunit($runner, []));
        self::assertNull($runner->command);
    }

    /** @param list<string> $arguments */
    private function phpunit(PhpUnitRunner $runner, array $arguments): int
    {
        ob_start();
        try {
            return (new PhpUnitCommand(dirname(__DIR__, 2), $runner))->run($arguments);
        } finally {
            ob_end_clean();
        }
    }
}

/** Records the phpunit call and the generated config while it still exists. */
final class PhpUnitRunner extends Runner
{
    /** @var list<string>|null */
    public ?array $command = null;

    public string $generated = '';

    /** @var list<string> what the generated config's directory held during the run */
    public array $siblings = [];

    public int $mode = 0;

    private string $xml = '';

    public function __construct(private readonly int $status = 0)
    {
    }

    public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
    {
        $this->command = $command;
        if (($command[1] ?? '') === '-c') {
            $this->generated = $command[2];
            $this->xml = (string) file_get_contents($command[2]);
            $this->siblings = array_values(array_diff((array) scandir(dirname($command[2])), ['.', '..']));
            $this->mode = fileperms(dirname($command[2])) & 0777;
        }
        return $this->status;
    }

    public function config(): DOMDocument
    {
        $document = new DOMDocument();
        $document->loadXML($this->xml);
        return $document;
    }
}
