<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\ConfigWithoutRunnerCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class ConfigWithoutRunnerCheckTest extends CheckTestCase
{
    public function testSilentWithoutWorkflows(): void
    {
        $context = $this->repo(['phpstan.neon.dist' => 'parameters:'], git: true);
        $this->assertSilent($this->run_(new ConfigWithoutRunnerCheck(), $context));
    }

    /** The classic case: a phpstan config no workflow ever invokes. */
    public function testAPhpstanConfigNobodyRunsFails(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => 'parameters:',
            '.github/workflows/ci.yml' => "jobs:\n  lint:\n    steps:\n      - run: cklint\n",
        ], git: true);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'phpstan.neon.dist');
    }

    public function testAnInvokedConfigPasses(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => 'parameters:',
            '.github/workflows/ci.yml' => "jobs:\n  lint:\n    steps:\n      - run: phpstan analyse\n",
        ], git: true);
        $this->assertPasses($this->run_(new ConfigWithoutRunnerCheck(), $context));
    }

    /** ckcoverage runs phpunit, so it counts as the phpunit step. */
    public function testCkcoverageCountsAsThePhpunitRunner(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: ckcoverage tests/phpunit\n",
        ], git: true);
        $this->assertPasses($this->run_(new ConfigWithoutRunnerCheck(), $context));
    }

    /**
     * The indirection that matters in practice: CI runs `npm run test`, and the
     * script is what names vitest. Without resolving it this would be a false
     * positive, and noise is how a checker gets ignored.
     */
    public function testAnNpmScriptCountsAsInvokingTheTool(): void
    {
        $context = $this->repo([
            'vitest.config.ts' => 'export default {}',
            'package.json' => '{"name":"x","scripts":{"test":"vitest run"}}',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: npm run test\n",
        ], git: true);
        $this->assertPasses($this->run_(new ConfigWithoutRunnerCheck(), $context));
    }

    public function testAnUnreferencedNpmScriptDoesNotCount(): void
    {
        $context = $this->repo([
            'vitest.config.ts' => 'export default {}',
            'package.json' => '{"name":"x","scripts":{"test":"vitest run"}}',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: npm run build\n",
        ], git: true);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'vitest.config.ts');
    }

    /** ckcoverage runs phpunit.xml(.dist) only, so a second config needs its own step. */
    public function testASecondPhpunitConfigNeedsItsOwnStep(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            'phpunit-unit.xml.dist' => '<phpunit/>',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: ckcoverage tests/phpunit\n",
        ], git: true);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'phpunit-unit.xml.dist');
    }

    /** A second suite inside the main suite's directories runs with it, unless the main suite excludes it. */
    public function testASecondConfigInsideTheMainSuiteRunsWithIt(): void
    {
        $main = static fn (string $exclude): string => '<phpunit><testsuites><testsuite name="all">'
            . '<directory>./tests/phpunit</directory>' . $exclude . '</testsuite></testsuites></phpunit>';
        $unit = static fn (string $directory): string => '<phpunit><testsuites><testsuite name="unit">'
            . "<directory>{$directory}</directory></testsuite></testsuites></phpunit>";
        $cases = [
            [$main(''), $unit('./tests/phpunit/Unit'), true],
            [$main(''), $unit('tests/phpunit/'), true],
            [$main(''), $unit('./tests/unit'), false],
            [$main('<exclude>./tests/phpunit/Unit</exclude>'), $unit('./tests/phpunit/Unit'), false],
        ];
        foreach ($cases as [$mainXml, $unitXml, $runs]) {
            $context = $this->repo([
                'phpunit.xml.dist' => $mainXml,
                'phpunit-unit.xml.dist' => $unitXml,
                '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: ck ci\n",
            ], git: true);
            $result = $this->run_(new ConfigWithoutRunnerCheck(), $context);
            $runs ? $this->assertPasses($result) : $this->assertFails($result, 'phpunit-unit.xml.dist');
        }
    }

    /** `ck ci --extra-phpunit-config` and `ckcoverage -c` run the named config; a skipped gate does not. */
    public function testTheSecondConfigRunsThroughCkCiOrCkcoverage(): void
    {
        $steps = [
            'ck ci --extra-phpunit-config phpunit-unit.xml.dist' => true,
            'ck ci --only ckcoverage,phpunit-extra --extra-phpunit-config="phpunit-unit.xml.dist"' => true,
            'ckcoverage -c phpunit-unit.xml.dist' => true,
            'ck ci --skip phpunit-extra --extra-phpunit-config phpunit-unit.xml.dist' => false,
        ];
        foreach ($steps as $step => $runs) {
            $context = $this->repo([
                'phpunit.xml.dist' => '<phpunit/>',
                'phpunit-unit.xml.dist' => '<phpunit/>',
                '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: {$step}\n",
            ], git: true);
            $result = $this->run_(new ConfigWithoutRunnerCheck(), $context);
            $runs ? $this->assertPasses($result) : $this->assertFails($result, 'phpunit-unit.xml.dist');
        }
    }

    /**
     * A step described in a comment is not a step. one repo's workflow explains
     * why its phpunit job was retired, and that prose alone satisfied the first
     * version of this check.
     */
    public function testAToolNamedOnlyInACommentDoesNotCount(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n"
                . "      # the phpunit job used to live here; see the notes\n"
                . "      - run: cklint\n",
        ], git: true);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'phpunit.xml.dist');
    }

    /**
     * Delegating to the shared CI runs phpstan and phpunit, so their configs
     * are not orphans — even though neither token is in the repo.
     */
    public function testTheSharedCiSatisfiesPhpstanAndPhpunit(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => 'parameters:',
            'phpunit.xml.dist' => '<phpunit/>',
            '.github/workflows/ci.yml' => "jobs:\n  ci:\n    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@main\n",
        ], git: true);
        $this->assertPasses($this->run_(new ConfigWithoutRunnerCheck(), $context));
    }

    /** But it does not run playwright, so a playwright config is still an orphan. */
    public function testTheSharedCiDoesNotCoverPlaywright(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => 'export default {}',
            '.github/workflows/ci.yml' => "jobs:\n  ci:\n    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@main\n",
        ], git: true);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'playwright.config.ts');
    }

    /** @param array<string, string> $files */
    private function runnerRepo(string $run, array $files): \CiviKitchen\Ckconform\Context
    {
        $files['.github/workflows/ci.yml'] = "jobs:\n  t:\n    runs-on: ubuntu-24.04\n    steps:\n      - run: {$run}\n";

        return $this->repo($files, git: true);
    }

    private const PHP_CONFIGS = [
        'phpstan.neon.dist' => 'parameters:',
        'phpunit.xml.dist' => '<phpunit/>',
    ];

    public function testCkCiRunsEveryPhpConfig(): void
    {
        $context = $this->runnerRepo('docker compose exec -T app ck ci', self::PHP_CONFIGS);
        $this->assertOk($this->run_(new ConfigWithoutRunnerCheck(), $context), 'every tool config has a CI step');
    }

    public function testCkCoverageRunsPhpunit(): void
    {
        $context = $this->runnerRepo("ck lint --all && ck coverage tests/phpunit", [
            'phpunit.xml.dist' => '<phpunit/>',
        ]);
        $this->assertOk($this->run_(new ConfigWithoutRunnerCheck(), $context), 'every tool config has a CI step');
    }

    public function testACkCiLimitedToLintLeavesPhpstanUnrun(): void
    {
        $context = $this->runnerRepo('ck ci --only cklint,ckconform', self::PHP_CONFIGS);
        $reporter = $this->run_(new ConfigWithoutRunnerCheck(), $context);
        $this->assertFails($reporter, 'phpstan.neon.dist (no phpstan step), phpunit.xml.dist (no phpunit step)');
    }

    /** The opt-in gates run phpunit only with a second config and phpstan only on the tests' own config. */
    public function testOptInGatesDoNotRunTheMainConfigs(): void
    {
        $context = $this->runnerRepo('ck ci --skip ckcoverage,phpstan', self::PHP_CONFIGS);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'phpstan.neon.dist (no phpstan step), phpunit.xml.dist (no phpunit step)');

        $context = $this->runnerRepo("ck ci --skip ckcoverage\n        env:\n          CK_EXTRA_PHPUNIT_CONFIG: phpunit.xml.dist", ['phpunit.xml.dist' => '<phpunit/>']);
        $this->assertOk($this->run_(new ConfigWithoutRunnerCheck(), $context), 'every tool config has a CI step');
    }

    /** `ck ci` unites repeated --only lists before it subtracts --skip. */
    public function testRepeatedOnlyListsAddUp(): void
    {
        foreach (['ck ci --only=cklint --only=ckcoverage,phpstan', 'ck ci --only "cklint, ckcoverage, phpstan"'] as $step) {
            $context = $this->runnerRepo($step, self::PHP_CONFIGS);
            $this->assertOk($this->run_(new ConfigWithoutRunnerCheck(), $context), 'every tool config has a CI step');
        }
    }

    /** A gate list from a variable may name any gate. */
    public function testAShellExpandedOnlyListCountsAsAnyGate(): void
    {
        $steps = [
            'ck ci --only "$CK_GATES"', 'ck ci --only="${{ inputs.gates }}"', 'ck ci --skip "$CK_SKIP"',
            'ck ci --only cklint --only "$GATES"', 'ck ci --only=cklint,$EXTRA', 'ck ci --only `cat .ci-gates`',
            'ck ci --only="`cat .ci-gates`"', 'ck ci --only={cklint,phpstan,ckcoverage}',
        ];
        foreach ($steps as $step) {
            $context = $this->runnerRepo($step, self::PHP_CONFIGS);
            $this->assertOk($this->run_(new ConfigWithoutRunnerCheck(), $context), 'every tool config has a CI step');
        }
    }

    public function testADirectoryNamedCkphpunitIsNoRunner(): void
    {
        foreach (['ls tests/ckphpunit', 'ls "tests"/ckphpunit'] as $step) {
            $context = $this->runnerRepo($step, ['phpunit.xml.dist' => '<phpunit/>']);
            $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'phpunit.xml.dist (no phpunit step)');
        }
    }

    /** A subshell or redirection around `ck ci` ends its gate list. */
    public function testACkCiInASubshellOrRedirectedRunsItsGates(): void
    {
        foreach (['(ck ci --only=ckcoverage)', 'out=$(ck ci --only ckcoverage)', 'ck ci --only=ckcoverage>ci.log'] as $step) {
            $context = $this->runnerRepo($step, ['phpunit.xml.dist' => '<phpunit/>']);
            $this->assertOk($this->run_(new ConfigWithoutRunnerCheck(), $context), 'every tool config has a CI step');
        }
    }

    public function testACkphpunitNamedInAMatrixListRunsPhpunit(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    strategy:\n      matrix:\n        tool: [ckphpunit, phpstan]\n"
                . "    runs-on: ubuntu-24.04\n    steps:\n      - run: vendor/bin/\${{ matrix.tool }}\n",
        ], git: true);
        $this->assertOk($this->run_(new ConfigWithoutRunnerCheck(), $context), 'every tool config has a CI step');
    }

    /** `ck phpunit` and the ckphpunit binary are `ck test`. */
    public function testCkPhpunitAndCkphpunitRunPhpunit(): void
    {
        foreach (['ck phpunit', 'ckphpunit --group headless', 'vendor/bin/ckphpunit', '$CK_TOOL_PATH/bin/ckphpunit', '$CK_BIN/ckphpunit', './ckphpunit', '/opt/toolbelt/bin/ckphpunit', '~/bin/ckphpunit',
            '${{ github.workspace }}/toolbelt/bin/ckphpunit', '"$TB"/ckphpunit', '/ckphpunit',
            '"$(composer config bin-dir)/ckphpunit"', '`composer config bin-dir`/ckphpunit', '"${{ github.workspace }}/toolbelt/bin"/ckphpunit',
            'out=`ckphpunit`', '/usr/local/sbin/ckphpunit', 'node_modules/.bin/ckphpunit'] as $step) {
            $context = $this->runnerRepo($step, ['phpunit.xml.dist' => '<phpunit/>']);
            $this->assertOk($this->run_(new ConfigWithoutRunnerCheck(), $context), 'every tool config has a CI step');
        }
    }

    public function testADirectoryNamedAfterAToolIsNoRunner(): void
    {
        $context = $this->runnerRepo('ls tests/phpunit/', ['phpunit.xml.dist' => '<phpunit/>']);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'phpunit.xml.dist (no phpunit step)');
    }

    public function testNpmTestResolvesTheTestScript(): void
    {
        $context = $this->runnerRepo('npm test', [
            'package.json' => '{"scripts": {"test": "vitest run"}}',
            'vitest.config.ts' => 'export default {};',
        ]);
        $this->assertOk($this->run_(new ConfigWithoutRunnerCheck(), $context), 'every tool config has a CI step');
    }

    public function testNpmTestRunningSomethingElseLeavesVitestUnrun(): void
    {
        $context = $this->runnerRepo('npm test', [
            'package.json' => '{"scripts": {"test": "eslint .", "unit": "vitest run"}}',
            'vitest.config.ts' => 'export default {};',
        ]);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'vitest.config.ts');
    }

    public function testComposerScriptsResolveIncludingReferences(): void
    {
        $context = $this->runnerRepo('composer test', [
            'composer.json' => '{"scripts": {"test": ["@unit"], "unit": "phpunit"}}',
            'phpunit.xml.dist' => '<phpunit/>',
        ]);
        $this->assertOk($this->run_(new ConfigWithoutRunnerCheck(), $context), 'every tool config has a CI step');
    }

    /** A second phpunit config runs where a phpunit step names it. */
    public function testAPhpunitStepNamingTheSecondConfigRunsIt(): void
    {
        foreach (['vendor/bin/phpunit -c phpunit-unit.xml.dist', 'phpunit --configuration=phpunit-unit.xml.dist'] as $step) {
            $context = $this->repo([
                'phpunit-unit.xml.dist' => '<phpunit/>',
                '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: $step\n",
            ], git: true);
            $this->assertPasses($this->run_(new ConfigWithoutRunnerCheck(), $context));
        }
    }

    public function testAPhpunitStepNotNamingTheSecondConfigLeavesItUnrun(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            'phpunit-unit.xml.dist' => '<phpunit/>',
            '.github/workflows/ci.yml' => "jobs:\n  t:\n    steps:\n      - run: vendor/bin/phpunit\n",
        ], git: true);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'phpunit-unit.xml.dist');
    }

    /** ckcoverage runs phpunit.xml(.dist) only; the shared CI runs a second config through extra_phpunit_config. */
    public function testTheSharedCiRunsTheSecondConfigOnlyWhenNamed(): void
    {
        $uses = "jobs:\n  ci:\n    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@main\n";
        $unnamed = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            'phpunit-unit.xml.dist' => '<phpunit/>',
            '.github/workflows/ci.yml' => $uses,
        ], git: true);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $unnamed), 'phpunit-unit.xml.dist');

        $named = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            'phpunit-unit.xml.dist' => '<phpunit/>',
            '.github/workflows/ci.yml' => $uses . "    with:\n      extra_phpunit_config: phpunit-unit.xml.dist\n",
        ], git: true);
        $this->assertPasses($this->run_(new ConfigWithoutRunnerCheck(), $named));
    }

    /** The shared GitLab pipeline runs the PHP gates, and playwright through the test:e2e script. */
    public function testTheSharedGitlabCiCoversPlaywrightAndThePhpTools(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => 'export default {}',
            'package.json' => '{"scripts": {"test:e2e": "playwright test"}}',
            'phpstan.neon.dist' => 'parameters:',
            '.gitlab-ci.yml' => self::GITLAB_CALLER,
        ], git: true);
        $this->assertPasses($this->run_(new ConfigWithoutRunnerCheck(), $context));
    }

    /** Without a test:e2e script the shared e2e job runs nothing. */
    public function testTheSharedGitlabCiLeavesPlaywrightUnrunWithoutTheScript(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => 'export default {}',
            'package.json' => '{"scripts": {"test": "vitest run"}}',
            '.gitlab-ci.yml' => self::GITLAB_CALLER,
        ], git: true);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $context), 'playwright.config.ts');
    }

    /** A repo script the workflow runs by path counts with what it runs, siblings it sources included. */
    public function testAShellScriptTheWorkflowRunsCountsAsInvokingTheTool(): void
    {
        $direct = $this->runnerRepo('tests/run-phpunit.sh', [
            'phpunit.xml.dist' => '<phpunit/>',
            'tests/run-phpunit.sh' => "#!/usr/bin/env bash\ncd \"$(dirname \"$0\")\"\ndocker compose exec -T app phpunit --configuration phpunit.xml.dist\n",
        ]);
        $this->assertPasses($this->run_(new ConfigWithoutRunnerCheck(), $direct));

        $sourced = $this->runnerRepo('./ci/check', [
            'phpstan.neon.dist' => 'parameters:',
            'ci/check' => "#!/usr/bin/env bash\n. ./analyse.sh\n",
            'ci/analyse.sh' => "phpstan analyse\n",
        ]);
        $this->assertPasses($this->run_(new ConfigWithoutRunnerCheck(), $sourced));
    }

    /** A script nothing in CI calls, or one that names the tool only in a comment, runs nothing. */
    public function testAShellScriptCountsOnlyWhenCalledAndOnlyForItsCommands(): void
    {
        $uncalled = $this->runnerRepo('cklint', [
            'phpunit.xml.dist' => '<phpunit/>',
            'tests/run-phpunit.sh' => "#!/usr/bin/env bash\nphpunit\n",
        ]);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $uncalled), 'phpunit.xml.dist');

        $commented = $this->runnerRepo('tests/run.sh', [
            'phpunit.xml.dist' => '<phpunit/>',
            'tests/run.sh' => "#!/usr/bin/env bash\n# runs phpunit some day\necho skipped\n",
        ]);
        $this->assertFails($this->run_(new ConfigWithoutRunnerCheck(), $commented), 'phpunit.xml.dist');
    }

    private const GITLAB_CALLER = "include:\n  - remote: https://raw.githubusercontent.com/civico-de/civikitchen/v1/ci/gitlab/extension-ci.yml\n";
}
