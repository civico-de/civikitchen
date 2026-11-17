<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\CiCoverageCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class CiCoverageCheckTest extends CheckTestCase
{
    public function testWarnsWhenCiRunsTestsWithoutCoverage(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            '.github/workflows/ci.yml' => "jobs:\n  test:\n    steps:\n      - run: cktest\n",
        ]);
        $this->assertWarns(
            $this->run_(new CiCoverageCheck(), $context),
            'CI runs tests without coverage — add ckcoverage',
        );
    }

    /** An untracked local tests/phpunit is not a suite CI could ever run. */
    public function testAnUntrackedSuiteDirectoryIsIgnored(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "jobs:\n  test:\n    steps:\n      - run: cktest\n",
        ], git: true);
        mkdir($context->root . '/tests/phpunit', 0777, true);
        file_put_contents($context->root . '/tests/phpunit/SomeTest.php', '<?php');
        $this->assertSilent($this->run_(new CiCoverageCheck(), $context));
    }

    public function testSilentWhenCkcoverageIsWired(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            '.github/workflows/ci.yml' => "jobs:\n  test:\n    steps:\n      - run: ckcoverage\n",
        ]);
        $this->assertSilent($this->run_(new CiCoverageCheck(), $context));
    }

    /** Any one workflow mentioning coverage satisfies the check, as in bash. */
    public function testASecondWorkflowMayCarryCoverage(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            '.github/workflows/lint.yml' => "jobs:\n  lint:\n    steps:\n      - run: cklint\n",
            '.github/workflows/test.yml' => "jobs:\n  test:\n    steps:\n      - run: phpunit --coverage-text\n",
        ]);
        $this->assertSilent($this->run_(new CiCoverageCheck(), $context));
    }

    public function testSilentWithoutWorkflows(): void
    {
        $context = $this->repo(['tests/phpunit/SomeTest.php' => '<?php']);
        $this->assertSilent($this->run_(new CiCoverageCheck(), $context));
    }

    public function testSilentWithoutATestDirectory(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "jobs:\n  build:\n    steps:\n      - run: make\n",
        ]);
        $this->assertSilent($this->run_(new CiCoverageCheck(), $context));
    }

    /**
     * A declared min_coverage needs ckcoverage in CI: `phpunit --coverage-text`
     * prints a percentage and always exits 0.
     */
    public function testADeclaredFloorWithoutCkcoverageFails(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "min_coverage=54\n",
            'tests/phpunit/SomeTest.php' => '<?php',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: phpunit --coverage-text tests/phpunit\n",
        ]);
        $this->assertFails($this->run_(new CiCoverageCheck(), $context), 'it never fails');
    }

    public function testADeclaredFloorWithCkcoveragePasses(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "min_coverage=54\n",
            'tests/phpunit/SomeTest.php' => '<?php',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: ckcoverage tests/phpunit\n",
        ]);
        $this->assertPasses($this->run_(new CiCoverageCheck(), $context));
    }

    public function testIncludingTheSharedGitlabCiCountsAsRunningCkcoverage(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "min_coverage=54\n",
            'tests/phpunit/SomeTest.php' => '<?php',
            '.gitlab-ci.yml' => "include:\n  - remote: https://raw.githubusercontent.com/civico-de/civikitchen/v1/ci/gitlab/extension-ci.yml\n",
        ]);
        $this->assertPasses($this->run_(new CiCoverageCheck(), $context));
    }

    /** Delegating to the shared CI runs ckcoverage, though the token is not local. */
    public function testCallingTheSharedCiCountsAsRunningCkcoverage(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "min_coverage=54\n",
            'tests/phpunit/SomeTest.php' => '<?php',
            '.github/workflows/ci.yml' => "jobs:\n  ci:\n    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@main\n    with:\n      key: x\n",
        ]);
        $this->assertPasses($this->run_(new CiCoverageCheck(), $context));
    }

    /** No floor declared: reporting-only coverage is still acceptable. */
    public function testWithoutAFloorReportingCoverageIsEnough(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: phpunit --coverage-text\n",
        ]);
        $this->assertPasses($this->run_(new CiCoverageCheck(), $context));
    }

    /** @param list<string> $steps */
    private function measuredRepo(array $steps): \CiviKitchen\Ckconform\Context
    {
        $body = "jobs:\n  test:\n    runs-on: ubuntu-24.04\n    steps:\n";
        foreach ($steps as $step) {
            $body .= str_starts_with($step, '#') ? "      {$step}\n" : "      - run: {$step}\n";
        }

        return $this->repo([
            '__policy_fixture' => "min_coverage=80\n",
            'tests/phpunit/SomeTest.php' => '<?php',
            '.github/workflows/ci.yml' => $body,
        ], git: true);
    }

    public function testCkCiMeasuresCoverage(): void
    {
        $this->assertSilent($this->run_(new CiCoverageCheck(), $this->measuredRepo(['docker compose exec -T app ck ci'])));
    }

    public function testCkCoverageMeasuresCoverage(): void
    {
        $context = $this->measuredRepo(['ck lint --all', 'ck coverage tests/phpunit']);
        $this->assertSilent($this->run_(new CiCoverageCheck(), $context));
    }

    public function testACkCiThatSkipsTheCoverageGateMeasuresNothing(): void
    {
        $context = $this->measuredRepo(['ck ci --skip=ckcoverage,ckeslint']);
        $this->assertFails($this->run_(new CiCoverageCheck(), $context), 'no workflow runs ckcoverage');
    }

    public function testACommentNamingCkcoverageMeasuresNothing(): void
    {
        $context = $this->measuredRepo(['# TODO: switch to ckcoverage once the suite is green; cklint later', 'phpunit']);
        $this->assertFails($this->run_(new CiCoverageCheck(), $context), 'no workflow runs ckcoverage');
    }
}
