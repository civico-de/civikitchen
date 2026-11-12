<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Ab\JUnit;
use CiviKitchen\Toolbelt\Cli\AbCommand;
use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use PHPUnit\Framework\TestCase;

final class AbTest extends TestCase
{
    private const IMAGE = 'ghcr.io/civico-de/civikitchen:';
    private const CHECK = ['--', 'ckcoretest', '--log-junit', AbCommand::JUNIT, 'tests/phpunit/api/v4/Query'];

    private string $out;

    protected function setUp(): void
    {
        $this->out = Directory::temporary('civikitchen-ab') . '/out';
    }

    protected function tearDown(): void
    {
        Directory::remove(dirname($this->out));
    }

    public function testNamesTheTestsThatPassOnAAndFailOnB(): void
    {
        $runner = new AbRunner();
        $runner->reports = [
            'a' => ['QueryTest::testJoin' => 'passed', 'QueryTest::testOld' => 'failed', 'QueryTest::testBroken' => 'failed',
                'QueryTest::testDropped' => 'passed', 'QueryTest::testSame with data set #1' => 'passed', 'QueryTest::testNever' => 'skipped'],
            'b' => ['QueryTest::testJoin' => 'failed', 'QueryTest::testOld' => 'passed', 'QueryTest::testBroken' => 'error',
                'QueryTest::testSame with data set #1' => 'passed', 'QueryTest::testNever' => 'failed', 'QueryTest::testNew' => 'passed'],
        ];
        $runner->status = ['a' => 1, 'b' => 1];

        [$status, $output] = $this->ab($runner, ['standalone-6.16', 'standalone-beta', '--mount', '/src/myext:/var/www/html/ext/myext', '--out', $this->out, ...self::CHECK]);

        self::assertSame(1, $status);
        self::assertStringContainsString("pass on A, fail on B: 1\n  QueryTest::testJoin\n", $output);
        self::assertStringContainsString("fail on A, pass on B: 1\n  QueryTest::testOld\n", $output);
        self::assertStringContainsString("fail on both: 1\n  QueryTest::testBroken\n", $output);
        self::assertStringContainsString("ran on A, skipped or missing on B: 1\n  QueryTest::testDropped\n", $output);
        self::assertStringContainsString('6 tests on A, 6 on B, 1 only on B', $output);
        self::assertStringContainsString('A ' . self::IMAGE . 'standalone-6.16 (CiviCRM 6.16.2): exit 1', $output);
        self::assertStringContainsString('(CiviCRM 6.17.beta1, civicrm-core 6.17 abc1234)', $output);

        self::assertSame(self::IMAGE . 'standalone-6.16', $runner->configs['ck-ab-standalone-6-16-vs-standalone-beta-a']['services']['app']['image']);
        $b = $runner->configs['ck-ab-standalone-6-16-vs-standalone-beta-b']['services']['app'];
        self::assertSame(self::IMAGE . 'standalone-beta', $b['image']);
        self::assertSame(['/src/myext:/var/www/html/ext/myext'], $b['volumes']);
        self::assertSame($runner->configs['ck-ab-standalone-6-16-vs-standalone-beta-a']['services']['db'], $runner->configs['ck-ab-standalone-6-16-vs-standalone-beta-b']['services']['db']);
        // Both stacks are gone, the reports stay.
        self::assertSame(['a', 'b'], $runner->removed);
        self::assertFileDoesNotExist("{$this->out}/A.compose.json");
        self::assertFileExists("{$this->out}/B.junit.xml");
    }

    public function testIdenticalResultsExitZero(): void
    {
        $runner = new AbRunner();
        $runner->reports = ['a' => ['T::testOne' => 'passed', 'T::testTwo' => 'failed'], 'b' => ['T::testOne' => 'passed', 'T::testTwo' => 'failed']];
        $runner->status = ['a' => 1, 'b' => 1];

        [$status, $output] = $this->ab($runner, ['standalone', 'example.org/civi:next', '--out', $this->out, ...self::CHECK]);

        self::assertSame(0, $status);
        self::assertStringContainsString('pass on A, fail on B: 0', $output);
        self::assertSame('example.org/civi:next', $runner->configs['ck-ab-standalone-vs-next-b']['services']['app']['image']);
    }

    public function testWithoutReportsTheExitStatusDecides(): void
    {
        foreach ([[['a' => 0, 'b' => 3], 1, 'passes on A and fails on B'], [['a' => 2, 'b' => 2], 0, 'same exit status'], [['a' => 1, 'b' => 0], 0, 'fails on A and passes on B']] as [$exits, $expected, $line]) {
            $runner = new AbRunner();
            $runner->status = $exits;
            [$status, $output] = $this->ab($runner, ['standalone', 'standalone-master', '--out', $this->out, '--', 'cv', 'api4', 'Contact.get']);
            self::assertSame($expected, $status, $line);
            self::assertStringContainsString($line, $output);
            self::assertStringContainsString('comparing exit status only', $output);
        }
    }

    public function testAReportOnOneSideOnlyIsAnError(): void
    {
        $runner = new AbRunner();
        $runner->reports = ['a' => ['T::testOne' => 'passed']];

        $this->expectExceptionMessage('only A wrote /tmp/ck-ab.xml');
        try {
            $this->ab($runner, ['standalone', 'standalone-master', '--out', $this->out, ...self::CHECK]);
        } finally {
            self::assertSame(['a', 'b'], $runner->removed);
        }
    }

    public function testAnEmptyReportIsAnError(): void
    {
        $runner = new AbRunner();
        $runner->reports = ['a' => [], 'b' => ['T::testOne' => 'passed']];

        $this->expectExceptionMessage('holds no test case');
        $this->ab($runner, ['standalone', 'standalone-master', '--out', $this->out, ...self::CHECK]);
    }

    public function testAStackThatDoesNotBootStopsTheRunWithItsLog(): void
    {
        $runner = new AbRunner();
        $runner->bootStatus = 1;

        try {
            $this->ab($runner, ['standalone', 'standalone-master', '--out', $this->out, ...self::CHECK]);
            self::fail('expected the boot failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('did not boot', $e->getMessage());
            self::assertStringContainsString('provisioning failed', $e->getMessage());
        }
        self::assertSame(['a', 'b'], $runner->removed);
        self::assertSame([], $runner->ran);
    }

    public function testKeepLeavesBothStacks(): void
    {
        $runner = new AbRunner();
        [$status, $output] = $this->ab($runner, ['standalone', 'standalone-master', '--keep', '--out', $this->out, '--', 'true']);

        self::assertSame(0, $status);
        self::assertSame([], $runner->removed);
        self::assertFileExists("{$this->out}/A.compose.json");
        self::assertStringContainsString("kept B: docker compose -f {$this->out}/B.compose.json", $output);
    }

    public function testAnExistingStackOfThatNameIsNotReused(): void
    {
        $runner = new AbRunner();
        $runner->existing = true;

        $this->expectExceptionMessage('a stack named ck-ab-standalone-vs-standalone-master-a exists');
        $this->ab($runner, ['standalone', 'standalone-master', '--out', $this->out, '--', 'true']);
    }

    public function testRejectsWhatItCannotCompare(): void
    {
        $cases = [
            [['standalone', 'standalone-beta'], 'which check?'],
            [['standalone', '--', 'true'], 'two images, got 1'],
            [['standalone', 'standalone', '--', 'true'], 'nothing to compare'],
            [['standalone', 'standalone-beta', '--mount', 'ext:/var/www', '--', 'true'], '--mount takes'],
            [['standalone', 'standalone-beta', '--cms', 'all', '--', 'true'], 'unknown option --cms'],
            [['standalone', 'standalone-beta', '--out', '--', 'true'], '--out needs a value'],
        ];
        foreach ($cases as [$arguments, $message]) {
            try {
                $this->ab(new AbRunner(), $arguments);
                self::fail("expected: {$message}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        $runner = new AbRunner();
        $runner->docker = false;
        $this->expectExceptionMessage('needs docker');
        $this->ab($runner, ['standalone', 'standalone-beta', '--', 'true']);
    }

    public function testHelp(): void
    {
        self::assertSame(0, $this->ab(new AbRunner(), ['--help'])[0]);
        [$status, $output] = $this->ab(new AbRunner(), []);
        self::assertSame(2, $status);
        self::assertStringContainsString('ck ab <image A> <image B>', $output);
    }

    public function testJUnitRejectsAFileThatIsNoReport(): void
    {
        mkdir($this->out, 0777, true);
        file_put_contents("{$this->out}/broken.xml", 'PHP Fatal error');

        $this->expectExceptionMessage('is not a JUnit report');
        JUnit::outcomes("{$this->out}/broken.xml");
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function ab(AbRunner $runner, array $arguments): array
    {
        ob_start();
        try {
            $status = (new AbCommand($runner))->run($arguments);
            return [$status, (string) ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }
}

/** Docker as `ck ab` drives it: side a is the first image, side b the second. */
final class AbRunner extends Runner
{
    /** @var array<string, array<string, string>> per side, the outcome of each test; no entry means no report */
    public array $reports = [];

    /** @var array<string, int> exit status of the check per side */
    public array $status = ['a' => 0, 'b' => 0];

    /** @var array<string, array<string, mixed>> compose config by project */
    public array $configs = [];

    /** @var list<string> */
    public array $removed = [];

    /** @var list<string> sides the check ran on */
    public array $ran = [];

    public bool $docker = true;
    public bool $existing = false;
    public int $bootStatus = 0;

    public function which(string $program): ?string
    {
        return $program === 'docker' && !$this->docker ? null : "/fake/{$program}";
    }

    public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
    {
        $side = $this->side($command[3]);
        if ($command[4] === 'up') {
            return in_array('--wait', $command, true) ? $this->bootStatus : 0;
        }
        if ($command[4] === 'down') {
            $this->removed[] = $side;
            return 0;
        }
        $this->ran[] = $side;
        return $this->status[$side];
    }

    public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        if ($command[1] === 'ps') {
            return ['status' => 0, 'output' => $this->existing ? "abc123\n" : ''];
        }
        if ($command[4] === 'logs') {
            return ['status' => 0, 'output' => "[civikitchen] ERROR: provisioning failed\n"];
        }
        $side = $this->side($command[3]);
        if (!isset($this->reports[$side])) {
            return ['status' => 1, 'output' => 'no such file'];
        }
        $xml = '<testsuites><testsuite name="suite">';
        foreach ($this->reports[$side] as $test => $outcome) {
            [$class, $name] = explode('::', $test);
            $child = ['passed' => '', 'failed' => '<failure>no</failure>', 'error' => '<error>boom</error>', 'skipped' => '<skipped/>'][$outcome];
            $xml .= "<testcase name=\"{$name}\" class=\"{$class}\">{$child}</testcase>";
        }
        file_put_contents((string) end($command), $xml . '</testsuite></testsuites>');
        return ['status' => 0, 'output' => ''];
    }

    public function captureSeparate(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        $side = $this->side($command[3]);
        if (in_array('cv', $command, true)) {
            return ['status' => 0, 'stdout' => $side === 'a' ? "6.16.2\n" : "6.17.beta1\n", 'stderr' => ''];
        }
        return $side === 'a' ? ['status' => 1, 'stdout' => '', 'stderr' => 'cat: no such file'] : ['status' => 0, 'stdout' => "civicrm-core 6.17 abc1234\n", 'stderr' => ''];
    }

    private function side(string $composeFile): string
    {
        $config = json_decode((string) file_get_contents($composeFile), true);
        $this->configs[$config['name']] = $config;
        return substr($config['name'], -1);
    }
}
