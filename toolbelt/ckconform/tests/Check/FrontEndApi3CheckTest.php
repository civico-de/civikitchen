<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\FrontEndApi3Check;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class FrontEndApi3CheckTest extends CheckTestCase
{
    public function testSaysNothingOutsideAGitRepo(): void
    {
        $context = $this->repo(['js/thing.js' => 'CRM.api3("Contact", "get", {});']);
        $this->assertSilent($this->run_(new FrontEndApi3Check(), $context));
    }

    public function testSaysNothingWhenNoFrontEndFileCallsApi3(): void
    {
        $context = $this->repo(['js/thing.js' => 'CRM.api4("Contact", "get", {});'], git: true);
        $this->assertSilent($this->run_(new FrontEndApi3Check(), $context));
    }

    public function testFailsForAnUndocumentedApi3Call(): void
    {
        $context = $this->repo(['js/thing.js' => 'CRM.api3("Contact", "get", {});'], git: true);
        $this->assertFails(
            $this->run_(new FrontEndApi3Check(), $context),
            "js/thing.js calls CRM.api3 — migrate to CRM.api4, or annotate 'ck-allow-api3 -- <reason>'",
        );
    }

    public function testTemplatesAndVuesAreCoveredToo(): void
    {
        $context = $this->repo([
            'templates/CRM/Fixture/Page.tpl' => '{literal}CRM.api3("Foo", "get");{/literal}',
            'js/Widget.vue' => '<script>CRM.api3("Bar", "get");</script>',
            'ang/page.html' => '<div>CRM.api3("Baz", "get");</div>',
        ], git: true);
        $reporter = $this->run_(new FrontEndApi3Check(), $context);
        self::assertSame(
            [
                "ang/page.html calls CRM.api3 — migrate to CRM.api4, or annotate 'ck-allow-api3 -- <reason>'",
                "js/Widget.vue calls CRM.api3 — migrate to CRM.api4, or annotate 'ck-allow-api3 -- <reason>'",
                "templates/CRM/Fixture/Page.tpl calls CRM.api3 — migrate to CRM.api4, or annotate 'ck-allow-api3 -- <reason>'",
            ],
            $reporter->messages('FAIL'),
        );
    }

    public function testADocumentedReasonPasses(): void
    {
        $context = $this->repo([
            'js/thing.js' => "// ck-allow-api3 -- Mailing has no APIv4 form yet\nCRM.api3('Mailing', 'get', {});\n",
        ], git: true);
        $reporter = $this->run_(new FrontEndApi3Check(), $context);
        $this->assertPasses($reporter);
        self::assertSame(['js/thing.js uses CRM.api3 with a documented reason'], $reporter->messages('ok'));
    }

    public function testTheAnnotationMayLiveAnywhereInTheFile(): void
    {
        $context = $this->repo([
            'js/thing.js' => "CRM.api3('Mailing', 'get', {});\n\n// ck-allow-api3 --   still no v4 entity\n",
        ], git: true);
        $this->assertPasses($this->run_(new FrontEndApi3Check(), $context));
    }

    public function testAnEmptyReasonIsNotAReason(): void
    {
        $context = $this->repo([
            'js/thing.js' => "// ck-allow-api3 --\nCRM.api3('Mailing', 'get', {});\n",
        ], git: true);
        $this->assertFails($this->run_(new FrontEndApi3Check(), $context), 'calls CRM.api3');
    }

    /**
     * Matched per line, so a reason wrapped onto the following line does not count.
     */
    public function testTheReasonMustBeOnTheAnnotationLine(): void
    {
        $context = $this->repo([
            'js/thing.js' => "// ck-allow-api3 --\n// because Mailing\nCRM.api3('Mailing', 'get');\n",
        ], git: true);
        $this->assertFails($this->run_(new FrontEndApi3Check(), $context));
    }

    public function testVendoredAndGeneratedFilesAreSkipped(): void
    {
        $context = $this->repo([
            'node_modules/pkg/index.js' => 'CRM.api3("Contact", "get");',
            'js/dist/bundle.js' => 'CRM.api3("Contact", "get");',
            'js/thing.min.js' => 'CRM.api3("Contact", "get");',
        ], git: true);
        $this->assertSilent($this->run_(new FrontEndApi3Check(), $context));
    }

    /**
     * The exclusion requires both slashes of '/dist/', so a top-level dist/ is
     * still checked.
     */
    public function testATopLevelDistDirectoryIsStillChecked(): void
    {
        $context = $this->repo(['dist/bundle.js' => 'CRM.api3("Contact", "get");'], git: true);
        $this->assertFails($this->run_(new FrontEndApi3Check(), $context), 'dist/bundle.js calls CRM.api3');
    }

    public function testUntrackedFilesAreIgnored(): void
    {
        $context = $this->repo(['js/tracked.js' => 'var x = 1;'], git: true);
        file_put_contents($context->path('js/scratch.js'), 'CRM.api3("Contact", "get");');
        $this->assertSilent($this->run_(new FrontEndApi3Check(), $context));
    }

    /** crmApi is core's Angular wrapper around CRM.api3 (ang/crmUtil.js). */
    public function testTheAngularCrmApiServiceIsApi3(): void
    {
        $context = $this->repo(['ang/myext/Ctrl.js' => "crmApi('Contact', 'get', {}).then(done);\n"], git: true);
        $this->assertFails($this->run_(new FrontEndApi3Check(), $context), 'ang/myext/Ctrl.js calls crmApi');
    }

    public function testTypescriptAndJsxFilesAreScanned(): void
    {
        $context = $this->repo([
            'src/client.ts' => "CRM.api3('Contact', 'get', {});\n",
            'src/Widget.jsx' => "CRM.api3('Contact', 'get', {});\n",
            'src/Panel.tsx' => "CRM.api3('Contact', 'get', {});\n",
        ], git: true);
        self::assertCount(3, $this->run_(new FrontEndApi3Check(), $context)->messages('FAIL'));
    }

    public function testApi4CallsAndLookalikesAreSilent(): void
    {
        $context = $this->repo([
            'ang/myext/Ctrl.js' => "angular.module('myext').controller('Ctrl', function(\$scope, crmApi4) {\n  crmApi4('Contact', 'get', {});\n  myCrmApi('x');\n});\n",
            'src/client.ts' => "CRM.api4('Contact', 'get', {});\n",
        ], git: true);
        $this->assertSilent($this->run_(new FrontEndApi3Check(), $context));
    }
}
