<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Cli\PlaywrightCommand;
use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Playwright\Image;
use CiviKitchen\Toolbelt\Process\Runner;
use PHPUnit\Framework\TestCase;

final class PlaywrightTest extends TestCase
{
    private string $temporary;

    private string $directory;

    /** Variables the tests set, with the values the developer's shell had. */
    private const ENVIRONMENT = ['CK_PLAYWRIGHT_TEST', 'LC_CK_TEST', 'PLAYWRIGHT_BROWSERS_PATH', 'DOCKER_DEFAULT_PLATFORM'];

    /** @var array<string, string|false> */
    private array $environment = [];

    /** @var list<string> build directories an interrupted real run left behind */
    private array $leftovers = [];

    protected function setUp(): void
    {
        $this->temporary = (string) realpath(Directory::temporary('civikitchen-playwright'));
        $this->directory = (string) getcwd();
        foreach (self::ENVIRONMENT as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
        $this->leftovers = $this->buildDirectories();
    }

    protected function tearDown(): void
    {
        chdir($this->directory);
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        Directory::remove($this->temporary);
    }

    public function testAnNpmProjectIsBuiltOnTheImageOfThePlaywrightItsTreeHolds(): void
    {
        $project = $this->project(['package-lock.json' => '{}']);
        $runner = new PlaywrightRunner();
        $tag = (new Image(dirname(__DIR__, 2), $runner))->for($project);

        self::assertMatchesRegularExpression('/^civikitchen-playwright:[0-9a-f]{12}$/', $tag);
        [$dependencies, $image] = $runner->builds;
        self::assertSame(['--build-arg', 'PACKAGE_MANAGER=npm', '--target', 'playwright-core'], array_slice($dependencies, 2, 4));
        self::assertSame(['--build-arg', 'PACKAGE_MANAGER=npm', '--build-arg', 'PLAYWRIGHT_VERSION=1.59.1', '-t', $tag], array_slice($image, 2, 6));
        self::assertSame(['Dockerfile', 'package-lock.json', 'package.json'], $runner->contexts[0]);
        self::assertSame($this->leftovers, $this->buildDirectories(), 'the build context and the export are removed');
    }

    public function testAnImageIsBuiltOncePerLockfile(): void
    {
        $project = $this->project(['package-lock.json' => '{}']);
        $runner = new PlaywrightRunner();
        $runner->imageExists = true;
        $first = (new Image(dirname(__DIR__, 2), $runner))->for($project);
        file_put_contents("{$project}/package-lock.json", '{"lockfileVersion": 3}');

        self::assertNotSame($first, $second = (new Image(dirname(__DIR__, 2), $runner))->for($project));
        // An image of another platform is not reused: Docker would look for the requested one in a registry.
        putenv('DOCKER_DEFAULT_PLATFORM=linux/amd64');
        self::assertNotSame($second, (new Image(dirname(__DIR__, 2), $runner))->for($project));
        self::assertSame([], $runner->builds);
    }

    public function testABunProjectBuildsWithTheBunItPins(): void
    {
        $project = $this->project(['bun.lock' => '{}'], ['packageManager' => 'bun@1.4.2+sha512.abc']);
        $runner = new PlaywrightRunner();
        (new Image(dirname(__DIR__, 2), $runner))->for($project);

        self::assertSame(['--build-arg', 'PACKAGE_MANAGER=bun', '--build-arg', 'BUN_VERSION=1.4.2'], array_slice($runner->builds[0], 2, 4));
        self::assertSame(['Dockerfile', 'bun.lock', 'package.json'], $runner->contexts[0]);
    }

    public function testInstallSettingsEnterTheBuildAndTheTag(): void
    {
        $project = $this->project(['bun.lock' => '{}'], ['packageManager' => 'bun@1.4.2']);
        $runner = new PlaywrightRunner();
        $without = (new Image(dirname(__DIR__, 2), $runner))->for($project);
        file_put_contents("{$project}/.npmrc", "legacy-peer-deps=true\n");
        file_put_contents("{$project}/bunfig.toml", "[install]\n");
        $with = (new Image(dirname(__DIR__, 2), $runner))->for($project);

        self::assertNotSame($without, $with);
        self::assertSame(['.npmrc', 'Dockerfile', 'bun.lock', 'bunfig.toml', 'package.json'], $runner->contexts[2]);
        self::assertSame($this->leftovers, $this->buildDirectories(), 'a context with dotfiles is removed too');
    }

    /** @return iterable<string, array{array<string, string>, array<string, string>, string}> */
    public static function unbuildableProjects(): iterable
    {
        yield 'no lockfile' => [[], [], 'needs exactly one lockfile'];
        yield 'two lockfiles' => [['package-lock.json' => '{}', 'bun.lock' => '{}'], [], 'needs exactly one lockfile'];
        yield 'Bun without a pin' => [['bun.lock' => '{}'], [], 'pins no Bun'];
        yield 'Bun pinned by range' => [['bun.lock' => '{}'], ['packageManager' => 'bun@^1.4'], 'pins no Bun'];
    }

    /**
     * @dataProvider unbuildableProjects
     * @param array<string, string> $files
     * @param array<string, string> $package
     */
    public function testAProjectWithoutAnUnambiguousLockfileIsRefused(array $files, array $package, string $message): void
    {
        $project = $this->project($files, $package);
        $this->expectExceptionMessage($message);
        (new Image(dirname(__DIR__, 2), new PlaywrightRunner()))->for($project);
    }

    public function testABuildWithoutBuildKitIsRefusedBeforeItStarts(): void
    {
        $project = $this->project(['package-lock.json' => '{}']);
        $runner = new PlaywrightRunner();
        $runner->buildx = false;
        try {
            (new Image(dirname(__DIR__, 2), $runner))->for($project);
            self::fail('the build ran');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString("needs BuildKit: install the docker CLI's buildx plugin", $exception->getMessage());
        }
        self::assertSame([], $runner->builds);
    }

    public function testAProjectWithoutPackageJsonIsRefused(): void
    {
        $this->expectExceptionMessage('package.json is missing or not JSON');
        (new Image(dirname(__DIR__, 2), new PlaywrightRunner()))->for($this->temporary);
    }

    /** @return iterable<string, array{string, string}> */
    public static function failedBuilds(): iterable
    {
        yield 'the install fails' => ['dependencies', 'could not install the dependencies of'];
        yield 'no playwright-core in the tree' => ['no-core', 'hold no playwright-core release'];
        yield 'the image fails' => ['image', 'could not build the Playwright image civikitchen-playwright:'];
    }

    /** @dataProvider failedBuilds */
    public function testAFailedBuildIsAnErrorAndLeavesNothingBehind(string $failure, string $message): void
    {
        $project = $this->project(['package-lock.json' => '{}']);
        $runner = new PlaywrightRunner();
        $runner->failure = $failure;
        try {
            (new Image(dirname(__DIR__, 2), $runner))->for($project);
            self::fail('the build passed');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
        self::assertSame($this->leftovers, $this->buildDirectories());
    }

    public function testRunsTheCommandInTheWorkTreeAtItsOwnPath(): void
    {
        $workTree = $this->temporary . '/repo';
        $project = $this->project(['package-lock.json' => '{}'], [], 'repo/ext');
        mkdir("{$project}/tests/e2e", 0777, true);
        mkdir("{$project}/node_modules");
        mkdir("{$workTree}/node_modules");
        mkdir("{$workTree}/.git");
        chdir("{$project}/tests/e2e");
        putenv('CK_PLAYWRIGHT_TEST=1');
        putenv('LC_CK_TEST=de_DE.UTF-8');
        putenv('PLAYWRIGHT_BROWSERS_PATH=/home/alice/browsers');
        $runner = new PlaywrightRunner();
        $runner->runStatus = 3;

        self::assertSame(3, $this->playwright($runner, ['npm', 'run', 'test:e2e', '--', '--grep', '@smoke']));
        $run = $runner->run();
        self::assertSame(['npm', 'run', 'test:e2e', '--', '--grep', '@smoke'], array_slice($run, -6));
        self::assertMatchesRegularExpression('/^civikitchen-playwright:[0-9a-f]{12}$/', $run[count($run) - 7]);
        $options = $this->options($run);
        self::assertSame(['host'], $options['--network']);
        self::assertSame(['991'], $options['--group-add']);
        self::assertSame(["{$project}/tests/e2e"], $options['-w']);
        self::assertSame(['/var/run/docker.sock:/var/run/docker.sock', "{$workTree}:{$workTree}"], $options['-v']);
        self::assertSame(["type=tmpfs,destination={$project}/node_modules", "type=tmpfs,destination={$workTree}/node_modules"], $options['--mount']);
        self::assertContains('HOME=/tmp', $options['-e']);
        self::assertContains('CK_PLAYWRIGHT_TEST', $options['-e']);
        self::assertSame([], array_intersect(['HOME', 'PATH', 'TMPDIR', 'USER', 'DOCKER_HOST', 'LANG', 'LC_CK_TEST', 'PLAYWRIGHT_BROWSERS_PATH'], $options['-e']));
        // Piped input reaches the command; a terminal only when both ends are one.
        self::assertContains('-i', $run);
        self::assertSame(posix_isatty(STDIN) && posix_isatty(STDOUT), in_array('-t', $run, true));
    }

    public function testTakesTheNetworkBeforeTheCommand(): void
    {
        $this->project(['package-lock.json' => '{}']);
        chdir($this->temporary);
        $runner = new PlaywrightRunner();

        self::assertSame(0, $this->playwright($runner, ['--network', 'container:abc', '--', 'playwright', 'test', '--network', 'x']));
        self::assertSame(['container:abc'], $this->options($runner->run())['--network']);
        self::assertSame(['playwright', 'test', '--network', 'x'], array_slice($runner->run(), -4));
    }

    public function testOutsideAGitWorkTreeOnlyTheCurrentDirectoryIsMounted(): void
    {
        $this->project(['package-lock.json' => '{}']);
        chdir($this->temporary);
        $runner = new PlaywrightRunner();

        $this->playwright($runner, ['playwright', 'test']);
        self::assertContains("{$this->temporary}:{$this->temporary}", $this->options($runner->run())['-v']);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function refusedArguments(): iterable
    {
        yield 'an unknown option' => [['--headed', 'playwright', 'test'], 'not --headed'];
        yield 'a network without a mode' => [['--network'], 'not --network'];
        yield 'no command' => [['--network', 'host', '--'], 'needs a command to run'];
    }

    /**
     * @dataProvider refusedArguments
     * @param list<string> $arguments
     */
    public function testRefusesArgumentsItCannotRun(array $arguments, string $message): void
    {
        $this->expectExceptionMessage($message);
        $this->playwright(new PlaywrightRunner(), $arguments);
    }

    /** The real Runner: a fake that answers the docker lookup itself would hide a call the Runner lacks. */
    public function testNeedsDockerOnTheHost(): void
    {
        $path = (string) getenv('PATH');
        putenv("PATH={$this->temporary}");
        try {
            $this->expectExceptionMessage('needs docker on PATH');
            (new PlaywrightCommand(dirname(__DIR__, 2), new Runner()))->run(['playwright', 'test']);
        } finally {
            putenv("PATH={$path}");
        }
    }

    public function testFindsNoProjectAboveTheWorkTree(): void
    {
        mkdir($this->temporary . '/repo/sub', 0777, true);
        mkdir($this->temporary . '/repo/.git');
        file_put_contents($this->temporary . '/package.json', '{}');
        chdir($this->temporary . '/repo/sub');
        $runner = new PlaywrightRunner();
        $this->expectExceptionMessage("no package.json in {$this->temporary}/repo/sub or above it in {$this->temporary}/repo");
        $this->playwright($runner, ['playwright', 'test']);
    }

    public function testAnUnreadableSocketGroupIsAnError(): void
    {
        $this->project(['package-lock.json' => '{}']);
        chdir($this->temporary);
        $runner = new PlaywrightRunner();
        $runner->socketGroup = "stat: cannot statx '/var/run/docker.sock': No such file or directory\n";
        $this->expectExceptionMessage('cannot read the group of the Docker socket');
        $this->playwright($runner, ['playwright', 'test']);
    }

    public function testHelpNamesTheNetworkOption(): void
    {
        ob_start();
        $status = (new PlaywrightCommand(dirname(__DIR__, 2), new PlaywrightRunner()))->run(['--help']);
        $output = (string) ob_get_clean();
        self::assertSame(0, $status);
        self::assertStringContainsString('--network <mode>', $output);
    }

    /**
     * @param array<string, string> $files
     * @param array<string, string> $package
     */
    private function project(array $files, array $package = [], string $path = ''): string
    {
        $directory = $path === '' ? $this->temporary : "{$this->temporary}/{$path}";
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents("{$directory}/package.json", json_encode(['name' => 'widget'] + $package));
        foreach ($files as $name => $content) {
            file_put_contents("{$directory}/{$name}", $content);
        }
        return $directory;
    }

    /** @param list<string> $arguments */
    private function playwright(PlaywrightRunner $runner, array $arguments): int
    {
        return (new PlaywrightCommand(dirname(__DIR__, 2), $runner))->run($arguments);
    }

    /** @return list<string> */
    private function buildDirectories(): array
    {
        return glob(sys_get_temp_dir() . '/ck-playwright-*') ?: [];
    }

    /**
     * The values of each repeatable `docker run` option before the image.
     *
     * @param list<string> $run
     * @return array<string, list<string>>
     */
    private function options(array $run): array
    {
        $options = ['--network' => [], '--group-add' => [], '-w' => [], '-v' => [], '--mount' => [], '-e' => []];
        foreach ($run as $index => $part) {
            if (isset($options[$part]) && isset($run[$index + 1])) {
                $options[$part][] = $run[$index + 1];
            }
            if (str_starts_with($part, 'civikitchen-playwright:')) {
                break;
            }
        }
        return $options;
    }
}

/** Docker as `ck playwright` drives it. */
final class PlaywrightRunner extends Runner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var list<list<string>> */
    public array $builds = [];

    /** @var list<list<string>> the files of each build context */
    public array $contexts = [];

    public bool $imageExists = false;
    public bool $buildx = true;
    public string $socketGroup = "991\n";
    public string $failure = '';
    public int $runStatus = 0;

    public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
    {
        $this->commands[] = $command;
        if (array_slice($command, 0, 2) === ['docker', 'build']) {
            $this->builds[] = $command;
            $this->contexts[] = array_values(array_diff(scandir((string) end($command)) ?: [], ['.', '..']));
            $export = current(array_filter($command, static fn(string $part): bool => str_starts_with($part, 'type=local,dest=')));
            if ($export === false) {
                return $this->failure === 'image' ? 1 : 0;
            }
            if ($this->failure !== 'no-core') {
                file_put_contents(substr($export, strlen('type=local,dest=')) . '/package.json', '{"name": "playwright-core", "version": "1.59.1"}');
            }
            return $this->failure === 'dependencies' ? 1 : 0;
        }
        return $this->runStatus;
    }

    public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        $this->commands[] = $command;
        if (array_slice($command, 0, 3) === ['docker', 'image', 'inspect']) {
            return ['status' => $this->imageExists ? 0 : 1, 'output' => ''];
        }
        if (array_slice($command, 0, 2) === ['docker', 'buildx']) {
            return ['status' => $this->buildx ? 0 : 1, 'output' => ''];
        }
        return ['status' => 0, 'output' => $this->socketGroup];
    }

    public function which(string $program): ?string
    {
        return "/fake/{$program}";
    }

    /** @return list<string> */
    public function run(): array
    {
        $runs = array_values(array_filter($this->commands, static fn(array $command): bool => array_slice($command, 0, 3) === ['docker', 'run', '--rm']
            && !in_array('--entrypoint', $command, true)));
        return $runs[0] ?? [];
    }
}
