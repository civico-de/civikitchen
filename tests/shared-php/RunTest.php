<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Cli\RunCommand;
use CiviKitchen\Toolbelt\Process\Runner;
use PHPUnit\Framework\TestCase;

final class RunTest extends TestCase
{
    private const IMAGE = 'ghcr.io/civico-de/civikitchen:';

    public function testRunsTheCommandInAFreshStackAndRemovesIt(): void
    {
        $runner = new RunRunner();
        $runner->status = 3;

        [$status, $output] = $this->runCommand($runner, ['standalone-master', '--mount', '/src/core/tests:/var/www/html/core/tests', '--', 'ckcoretest', 'tests/phpunit/api/v4/Query']);

        self::assertSame(3, $status);
        self::assertSame('ck-run-standalone-master', $runner->config['name']);
        self::assertSame(self::IMAGE . 'standalone-master', $runner->config['services']['app']['image']);
        self::assertSame(['/src/core/tests:/var/www/html/core/tests'], $runner->config['services']['app']['volumes']);
        self::assertArrayNotHasKey('CIVIKITCHEN_LOCALES', $runner->config['services']['app']['environment']);
        self::assertSame(['exec', '-T', '-u', 'www-data', '-w', '/var/www/html', 'app', 'ckcoretest', 'tests/phpunit/api/v4/Query'], $runner->ran);
        self::assertStringContainsString('standalone-master (CiviCRM 6.18.alpha1, civicrm-core master abc1234): ckcoretest', $output);
        self::assertTrue($runner->removed);
        self::assertFileDoesNotExist($runner->file);
    }

    public function testKeepLeavesTheStackAndNamesIt(): void
    {
        $runner = new RunRunner();

        [$status, $output] = $this->runCommand($runner, ['example.org/civi:next', '--name', 'bug-1234', '--keep', '--', 'true']);

        self::assertSame(0, $status);
        self::assertSame('bug-1234', $runner->config['name']);
        self::assertSame('example.org/civi:next', $runner->config['services']['app']['image']);
        self::assertFalse($runner->removed);
        $file = getcwd() . '/bug-1234.compose.json';
        self::assertFileExists($file);
        unlink($file);
        self::assertStringContainsString("docker compose -f {$file} exec -u www-data app bash", $output);
        self::assertStringContainsString("docker compose -f {$file} down -v", $output);
    }

    public function testLocalesReachTheAppContainer(): void
    {
        $runner = new RunRunner();

        $this->runCommand($runner, ['standalone-master', '--locales', 'it_IT,fr_CA', '--', 'true']);

        self::assertSame('it_IT,fr_CA', $runner->config['services']['app']['environment']['CIVIKITCHEN_LOCALES']);
    }

    public function testAStackThatCannotBeRemovedIsNamedByProject(): void
    {
        $runner = new RunRunner();
        $runner->downStatus = 1;

        [$status] = $this->runCommand($runner, ['standalone', '--name', 'bug-1234', '--', 'true']);

        self::assertSame(0, $status);
        self::assertFileDoesNotExist($runner->file);
    }

    public function testAStackThatDoesNotBootIsRemovedAndRunsNothing(): void
    {
        $runner = new RunRunner();
        $runner->bootStatus = 1;

        try {
            $this->runCommand($runner, ['standalone', '--', 'true']);
            self::fail('expected the boot failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('did not boot', $e->getMessage());
            self::assertStringContainsString('provisioning failed', $e->getMessage());
        }
        self::assertTrue($runner->removed);
        self::assertSame([], $runner->ran);
    }

    public function testAnExistingStackOfThatNameIsNotReused(): void
    {
        $runner = new RunRunner();
        $runner->existing = true;

        $this->expectExceptionMessage('a stack named ck-run-standalone exists');
        $this->runCommand($runner, ['standalone', '--', 'true']);
    }

    public function testRejectsWhatItCannotRun(): void
    {
        $cases = [
            'which command?' => ['standalone'],
            'run takes one image, got 2' => ['standalone', 'standalone-beta', '--', 'true'],
            'run takes one image, got 0' => ['--keep', '--', 'true'],
            '--mount takes' => ['standalone', '--mount', 'relative:/x', '--', 'true'],
            '--name takes' => ['standalone', '--name', 'Bug 1', '--', 'true'],
            '--name needs a value' => ['standalone', '--name', '--', 'true'],
            '--locales takes' => ['standalone', '--locales', 'it_IT, fr_FR', '--', 'true'],
            '--locales needs a value' => ['standalone', '--locales', '--', 'true'],
            'unknown option --out' => ['standalone', '--out', '/x', '--', 'true'],
        ];
        foreach ($cases as $message => $arguments) {
            try {
                $this->runCommand(new RunRunner(), $arguments);
                self::fail("expected: {$message}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        $runner = new RunRunner();
        $runner->docker = false;
        $this->expectExceptionMessage('needs docker on PATH');
        $this->runCommand($runner, ['standalone', '--', 'true']);
    }

    public function testHelp(): void
    {
        [$status, $output] = $this->runCommand(new RunRunner(), ['--help']);
        self::assertSame(0, $status);
        self::assertStringContainsString('ck run <image> [options] -- <command>', $output);
        self::assertSame(2, $this->runCommand(new RunRunner(), [])[0]);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function runCommand(RunRunner $runner, array $arguments): array
    {
        ob_start();
        try {
            $status = (new RunCommand($runner))->run($arguments);
            return [$status, (string) ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }
}

/** Docker as `ck run` drives it. */
final class RunRunner extends Runner
{
    public int $status = 0;
    public int $bootStatus = 0;
    public int $downStatus = 0;
    public bool $docker = true;
    public bool $existing = false;
    public bool $removed = false;
    public string $file = '';

    /** @var array<string, mixed> the compose config as read at boot */
    public array $config = [];

    /** @var list<string> the exec call of the command, after the compose file */
    public array $ran = [];

    public function which(string $program): ?string
    {
        return $program === 'docker' && !$this->docker ? null : "/fake/{$program}";
    }

    public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
    {
        if ($command[4] === 'up') {
            $this->file = $command[3];
            $this->config = json_decode((string) file_get_contents($command[3]), true);
            return $this->bootStatus;
        }
        if ($command[4] === 'down') {
            $this->removed = $this->downStatus === 0;
            return $this->downStatus;
        }
        $this->ran = array_slice($command, 4);
        return $this->status;
    }

    public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        if ($command[1] === 'ps') {
            return ['status' => 0, 'output' => $this->existing ? "abc123\n" : ''];
        }
        return ['status' => 0, 'output' => "[civikitchen] ERROR: provisioning failed\n"];
    }

    public function captureSeparate(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        return in_array('cv', $command, true)
            ? ['status' => 0, 'stdout' => "6.18.alpha1\n", 'stderr' => '']
            : ['status' => 0, 'stdout' => "civicrm-core master abc1234\n", 'stderr' => ''];
    }
}
