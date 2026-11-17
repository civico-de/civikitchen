<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\LicenseCoherenceCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class LicenseCoherenceCheckTest extends CheckTestCase
{
    public function testFailsWhenInfoXmlDisagreesWithThePolicy(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "license=Proprietary\n",
            'info.xml' => $this->infoXml(license: 'AGPL-3.0-or-later'),
        ]);
        $this->assertFails(
            $this->run_(new LicenseCoherenceCheck(), $context),
            "info.xml <license> is 'AGPL-3.0-or-later', civikitchen.yaml expects 'Proprietary'"
        );
    }

    public function testAMissingLicenseTagReadsAsEmptyInTheMessage(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "license=Proprietary\n",
            'info.xml' => '<?xml version="1.0"?><extension key="fixture" type="module"><file>fixture</file></extension>',
        ]);
        $this->assertFails(
            $this->run_(new LicenseCoherenceCheck(), $context),
            "info.xml <license> is 'empty', civikitchen.yaml expects 'Proprietary'"
        );
    }

    public function testFailsWhenComposerDisagreesWithThePolicy(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "license=Proprietary\n",
            'composer.json' => '{"name": "example/fixture", "license": "MIT"}',
        ]);
        $this->assertFails(
            $this->run_(new LicenseCoherenceCheck(), $context),
            "composer.json license is 'MIT', civikitchen.yaml expects 'Proprietary'"
        );
    }

    public function testPolicyComparisonIsCaseInsensitive(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "license=proprietary\n",
            'info.xml' => $this->infoXml(license: 'Proprietary'),
            'composer.json' => '{"license": "PROPRIETARY"}',
        ]);
        $this->assertSilent($this->run_(new LicenseCoherenceCheck(), $context));
    }

    public function testAComposerWithoutALicenseFieldIsNotPolicedAgainstThePolicy(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "license=Proprietary\n",
            'composer.json' => '{"name": "example/fixture"}',
        ]);
        $this->assertSilent($this->run_(new LicenseCoherenceCheck(), $context));
    }

    public function testWithoutPolicyTheDeclarationsOnlyHaveToAgree(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(license: 'AGPL-3.0-or-later'),
            'composer.json' => '{"license": "MIT"}',
        ]);
        $this->assertFails(
            $this->run_(new LicenseCoherenceCheck(), $context),
            "licence declarations disagree: info.xml 'AGPL-3.0-or-later' vs composer.json 'MIT'"
        );
    }

    public function testWithoutPolicyAgreeingDeclarationsAreSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(license: 'AGPL-3.0-or-later'),
            'composer.json' => '{"license": "agpl-3.0-or-later"}',
        ]);
        $this->assertSilent($this->run_(new LicenseCoherenceCheck(), $context));
    }

    /**
     * SPDX disjunctive licensing is allowed, and satisfied when the expected
     * licence is one of the members.
     */
    public function testADisjunctiveListIsAcceptedWhenItContainsTheExpectedLicence(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(license: 'MIT'),
            'composer.json' => '{"license": ["MIT", "GPL-2.0"]}',
            '__policy_fixture' => "license=MIT\n",
        ]);
        $this->assertPasses($this->run_(new LicenseCoherenceCheck(), $context));
    }

    /**
     * Permitted is not unchecked: a list without the expected licence fails.
     */
    public function testADisjunctiveListWithoutTheExpectedLicenceFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(license: 'Proprietary'),
            'composer.json' => '{"license": ["MIT", "GPL-2.0"]}',
            '__policy_fixture' => "license=Proprietary\n",
        ]);
        $this->assertFails(
            $this->run_(new LicenseCoherenceCheck(), $context),
            "composer.json license is 'MIT or GPL-2.0', civikitchen.yaml expects 'Proprietary'"
        );
    }

    public function testADisjunctiveListIsComparedAgainstInfoXmlWithoutAPolicy(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(license: 'Proprietary'),
            'composer.json' => '{"license": ["MIT", "GPL-2.0"]}',
        ]);
        $this->assertFails($this->run_(new LicenseCoherenceCheck(), $context), 'MIT or GPL-2.0');
    }

    /** Composer's documented disjunctive form; its SPDX grammar takes the operator in any case. */
    public function testAParenthesisedOrExpressionListsAlternatives(): void
    {
        foreach (['(MIT or GPL-3.0-or-later)', 'MIT OR GPL-3.0-or-later'] as $expression) {
            $context = $this->repo([
                'info.xml' => $this->infoXml(license: 'MIT'),
                'composer.json' => json_encode(['license' => $expression]),
            ]);
            $this->assertSilent($this->run_(new LicenseCoherenceCheck(), $context));
        }
    }

    public function testAnOrExpressionWithoutTheDeclaredLicenceFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(license: 'MIT'),
            'composer.json' => json_encode(['license' => '(Apache-2.0 or GPL-3.0-or-later)']),
        ]);
        $this->assertFails(
            $this->run_(new LicenseCoherenceCheck(), $context),
            "info.xml 'MIT' vs composer.json 'Apache-2.0 or GPL-3.0-or-later'",
        );
    }

    /** A conjunction grants both licences together, not either one. */
    public function testAnAndExpressionIsNoListOfAlternatives(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(license: 'MIT'),
            'composer.json' => json_encode(['license' => '(MIT and GPL-3.0-or-later)']),
        ]);
        $this->assertFails($this->run_(new LicenseCoherenceCheck(), $context), 'licence declarations disagree');
    }
}
