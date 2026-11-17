<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\TestSuiteRequiredCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class TestSuiteRequiredCheckTest extends CheckTestCase
{
    public function testSaysNothingWhenASuiteExists(): void
    {
        $context = $this->repo([
            'tests/phpunit/bootstrap.php' => '<?php',
            'Civi/Thing.php' => '<?php class Thing {}',
        ], git: true);
        $this->assertSilent($this->run_(new TestSuiteRequiredCheck(), $context));
    }

    /** An untracked tests/phpunit is a suite nobody else can run — it must not count. */
    public function testAnUntrackedSuiteDoesNotCount(): void
    {
        $context = $this->repo(['Civi/Thing.php' => '<?php class Thing {}'], git: true);
        mkdir($context->root . '/tests/phpunit', 0777, true);
        file_put_contents($context->root . '/tests/phpunit/bootstrap.php', '<?php');
        $this->assertFails($this->run_(new TestSuiteRequiredCheck(), $context), 'no test suite');
    }

    public function testFailsWhenThereIsSourceButNoSuite(): void
    {
        $context = $this->repo([
            'Civi/Api4/Thing.php' => '<?php class Thing {}',
            'CRM/Fixture/Page.php' => '<?php class Page {}',
        ], git: true);
        $this->assertFails(
            $this->run_(new TestSuiteRequiredCheck(), $context),
            "no test suite (tests/phpunit) but 2 PHP source file(s) — add tests, or declare 'tests=optional -- <reason>' in civikitchen.yaml",
        );
    }

    /**
     * A config-only extension keeps its logic in the root <key>.php.
     */
    public function testTheRootPhpFileCounts(): void
    {
        $context = $this->repo([
            'fixture.php' => '<?php function fixture_civicrm_config() {}',
        ], git: true);
        $this->assertFails(
            $this->run_(new TestSuiteRequiredCheck(), $context),
            'but 1 PHP source file(s)',
        );
    }

    public function testGeneratedAndMachineOwnedFilesDoNotCount(): void
    {
        $context = $this->repo([
            'fixture.civix.php' => '<?php // generated',
            'phpstanBootstrap.php' => '<?php',
            'CRM/Fixture/DAO/Thing.php' => '<?php class Thing {}',
            'Civi/Api4/Thing.php' => '<?php class Thing {}',
        ], git: true);
        $reporter = $this->run_(new TestSuiteRequiredCheck(), $context);
        $this->assertFails($reporter, 'but 1 PHP source file(s)');
    }

    /** civix seeds BAO/ once; what grows in it is hand-written domain logic. */
    public function testBaoClassesCountAsSource(): void
    {
        $context = $this->repo([
            'CRM/Fixture/BAO/Thing.php' => '<?php class Thing { public function sync() {} }',
        ], git: true);
        $this->assertFails($this->run_(new TestSuiteRequiredCheck(), $context), 'but 1 PHP source file(s)');
    }

    public function testPassesWhenThereIsNoPhpSourceAtAll(): void
    {
        $context = $this->repo([
            'ang/fixture.aff.html' => '<div></div>',
        ], git: true);
        $reporter = $this->run_(new TestSuiteRequiredCheck(), $context);
        $this->assertPasses($reporter);
        self::assertSame(['no test suite — no PHP source to cover'], $reporter->messages('ok'));
    }

    public function testTheOptOutIsQuotedBackVerbatim(): void
    {
        $context = $this->repo([
            'Civi/Api4/Thing.php' => '<?php class Thing {}',
            '__policy_fixture' => "# policy\ntests=optional -- config/mgd-only extension, verified by the checks/ harness\n",
        ], git: true);
        $reporter = $this->run_(new TestSuiteRequiredCheck(), $context);
        $this->assertPasses($reporter);
        self::assertSame(
            ['no test suite — declared optional in civikitchen.yaml (optional -- config/mgd-only extension, verified by the checks/ harness)'],
            $reporter->messages('ok'),
        );
    }

    /** 'tests=required' or a typo must not silently disable the check. */
    public function testAnUnrecognisedPolicyValueFails(): void
    {
        $context = $this->repo([
            'Civi/Api4/Thing.php' => '<?php class Thing {}',
            '__policy_fixture' => "tests=required\n",
        ], git: true);
        $this->expectException(\RuntimeException::class);
        $this->run_(new TestSuiteRequiredCheck(), $context);
    }

    /** The reason is not optional. */
    public function testAnOptOutWithoutAReasonFails(): void
    {
        $context = $this->repo([
            'Civi/Api4/Thing.php' => '<?php class Thing {}',
            '__policy_fixture' => "tests=optional\n",
        ], git: true);
        $this->expectException(\RuntimeException::class);
        $this->run_(new TestSuiteRequiredCheck(), $context);
    }

    /** Outside git (tarball export) the filesystem fallback still counts source. */
    public function testSourceIsCountedOutsideAGitCheckout(): void
    {
        $context = $this->repo([
            'Civi/Api4/Thing.php' => '<?php class Thing {}',
        ]);
        $this->assertFails($this->run_(new TestSuiteRequiredCheck(), $context), 'no test suite');
    }
}
