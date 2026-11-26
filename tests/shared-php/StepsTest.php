<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Cli\StepsCommand;
use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use PHPUnit\Framework\TestCase;

final class StepsTest extends TestCase
{
    private string $temporary;
    private string $module;

    protected function setUp(): void
    {
        $this->temporary = Directory::temporary('civikitchen-steps');
        $this->module = $this->temporary . '/search.ts';
        file_put_contents($this->module, 'export default async () => {};');
    }

    protected function tearDown(): void
    {
        Directory::remove($this->temporary);
    }

    public function testFinishedStepsPrintTheirReportAndExitZero(): void
    {
        $runner = new StepsRunnerFake(['ok' => true, 'value' => ['heading' => 'Saved'], 'url' => 'http://localhost:8301/x', 'errors' => []]);

        [$status, $output] = $this->steps($runner, ['http://localhost:8301/', $this->module, '--cms', 'joomla', '--phase', 'before',
            '--size', '800x600', '--timeout', '30', '--page', 'civicrm/admin/search?reset=1']);

        self::assertSame(0, $status);
        self::assertSame(['heading' => 'Saved'], json_decode($output, true)['value']);
        self::assertSame(1, substr_count($output, "\n"));
        $spec = $runner->spec();
        self::assertSame('joomla', $spec['cms']);
        self::assertSame('http://localhost:8301', $spec['site']);
        self::assertSame('/administrator/index.php?option=com_civicrm&task=', $spec['routePrefix']);
        self::assertSame('#mod-login-username', $spec['login']['username']);
        self::assertSame('before', $spec['phase']);
        self::assertSame('civicrm/admin/search?reset=1', $spec['page']);
        self::assertSame(['width' => 800, 'height' => 600], $spec['size']);
        self::assertSame(30000, $spec['timeoutMs']);
        self::assertSame('/ck-steps/module.mts', $spec['steps']);
    }

    public function testTheContainerSeesTheTwoScriptsAndNothingElseOfTheHost(): void
    {
        $runner = new StepsRunnerFake(['ok' => true, 'errors' => []]);

        $this->steps($runner, ['http://localhost:8300', $this->module]);

        $command = $runner->browser();
        $mounts = [];
        foreach ($command as $index => $part) {
            if ($part === '-v') {
                $mounts[] = $command[$index + 1];
            }
        }
        self::assertCount(2, $mounts);
        self::assertStringEndsWith(':/ck-steps/steps.mts:ro', $mounts[0]);
        self::assertSame((string) realpath($this->module) . ':/ck-steps/module.mts:ro', $mounts[1]);
        self::assertNotContains('/var/run/docker.sock:/var/run/docker.sock', $mounts);
        // The container ends on its own, also when the steps never yield.
        $bound = array_search('timeout', $command, true);
        self::assertSame(['timeout', '-k', '5', '150', 'node', '/ck-steps/steps.mts'], array_slice($command, (int) $bound, 6));
        // The copy of the runner script next to the module is gone again.
        self::assertSame([], glob($this->temporary . '/.ck-steps-*'));
        self::assertSame('standalone', $runner->spec()['cms']);
        self::assertSame('after', $runner->spec()['phase']);
        self::assertArrayNotHasKey('page', $runner->spec());
        self::assertSame(120000, $runner->spec()['timeoutMs']);
    }

    public function testStepsThatThrewPrintTheirReportAndExitOne(): void
    {
        $runner = new StepsRunnerFake(['ok' => false, 'error' => 'locator.click: Timeout 3000ms exceeded.', 'errors' => ['Uncaught TypeError']]);

        [$status, $output] = $this->steps($runner, ['http://localhost:8300', $this->module]);

        self::assertSame(1, $status);
        self::assertSame('locator.click: Timeout 3000ms exceeded.', json_decode($output, true)['error']);
    }

    public function testABrowserThatGaveNoReportIsAnError(): void
    {
        $runner = new StepsRunnerFake(['ok' => true, 'errors' => []]);
        $runner->status = 1;
        $this->expectExceptionMessage('the browser gave no report on http://localhost:8300 (exit 1)');
        $this->steps($runner, ['http://localhost:8300', $this->module]);
    }

    /** @dataProvider endedByTheTimeLimit */
    public function testStepsThatHeldUpTheBrowserAreReportedAsOutOfTime(int $exit): void
    {
        $runner = new StepsRunnerFake(['ok' => true, 'errors' => []]);
        $runner->status = $exit;
        $runner->progress = "ck steps: running the steps\n";
        [$status, $output] = $this->steps($runner, ['http://localhost:8300', $this->module, '--timeout', '30']);
        self::assertSame(1, $status);
        self::assertSame(
            ['ok' => false, 'error' => "the steps did not finish within 30 s and held up the browser's script", 'errors' => []],
            json_decode($output, true),
        );
    }

    /** @return array<string, array{int}> */
    public static function endedByTheTimeLimit(): array
    {
        return ['ended by TERM' => [124], 'ended by KILL' => [137]];
    }

    public function testABrowserEndedBeforeTheStepsBeganIsAnError(): void
    {
        $runner = new StepsRunnerFake(['ok' => true, 'errors' => []]);
        $runner->status = 124;
        $this->expectExceptionMessage('the browser gave no report on http://localhost:8300 (exit 124)');
        $this->steps($runner, ['http://localhost:8300', $this->module]);
    }

    public function testBuildImagePrintsTheTagAndStartsNoBrowser(): void
    {
        $runner = new StepsRunnerFake(['ok' => true, 'errors' => []]);
        [$status, $output] = $this->steps($runner, ['--build-image']);
        self::assertSame(0, $status);
        self::assertMatchesRegularExpression('/^civikitchen-playwright:[0-9a-f]{12}\n$/', $output);
        self::assertNotContains('run', array_merge(...$runner->commands));
    }

    public function testOutputThatIsNoReportIsAnError(): void
    {
        $runner = new StepsRunnerFake(['errors' => []]);
        $this->expectExceptionMessage('the browser gave no report');
        $this->steps($runner, ['http://localhost:8300', $this->module]);
    }

    public function testWithoutDockerNothingStarts(): void
    {
        $runner = new StepsRunnerFake(['ok' => true, 'errors' => []]);
        $runner->docker = false;
        try {
            $this->steps($runner, ['http://localhost:8300', $this->module]);
            self::fail('steps must need docker');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('needs docker on PATH', $e->getMessage());
        }
        self::assertSame([], $runner->commands);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidArguments(): iterable
    {
        yield 'no module' => [['http://localhost:8300'], 'takes a site URL and a steps module'];
        yield 'a site with a path' => [['http://localhost:8300/civicrm', 'MODULE'], 'a URL without a path'];
        yield 'no URL' => [['localhost:8300', 'MODULE'], 'a URL without a path'];
        yield 'a missing module' => [['http://localhost:8300', '/nowhere/steps.ts'], 'no steps module at /nowhere/steps.ts'];
        yield 'an unknown CMS' => [['http://localhost:8300', 'MODULE', '--cms', 'typo3'], "unknown CMS 'typo3'"];
        yield 'an unknown phase' => [['http://localhost:8300', 'MODULE', '--phase', 'during'], '--phase takes before or after'];
        yield 'a size without a height' => [['http://localhost:8300', 'MODULE', '--size', '800'], '--size takes <width>x<height>'];
        yield 'no seconds' => [['http://localhost:8300', 'MODULE', '--timeout', '0'], '--timeout takes whole seconds'];
        yield 'an option without a value' => [['http://localhost:8300', 'MODULE', '--cms'], '--cms needs a value'];
        yield 'an unknown option' => [['http://localhost:8300', 'MODULE', '--keep', 'yes'], 'unknown option --keep'];
    }

    /**
     * @dataProvider invalidArguments
     * @param list<string> $arguments
     */
    public function testInvalidArgumentsStopBeforeDocker(array $arguments, string $message): void
    {
        $runner = new StepsRunnerFake(['ok' => true, 'errors' => []]);
        $arguments = array_map(fn(string $argument): string => $argument === 'MODULE' ? $this->module : $argument, $arguments);
        try {
            $this->steps($runner, $arguments);
            self::fail('invalid arguments must stop the run');
        } catch (RuntimeException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame([], $runner->commands);
    }

    public function testAModuleThatIsNoScriptIsRefused(): void
    {
        $text = $this->temporary . '/steps.txt';
        file_put_contents($text, '');
        $this->expectExceptionMessage('steps takes a .ts, .mts, .js, .mjs module');
        $this->steps(new StepsRunnerFake(['ok' => true, 'errors' => []]), ['http://localhost:8300', $text]);
    }

    public function testAModuleInADirectoryCkCannotWriteToIsNamed(): void
    {
        chmod($this->temporary, 0555);
        try {
            $this->expectExceptionMessage("cannot create a file in {$this->temporary}");
            $this->steps(new StepsRunnerFake(['ok' => true, 'errors' => []]), ['http://localhost:8300', $this->module]);
        } finally {
            chmod($this->temporary, 0755);
        }
    }

    public function testTheCliImageShipsEveryBrowserScript(): void
    {
        $root = dirname(__DIR__, 2);
        $dockerfile = (string) file_get_contents($root . '/docker/cli/Dockerfile');
        $scripts = array_map('basename', (array) glob($root . '/toolbelt/demo/*.mts'));
        self::assertContains('steps.mts', $scripts);
        foreach ($scripts as $script) {
            self::assertStringContainsString(
                "COPY toolbelt/demo/{$script} /opt/civikitchen/toolbelt/demo/{$script}\n",
                $dockerfile,
            );
        }
    }

    public function testHelpAndNoArguments(): void
    {
        $runner = new StepsRunnerFake(['ok' => true, 'errors' => []]);
        [$status, $output] = $this->steps($runner, ['--help']);
        self::assertSame(0, $status);
        self::assertStringContainsString('ck steps <site-url> <steps.ts>', $output);
        self::assertSame(2, $this->steps($runner, [])[0]);
        self::assertSame([], $runner->commands);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function steps(StepsRunnerFake $runner, array $arguments): array
    {
        ob_start();
        try {
            $status = (new StepsCommand(dirname(__DIR__, 2), $runner))->run($arguments);
        } finally {
            $output = (string) ob_get_clean();
        }
        return [$status, $output];
    }
}

/** Docker as `ck steps` drives it: the Playwright image exists and the browser prints one report. */
final class StepsRunnerFake extends Runner
{
    /** @var list<list<string>> */
    public array $commands = [];

    public bool $docker = true;
    public int $status = 0;
    public string $progress = "launching\n";

    /** @param array<string, mixed> $report */
    public function __construct(private readonly array $report)
    {
    }

    public function which(string $program): ?string
    {
        return $program === 'docker' && !$this->docker ? null : "/fake/{$program}";
    }

    public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        $this->commands[] = $command;
        return ['status' => 0, 'output' => ''];
    }

    public function captureStdout(array $command, ?string $input = null, ?string $workingDirectory = null): array
    {
        $this->commands[] = $command;
        return ['status' => $this->status, 'output' => $this->progress . json_encode($this->report) . "\n"];
    }

    /** @return list<string> the `docker run` of the browser */
    public function browser(): array
    {
        return (array) end($this->commands);
    }

    /** @return array<string, mixed> what steps.mts was given */
    public function spec(): array
    {
        $command = $this->browser();
        return json_decode((string) end($command), true);
    }
}
