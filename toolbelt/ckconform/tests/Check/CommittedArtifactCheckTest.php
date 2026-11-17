<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\CommittedArtifactCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class CommittedArtifactCheckTest extends CheckTestCase
{
    public function testFailsWhenNodeModulesIsCommittedAtTheTopLevel(): void
    {
        $context = $this->repo(['node_modules/some-dep/index.js' => ''], git: true);
        $this->assertFails(
            $this->run_(new CommittedArtifactCheck(), $context),
            'build/cache artifact committed: node_modules',
        );
    }

    public function testFailsWhenVendorIsCommittedNested(): void
    {
        $context = $this->repo(['ext/composer.json' => '{}', 'ext/vendor/autoload.php' => ''], git: true);
        $this->assertFails(
            $this->run_(new CommittedArtifactCheck(), $context),
            'build/cache artifact committed: ext/vendor/',
        );
    }

    public function testFailsWhenThePhpunitCacheFileItselfIsCommitted(): void
    {
        $context = $this->repo(['.phpunit.result.cache' => '{}'], git: true);
        $this->assertFails(
            $this->run_(new CommittedArtifactCheck(), $context),
            'build/cache artifact committed: .phpunit.result.cache',
        );
    }

    public function testReportsEachArtifactInOrder(): void
    {
        $context = $this->repo([
            '.phpunit.result.cache' => '{}',
            'node_modules/dep/index.js' => '',
            'composer.json' => '{}',
            'vendor/autoload.php' => '',
        ], git: true);
        $reporter = $this->run_(new CommittedArtifactCheck(), $context);
        self::assertSame([
            'build/cache artifact committed: .phpunit.result.cache',
            'build/cache artifact committed: node_modules/',
            'build/cache artifact committed: vendor/',
        ], $reporter->messages('FAIL'));
    }

    public function testPassesWhenNoneAreCommitted(): void
    {
        $context = $this->repo(['src/Foo.php' => '<?php'], git: true);
        $this->assertSilent($this->run_(new CommittedArtifactCheck(), $context));
    }

    public function testSilentOutsideAGitRepo(): void
    {
        $context = $this->repo(['node_modules/some-dep/index.js' => '']);
        $this->assertSilent($this->run_(new CommittedArtifactCheck(), $context));
    }

    /**
     * TypeScript names its cache after the tsconfig, so a '.tsbuildinfo' ignore
     * pattern misses 'tsconfig.tsbuildinfo'.
     */
    public function testATsbuildinfoCacheIsFlaggedWhateverItIsNamed(): void
    {
        $context = $this->repo(['frontend/tsconfig.tsbuildinfo' => '{}'], git: true);
        $this->assertFails(
            $this->run_(new CommittedArtifactCheck(), $context),
            'build/cache artifact committed: frontend/tsconfig.tsbuildinfo'
        );
    }

    public function testVendorPolicyAllowsCommittedVendor(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "vendor=committed -- extensions deploy without composer install\n",
            'vendor/autoload.php' => "<?php\n",
        ], git: true);
        $reporter = $this->run_(new CommittedArtifactCheck(), $context);
        self::assertSame(0, $reporter->failures());
    }

    /** A vendor/ no composer.json sits beside is a hand-picked bundle, not composer's output. */
    public function testAVendorDirectoryWithoutAComposerManifestIsNoArtifact(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "bundles=committed -- upstream chart bundle\n",
            'js/vendor/chart.umd.min.js' => '',
        ], git: true);
        $this->assertSilent($this->run_(new CommittedArtifactCheck(), $context));
    }

    public function testAVendorBesideANestedComposerManifestIsNamedByPath(): void
    {
        $context = $this->repo([
            'tools/composer.json' => '{}',
            'tools/vendor/autoload.php' => '',
            'js/vendor/chart.js' => '',
        ], git: true);
        self::assertSame(
            ['build/cache artifact committed: tools/vendor/'],
            $this->run_(new CommittedArtifactCheck(), $context)->messages('FAIL'),
        );
    }
}
