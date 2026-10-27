<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Policy;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    /** The image logs PHP warnings to stderr; one must not become the coverage floor. */
    public function testOnlyWhatCkconformPrintsOnStdoutIsAValue(): void
    {
        $runner = new NoisyPolicyRunner(0, "70 -- measured\n", "PHP Deprecated:  a dependency of ckconform\n");

        self::assertSame(['70 -- measured'], (new Policy('/ck', $runner))->values('min_coverage'));
        self::assertSame('70 -- measured', (new Policy('/ck', $runner))->value('min_coverage'));
    }

    public function testAnUnreadablePolicyThrowsWithCkconformsReason(): void
    {
        $runner = new NoisyPolicyRunner(2, '', "ckconform: invalid civikitchen.yaml\n");

        $this->expectExceptionMessage("cannot read policy.min_coverage from /repo/civikitchen.yaml:\nckconform: invalid civikitchen.yaml");
        (new Policy('/ck', $runner))->values('min_coverage', '/repo');
    }
}

/** ckconform as a process: stdout and stderr apart, or merged the way capture() hands them over. */
final class NoisyPolicyRunner extends Runner
{
    public function __construct(
        private readonly int $status,
        private readonly string $stdout,
        private readonly string $stderr,
    ) {
    }

    public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        return ['status' => $this->status, 'output' => $this->stderr . $this->stdout];
    }

    public function captureSeparate(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        return ['status' => $this->status, 'stdout' => $this->stdout, 'stderr' => $this->stderr];
    }
}
