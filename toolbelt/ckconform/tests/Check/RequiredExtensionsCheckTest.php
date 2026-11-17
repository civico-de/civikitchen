<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\RequiredExtensionsCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class RequiredExtensionsCheckTest extends CheckTestCase
{
    public function testSaysNothingForAnExtensionThatUsesNoneOfThem(): void
    {
        $this->assertSilent($this->run_(new RequiredExtensionsCheck(), $this->repo([], git: true)));
    }

    public function testFailsWhenManagedShipsASearchDisplayWithoutTheExt(): void
    {
        $context = $this->repo([
            'managed/MySearch.mgd.php' => "<?php\nreturn [['entity' => 'SearchDisplay', 'name' => 'x']];\n",
        ], git: true);
        $this->assertFails(
            $this->run_(new RequiredExtensionsCheck(), $context),
            'info.xml does not <requires> org.civicrm.search_kit — ships SearchDisplays (managed/ or an Afform <crm-search-display>)',
        );
    }

    public function testSearchDisplayAlsoTriggersIt(): void
    {
        $context = $this->repo([
            'managed/nested/Display.mgd.php' => "<?php\nreturn [['entity' => 'SearchDisplay']];\n",
        ], git: true);
        $this->assertFails($this->run_(new RequiredExtensionsCheck(), $context), 'org.civicrm.search_kit');
    }

    public function testSilentWhenSearchKitIsDeclared(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: "  <requires>\n    <ext>org.civicrm.search_kit</ext>\n  </requires>"),
            'managed/MySearch.mgd.php' => "<?php\nreturn [['entity' => 'SearchDisplay']];\n",
        ], git: true);
        $this->assertSilent($this->run_(new RequiredExtensionsCheck(), $context));
    }

    /**
     * The ang/afform/ layout most repos use, not just a flat ang/*.aff.html.
     */
    public function testFindsAfformsNestedBelowAng(): void
    {
        $context = $this->repo([
            'ang/afform/afGreeting.aff.html' => '<div af-fieldset=""></div>',
            'ang/afform/afGreeting.aff.json' => '{"title": "Greeting"}',
        ], git: true);
        $this->assertFails(
            $this->run_(new RequiredExtensionsCheck(), $context),
            'info.xml does not <requires> org.civicrm.afform — ang/ ships Afforms',
        );
    }

    public function testFailsForCiviRulesBaseClasses(): void
    {
        $context = $this->repo([
            'CRM/Fixture/Action/Thing.php' => "<?php\nclass CRM_Fixture_Action_Thing extends CRM_Civirules_Action {}\n",
        ], git: true);
        $this->assertFails(
            $this->run_(new RequiredExtensionsCheck(), $context),
            'info.xml does not <requires> org.civicoop.civirules — PHP extends CiviRules base classes',
        );
    }

    public function testCivirulesActionsBaseClassAlsoCounts(): void
    {
        $context = $this->repo([
            'CRM/Fixture/Action/Other.php' => "<?php\nclass X extends CRM_CivirulesActions_Generic_Api {}\n",
        ], git: true);
        $this->assertFails($this->run_(new RequiredExtensionsCheck(), $context), 'org.civicoop.civirules');
    }

    public function testAllowsAnExplicitlyAnnotatedAndGuardedOptionalCiviRulesAdapter(): void
    {
        $context = $this->repo([
            'CRM/Fixture/Action/Optional.php' => <<<'PHP'
<?php
// ckconform-optional civirules -- adapter is inert when CiviRules is absent
if (class_exists('CRM_Civirules_Action')) {
    /** Optional adapter. */
    final class CRM_Fixture_Action_Optional extends CRM_Civirules_Action {}
}
PHP,
        ], git: true);
        $this->assertSilent($this->run_(new RequiredExtensionsCheck(), $context));
    }

    public function testAnnotatedGuardAroundAnAbstractBaseIsOptional(): void
    {
        $context = $this->repo([
            'CRM/Fixture/Form/Base.php' => <<<'PHP'
<?php
// ckconform-optional civirules -- base form is inert when CiviRules is absent
if (class_exists('CRM_CivirulesActions_Form_Form')) {
    /** Optional base form. */
    abstract class CRM_Fixture_Form_Base extends CRM_CivirulesActions_Form_Form {}
}
PHP,
        ], git: true);
        $this->assertSilent($this->run_(new RequiredExtensionsCheck(), $context));
    }

    public function testGuardWithoutExplicitAnnotationStillRequiresCiviRules(): void
    {
        $context = $this->repo([
            'CRM/Fixture/Action/Optional.php' => "<?php\nif (class_exists('CRM_Civirules_Action')) {\nclass X extends CRM_Civirules_Action {}\n}\n",
        ], git: true);
        $this->assertFails($this->run_(new RequiredExtensionsCheck(), $context), 'org.civicoop.civirules');
    }

    public function testAnnotationWithoutAnImmediateGuardStillRequiresCiviRules(): void
    {
        $context = $this->repo([
            'CRM/Fixture/Action/Unsafe.php' => <<<'PHP'
<?php
// ckconform-optional civirules -- unrelated availability probe
if (class_exists('CRM_Civirules_Action')) {}
class CRM_Fixture_Action_Unsafe extends CRM_Civirules_Action {}
PHP,
        ], git: true);
        $this->assertFails($this->run_(new RequiredExtensionsCheck(), $context), 'org.civicoop.civirules');
    }

    public function testPhpstanStubsDoNotCreateRuntimeDependencies(): void
    {
        $context = $this->repo([
            'phpstan-stubs/CRM_Civirules_Action.php' => "<?php\nabstract class Stub extends CRM_Civirules_Action {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new RequiredExtensionsCheck(), $context));
    }

    public function testTestsDoNotCreateRuntimeDependencies(): void
    {
        $context = $this->repo([
            'tests/phpunit/CRM/Fixture/ActionTest.php' => "<?php\nclass Fake extends CRM_Civirules_Action {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new RequiredExtensionsCheck(), $context));
    }

    /**
     * An `<ext>` element carrying an attribute is still a declaration.
     */
    public function testAnExtElementWithAnAttributeIsStillADeclaration(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(
                extra: "  <requires>\n    <ext version=\"3.32\">org.civicoop.civirules</ext>\n  </requires>",
            ),
            'CRM/Fixture/Action/Thing.php' => "<?php\nclass T extends CRM_Civirules_Action {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new RequiredExtensionsCheck(), $context));
    }

    public function testWhitespaceAroundTheKeyIsTrimmed(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: "  <requires>\n    <ext>\n      org.civicrm.afform\n    </ext>\n  </requires>"),
            'ang/afform/x.aff.html' => '<div></div>',
        ], git: true);
        $this->assertSilent($this->run_(new RequiredExtensionsCheck(), $context));
    }

    /**
     * A longer, unrelated key must not satisfy the requirement.
     */
    public function testALongerKeyDoesNotSatisfyTheRequirement(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: "  <requires>\n    <ext>org.civicrm.afform_extras</ext>\n  </requires>"),
            'ang/afform/x.aff.html' => '<div></div>',
        ], git: true);
        $this->assertFails($this->run_(new RequiredExtensionsCheck(), $context), 'org.civicrm.afform —');
    }

    public function testAllThreeCanFireTogetherInBashOrder(): void
    {
        $context = $this->repo([
            'managed/S.mgd.php' => "<?php\nreturn [['entity' => 'SearchDisplay']];\n",
            'ang/afform/x.aff.json' => '{}',
            'CRM/T.php' => "<?php\nclass T extends CRM_Civirules_Action {}\n",
        ], git: true);
        $reporter = $this->run_(new RequiredExtensionsCheck(), $context);
        self::assertSame(
            [
                'info.xml does not <requires> org.civicrm.search_kit — ships SearchDisplays (managed/ or an Afform <crm-search-display>)',
                'info.xml does not <requires> org.civicrm.afform — ang/ ships Afforms',
                'info.xml does not <requires> org.civicoop.civirules — PHP extends CiviRules base classes',
            ],
            $reporter->messages('FAIL'),
        );
    }

    /** SavedSearch is a core entity; core evaluates the smart groups built from it. */
    public function testACoreSavedSearchNeedsNoSearchKit(): void
    {
        $context = $this->repo([
            'managed/Group_Donors.mgd.php' => "<?php\nreturn [\n  ['name' => 'SavedSearch_myext_recent_donors', 'entity' => 'SavedSearch', 'params' => ['version' => 4, 'values' => ['name' => 'myext_recent_donors', 'api_entity' => 'Contact']]],\n  ['name' => 'Group_myext_donors', 'entity' => 'Group', 'params' => ['version' => 4, 'values' => ['name' => 'myext_donors', 'saved_search_id.name' => 'myext_recent_donors']]],\n];\n",
        ], git: true);
        $this->assertSilent($this->run_(new RequiredExtensionsCheck(), $context));
    }

    public function testAnAfformSearchDisplayNeedsSearchKit(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(extra: "  <requires>\n    <ext>org.civicrm.afform</ext>\n  </requires>"),
            'ang/afsearchMyext.aff.html' => '<crm-search-display-table search-name="myext_s" display-name="myext_t"></crm-search-display-table>',
        ], git: true);
        $this->assertFails($this->run_(new RequiredExtensionsCheck(), $context), 'org.civicrm.search_kit');
    }
}
