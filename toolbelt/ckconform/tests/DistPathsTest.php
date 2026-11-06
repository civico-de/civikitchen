<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

use CiviKitchen\Ckconform\DistPaths;
use CiviKitchen\Ckconform\Policy;

final class DistPathsTest extends CheckTestCase
{
    public function testUnifiedConfigNeverShipsByDefault(): void
    {
        $excluded = DistPaths::excluded($this->repo([]));
        self::assertFalse(DistPaths::ships('civikitchen.yaml', $excluded));
    }

    public function testDevelopmentConfigNeverShipsByDefault(): void
    {
        $excluded = DistPaths::excluded($this->repo([]));
        $template = dirname(__DIR__, 3) . '/scaffold/template/extension';
        // composer.json and the changelog are meant for the site that installs the release.
        $scaffolded = array_diff(scandir($template) ?: [], ['.', '..', 'composer.json', 'CHANGELOG.md']);
        // The configs a ck* tool reads from the repo root, beyond what the template scaffolds.
        $read = ['mago.toml', 'phpunit-unit.xml.dist', 'composer-dependency-analyser.php', '.oxlintrc.json', 'vitest.config.ts'];
        foreach ([...$scaffolded, ...$read] as $path) {
            self::assertFalse(DistPaths::ships($path, $excluded), $path);
        }
    }

    public function testSecretConfigurationNeverShipsByDefault(): void
    {
        $excluded = DistPaths::excluded($this->repo([]));
        foreach (['.env', '.env.local', '.netrc', '.npmrc', 'auth.json', 'credentials.json'] as $path) {
            self::assertFalse(DistPaths::ships($path, $excluded), $path);
        }
    }

    public function testUnifiedConfigCannotBeForcedIntoARelease(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "dist_include=civikitchen.yaml -- would disclose development policy\n",
        ]);
        self::assertStringContainsString('protected', implode("\n", DistPaths::problems($context)));
    }

    public function testDistPathsCannotEscapeTheRepository(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "dist_exclude=../outside\n",
        ]);
        self::assertStringContainsString('safe repo-relative path', implode("\n", DistPaths::problems($context)));
    }

    public function testCommaInStructuredPathIsNotAListSeparator(): void
    {
        $context = $this->repo([
            'civikitchen.yaml' => "version: 1\npolicy:\n  dist:\n    exclude:\n      - docs/a,b\n",
        ]);
        $excluded = DistPaths::excluded($context);
        self::assertFalse(DistPaths::ships('docs/a,b', $excluded));
        self::assertTrue(DistPaths::ships('docs/a', $excluded));
        self::assertTrue(DistPaths::ships('b', $excluded));
    }

    public function testReasonDelimiterInExcludePathIsLiteral(): void
    {
        $context = $this->repo([
            'civikitchen.yaml' => "version: 1\npolicy:\n  dist:\n    exclude:\n      - 'docs/foo -- bar'\n",
        ]);
        $excluded = DistPaths::excluded($context);
        self::assertFalse(DistPaths::ships('docs/foo -- bar', $excluded));
        self::assertTrue(DistPaths::ships('docs/foo', $excluded));
    }

    public function testEverySecretPathIsProtectedFromInclude(): void
    {
        foreach (['.netrc', '.npmrc', '.pypirc', 'auth.json', 'credentials.json', '.env.production'] as $path) {
            $context = $this->repo(['__policy_fixture' => "dist_include={$path} -- unsafe fixture\n"]);
            self::assertStringContainsString('protected', implode("\n", DistPaths::problems($context)), $path);
        }
    }

    public function testDeclaredBuildOutputIsStaged(): void
    {
        $context = $this->repo($this->bunBuild(['ang/app.bundle.js', 'dist/lib/']));
        self::assertSame([], DistPaths::problems($context));
        self::assertSame(['ang/app.bundle.js', 'dist/lib'], DistPaths::staged($context));
        self::assertSame(['bun'], Policy::parse($context->read('civikitchen.yaml'))['dist_build_tool']);
    }

    public function testNoBuildDeclaredStagesNothing(): void
    {
        self::assertSame([], DistPaths::staged($this->repo([])));
    }

    public function testBuildOutputTheArchiveLeavesOutIsRefused(): void
    {
        $paths = ['node_modules/lib/dist/lib.min.js', 'tests/fixture.js', '.github/built.js', 'civikitchen.yaml', '.env.production', 'build/app.js'];
        foreach ($paths as $path) {
            $files = $this->bunBuild([$path]);
            $files['civikitchen.yaml'] = str_replace("  dist:\n", "  dist:\n    exclude:\n      - build/\n", $files['civikitchen.yaml']);
            self::assertStringContainsString("leaves out: {$path}", implode("\n", DistPaths::problems($this->repo($files))), $path);
        }
    }

    public function testBuildOutputReincludedByPolicyShips(): void
    {
        $files = $this->bunBuild(['tests/fixture.js']);
        $files['civikitchen.yaml'] = str_replace("  dist:\n", "  dist:\n    include:\n      - path: tests\n        reason: release fixture\n", $files['civikitchen.yaml']);
        self::assertSame([], DistPaths::problems($this->repo($files)));
    }

    public function testBuildOutputMustStayInsideTheRepository(): void
    {
        $problems = DistPaths::problems($this->repo($this->bunBuild(['../outside.js'])));
        self::assertStringContainsString('dist.build.outputs must be a safe repo-relative path: ../outside.js', implode("\n", $problems));
    }

    public function testBunBuildNeedsAnExactPackageManagerPinAndALockfile(): void
    {
        $files = $this->bunBuild(['ang/app.bundle.js']);
        $files['package.json'] = '{"packageManager": "bun@^1.4"}';
        unset($files['bun.lock']);
        $problems = implode("\n", DistPaths::problems($this->repo($files)));
        self::assertStringContainsString('"packageManager": "bun@x.y.z"', $problems);
        self::assertStringContainsString('committed bun.lock', $problems);
    }

    public function testBuildDeclarationNeedsAKnownToolAndDistinctOutputs(): void
    {
        $builds = [
            "tool: npm\n      outputs:\n        - a.js\n",
            "tool: bun\n",
            "tool: bun\n      outputs:\n        - a.js\n        - a.js\n",
        ];
        foreach ($builds as $build) {
            try {
                Policy::parse("version: 1\npolicy:\n  dist:\n    build:\n      {$build}");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('dist', $e->getMessage(), $build);
                continue;
            }
            self::fail("the schema accepted: {$build}");
        }
    }

    /**
     * @param list<string> $outputs
     * @return array<string, string>
     */
    private function bunBuild(array $outputs): array
    {
        $yaml = "version: 1\npolicy:\n  dist:\n    build:\n      tool: bun\n      outputs:\n";
        foreach ($outputs as $output) {
            $yaml .= "        - '{$output}'\n";
        }

        return ['civikitchen.yaml' => $yaml, 'package.json' => '{"packageManager": "bun@1.4.0"}', 'bun.lock' => '{}'];
    }
}
