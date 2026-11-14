<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\ComposerJsonCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class ComposerJsonCheckTest extends CheckTestCase
{
    public function testFailsWithoutComposerJson(): void
    {
        $reporter = $this->run_(new ComposerJsonCheck(), $this->repo([]));
        $this->assertFails($reporter, 'no composer.json (extension metadata)');
    }

    public function testSilentWhenComposerJsonExists(): void
    {
        $context = $this->repo(['composer.json' => '{}']);
        $this->assertSilent($this->run_(new ComposerJsonCheck(), $context));
    }

    public function testFailsWhenComposerJsonRepeatsTheDescription(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '<description>Sends greetings.</description>'),
            'composer.json' => '{"name": "example/fixture", "description": "Sends greetings."}',
        ]);
        $this->assertFails($this->run_(new ComposerJsonCheck(), $context), 'remove "description" from composer.json');
    }

    public function testSilentWhenOnlyInfoXmlDescribes(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: '<description>Sends greetings.</description>'),
            'composer.json' => '{"name": "example/fixture"}',
        ]);
        $this->assertSilent($this->run_(new ComposerJsonCheck(), $context));
    }
}
