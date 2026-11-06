<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\HandbookCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class HandbookCheckTest extends CheckTestCase
{
    private const DOCS_CALLER = "name: Docs\njobs:\n  docs:\n    uses: civico-de/civikitchen/.github/workflows/extension-docs.yml@v1\n";

    public function testAProprietaryExtensionNeedsNoHandbook(): void
    {
        $this->assertSilent($this->run_(new HandbookCheck(), $this->repo([])));
    }

    public function testAnOpenSourceExtensionWithoutAHandbookFails(): void
    {
        $context = $this->repo(['info.xml' => $this->infoXml(license: 'AGPL-3.0-or-later')]);
        $this->assertFails($this->run_(new HandbookCheck(), $context), 'no handbook/index.md');
    }

    public function testAnUnpublishedHandbookFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(license: 'AGPL-3.0-or-later'),
            'handbook/index.md' => "# Fixture\n",
        ], git: true);
        $this->assertFails($this->run_(new HandbookCheck(), $context), 'no workflow calls extension-docs.yml');
    }

    public function testAPublishedHandbookPasses(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(license: 'AGPL-3.0-or-later'),
            'handbook/index.md' => "# Fixture\n",
            '.github/workflows/docs.yml' => self::DOCS_CALLER,
        ], git: true);
        $this->assertSilent($this->run_(new HandbookCheck(), $context));
    }

    public function testARepositoryThatNeverReleasesNeedsNoHandbook(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(license: 'AGPL-3.0-or-later'),
            '__policy_fixture' => "release=none -- fixture, never installed\n",
        ]);
        $this->assertSilent($this->run_(new HandbookCheck(), $context));
    }

    /** One Pages site per repository cannot host several handbooks. */
    public function testAMonorepoExtensionNeedsOnlyTheHandbook(): void
    {
        $context = $this->monorepoExtension(
            [],
            ['info.xml' => $this->infoXml(license: 'AGPL-3.0-or-later'), 'handbook/index.md' => "# Fixture\n"],
            ['info.xml' => $this->infoXml(key: 'base')],
        );
        $this->assertSilent($this->run_(new HandbookCheck(), $context));
    }

    public function testGitlabRepositoriesNeedOnlyTheHandbook(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(license: 'MIT'),
            'handbook/index.md' => "# Fixture\n",
            '__policy_fixture' => "ci=gitlab\n",
        ], git: true);
        $this->assertSilent($this->run_(new HandbookCheck(), $context));
    }
}
