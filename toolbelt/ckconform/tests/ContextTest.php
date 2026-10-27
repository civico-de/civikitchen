<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

use CiviKitchen\Ckconform\Context;

final class ContextTest extends CheckTestCase
{
    /**
     * An empty <ext> element can never match a real key in an in_array() test;
     * the shared parser trims and drops it.
     */
    public function testRequiredExtensionsTrimsAndDropsEmptyElements(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: <<<'XML'
                  <requires>
                    <ext>  org.civicrm.search_kit  </ext>
                    <ext version="3.32">org.civicoop.civirules</ext>
                    <ext></ext>
                    <ext>   </ext>
                  </requires>
                XML),
        ]);
        self::assertSame(
            ['org.civicrm.search_kit', 'org.civicoop.civirules'],
            $context->requiredExtensions(),
        );
    }

    /** git trusts safe.directory only for the repository top level, not a monorepo extension below it. */
    public function testIsIgnoredInAMonorepoExtensionUnderAForeignOwner(): void
    {
        $context = $this->monorepoExtension(['.gitignore' => "*.log\n"], []);
        putenv('GIT_TEST_ASSUME_DIFFERENT_OWNER=1');
        try {
            self::assertTrue($context->isIgnored('debug.log'));
            self::assertFalse($context->isIgnored('info.xml'));
        } finally {
            putenv('GIT_TEST_ASSUME_DIFFERENT_OWNER');
        }
    }

    public function testRequiredExtensionsIsEmptyWithoutInfoXmlOrRequires(): void
    {
        self::assertSame([], $this->repo([])->requiredExtensions());
        self::assertSame([], $this->repo(['info.xml' => 'not xml'])->requiredExtensions());
    }

    public function testIsUnderMatchesWholeDirectoryNamesOnly(): void
    {
        self::assertTrue(Context::isUnder('dist/app.js', ['dist']));
        self::assertTrue(Context::isUnder('ang/widget/dist/app.js', ['build', 'dist']));
        self::assertFalse(Context::isUnder('distribution/app.js', ['dist']));
        self::assertFalse(Context::isUnder('ang/rebuild/app.js', ['build']));
        self::assertFalse(Context::isUnder('ang/dist', ['dist']));
    }

    public function testCompatibilityFloorIsTheLowestNumericVer(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(compatibility: '6.19', extra: '<compatibility><ver>6.x</ver><ver>6.15</ver></compatibility>'),
        ]);
        self::assertSame('6.15', $context->compatibilityFloor());
    }

    public function testExtensionDirectoryIsRelativeToTheRepositoryRoot(): void
    {
        self::assertSame('example', $this->monorepoExtension([], [])->extensionDirectory());
        self::assertSame('.', $this->repo([], git: true)->extensionDirectory());
    }

    public function testRepositoryExtensionsAreTheDirectSubdirectoriesCarryingAnInfoXml(): void
    {
        $context = $this->monorepoExtension([], [], ['Civi/Neighbour.php' => '<?php']);
        self::assertSame(['base', 'example'], array_keys($context->repositoryExtensions()));
        self::assertTrue($context->isMonorepo());
    }

    public function testASingleExtensionRepoIsNoMonorepo(): void
    {
        $context = $this->repo(['Civi/Thing.php' => '<?php'], git: true);
        self::assertFalse($context->isMonorepo());
        self::assertSame([], $context->repositoryExtensions());
    }

    public function testCommitsSinceReportsPathsRelativeToTheExtension(): void
    {
        $context = $this->monorepoExtension([], ['Civi/Thing.php' => '<?php'], ['Civi/Neighbour.php' => '<?php']);
        $this->gitCommit('initial', '2020-01-01T00:00:00Z');
        $this->gitTag('v1.0.0');
        $this->write('example/Civi/Later.php', '<?php');
        $this->write('base/Civi/Later.php', '<?php');
        $this->gitCommit('both', '2020-01-02T00:00:00Z');
        $commits = $context->commitsSince('v1.0.0');
        self::assertNotNull($commits);
        self::assertSame([['Civi/Later.php']], array_map(static fn (array $c): array => $c['files'], $commits));
    }
    /**
     * The shape ckinit generates: managed markers are comments, the header's END
     * marker sits at column 0 right after `jobs:`, a repo-owned input follows a
     * job's END marker, and a dotted directory has a sanitised job id.
     */
    private const GENERATED_ROOT = <<<'YAML'
        name: CI
        on: [push]
        jobs:
        # END CIVIKITCHEN MANAGED header
          # BEGIN CIVIKITCHEN MANAGED job-ext-a
          ext-a:
            uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1
            with:
              working_directory: ext-a
          # END CIVIKITCHEN MANAGED job-ext-a
              playwright: true
          # BEGIN CIVIKITCHEN MANAGED job-ext_b
          ext_b:
            uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1
            with:
              working_directory: ext.b
          # END CIVIKITCHEN MANAGED job-ext_b
        YAML;

    /**
     * Two jobs may run one extension (a test job and a lint job), and both
     * judge it: a rule the first one satisfies is satisfied.
     */
    public function testSelectsEveryJobThatNamesThisExtensionsDirectory(): void
    {
        $context = $this->monorepoExtension(
            [
                '.github/workflows/ci.yml' => "name: ci\non: push\njobs:\n"
                    . "  tests:\n    uses: ./.github/workflows/extension-ci.yml\n"
                    . "    with:\n      working_directory: example\n"
                    . "  lint:\n    runs-on: ubuntu-latest\n    defaults:\n      run:\n"
                    . "        working-directory: example\n    steps:\n      - run: cklint\n",
            ],
            [],
            ['Civi/Neighbour.php' => '<?php'],
        );
        self::assertSame(
            ['../.github/workflows/ci.yml:tests', '../.github/workflows/ci.yml:lint'],
            array_keys($context->scopedWorkflows()),
        );
        self::assertNull($context->workflowScopeFailure());
    }

    public function testSelectsTheJobOfAGeneratedRootWorkflow(): void
    {
        $context = $this->generated('ext-a');
        self::assertSame(
            ['../.github/workflows/ci.yml:ext-a'],
            array_keys($context->scopedWorkflows()),
        );
        self::assertStringContainsString(
            'playwright: true',
            $context->scopedWorkflows()['../.github/workflows/ci.yml:ext-a'],
        );
        self::assertNull($context->workflowScopeFailure());
    }

    public function testSelectsTheJobWhoseSanitisedIdDiffersFromItsDirectory(): void
    {
        self::assertSame(
            ['../.github/workflows/ci.yml:ext_b'],
            array_keys($this->generated('ext.b')->scopedWorkflows()),
        );
    }

    public function testNamesTheWorkflowThatDoesNotParse(): void
    {
        $context = $this->monorepoExtension(
            ['.github/workflows/ci.yml' => "jobs:\n  a:\n   uses: x\n    with: y\n"],
            [],
            ['Civi/Neighbour.php' => '<?php'],
        );
        self::assertStringContainsString('does not parse as YAML', (string) $context->workflowScopeFailure());
    }

    public function testNamesAJobItCannotReadAsWritten(): void
    {
        $context = $this->monorepoExtension(
            ['.github/workflows/ci.yml' => "jobs:\n  example: {uses: ci.yml, with: {working_directory: example}}\n"],
            [],
            ['Civi/Neighbour.php' => '<?php'],
        );
        self::assertStringContainsString(
            'is not written as a block mapping',
            (string) $context->workflowScopeFailure(),
        );
    }

    public function testAnExtensionTwoLevelsDownIsNoSeveralExtensionsLayout(): void
    {
        $context = $this->nestedExtension([
            '.github/workflows/ci.yml' => "name: CI\njobs:\n  lint:\n    steps:\n      - run: cklint\n",
        ]);
        self::assertFalse($context->isMonorepo());
        self::assertSame([], $context->repositoryExtensions());
        self::assertNull($context->workflowScopeFailure());
        self::assertSame(['../../.github/workflows/ci.yml'], array_keys($context->scopedWorkflows()));
    }

    private function generated(string $directory): Context
    {
        return $this->monorepoExtension(
            ['.github/workflows/ci.yml' => self::GENERATED_ROOT . "\n"],
            ['Civi/Thing.php' => '<?php'],
            ['Civi/Neighbour.php' => '<?php'],
            directory: $directory,
            neighbourDirectory: $directory === 'ext-a' ? 'ext.b' : 'ext-a',
        );
    }
}
