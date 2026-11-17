<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\LockfileCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class LockfileCheckTest extends CheckTestCase
{
    public function testFailsWhenPackageJsonHasNoTrackedLockfile(): void
    {
        $context = $this->repo(['package.json' => '{"dependencies": {"left-pad": "^1.3.0"}}'], git: true);
        $this->assertFails(
            $this->run_(new LockfileCheck(), $context),
            'package.json has no tracked lockfile (builds are unreproducible)',
        );
    }

    public function testPassesWhenALockfileIsTrackedNextToTheManifest(): void
    {
        $context = $this->repo([
            'package.json' => '{}',
            'package-lock.json' => '{}',
        ], git: true);
        $this->assertPasses($this->run_(new LockfileCheck(), $context));
    }

    public function testChecksTheLockfileNextToANestedManifest(): void
    {
        $context = $this->repo(['frontend/package.json' => '{"dependencies": {"left-pad": "^1.3.0"}}'], git: true);
        $this->assertFails(
            $this->run_(new LockfileCheck(), $context),
            'frontend/package.json has no tracked lockfile (builds are unreproducible)',
        );
    }

    public function testANestedManifestWithItsOwnYarnLockPasses(): void
    {
        $context = $this->repo([
            'frontend/package.json' => '{}',
            'frontend/yarn.lock' => '',
        ], git: true);
        $this->assertPasses($this->run_(new LockfileCheck(), $context));
    }

    /** A lockfile belonging to a sibling package does not count. */
    public function testALockfileInAnotherDirectoryDoesNotCount(): void
    {
        $context = $this->repo([
            'frontend/package.json' => '{"dependencies": {"left-pad": "^1.3.0"}}',
            'backend/yarn.lock' => '',
        ], git: true);
        $this->assertFails($this->run_(new LockfileCheck(), $context), 'frontend/package.json');
    }

    public function testAPackageJsonInsideNodeModulesIsIgnored(): void
    {
        $context = $this->repo(['node_modules/some-dep/package.json' => '{}'], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    public function testComposerJsonWithNoRequireDoesNotNeedALockfile(): void
    {
        $context = $this->repo(['composer.json' => '{"name": "acme/ext"}'], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    public function testComposerJsonRequiringOnlyPhpDoesNotNeedALockfile(): void
    {
        $context = $this->repo([
            'composer.json' => '{"require": {"php": ">=8.1"}}',
        ], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    public function testComposerJsonWithARealDependencyNeedsATrackedLock(): void
    {
        $context = $this->repo([
            'composer.json' => '{"require": {"php": ">=8.1", "guzzlehttp/guzzle": "^7.0"}}',
        ], git: true);
        $this->assertFails(
            $this->run_(new LockfileCheck(), $context),
            'composer.json declares dependencies but composer.lock is not tracked',
        );
    }

    public function testComposerJsonWithARealDependencyAndATrackedLockPasses(): void
    {
        $context = $this->repo([
            'composer.json' => '{"require": {"php": ">=8.1", "guzzlehttp/guzzle": "^7.0"}}',
            'composer.lock' => '{}',
        ], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    public function testGitignoreExcludingALockfileFails(): void
    {
        $context = $this->repo(['.gitignore' => "yarn.lock\n", 'package.json' => '{"dependencies": {"left-pad": "^1.3.0"}}'], git: true);
        $this->assertFails(
            $this->run_(new LockfileCheck(), $context),
            '.gitignore excludes yarn.lock — lockfiles belong in the repo',
        );
    }

    public function testGitignoreExcludingALockfileNextToAManifestFails(): void
    {
        $context = $this->repo([
            '.gitignore' => "/build/composer.lock\n",
            'build/composer.json' => '{"name": "acme/build", "require": {"guzzlehttp/guzzle": "^7.9"}}',
        ], git: true);
        $this->assertFails(
            $this->run_(new LockfileCheck(), $context),
            '.gitignore excludes build/composer.lock — lockfiles belong in the repo',
        );
    }

    /** Where no manifest lives, no lockfile is required — the pattern is inert. */
    public function testAnIgnorePatternAwayFromAnyManifestIsSilent(): void
    {
        $context = $this->repo(['.gitignore' => "/build/composer.lock\n"], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    /** A wildcard pattern must count like a literal name. */
    public function testAWildcardPatternCoveringLockfilesFails(): void
    {
        $context = $this->repo(['.gitignore' => "*.lock\n", 'package.json' => '{"dependencies": {"left-pad": "^1.3.0"}}'], git: true);
        $this->assertFails(
            $this->run_(new LockfileCheck(), $context),
            '.gitignore excludes yarn.lock — lockfiles belong in the repo',
        );
    }

    /**
     * `!build/composer.lock` ends in "/composer.lock" but is the opposite of
     * an ignore rule.
     */
    public function testANegationLineIsNotAnIgnoreRule(): void
    {
        $context = $this->repo(['.gitignore' => "!build/composer.lock\n"], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    public function testANegationReinstatingTheLockfilePasses(): void
    {
        $context = $this->repo(['.gitignore' => "composer.lock\n!composer.lock\n"], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    /** bun.lockb counts as a JS lockfile, so ignoring it is just as fatal. */
    public function testGitignoreExcludingBunLockbFails(): void
    {
        $context = $this->repo(['.gitignore' => "bun.lockb\n", 'package.json' => '{"dependencies": {"left-pad": "^1.3.0"}}'], git: true);
        $this->assertFails(
            $this->run_(new LockfileCheck(), $context),
            '.gitignore excludes bun.lockb — lockfiles belong in the repo',
        );
    }

    /**
     * A commented-out entry must not trigger the rule, even though it ends in
     * "/pnpm-lock.yaml".
     */
    public function testACommentedGitignoreLineIsNotAMatch(): void
    {
        $context = $this->repo(['.gitignore' => "# keep an eye on /pnpm-lock.yaml\n"], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    public function testSilentOutsideAGitRepo(): void
    {
        $context = $this->repo(['package.json' => '{}']);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    /** 'sub-package.json' is not an npm manifest and needs no lockfile. */
    public function testASuffixedJsonFileIsNotAManifest(): void
    {
        $context = $this->repo([
            'tests/fixtures/sub-package.json' => '{}',
        ], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    /** npm install writes only the workspace root's lockfile. */
    public function testWorkspacePackagesShareTheRootLockfile(): void
    {
        $context = $this->repo([
            'package.json' => '{"workspaces": ["packages/*"], "devDependencies": {"typescript": "^5.6.0"}}',
            'package-lock.json' => '{}',
            'packages/ui/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'packages/admin/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
        ], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    public function testAManifestOutsideTheWorkspaceGlobsNeedsItsOwnLockfile(): void
    {
        $context = $this->repo([
            'package.json' => '{"workspaces": {"packages": ["packages/*"]}}',
            'package-lock.json' => '{}',
            'packages/ui/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'tools/package.json' => '{"devDependencies": {"eslint": "^9.0.0"}}',
        ], git: true);
        self::assertSame(
            ['tools/package.json has no tracked lockfile (builds are unreproducible)'],
            $this->run_(new LockfileCheck(), $context)->messages('FAIL'),
        );
    }

    /** `*` stays within one directory level; `**` crosses them. */
    public function testAWorkspaceStarMatchesOneLevelOnly(): void
    {
        $context = $this->repo([
            'package.json' => '{"workspaces": ["packages/*", "apps/**"]}',
            'package-lock.json' => '{}',
            'packages/ui/nested/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'apps/web/site/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
        ], git: true);
        self::assertSame(
            ['packages/ui/nested/package.json has no tracked lockfile (builds are unreproducible)'],
            $this->run_(new LockfileCheck(), $context)->messages('FAIL'),
        );
    }

    /** A character class matches one listed character, and a `**` segment also matches no directory at all. */
    public function testAWorkspaceGlobSupportsClassesAndAnEmptyDoubleStar(): void
    {
        $context = $this->repo([
            'package.json' => '{"workspaces": ["packages/[ab]*", "apps/**/web", "libs/[!x]*"]}',
            'package-lock.json' => '{}',
            'packages/a1/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'packages/c1/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'apps/web/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'apps/site/web/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'libs/x1/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'libs/y1/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
        ], git: true);
        self::assertSame(
            [
                'libs/x1/package.json has no tracked lockfile (builds are unreproducible)',
                'packages/c1/package.json has no tracked lockfile (builds are unreproducible)',
            ],
            $this->run_(new LockfileCheck(), $context)->messages('FAIL'),
        );
    }

    /** Brace lists, a leading `]` in a class, and `**` inside a segment, which only acts as `*`. */
    public function testAWorkspaceGlobReadsBracesAndClassEdges(): void
    {
        $context = $this->repo([
            'package.json' => '{"workspaces": ["a/{x,y}", "b/[]z]*", "c/[[:alpha:]]", "c/[^]", "d/q**/e"]}',
            'package-lock.json' => '{}',
            'a/x/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'a/y/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'a/w/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'b/]1/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'b/z1/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'b/q1/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'c/1/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'd/qe/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'd/qx/e/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
        ], git: true);
        self::assertSame(
            [
                'a/w/package.json has no tracked lockfile (builds are unreproducible)',
                'b/q1/package.json has no tracked lockfile (builds are unreproducible)',
                'c/1/package.json has no tracked lockfile (builds are unreproducible)',
                'd/qe/package.json has no tracked lockfile (builds are unreproducible)',
            ],
            $this->run_(new LockfileCheck(), $context)->messages('FAIL'),
        );
    }

    /** As npm: nested and empty brace alternatives, `**` matching its own root, a leading `/`, escapes and ranges in classes. */
    public function testAWorkspaceGlobFollowsNpm(): void
    {
        $workspaces = ['e/{f,{g,h}}', 'apps/**', 'x/{,y}', '/lead/*', 'k/[!-a]', 'm/[z-a]', 'n/[a\\-c]', 'p/**', '!p/b/**', 'p/b/a', '!!q/*', '!z/a', 'z/*', 'u/?', 'v/[ä]', 'w/*', '!w/\\b', '!w/\\\\c', '!w/\\?'];
        $context = $this->repo([
            'package.json' => json_encode(['workspaces' => $workspaces]),
            'package-lock.json' => '{}',
            'e/f/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'e/g/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'e/h/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'e/i/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'apps/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'x/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'x/y/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'lead/a/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'k/b/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'k/-/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'k/a/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'm/q/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'n/-/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'n/b/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'n/[a/-c]/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'p/b/c/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'q/a/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'z/a/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'z/b/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'u/ä/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'v/ä/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'w/b/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'w/c/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'w/\\c/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
            'w/?/package.json' => '{"dependencies": {"react": "^18.3.0"}}',
        ], git: true);
        self::assertSame(
            [
                'e/i/package.json has no tracked lockfile (builds are unreproducible)',
                'k/-/package.json has no tracked lockfile (builds are unreproducible)',
                'k/a/package.json has no tracked lockfile (builds are unreproducible)',
                'm/q/package.json has no tracked lockfile (builds are unreproducible)',
                'n/-/package.json has no tracked lockfile (builds are unreproducible)',
                'n/b/package.json has no tracked lockfile (builds are unreproducible)',
                'w/?/package.json has no tracked lockfile (builds are unreproducible)',
                'w/\\c/package.json has no tracked lockfile (builds are unreproducible)',
                'w/b/package.json has no tracked lockfile (builds are unreproducible)',
                'z/a/package.json has no tracked lockfile (builds are unreproducible)',
            ],
            $this->run_(new LockfileCheck(), $context)->messages('FAIL'),
        );
    }

    public function testAManifestWithoutDependenciesNeedsNoLockfile(): void
    {
        $context = $this->repo(['ang/package.json' => '{"type": "module"}'], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    public function testPlatformPackagesAreNoDependencies(): void
    {
        $context = $this->repo([
            'composer.json' => '{"require": {"php": ">=8.1", "ext-intl": "*", "ext-json": "*", "lib-icu": ">=60", "composer-runtime-api": "^2.2"}}',
        ], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    public function testARealPackageBesidePlatformPackagesNeedsALockfile(): void
    {
        $context = $this->repo([
            'composer.json' => '{"require": {"php": ">=8.1", "ext-intl": "*", "guzzlehttp/guzzle": "^7.9"}}',
        ], git: true);
        $this->assertFails($this->run_(new LockfileCheck(), $context), 'composer.json declares dependencies');
    }

    public function testIgnoringALockfileNobodyNeedsIsSilent(): void
    {
        $context = $this->repo([
            '.gitignore' => "/composer.lock\n",
            'composer.json' => '{"require": {"php": ">=8.1"}}',
        ], git: true);
        $this->assertSilent($this->run_(new LockfileCheck(), $context));
    }

    public function testIgnoringARequiredComposerLockFails(): void
    {
        $context = $this->repo([
            '.gitignore' => "/composer.lock\n",
            'composer.json' => '{"require": {"guzzlehttp/guzzle": "^7.9"}}',
        ], git: true);
        $this->assertFails($this->run_(new LockfileCheck(), $context), '.gitignore excludes composer.lock');
    }
}
