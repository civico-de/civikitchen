<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\TemplateReferenceCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;
use CiviKitchen\Ckconform\Tests\FakeCoreTrait;

final class TemplateReferenceCheckTest extends CheckTestCase
{
    use FakeCoreTrait;

    public function testSilentWithoutPagesOrForms(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => "<?php\nnamespace Civi\\Greeter;\nclass Thing {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new TemplateReferenceCheck(), $context));
    }

    public function testAPageWithItsTemplateIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'CRM/Greeter/Page/Foo.php' => "<?php\nclass CRM_Greeter_Page_Foo extends CRM_Core_Page {}\n",
            'templates/CRM/Greeter/Page/Foo.tpl' => "<div>hi</div>\n",
        ], git: true);
        $this->assertSilent($this->run_(new TemplateReferenceCheck(), $context));
    }

    public function testAPageWithoutItsTemplateFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'CRM/Greeter/Page/Foo.php' => "<?php\nclass CRM_Greeter_Page_Foo extends CRM_Core_Page {}\n",
        ], git: true);
        $this->assertFails(
            $this->run_(new TemplateReferenceCheck(), $context),
            'templates/CRM/Greeter/Page/Foo.tpl'
        );
    }

    public function testAnAbstractClassNeedsNoTemplate(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'CRM/Greeter/Form/Base.php' => "<?php\nabstract class CRM_Greeter_Form_Base extends CRM_Core_Form {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new TemplateReferenceCheck(), $context));
    }

    public function testAnOverriddenTemplateNameNeedsNoTemplate(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'CRM/Greeter/Page/Foo.php' => "<?php\nclass CRM_Greeter_Page_Foo extends CRM_Core_Page {\n  public function getTemplateFileName() { return 'CRM/Greeter/Page/Other.tpl'; }\n}\n",
        ], git: true);
        $this->assertSilent($this->run_(new TemplateReferenceCheck(), $context));
    }

    /** A run() override that never calls parent::run() never renders. */
    public function testANonRenderingRunOverrideNeedsNoTemplate(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'CRM/Greeter/Page/Hook.php' => "<?php\nclass CRM_Greeter_Page_Hook extends CRM_Core_Page {\n  public function run() {\n    echo json_encode(['ok' => TRUE]);\n    CRM_Utils_System::civiExit();\n  }\n}\n",
        ], git: true);
        $this->assertSilent($this->run_(new TemplateReferenceCheck(), $context));
    }

    /** ... but delegating to parent::run() still renders the template. */
    public function testARunOverrideDelegatingToParentStillNeedsIt(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'CRM/Greeter/Page/Foo.php' => "<?php\nclass CRM_Greeter_Page_Foo extends CRM_Core_Page {\n  public function run() {\n    \$this->assign('x', 1);\n    parent::run();\n  }\n}\n",
        ], git: true);
        $this->assertFails(
            $this->run_(new TemplateReferenceCheck(), $context),
            'templates/CRM/Greeter/Page/Foo.tpl'
        );
    }

    /** Extending a sibling Page inherits its template. */
    public function testASubclassOfAnOwnPageIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'CRM/Greeter/Page/Foo.php' => "<?php\nclass CRM_Greeter_Page_Foo extends CRM_Core_Page {}\n",
            'CRM/Greeter/Page/Bar.php' => "<?php\nclass CRM_Greeter_Page_Bar extends CRM_Greeter_Page_Foo {}\n",
            'templates/CRM/Greeter/Page/Foo.tpl' => "<div>hi</div>\n",
        ], git: true);
        $this->assertSilent($this->run_(new TemplateReferenceCheck(), $context));
    }

    public function testAMissingLiteralTemplateFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Mailer.php' => "<?php\nnamespace Civi\\Greeter;\nclass Mailer {\n  public function body() {\n    return \\CRM_Core_Smarty::singleton()->fetch('CRM/Greeter/Snippet/Body.tpl');\n  }\n}\n",
        ], git: true);
        $this->assertFails(
            $this->run_(new TemplateReferenceCheck(), $context),
            'templates/CRM/Greeter/Snippet/Body.tpl'
        );
    }

    public function testAForeignLiteralTemplateIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Mailer.php' => "<?php\nnamespace Civi\\Greeter;\nclass Mailer {\n  public function body() {\n    return \\CRM_Core_Smarty::singleton()->fetch('CRM/Contact/Page/View.tpl');\n  }\n}\n",
        ], git: true);
        $this->assertSilent($this->run_(new TemplateReferenceCheck(), $context));
    }

    public function testAStringTemplateIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Mailer.php' => "<?php\nnamespace Civi\\Greeter;\nclass Mailer {\n  public function body() {\n    return \\CRM_Core_Smarty::singleton()->fetch('string:CRM/Greeter/Gone.tpl');\n  }\n}\n",
        ], git: true);
        $this->assertSilent($this->run_(new TemplateReferenceCheck(), $context));
    }

    /**
     * Smarty 5 tag compatibility is SmartyCompatCheck's; this check is about
     * missing files.
     */
    public function testAPhpBlockIsNotThisChecksBusiness(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'CRM/Greeter/Page/Foo.php' => "<?php\nclass CRM_Greeter_Page_Foo extends CRM_Core_Page {}\n",
            'templates/CRM/Greeter/Page/Foo.tpl' => "{php}echo 1;{/php}\n",
        ], git: true);
        $this->assertSilent($this->run_(new TemplateReferenceCheck(), $context));
    }

    public function testAPageClassUnderNodeModulesIsNotJudged(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'node_modules/dep/render.php' => "<?php\n\$smarty->fetch('CRM/Greeter/Page/Gone.tpl');\n",
        ], git: true);
        $this->assertSilent($this->run_(new TemplateReferenceCheck(), $context));
    }

    public function testAFullyQualifiedOwnParentIsStillAnOwnParent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'CRM/Greeter/Page/Foo.php' => "<?php\nclass CRM_Greeter_Page_Foo extends CRM_Core_Page {}\n",
            'CRM/Greeter/Page/Bar.php' => "<?php\nclass CRM_Greeter_Page_Bar extends \\CRM_Greeter_Page_Foo {}\n",
            'templates/CRM/Greeter/Page/Foo.tpl' => "<div>hi</div>\n",
        ], git: true);
        $this->assertSilent($this->run_(new TemplateReferenceCheck(), $context));
    }

    /** Near miss: a fully qualified core parent still needs the derived template. */
    public function testAFullyQualifiedCoreParentStillNeedsATemplate(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'CRM/Greeter/Page/Bar.php' => "<?php\nclass CRM_Greeter_Page_Bar extends \\CRM_Core_Page {}\n",
        ], git: true);
        $this->assertFails($this->run_(new TemplateReferenceCheck(), $context), 'templates/CRM/Greeter/Page/Bar.tpl');
    }

    /** @param array<string, string> $files */
    private function greeter(array $files): \CiviKitchen\Ckconform\Context
    {
        return $this->repo(['info.xml' => $this->infoXml(key: 'de.example.greeter')] + $files, git: true);
    }

    private function coreClass(string $class, string $parent, string $body = ''): void
    {
        $file = ($this->coreDir() ?? $this->makeCore()) . '/' . str_replace('_', '/', $class) . '.php';
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, "<?php\nclass $class extends $parent {\n$body}\n");
    }

    /** @return iterable<string, array{string}> */
    public static function nonRenderingDeclarations(): iterable
    {
        yield 'static AJAX holder' => ["class CRM_Greeter_Page_AJAX {\n  public static function getRows() { CRM_Utils_JSON::output([]); }\n}\n"];
        yield 'trait' => ["trait CRM_Greeter_Form_SettingsTrait {}\n"];
        yield 'class named only in a docblock' => ["/** Mirrors class CRM_Greeter_Page_AJAX extends CRM_Core_Page */\ninterface CRM_Greeter_Page_AJAX {}\n"];
    }

    /** @dataProvider nonRenderingDeclarations */
    public function testDeclarationsThatRenderNothingNeedNoTemplate(string $declaration): void
    {
        $this->assertSilent($this->run_(new TemplateReferenceCheck(), $this->greeter(['CRM/Greeter/Page/AJAX.php' => "<?php\n$declaration"])));
    }

    /** @return iterable<string, array{string, string}> */
    public static function coreParentsSupplyingATemplate(): iterable
    {
        yield 'report form' => ['CRM_Report_Form', 'CRM_Core_Form'];
        yield 'import data source' => ['CRM_Import_Form_DataSource', 'CRM_Core_Form'];
    }

    /** @dataProvider coreParentsSupplyingATemplate */
    public function testACoreParentOverridingTheTemplateNameSuppliesIt(string $parent, string $grandparent): void
    {
        $this->coreClass($parent, $grandparent, "  public function getTemplateFileName() { return 'CRM/Report/Form.tpl'; }\n");
        $context = $this->greeter(['CRM/Greeter/Form/Summary.php' => "<?php\nclass CRM_Greeter_Form_Summary extends $parent {}\n"]);
        $this->assertSilent($this->run_(new TemplateReferenceCheck(), $context));
    }

    public function testACoreParentDerivingTheTemplateStillNeedsIt(): void
    {
        $this->coreClass('CRM_Contact_Page_View', 'CRM_Core_Page');
        $context = $this->greeter(['CRM/Greeter/Page/View.php' => "<?php\nclass CRM_Greeter_Page_View extends CRM_Contact_Page_View {}\n"]);
        $this->assertFails($this->run_(new TemplateReferenceCheck(), $context), 'templates/CRM/Greeter/Page/View.tpl');
    }

    /** Without core the ancestry is unknown; only direct Page/Form children are judged. */
    public function testAnUnknownParentWithoutCoreIsNotJudged(): void
    {
        $context = $this->greeter(['CRM/Greeter/Form/Summary.php' => "<?php\nclass CRM_Greeter_Form_Summary extends CRM_Report_Form {}\n"]);
        $this->assertSilent($this->run_(new TemplateReferenceCheck(), $context));
    }

    /** A payment processor in CRM/Core/Payment/ does not make CRM/Core/ templates ours. */
    public function testACoreTemplateFetchedBesideAShippedCoreDirectoryIsSilent(): void
    {
        mkdir($this->makeCore() . '/CRM/Core', 0777, true);
        $context = $this->greeter([
            'CRM/Core/Payment/Greeter.php' => "<?php\nclass CRM_Core_Payment_Greeter {\n  function f(\$smarty) { return \$smarty->fetch('CRM/Core/BillingBlock.tpl'); }\n}\n",
        ]);
        $this->assertSilent($this->run_(new TemplateReferenceCheck(), $context));
    }
}
