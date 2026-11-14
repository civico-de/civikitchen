<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\CivicrmFloorCheck;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Policy;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class CivicrmFloorCheckTest extends CheckTestCase
{
    private ?string $defaultsFile = null;

    protected function tearDown(): void
    {
        putenv(Policy::DEFAULTS_ENV);
        if ($this->defaultsFile !== null && is_file($this->defaultsFile)) {
            unlink($this->defaultsFile);
        }
        parent::tearDown();
    }

    private function extension(string $compatibility, ?string $floor): Context
    {
        $files = ['info.xml' => $this->infoXml(compatibility: $compatibility)];
        if ($floor !== null) {
            $files['civikitchen.yaml'] = $this->policyFixture("civicrm_floor={$floor}\n");
        }

        return $this->repo($files);
    }

    public function testAFloorBelowThePolicyFails(): void
    {
        $this->assertFails(
            $this->run_(new CivicrmFloorCheck(), $this->extension('6.12', '6.16')),
            'compatibility floor is 6.12, below the 6.16',
        );
    }

    public function testTheSameFloorPasses(): void
    {
        $this->assertPasses($this->run_(new CivicrmFloorCheck(), $this->extension('6.16', '6.16')));
    }

    /** An extension that needs a newer core keeps its own, higher floor. */
    public function testAHigherFloorPasses(): void
    {
        $this->assertPasses($this->run_(new CivicrmFloorCheck(), $this->extension('6.17.2', '6.16')));
    }

    /** 6.9 sorts above 6.16 as a string; the check compares versions. */
    public function testMinorsCompareNumerically(): void
    {
        $this->assertFails($this->run_(new CivicrmFloorCheck(), $this->extension('6.9', '6.16')));
    }

    public function testTheLowestOfSeveralVersionsCounts(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(compatibility: "6.18</ver>\n    <ver>6.15"),
            'civikitchen.yaml' => $this->policyFixture("civicrm_floor=6.16\n"),
        ]);
        $this->assertFails($this->run_(new CivicrmFloorCheck(), $context), 'floor is 6.15');
    }

    public function testAMissingCompatibilityFails(): void
    {
        $this->assertFails(
            $this->run_(new CivicrmFloorCheck(), $this->extension('', '6.16')),
            'declares no <compatibility><ver>',
        );
    }

    public function testWithoutThePolicyNothingIsRequired(): void
    {
        $this->assertSilent($this->run_(new CivicrmFloorCheck(), $this->extension('5.75', null)));
    }

    public function testTheOrganisationDefaultsApply(): void
    {
        $this->defaultsFile = sys_get_temp_dir() . '/ckconform-defaults-' . bin2hex(random_bytes(6));
        file_put_contents($this->defaultsFile, $this->policyFixture("civicrm_floor=6.16\n"));
        putenv(Policy::DEFAULTS_ENV . '=' . $this->defaultsFile);

        $this->assertFails($this->run_(new CivicrmFloorCheck(), $this->extension('6.12', null)));
    }

    public function testAFloorThatIsNotAMinorIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->extension('6.16', '6')->policyValue('civicrm_floor');
    }
}
