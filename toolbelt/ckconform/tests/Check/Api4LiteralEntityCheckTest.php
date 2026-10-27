<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\Api4LiteralEntityCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;
use CiviKitchen\Ckconform\Tests\FakeCoreTrait;

final class Api4LiteralEntityCheckTest extends CheckTestCase
{
    use FakeCoreTrait;

    /** @param list<string> $entities */
    private function core(array $entities = []): void
    {
        $this->makeCore();
        foreach ($entities as $name) {
            file_put_contents($this->core . '/Civi/Api4/' . $name . '.php', "<?php\nnamespace Civi\\Api4;\nclass {$name} {}\n");
        }
    }
    /**
     * @param array<string, string> $extra
     */
    private function widget(array $extra): \CiviKitchen\Ckconform\Context
    {
        $this->core();

        return $this->repo([
            'Civi/Api4/Widget.php' => "<?php\n",
            'Civi/Api4/WidgetState.php' => "<?php\n",
        ] + $extra, git: true);
    }

    public function testSilentWithoutLocalEntities(): void
    {
        $context = $this->repo([
            'Civi/Ext/Thing.php' => "<?php\ncivicrm_api4('WidgetGhost', 'get', []);\n",
        ], git: true);
        $this->assertSilent($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** A call to an own entity that was never defined — a typo or rename miss. */
    public function testAnOwnEntityThatDoesNotExistFails(): void
    {
        $context = $this->widget([
            'Civi/Widget/Runner.php' => "<?php\ncivicrm_api4('WidgetStatee', 'get', []);\n",
        ]);
        $this->assertFails($this->run_(new Api4LiteralEntityCheck(), $context), 'WidgetStatee');
    }

    public function testOwnEntitiesThatExistPass(): void
    {
        $context = $this->widget([
            'Civi/Widget/Runner.php' => "<?php\ncivicrm_api4('Widget', 'get', []);\ncivicrm_api4('WidgetState', 'get', []);\n",
        ]);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /**
     * The whole point: another extension's entity shares no leading word with
     * ours, so calling civirules from widget must not be flagged.
     */
    public function testAnotherExtensionsEntityIsLeftAlone(): void
    {
        $context = $this->widget([
            'Civi/Widget/Runner.php' => "<?php\ncivicrm_api4('CiviRulesRule', 'get', []);\n",
        ]);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** Core entities share no leading word with ours either. */
    public function testCoreEntitiesAreLeftAlone(): void
    {
        $context = $this->widget([
            'Civi/Widget/Runner.php' => "<?php\ncivicrm_api4('Contact', 'get', []);\ncivicrm_api4('MailingJob', 'get', []);\n",
        ]);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** A civicrm_api4 example in a docblock is not a call. */
    public function testAnExampleInACommentIsNotACall(): void
    {
        $context = $this->widget([
            'Civi/Widget/Runner.php' => "<?php\n/** e.g. civicrm_api4('WidgetGhost', 'get') */\nclass Runner {}\n",
        ]);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** A fixture entity under tests/ is not shipped and must not seed the family. */
    public function testAFixtureEntityDoesNotSeedTheFamily(): void
    {
        $context = $this->repo([
            'tests/fixtures/Civi/Api4/Widget.php' => "<?php\n",
            'Civi/Ext/Runner.php' => "<?php\ncivicrm_api4('WidgetGhost', 'get', []);\n",
        ], git: true);
        $this->assertSilent($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** A shipped call resolved only by a tests/fixtures entity fatals on an install. */
    public function testAnEntityDefinedOnlyAsAFixtureFails(): void
    {
        $context = $this->widget([
            'tests/fixtures/Civi/Api4/WidgetFixture.php' => "<?php\n",
            'Civi/Widget/Runner.php' => "<?php\ncivicrm_api4('WidgetFixture', 'get', []);\n",
        ]);
        $this->assertFails($this->run_(new Api4LiteralEntityCheck(), $context), 'WidgetFixture');
    }

    /** A dangling call inside tests/ fails that test run itself, not the site. */
    public function testACallInTestsIsNotScanned(): void
    {
        $context = $this->widget([
            'tests/phpunit/RunnerTest.php' => "<?php\ncivicrm_api4('WidgetStatee', 'get', []);\n",
        ]);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** Built output restates the source; its findings belong to the source file. */
    public function testBuiltArtefactsAreNotScanned(): void
    {
        $context = $this->widget([
            'dist/Runner.php' => "<?php\ncivicrm_api4('WidgetStatee', 'get', []);\n",
            'ang/widget/build/Runner.php' => "<?php\ncivicrm_api4('WidgetStatee', 'get', []);\n",
        ]);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /**
     * A local CiviRulesRule-style entity puts 'Civi' in the family — which
     * would flag every core CiviCase/CiviMail call. That family is skipped.
     */
    public function testALocalCiviPrefixedEntityDoesNotFlagCoreCiviCalls(): void
    {
        $context = $this->repo([
            'Civi/Api4/CiviRulesRule.php' => "<?php\n",
            'Civi/Ext/Runner.php' => "<?php\ncivicrm_api4('CiviCase', 'get', []);\n",
        ], git: true);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** @return iterable<string, array{string}> */
    public static function callForms(): iterable
    {
        yield 'upper-case function name' => ["CIVICRM_API4('WidgetTypo', 'get', []);"];
        yield 'named entity argument' => ["civicrm_api4(action: 'get', entity: 'WidgetTypo');"];
        yield 'comment before the argument' => ["civicrm_api4(/* entity */ 'WidgetTypo', 'get');"];
        yield 'through call_user_func' => ["call_user_func('civicrm_api4', 'WidgetTypo', 'get', []);"];
        yield 'fully qualified function' => ["\\civicrm_api4('WidgetTypo', 'get');"];
    }

    /** @dataProvider callForms */
    public function testEveryCallFormIsRead(string $call): void
    {
        $context = $this->widget(['Civi/Widget/Runner.php' => "<?php\n$call\n"]);
        $this->assertFails($this->run_(new Api4LiteralEntityCheck(), $context), 'WidgetTypo');
    }

    /** Near misses: the literal is not the entity argument of a civicrm_api4() call. */
    public function testOtherArgumentsAndCallbacksAreNotEntities(): void
    {
        $context = $this->widget(['Civi/Widget/Runner.php' => <<<'PHP'
            <?php
            civicrm_api4(action: 'WidgetTypo', entity: 'WidgetState');
            call_user_func('civicrm_api3', 'WidgetTypo', 'get', []);
            call_user_func('civicrm_api4', 'Widget', 'WidgetTypo');
            civicrm_api4('Widget' . 'Typo', 'get');
            PHP]);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** Which entities core ships is unknowable without a checkout, so nothing is judged. */
    public function testSilentWithoutACoreCheckout(): void
    {
        $context = $this->repo([
            'Civi/Api4/Widget.php' => "<?php\n",
            'Civi/Widget/Runner.php' => "<?php\ncivicrm_api4('WidgetTypo', 'get', []);\n",
        ], git: true);
        $this->assertSilent($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    /** An own MembershipPeriod shares its leading word with core's Membership entities. */
    public function testCoreEntitiesSharingTheLeadingWordAreLeftAlone(): void
    {
        $this->core(['MembershipType', 'MembershipStatus']);
        $context = $this->repo([
            'Civi/Api4/MembershipPeriod.php' => "<?php\n",
            'Civi/Myext/Runner.php' => "<?php\ncivicrm_api4('MembershipType', 'get', []);\ncivicrm_api4('MembershipStatus', 'get', []);\n",
        ], git: true);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }

    public function testATypoOfAnOwnEntityStillFailsBesideCore(): void
    {
        $this->core(['MembershipType']);
        $context = $this->repo([
            'Civi/Api4/MyextWidget.php' => "<?php\n",
            'Civi/Myext/Runner.php' => "<?php\ncivicrm_api4('MyextWidgett', 'get', []);\n",
        ], git: true);
        $this->assertFails($this->run_(new Api4LiteralEntityCheck(), $context), 'MyextWidgett');
    }

    public function testADeclaredExternalEntityIsLeftAlone(): void
    {
        $this->core();
        $context = $this->repo([
            '__policy_fixture' => "known_api4_entities=WidgetLegacy -- supplied by required widgetlegacy\n",
            'info.xml' => $this->infoXml(extra: '<requires><ext>widgetlegacy</ext></requires>'),
            'Civi/Api4/Widget.php' => "<?php\n",
            'Civi/Widget/Runner.php' => "<?php\ncivicrm_api4('WidgetLegacy', 'get', []);\n",
        ], git: true);
        $this->assertPasses($this->run_(new Api4LiteralEntityCheck(), $context));
    }
}
