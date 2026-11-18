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
        $this->assertFails($this->run_(new HandbookCheck(), $context), 'no docs/handbook/index.md');
    }

    public function testAManualLeftInTheOldDirectoryNamesTheMove(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(license: 'AGPL-3.0-or-later'),
            'handbook/index.md' => "# Fixture\n",
        ]);
        $this->assertFails($this->run_(new HandbookCheck(), $context), 'git mv handbook docs/handbook');
    }

    public function testTheTaggedDirectoryIsTheManual(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(
                license: 'AGPL-3.0-or-later',
                extra: '<tags><tag>handbook:dir=manual</tag></tags>'
                    . '<urls><url desc="Documentation">https://example.github.io/fixture/</url></urls>',
            ),
            'manual/index.md' => "# Fixture\n",
            '.github/workflows/docs.yml' => self::DOCS_CALLER,
        ], git: true);
        $this->assertSilent($this->run_(new HandbookCheck(), $context));
    }

    /** @return iterable<string, array{string}> */
    public static function badTags(): iterable
    {
        yield 'two tags' => ['<tag>handbook:dir=manual</tag><tag>handbook:dir=docs</tag>'];
        yield 'outside' => ['<tag>handbook:dir=../manual</tag>'];
        yield 'root' => ['<tag>handbook:dir=/</tag>'];
        yield 'dot' => ['<tag>handbook:dir=.</tag>'];
    }

    /** @dataProvider badTags */
    public function testABadTagFails(string $tags): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(license: 'AGPL-3.0-or-later', extra: "<tags>{$tags}</tags>"),
            'manual/index.md' => "# Fixture\n",
            'index.md' => "# Fixture\n",
        ]);
        $this->assertFails($this->run_(new HandbookCheck(), $context), 'exactly one handbook directory');
    }

    public function testAnUnpublishedHandbookFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(license: 'AGPL-3.0-or-later'),
            'docs/handbook/index.md' => "# Fixture\n",
        ], git: true);
        $this->assertFails($this->run_(new HandbookCheck(), $context), 'no workflow calls extension-docs.yml');
    }

    public function testAPublishedAndLinkedHandbookPasses(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(
                license: 'AGPL-3.0-or-later',
                extra: '<urls><url desc="Documentation">https://example.github.io/fixture/</url></urls>',
            ),
            'docs/handbook/index.md' => "# Fixture\n",
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
            ['info.xml' => $this->infoXml(license: 'AGPL-3.0-or-later'), 'docs/handbook/index.md' => "# Fixture\n"],
            ['info.xml' => $this->infoXml(key: 'base')],
        );
        $this->assertSilent($this->run_(new HandbookCheck(), $context));
    }

    public function testGitlabRepositoriesNeedOnlyTheHandbook(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(license: 'MIT'),
            'docs/handbook/index.md' => "# Fixture\n",
            '__policy_fixture' => "ci=gitlab\n",
        ], git: true);
        $this->assertSilent($this->run_(new HandbookCheck(), $context));
    }

    public function testAPublishedHandbookWithoutADocumentationLinkFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(
                license: 'AGPL-3.0-or-later',
                extra: '<urls><url desc="Main Extension Page">https://github.com/example/fixture</url></urls>',
            ),
            'docs/handbook/index.md' => "# Fixture\n",
            '.github/workflows/docs.yml' => self::DOCS_CALLER,
        ], git: true);
        $this->assertFails($this->run_(new HandbookCheck(), $context), 'does not link the published handbook');
    }

    public function testADocumentationLinkToTheRepositoryFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(
                license: 'AGPL-3.0-or-later',
                extra: '<urls><url desc="Documentation">https://github.com/example/fixture</url></urls>',
            ),
            'docs/handbook/index.md' => "# Fixture\n",
            '.github/workflows/docs.yml' => self::DOCS_CALLER,
        ], git: true);
        $this->assertFails($this->run_(new HandbookCheck(), $context), 'does not link the published handbook');
    }
}
