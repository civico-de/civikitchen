<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Cli\MutationCommand;
use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use PHPUnit\Framework\TestCase;

final class MutationTest extends TestCase
{
    private string $temporary;

    private string $before;

    private string|false $uf;

    protected function setUp(): void
    {
        $this->uf = getenv('CIVICRM_UF');
        putenv('CIVICRM_UF');
        $this->temporary = Directory::temporary('civikitchen-mutation');
        $this->before = (string) getcwd();
        chdir($this->temporary);
        file_put_contents('info.xml', '<extension key="org.example.widget"/>');
        file_put_contents('phpunit.xml.dist', '<phpunit/>');
        mkdir('Civi');
    }

    protected function tearDown(): void
    {
        putenv($this->uf === false ? 'CIVICRM_UF' : "CIVICRM_UF={$this->uf}");
        chdir($this->before);
        Directory::remove($this->temporary);
    }

    public function testWithoutAFloorItSkipsAndNeverStartsInfection(): void
    {
        $runner = new MutationRunner([]);
        self::assertSame(0, $this->run_($runner));
        self::assertNull($runner->command);
    }

    /** infection itself names every way to get coverage; --coverage needs no driver in this process. */
    public function testMutatesTheChangedLinesAndHandsItsArgumentsToInfection(): void
    {
        $runner = new MutationRunner(['mutation_min_msi' => '80']);
        self::assertSame(0, $this->run_($runner, ['--coverage=build/coverage']));
        self::assertSame(['/fake/infection', '--configuration=.ckmutate.json', '--no-progress', '--no-interaction',
            '--min-msi=80', '--git-diff-lines', '--git-diff-base=origin/main', '--coverage=build/coverage'], $runner->command);
        self::assertSame('UnitTests', $runner->environment['CIVICRM_UF'] ?? null);
        self::assertSame(['Civi'], $runner->config['source']['directories'] ?? null);
        self::assertSame('/fake/phpunit', $runner->config['phpUnit']['customPath'] ?? null);
    }

    public function testPolicyPathsReplaceTheDiffAndTheCoveredFloorIsPassedOn(): void
    {
        mkdir('lib');
        $runner = new MutationRunner(['mutation_min_msi' => '80', 'mutation_min_covered_msi' => '90', 'mutation_paths' => 'Civi,lib']);
        self::assertSame(0, $this->run_($runner));
        self::assertSame(['/fake/infection', '--configuration=.ckmutate.json', '--no-progress', '--no-interaction',
            '--min-msi=80', '--min-covered-msi=90'], $runner->command);
        self::assertSame(['Civi', 'lib'], $runner->config['source']['directories'] ?? null);
    }

    public function testASetCivicrmUfIsKept(): void
    {
        putenv('CIVICRM_UF=Standalone');
        $runner = new MutationRunner(['mutation_min_msi' => '80']);
        $this->run_($runner);
        self::assertSame('Standalone', $runner->environment['CIVICRM_UF'] ?? null);
    }

    public function testInfectionsStatusIsTheResult(): void
    {
        self::assertSame(1, $this->run_(new MutationRunner(['mutation_min_msi' => '80'], infectionStatus: 1)));
    }

    /** @dataProvider refusals */
    public function testRefusesBeforeInfectionRuns(callable $arrange, MutationRunner $runner): void
    {
        $arrange();
        self::assertSame(2, $this->run_($runner));
        self::assertNull($runner->command);
    }

    /** @return array<string, array{callable, MutationRunner}> */
    public static function refusals(): array
    {
        $floor = ['mutation_min_msi' => '80'];
        return [
            'no info.xml' => [static fn () => unlink('info.xml'), new MutationRunner($floor)],
            'no infection' => [static fn () => null, new MutationRunner($floor, infection: false)],
            'no phpunit config' => [static fn () => unlink('phpunit.xml.dist'), new MutationRunner($floor)],
            'no source directory' => [static fn () => rmdir('Civi'), new MutationRunner($floor)],
            'a policy path that is missing' => [static fn () => null, new MutationRunner($floor + ['mutation_paths' => 'Civi,lib'])],
            'an unresolvable base' => [static fn () => null, new MutationRunner($floor, baseResolves: false)],
            'a config of the user' => [static fn () => file_put_contents('.ckmutate.json', '{"mine":true}'), new MutationRunner($floor)],
        ];
    }

    public function testLeavesAnExistingConfigAlone(): void
    {
        file_put_contents('.ckmutate.json', '{"mine":true}');
        $this->run_(new MutationRunner(['mutation_min_msi' => '80']));
        self::assertSame('{"mine":true}', file_get_contents('.ckmutate.json'));
    }

    /** @param list<string> $arguments */
    private function run_(MutationRunner $runner, array $arguments = []): int
    {
        ob_start();
        try {
            return (new MutationCommand(dirname(__DIR__, 2), $runner))->run($arguments);
        } finally {
            ob_end_clean();
        }
    }
}

/** Answers the policy reads and git, and records the infection run with the config it found. */
final class MutationRunner extends Runner
{
    /** @var list<string>|null */
    public ?array $command = null;

    /** @var array<string, string> */
    public array $environment = [];

    /** @var array<string, mixed> */
    public array $config = [];

    /** @param array<string, string> $policy */
    public function __construct(
        private readonly array $policy,
        private readonly bool $infection = true,
        private readonly bool $baseResolves = true,
        private readonly int $infectionStatus = 0,
    ) {
    }

    public function which(string $program): ?string
    {
        return $program === 'infection' && !$this->infection ? null : "/fake/{$program}";
    }

    public function captureSeparate(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        $value = ($command[1] ?? '') === '--policy' ? ($this->policy[$command[2]] ?? '') . "\n" : '';
        return ['status' => 0, 'stdout' => $value, 'stderr' => ''];
    }

    public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        return ['status' => in_array('rev-parse', $command, true) && !$this->baseResolves ? 1 : 0, 'output' => ''];
    }

    public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
    {
        $this->command = $command;
        $this->environment = $environment ?? [];
        $this->config = (array) json_decode((string) file_get_contents('.ckmutate.json'), true);
        return $this->infectionStatus;
    }
}
