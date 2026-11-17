<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\LicensingUrlCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class LicensingUrlCheckTest extends CheckTestCase
{
    public function testSilentWithoutALicensingUrl(): void
    {
        $this->assertSilent($this->run_(new LicensingUrlCheck(), $this->repo([])));
    }

    public function testFailsWhenTheScaffoldedAgplLinkSurvivesRelicensing(): void
    {
        $context = $this->repo([
            'info.xml' => $this->urlInfoXml('Proprietary', 'https://www.gnu.org/licenses/agpl-3.0.html'),
        ]);
        $this->assertFails(
            $this->run_(new LicensingUrlCheck(), $context),
            'info.xml declares <license>Proprietary</license> but its Licensing url points at the agpl text'
                . ' (https://www.gnu.org/licenses/agpl-3.0.html)'
        );
    }

    public function testPassesWhenTheUrlMatchesTheDeclaration(): void
    {
        $context = $this->repo([
            'info.xml' => $this->urlInfoXml('AGPL-3.0-or-later', 'https://www.gnu.org/licenses/agpl-3.0.html'),
        ]);
        $reporter = $this->run_(new LicensingUrlCheck(), $context);
        $this->assertPasses($reporter);
        self::assertSame(['licensing url agrees with the declared licence'], $reporter->messages('ok'));
    }

    /**
     * `*agpl*` has to be decided before `*gpl*`: an AGPL url contains "gpl", so
     * plain GPL linking the AGPL text would otherwise look coherent.
     */
    public function testAnAgplUrlIsNotAcceptedForAPlainGplDeclaration(): void
    {
        $context = $this->repo([
            'info.xml' => $this->urlInfoXml('GPL-3.0-or-later', 'https://www.gnu.org/licenses/agpl-3.0.html'),
        ]);
        $this->assertFails(
            $this->run_(new LicensingUrlCheck(), $context),
            'points at the agpl text'
        );
    }

    public function testLgplIsAlsoDecidedBeforePlainGpl(): void
    {
        $context = $this->repo([
            'info.xml' => $this->urlInfoXml('GPL-3.0-or-later', 'https://www.gnu.org/licenses/lgpl-3.0.html'),
        ]);
        $this->assertFails($this->run_(new LicensingUrlCheck(), $context), 'points at the lgpl text');
    }

    public function testAPlainGplUrlAgreesWithAGplDeclaration(): void
    {
        $context = $this->repo([
            'info.xml' => $this->urlInfoXml('GPL-3.0-or-later', 'https://www.gnu.org/licenses/gpl-3.0.html'),
        ]);
        $this->assertPasses($this->run_(new LicensingUrlCheck(), $context));
    }

    public function testMitApacheAndMplUrlsAreRecognised(): void
    {
        foreach ([
            'https://opensource.org/licenses/mit' => 'mit',
            'https://www.apache.org/licenses/LICENSE-2.0' => 'apache',
            'https://www.mozilla.org/MPL/2.0/' => 'mpl',
        ] as $url => $expected) {
            $context = $this->repo(['info.xml' => $this->urlInfoXml('Proprietary', $url)]);
            $this->assertFails($this->run_(new LicensingUrlCheck(), $context), "points at the {$expected} text");
        }
    }

    /** An url that maps to no known licence is reported as agreeing. */
    public function testAnUnclassifiableUrlIsAccepted(): void
    {
        $context = $this->repo([
            'info.xml' => $this->urlInfoXml('Proprietary', 'https://example.com/legal/terms'),
        ]);
        $this->assertPasses($this->run_(new LicensingUrlCheck(), $context));
    }

    private function urlInfoXml(string $license, string $url): string
    {
        return $this->infoXml(
            license: $license,
            extra: "  <urls>\n    <url desc=\"Licensing\">{$url}</url>\n  </urls>",
        );
    }

    /**
     * The reverse direction of the agpl/gpl containment: AGPL declared with a
     * stale plain-GPL link must fail ('agpl-3.0' contains 'gpl').
     */
    public function testAPlainGplUrlIsNotAcceptedForAnAgplDeclaration(): void
    {
        $context = $this->repo([
            'info.xml' => $this->urlInfoXml('AGPL-3.0-or-later', 'https://www.gnu.org/licenses/gpl-3.0.html'),
        ]);
        $this->assertFails($this->run_(new LicensingUrlCheck(), $context), 'points at the gpl text');
    }
}
