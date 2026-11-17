<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\CoverageSectionCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class CoverageSectionCheckTest extends CheckTestCase
{
    public function testFailsWithoutACoverageSection(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit><testsuites/></phpunit>',
        ]);
        $this->assertFails(
            $this->run_(new CoverageSectionCheck(), $context),
            'phpunit config has no <coverage> section — coverage runs measure nothing',
        );
    }

    public function testPassesWithACoverageSection(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit><coverage><include><directory>Civi</directory></include></coverage></phpunit>',
        ]);
        $this->assertPasses($this->run_(new CoverageSectionCheck(), $context));
    }

    public function testPlainPhpunitXmlAlsoCounts(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            'phpunit.xml' => '<?xml version="1.0"?><phpunit><coverage><include><file>CRM/Fixture.php</file></include></coverage></phpunit>',
        ]);
        $this->assertPasses($this->run_(new CoverageSectionCheck(), $context));
    }

    /**
     * A commented-out `<coverage>` section declares no sources.
     */
    public function testACommentedOutSectionIsNotEnough(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit><!-- <coverage><include/></coverage> --><testsuites/></phpunit>',
        ]);
        $this->assertFails($this->run_(new CoverageSectionCheck(), $context));
    }

    /** An untracked config ships to nobody, so its <coverage> section proves nothing. */
    public function testAnUntrackedConfigDoesNotCount(): void
    {
        $context = $this->repo(['tests/phpunit/SomeTest.php' => '<?php'], git: true);
        file_put_contents(
            $context->root . '/phpunit.xml.dist',
            '<?xml version="1.0"?><phpunit><coverage/></phpunit>'
        );
        $this->assertFails($this->run_(new CoverageSectionCheck(), $context));
    }

    public function testSilentWithoutATestDirectory(): void
    {
        $this->assertSilent($this->run_(new CoverageSectionCheck(), $this->repo([])));
    }

    public function testACoverageElementWithoutSourcesFails(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit><coverage cacheDirectory=".phpunit.cache"/></phpunit>',
        ]);
        $this->assertFails($this->run_(new CoverageSectionCheck(), $context), '<coverage> lists no sources');
    }

    /** The toolbelt's PHPUnit 9 measures nothing from <source>, with or without an empty <coverage>. */
    public function testSourcesUnderTheSourceElementDoNotCount(): void
    {
        foreach (['<coverage/>', ''] as $coverage) {
            $context = $this->repo([
                'tests/phpunit/SomeTest.php' => '<?php',
                'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit>' . $coverage . '<source><include><directory>Civi</directory></include></source></phpunit>',
            ]);
            $this->assertFails($this->run_(new CoverageSectionCheck(), $context), 'PHPUnit 9 ignores <source>');
        }
    }

    /** PHPUnit 9 still measures the deprecated <filter><whitelist> layout. */
    public function testALegacyWhitelistCounts(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit><filter><whitelist><directory suffix=".php">CRM</directory></whitelist></filter></phpunit>',
        ]);
        $this->assertOk($this->run_(new CoverageSectionCheck(), $context), 'declares coverage sources');
    }

    public function testAnEmptyWhitelistFails(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit><filter><whitelist/></filter></phpunit>',
        ]);
        $this->assertFails($this->run_(new CoverageSectionCheck(), $context), 'lists no sources');
    }

    /** PHPUnit reads phpunit.xml first, so the .dist file's sources do not reach the run. */
    public function testAShippedPhpunitXmlIsJudgedAlone(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            'phpunit.xml' => '<?xml version="1.0"?><phpunit><testsuites/></phpunit>',
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit><coverage><include><directory>Civi</directory></include></coverage></phpunit>',
        ]);
        $this->assertFails($this->run_(new CoverageSectionCheck(), $context), 'has no <coverage> section');
    }

    public function testAMalformedConfigIsNamed(): void
    {
        $context = $this->repo([
            'tests/phpunit/SomeTest.php' => '<?php',
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit><coverage>',
        ]);
        $this->assertFails($this->run_(new CoverageSectionCheck(), $context), 'not well-formed XML');
    }
}
