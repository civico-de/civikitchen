<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\MediaLfsCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class MediaLfsCheckTest extends CheckTestCase
{
    private const POINTER = "version https://git-lfs.github.com/spec/v1\noid sha256:4d7a214614ab2935c943f9e0ff69d22eadbb8f32b1258daaa5e2ca24d17e2393\nsize 12345\n";

    private const PNG = "\x89PNG\r\n\x1a\n not a pointer";

    /** @return iterable<string, array{string}> */
    public static function media(): iterable
    {
        yield 'screenshot' => ['docs/handbook/images/shot.png'];
        yield 'upper-case extension' => ['images/PHOTO.JPG'];
        yield 'video at the root' => ['tour.mp4'];
        yield 'document' => ['docs/flyer.pdf'];
    }

    /** @dataProvider media */
    public function testFailsOnMediaCommittedToGitItself(string $file): void
    {
        $reporter = $this->run_(new MediaLfsCheck(), $this->repo([$file => self::PNG], git: true));
        $this->assertFails($reporter, "{$file} is committed to git itself, not through Git LFS");
        $this->assertFails($reporter, "git add --renormalize {$file}");
    }

    public function testPassesOnMediaWhoseIndexEntryIsAnLfsPointer(): void
    {
        $reporter = $this->run_(new MediaLfsCheck(), $this->repo([
            'docs/handbook/images/shot.png' => self::POINTER,
            'docs/handbook/images/de/shot.png' => self::POINTER,
        ], git: true));
        $this->assertPasses($reporter);
        self::assertContains('all 2 media file(s) are committed through Git LFS', $reporter->messages('ok'));
    }

    public function testJudgesTheIndexNotTheWorkTree(): void
    {
        $context = $this->repo(['docs/handbook/images/shot.png' => self::POINTER], git: true);
        // What a checkout with git-lfs installed leaves on disk for the same commit.
        $this->write('docs/handbook/images/shot.png', self::PNG);
        $this->assertPasses($this->run_(new MediaLfsCheck(), $context));
    }

    public function testLeavesSvgAndOtherTextAlone(): void
    {
        $reporter = $this->run_(new MediaLfsCheck(), $this->repo([
            'images/icon.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
            'docs/png.md' => '# About PNG',
        ], git: true));
        $this->assertPasses($reporter);
        self::assertSame([], $reporter->messages('ok'));
    }

    public function testIgnoresUntrackedMedia(): void
    {
        $context = $this->repo([], git: true);
        $this->write('out/tour.mp4', self::PNG);
        $this->assertPasses($this->run_(new MediaLfsCheck(), $context));
    }

    public function testAcceptsAnExemptFileAndAnExemptDirectory(): void
    {
        $reporter = $this->run_(new MediaLfsCheck(), $this->repo([
            'tests/fixtures/pixel.png' => self::PNG,
            'images/upstream/a/shot.png' => self::PNG,
            '__policy_fixture' => 'lfs_exempt=tests/fixtures/pixel.png,images/upstream -- fixtures and upstream files',
        ], git: true));
        $this->assertPasses($reporter);
    }

    public function testAnExemptionCoversOnlyItsPaths(): void
    {
        $reporter = $this->run_(new MediaLfsCheck(), $this->repo([
            'tests/fixtures/pixel.png' => self::PNG,
            'tests/fixtures-more/pixel.png' => self::PNG,
            '__policy_fixture' => 'lfs_exempt=tests/fixtures -- fixtures',
        ], git: true));
        $this->assertFails($reporter, 'tests/fixtures-more/pixel.png is committed to git itself');
        self::assertCount(1, $reporter->messages('FAIL'));
    }

    public function testFailsOnAnExemptFileStillFilteredThroughLfs(): void
    {
        $reporter = $this->run_(new MediaLfsCheck(), $this->repo([
            '.gitattributes' => "*.png filter=lfs diff=lfs merge=lfs -text\n",
            'tests/fixtures/pixel.png' => self::PNG,
            '__policy_fixture' => 'lfs_exempt=tests/fixtures -- fixtures',
        ], git: true));
        $this->assertFails($reporter, 'tests/fixtures/pixel.png is exempt from Git LFS but .gitattributes still filters it');
        $this->assertFails($reporter, '`*.png -filter -diff -merge`');
    }

    public function testAcceptsAnExemptFileTakenOutOfTheLfsFilter(): void
    {
        $reporter = $this->run_(new MediaLfsCheck(), $this->repo([
            '.gitattributes' => "*.png filter=lfs diff=lfs merge=lfs -text\n",
            'tests/fixtures/.gitattributes' => "*.png -filter -diff -merge\n",
            'tests/fixtures/pixel.png' => self::PNG,
            '__policy_fixture' => 'lfs_exempt=tests/fixtures -- fixtures',
        ], git: true));
        $this->assertPasses($reporter);
    }

    public function testSaysNothingOutsideAGitRepository(): void
    {
        $reporter = $this->run_(new MediaLfsCheck(), $this->repo(['docs/handbook/images/shot.png' => self::PNG]));
        $this->assertPasses($reporter);
        self::assertSame([], $reporter->messages('ok'));
    }
}
