<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\PhpstanConfigCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class PhpstanConfigCheckTest extends CheckTestCase
{
    public function testFailsWithoutAnyConfig(): void
    {
        $reporter = $this->run_(new PhpstanConfigCheck(), $this->repo([]));
        $this->assertFails($reporter, 'no phpstan.neon.dist');
    }

    public function testPassesAtLevel10(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => "parameters:\n  level: 10\n  paths:\n    - .\n",
        ]);
        $this->assertPasses($this->run_(new PhpstanConfigCheck(), $context));
    }

    public function testLevelMaxCounts(): void
    {
        $context = $this->repo(['phpstan.neon.dist' => "parameters:\n  level: max\n"]);
        $this->assertPasses($this->run_(new PhpstanConfigCheck(), $context));
    }

    public function testFailsBelowLevel10(): void
    {
        $context = $this->repo(['phpstan.neon.dist' => "parameters:\n  level: 8\n"]);
        $this->assertFails($this->run_(new PhpstanConfigCheck(), $context), 'phpstan below level 10 (template: level 10, no baseline)');
    }

    public function testUndistributedConfigWarns(): void
    {
        $context = $this->repo(['phpstan.neon' => "parameters:\n  level: 10\n"]);
        $reporter = $this->run_(new PhpstanConfigCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'phpstan.neon should be phpstan.neon.dist (local overrides stay untracked)');
    }

    public function testBlanketNotFoundIgnoresWarn(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => "parameters:\n  level: 10\n  ignoreErrors:\n    -\n      identifier: class.notFound\n",
        ]);
        $reporter = $this->run_(new PhpstanConfigCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'phpstan has blanket notFound ignores — use the bootstrap instead');
    }

    public function testBaselineFileFails(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => "parameters:\n  level: 10\n",
            'phpstan-baseline.neon' => "parameters:\n  ignoreErrors: []\n",
        ]);
        $this->assertFails($this->run_(new PhpstanConfigCheck(), $context), 'phpstan baseline file present (template: none)');
    }

    /**
     * A commented-out level line does not claim level 10.
     */
    public function testACommentedLevelIsNotEnough(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => "parameters:\n  # level: 10 once we get there\n  level: 6\n",
        ]);
        $this->assertFails($this->run_(new PhpstanConfigCheck(), $context), 'phpstan below level 10');
    }

    /** 'level: 100' is not level 10, but a substring grep could not tell. */
    public function testALongerNumberIsNotLevel10(): void
    {
        $context = $this->repo(['phpstan.neon.dist' => "parameters:\n  level: 100\n"]);
        $this->assertFails($this->run_(new PhpstanConfigCheck(), $context));
    }

    /** Both files are read together, as `cat phpstan.neon.dist phpstan.neon` did. */
    public function testLevelMayComeFromEitherFile(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => "includes:\n  - phpstan.neon\n",
            'phpstan.neon' => "parameters:\n  level: 10\n",
        ]);
        $this->assertPasses($this->run_(new PhpstanConfigCheck(), $context));
    }

    /** A ../sibling scan path exists on one laptop and dies in CI. */
    public function testAnEscapingScanPathFails(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => "parameters:\n  level: 10\n  scanDirectories:\n    - ../sibling\n",
        ]);
        $this->assertFails($this->run_(new PhpstanConfigCheck(), $context), 'outside the repo');
    }

    public function testAQuotedEscapingScanPathAlsoFails(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => "parameters:\n  level: 10\n  scanFiles:\n    - '../other/stub.php'\n",
        ]);
        $this->assertFails($this->run_(new PhpstanConfigCheck(), $context), '../other/stub.php');
    }

    /** A fixed container path is the honest form and must pass. */
    public function testAnAbsoluteScanPathPasses(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => "parameters:\n  level: 10\n  scanDirectories:\n"
                . "    - /var/www/html/ext/org.civicoop.civirules\n",
        ]);
        $this->assertPasses($this->run_(new PhpstanConfigCheck(), $context));
    }

    /** A `- ../` under excludePaths is harmless — only scan blocks count. */
    public function testAnEscapingExcludePathIsNotAScanPath(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => "parameters:\n  level: 10\n  excludePaths:\n    - ../other\n  paths:\n    - Civi\n",
        ]);
        $this->assertPasses($this->run_(new PhpstanConfigCheck(), $context));
    }
}
