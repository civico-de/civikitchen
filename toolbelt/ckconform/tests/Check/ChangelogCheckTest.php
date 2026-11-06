<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\ChangelogCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class ChangelogCheckTest extends CheckTestCase
{
    private const RELEASED = "# Changelog\n\n## [Unreleased]\n\n## [1.0.0] - 2026-01-01\n\n### Added\n\n- First.\n\n"
        . "[Unreleased]: https://example.org/compare/v1.0.0...HEAD\n[1.0.0]: https://example.org/releases/tag/v1.0.0\n";

    public function testFailsWithoutAChangelog(): void
    {
        $this->assertFails($this->run_(new ChangelogCheck(), $this->repo([], git: true)), 'no CHANGELOG.md');
    }

    public function testANewExtensionWithOnlyUnreleasedPasses(): void
    {
        $context = $this->repo([
            'CHANGELOG.md' => "# Changelog\n\n## [Unreleased]\n\n[Unreleased]: https://example.org/commits/main\n",
        ], git: true);
        $this->assertSilent($this->run_(new ChangelogCheck(), $context));
    }

    public function testReportsGrammarViolationsWithTheirLine(): void
    {
        $context = $this->repo(['CHANGELOG.md' => str_replace('### Added', '### Improved', self::RELEASED)], git: true);
        $this->assertFails($this->run_(new ChangelogCheck(), $context), 'CHANGELOG.md:7: unknown subsection: Improved');
    }

    public function testFailsWhenTheReleasedVersionHasNoSection(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '<version>1.1.0</version>'),
            'CHANGELOG.md' => self::RELEASED,
        ], git: true);
        $this->gitCommit('init');
        $this->gitTag('v1.1.0');
        $this->assertFails($this->run_(new ChangelogCheck(), $context), 'released as v1.1.0, but CHANGELOG.md has no section');
    }

    public function testAnUntaggedVersionMayWaitForItsSection(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '<version>1.1.0</version>'),
            'CHANGELOG.md' => self::RELEASED,
        ], git: true);
        $this->gitCommit('init');
        $this->gitTag('v1.0.0');
        $this->assertSilent($this->run_(new ChangelogCheck(), $context));
    }

    public function testSeveralExtensionsShareTheRootChangelog(): void
    {
        $context = $this->monorepoExtension(['CHANGELOG.md' => self::RELEASED], []);
        $this->assertSilent($this->run_(new ChangelogCheck(), $context));
    }
}
