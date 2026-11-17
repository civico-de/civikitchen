<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\GitignoreCoverageCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class GitignoreCoverageCheckTest extends CheckTestCase
{
    public function testSilentOutsideAGitRepo(): void
    {
        $context = $this->repo(['phpunit.xml.dist' => '<phpunit/>', '.gitignore' => '']);
        $this->assertSilent($this->run_(new GitignoreCoverageCheck(), $context));
    }

    /** A missing .gitignore is GitignoreCheck's finding, not a duplicate here. */
    public function testSilentWithoutAGitignore(): void
    {
        $context = $this->repo(['phpunit.xml.dist' => '<phpunit/>'], git: true);
        $this->assertSilent($this->run_(new GitignoreCoverageCheck(), $context));
    }

    public function testDemandsThePhpunitCacheWhenThereIsAPhpunitConfig(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            '.gitignore' => "/build\n",
        ], git: true);
        $this->assertFails($this->run_(new GitignoreCoverageCheck(), $context), '.phpunit.result.cache');
    }

    public function testPassesOnceTheCacheIsIgnored(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            '.gitignore' => ".phpunit.result.cache\n",
        ], git: true);
        $this->assertPasses($this->run_(new GitignoreCoverageCheck(), $context));
    }

    /**
     * A PHP-only extension must never be nagged about node_modules.
     */
    public function testNodeModulesIsNotDemandedWithoutAPackageJson(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            '.gitignore' => ".phpunit.result.cache\n",
        ], git: true);
        $this->assertPasses($this->run_(new GitignoreCoverageCheck(), $context));
    }

    public function testNodeModulesIsDemandedOnceThereIsAPackageJson(): void
    {
        $context = $this->repo([
            'package.json' => '{"name":"x"}',
            '.gitignore' => "/build\n",
        ], git: true);
        $this->assertFails($this->run_(new GitignoreCoverageCheck(), $context), 'node_modules/');
    }

    public function testTsbuildinfoIsDemandedOnlyForATypescriptRepo(): void
    {
        $withTs = $this->repo([
            'tsconfig.json' => '{}',
            'package.json' => '{"name":"x"}',
            '.gitignore' => "node_modules\n",
        ], git: true);
        $this->assertFails($this->run_(new GitignoreCoverageCheck(), $withTs), '*.tsbuildinfo');
    }

    public function testVendorIsDemandedWhenComposerJsonExists(): void
    {
        $context = $this->repo([
            'composer.json' => '{"name":"x/y"}',
            '.gitignore' => "/build\n",
        ], git: true);
        $this->assertFails($this->run_(new GitignoreCoverageCheck(), $context), 'vendor/');
    }

    /** A commented-out entry ignores nothing. */
    public function testACommentedEntryDoesNotCount(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            '.gitignore' => "# .phpunit.result.cache\n",
        ], git: true);
        $this->assertFails($this->run_(new GitignoreCoverageCheck(), $context));
    }

    public function testEveryDemandedArtifactIsListedTogether(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            'composer.json' => '{"name":"x/y"}',
            '.gitignore' => "/build\n",
        ], git: true);
        $reporter = $this->run_(new GitignoreCoverageCheck(), $context);
        $message = implode("\n", $reporter->messages('FAIL'));
        self::assertStringContainsString('.phpunit.result.cache', $message);
        self::assertStringContainsString('vendor/', $message);
    }

    /**
     * TypeScript names the cache after its tsconfig, so 'frontend/.tsbuildinfo'
     * matches nothing and must not be accepted.
     */
    public function testTheBrokenTsbuildinfoPatternIsNotAccepted(): void
    {
        $context = $this->repo([
            'tsconfig.json' => '{}',
            'package.json' => '{"name":"x"}',
            '.gitignore' => "node_modules\nfrontend/.tsbuildinfo\n",
        ], git: true);
        $this->assertFails($this->run_(new GitignoreCoverageCheck(), $context), '*.tsbuildinfo');
    }

    /** A negation un-ignores; it must not read as coverage. */
    public function testANegationIsNotCoverage(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<phpunit/>',
            '.gitignore' => "!.phpunit.result.cache\n",
        ], git: true);
        $this->assertFails($this->run_(new GitignoreCoverageCheck(), $context), '.phpunit.result.cache');
    }

    /** A nested cache has to be covered too, not just one at the root. */
    public function testANestedTsbuildinfoCounts(): void
    {
        $context = $this->repo([
            'tsconfig.json' => '{}',
            'package.json' => '{"name":"x"}',
            '.gitignore' => "node_modules\n*.tsbuildinfo\n",
        ], git: true);
        $this->assertPasses($this->run_(new GitignoreCoverageCheck(), $context));
    }

    public function testVendorPolicySkipsVendorIgnoreDemand(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "vendor=committed -- extensions deploy without composer install\n",
            '.gitignore' => "node_modules/\n",
            'composer.json' => '{}',
            'vendor/autoload.php' => "<?php\n",
        ], git: true);
        $reporter = $this->run_(new GitignoreCoverageCheck(), $context);
        self::assertSame(0, $reporter->failures());
    }

    public function testAMonorepoRootGitignoreIsJudged(): void
    {
        $context = $this->monorepoExtension(
            ['.gitignore' => "/*/vendor/\nnode_modules/\n.phpunit.result.cache\n"],
            ['composer.json' => '{}', 'phpunit.xml.dist' => '<phpunit/>', 'package.json' => '{}'],
        );
        $this->assertOk($this->run_(new GitignoreCoverageCheck(), $context), '.gitignore covers the artifacts');
    }

    public function testAMonorepoRootGitignoreMissingAnArtifactFails(): void
    {
        $context = $this->monorepoExtension(
            ['.gitignore' => "/*/vendor/\n"],
            ['composer.json' => '{}', 'phpunit.xml.dist' => '<phpunit/>'],
        );
        $this->assertFails($this->run_(new GitignoreCoverageCheck(), $context), 'produces: .phpunit.result.cache');
    }

    public function testAConfigThatDisablesTheResultCacheProducesNone(): void
    {
        $context = $this->repo([
            '.gitignore' => "/vendor/\n",
            'phpunit.xml.dist' => '<phpunit cacheResult="false"/>',
        ], git: true);
        $this->assertOk($this->run_(new GitignoreCoverageCheck(), $context), '.gitignore covers the artifacts');
    }

    public function testAMovedResultCacheIsDemandedWhereItIsWritten(): void
    {
        $context = $this->repo([
            '.gitignore' => ".phpunit.result.cache\n",
            'phpunit.xml.dist' => '<phpunit cacheResultFile=".cache/phpunit.result"/>',
        ], git: true);
        $this->assertFails($this->run_(new GitignoreCoverageCheck(), $context), 'produces: .cache/phpunit.result');
    }
}
