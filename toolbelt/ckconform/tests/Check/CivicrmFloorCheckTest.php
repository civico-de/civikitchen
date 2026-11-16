<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\CivicrmFloorCheck;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class CivicrmFloorCheckTest extends CheckTestCase
{
    /** @param list<string> $reasons `<version> -- <reason>` entries */
    private function extension(string $compatibility, array $reasons = []): Context
    {
        $files = ['info.xml' => $this->infoXml(compatibility: $compatibility)];
        if ($reasons !== []) {
            $lines = array_map(static fn (string $reason): string => "civicrm_floor_reasons={$reason}\n", $reasons);
            $files['civikitchen.yaml'] = $this->policyFixture(implode('', $lines));
        }

        return $this->repo($files);
    }

    public function testTheEsrNeedsNoReason(): void
    {
        $this->assertPasses($this->run_(new CivicrmFloorCheck(), $this->extension(CivicrmFloorCheck::ESR)));
    }

    public function testAHigherFloorWithoutAReasonFails(): void
    {
        $this->assertFails(
            $this->run_(new CivicrmFloorCheck(), $this->extension('6.18')),
            'info.xml declares CiviCRM 6.18, above the ESR 6.16, and civikitchen.yaml gives no reason for 6.18',
        );
    }

    public function testALowerFloorWithoutAReasonFails(): void
    {
        $this->assertFails(
            $this->run_(new CivicrmFloorCheck(), $this->extension('6.12')),
            'info.xml declares CiviCRM 6.12, below the ESR 6.16',
        );
    }

    public function testAReasonForTheDeclaredVersionPasses(): void
    {
        $this->assertPasses($this->run_(new CivicrmFloorCheck(), $this->extension('6.18', ['6.18 -- needs the scheduled job base class'])));
        $this->assertPasses($this->run_(new CivicrmFloorCheck(), $this->extension('6.12', ['6.12 -- a client site stays on this minor'])));
    }

    /** A patch release above the ESR minor is a narrower floor and needs its reason. */
    public function testAPatchPinNeedsItsOwnReason(): void
    {
        $this->assertPasses($this->run_(new CivicrmFloorCheck(), $this->extension('6.17.2', ['6.17.2 -- the first patch with the session fix'])));
        $this->assertFails(
            $this->run_(new CivicrmFloorCheck(), $this->extension('6.17.2', ['6.17 -- the session fix'])),
            'gives no reason for 6.17.2',
        );
    }

    public function testSeveralReasonsUpToTheDeclaredVersionPass(): void
    {
        $context = $this->extension('6.18', ['6.18 -- needs the scheduled job base class', '6.17 -- needs the new token event', '6.18 -- needs the queue runner']);
        $this->assertPasses($this->run_(new CivicrmFloorCheck(), $context));
    }

    public function testTheHighestReasonMustNameTheDeclaredVersion(): void
    {
        $this->assertFails(
            $this->run_(new CivicrmFloorCheck(), $this->extension('6.18', ['6.17 -- needs the new token event'])),
            'gives no reason for 6.18',
        );
    }

    public function testAReasonBeyondTheDeclaredVersionFails(): void
    {
        $this->assertFails(
            $this->run_(new CivicrmFloorCheck(), $this->extension('6.18', ['6.18 -- needs the scheduled job base class', '6.19 -- needs the mail hook'])),
            'gives a reason for 6.19, but info.xml declares 6.18',
        );
    }

    public function testAReasonTheEsrCoversFails(): void
    {
        $context = $this->extension('6.18', ['6.18 -- needs the scheduled job base class', '6.16 -- needs the entity display', '6.15 -- needs the form hook']);
        $reporter = $this->run_(new CivicrmFloorCheck(), $context);
        $this->assertFails($reporter, 'gives a reason for 6.16 that is not needed');
        $this->assertFails($reporter, 'gives a reason for 6.15 that is not needed');
        $this->assertFails($reporter, '"needs the form hook"');
    }

    public function testAReasonWhileInfoXmlDeclaresTheEsrFails(): void
    {
        $this->assertFails(
            $this->run_(new CivicrmFloorCheck(), $this->extension(CivicrmFloorCheck::ESR, ['6.18 -- needs the scheduled job base class'])),
            'gives a reason for 6.18 that is not needed',
        );
    }

    /** 6.9 sorts above 6.16 as a string; the check compares versions. */
    public function testMinorsCompareNumerically(): void
    {
        $this->assertFails($this->run_(new CivicrmFloorCheck(), $this->extension('6.9')), 'below the ESR');
    }

    public function testTheLowestOfSeveralVersionsCounts(): void
    {
        $context = $this->repo(['info.xml' => $this->infoXml(compatibility: "6.18</ver>\n    <ver>6.15")]);
        $this->assertFails($this->run_(new CivicrmFloorCheck(), $context), 'info.xml declares CiviCRM 6.15');
    }

    public function testAMissingCompatibilityFails(): void
    {
        $this->assertFails($this->run_(new CivicrmFloorCheck(), $this->extension('')), 'declares no <compatibility><ver>');
    }

    public function testAVersionThatIsNotACiviCrmVersionIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->extension('6.18', ['6 -- needs it'])->policyValues('civicrm_floor_reasons');
    }
}
