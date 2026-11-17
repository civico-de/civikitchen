<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\RiverleaCustomPropertyCheck;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Tests\CheckTestCase;
use CiviKitchen\Ckconform\Tests\FakeCoreTrait;

final class RiverleaCustomPropertyCheckTest extends CheckTestCase
{
    use FakeCoreTrait;

    /**
     * @param array<string, string> $files
     */
    private function ext(array $files): Context
    {
        $core = $this->makeCore();
        $riverlea = $core . '/ext/riverlea';
        mkdir($riverlea . '/core/css', 0777, true);
        mkdir($riverlea . '/streams/thames', 0777, true);
        mkdir($riverlea . '/js', 0777, true);
        file_put_contents($riverlea . '/core/css/_variables.css',
            "/* Add '--crm-c-pink' to your stream,\n   then set --crm-c-pink: red. */\n"
            . ":root {\n  --crm-link-color: var(--crm-c-blue-dark);\n  --crm-c-blue-dark: #1b5e8a;\n  --crm-c-ink: #111;\n}\n");
        file_put_contents($riverlea . '/streams/thames/_dark.css', ":root { --crm-c-page-background: #222; }\n");
        file_put_contents($riverlea . '/js/editor.js', "root.style.setProperty('--crm-font-size', size);\n"
            . "/**\n * <civi-riverlea-stream-variable name=\"--crm-c-primary\">\n */\n");

        return $this->repo($files, git: true);
    }

    /**
     * A fallback hides a name Riverlea never had. The suggestion follows the
     * words, not the one-letter neighbour --crm-c-ink.
     */
    public function testAnUnknownNameWithAFallbackFailsWithItsLineAndASuggestion(): void
    {
        $reporter = $this->run_(new RiverleaCustomPropertyCheck(), $this->ext([
            'css/acme.css' => ".acme {\n  font-size: var(--crm-font-size);\n  color: var(--crm-c-link, #1b5e8a);\n}\n",
        ]));
        $this->assertFails($reporter, 'css/acme.css:3: nothing in core or this repository defines --crm-c-link — did you mean --crm-link-color? Its fallback renders');
        $failure = array_values(array_filter($reporter->results(), static fn (array $r): bool => $r['level'] === 'FAIL'));
        self::assertCount(1, $failure);
        self::assertSame(['file' => 'css/acme.css', 'line' => 3], $failure[0]['location'] ?? null);
    }

    /** A name a core comment merely suggests is not defined, and a commented-out reference is not one. */
    public function testCommentsNeitherDefineNorReference(): void
    {
        $reporter = $this->run_(new RiverleaCustomPropertyCheck(), $this->ext([
            'css/acme.css' => "/* color: var(--crm-c-teal); */\n.a { color: var(--crm-c-pink); }\n",
        ]));
        $this->assertFails($reporter, 'defines --crm-c-pink');
        self::assertSame(1, $reporter->failures(), $reporter->render());
    }

    /** Every comment syntax of the scanned file types hides a reference. */
    public function testCommentedOutReferencesInEveryFileTypeAreIgnored(): void
    {
        $this->assertSilent($this->run_(new RiverleaCustomPropertyCheck(), $this->ext([
            'scss/acme.scss' => "// was var(--crm-old-thing)\n.a { color: red; } // var(--crm-old-b)\n",
            'js/acme.js' => "/* var(--crm-old-c) */\nconst a = 1; // var(--crm-old-d)\n",
            'templates/Acme.tpl' => "{* var(--crm-old-e) *}\n<!-- var(--crm-old-f) -->\n<style>/* var(--crm-old-g) */</style>\n",
            'ang/acme.aff.html' => "<!--\n  var(--crm-old-h)\n-->\n",
        ])));
    }

    /** A doc comment in core's editor.js names --crm-c-primary; nothing declares it. */
    public function testANameOnlyACoreCommentMentionsIsUnknown(): void
    {
        $this->assertFails($this->run_(new RiverleaCustomPropertyCheck(), $this->ext([
            'css/acme.css' => ".a { color: var(--crm-c-primary, #000); }\n",
        ])), 'defines --crm-c-primary');
    }

    public function testInterpolatedNamesAreNotRead(): void
    {
        $this->assertSilent($this->run_(new RiverleaCustomPropertyCheck(), $this->ext([
            'scss/acme.scss' => ".a { color: var(--crm-c-#{\$tone}); }\n",
            'js/acme.ts' => "const c = `var(--crm-c-\${tone})`;\n",
            'templates/Acme.tpl' => "<p style=\"color: var(--crm-c-{\$tone})\"></p>\n",
            'less/acme.less' => ".a { color: var(--crm-c-@{tone}); }\n",
        ])));
    }

    public function testATypoIsMatchedToTheNearestName(): void
    {
        $this->assertFails($this->run_(new RiverleaCustomPropertyCheck(), $this->ext([
            'ang/acme.aff.html' => "<div style=\"background: var(--crm-c-page-backgroud)\"></div>\n",
        ])), 'did you mean --crm-c-page-background?');
    }

    public function testAnUnrelatedNameGetsNoSuggestion(): void
    {
        $reporter = $this->run_(new RiverleaCustomPropertyCheck(), $this->ext([
            'templates/Acme.tpl' => "<style>.x { margin: var(--crm-zz); }</style>\n",
        ]));
        $this->assertFails($reporter, 'templates/Acme.tpl:1: nothing in core or this repository defines --crm-zz. Without a fallback the property computes to unset.');
    }

    /** A healthy stylesheet: Riverlea's names, a stream's dark-mode name, a runtime one, and its own. */
    public function testKnownAndOwnNamesPass(): void
    {
        $this->assertOk($this->run_(new RiverleaCustomPropertyCheck(), $this->ext([
            'css/acme.css' => ":root { --crm-acme-accent: var(--crm-link-color); }\n"
                . ".a { color: var(--crm-link-color, #00f); background: var( --crm-c-page-background); }\n"
                . ".b { font-size: var(--crm-font-size); border-color: var(--crm-acme-accent); }\n",
            'js/acme.js' => "el.style.setProperty('--crm-acme-runtime', c);\n"
                . "el.style.setProperty(`--crm-acme-template`, c);\nconst u = 'https://example.org'; // a URL is no comment\n"
                . "const v = 'var(--crm-acme-template)';\n",
            'ang/acme.aff.html' => "<p style=\"color: var(--crm-acme-runtime)\"></p>\n",
            'css/other.css' => ".c { color: var(--acme-own, red); }\n",
        ])), 'every --crm-* custom property');
    }

    public function testBuiltOutputIsNotRead(): void
    {
        $this->assertSilent($this->run_(new RiverleaCustomPropertyCheck(), $this->ext([
            'dist/acme.css' => ".a { color: var(--crm-c-link); }\n",
        ])));
    }

    public function testSilentWithoutRiverlea(): void
    {
        $this->makeCore();
        $context = $this->repo(['css/acme.css' => ".a { color: var(--crm-c-link); }\n"], git: true);
        $this->assertSilent($this->run_(new RiverleaCustomPropertyCheck(), $context));
    }

    public function testSilentWithoutCore(): void
    {
        $context = new Context($this->ext(['css/acme.css' => ".a { color: var(--crm-c-link); }\n"])->root);
        $this->assertSilent($this->run_(new RiverleaCustomPropertyCheck(), $context));
    }

    /** Against a real core the catalogue holds Riverlea's names, so the reported case resolves. */
    public function testRealCoreSuggestsTheLinkColour(): void
    {
        $realCore = getenv('CIVICRM_CORE_DIR') ?: '/var/www/html/core';
        if (!is_dir($realCore . '/ext/riverlea')) {
            self::markTestSkipped("no Riverlea under $realCore (set CIVICRM_CORE_DIR)");
        }
        $context = $this->repo(['css/acme.css' => ".a { color: var(--crm-c-link, #1b5e8a); }\n"
            . ".b { color: var(--crm-link-color); background: var(--crm-c-blue-dark); }\n"], git: true);
        $reporter = $this->run_(new RiverleaCustomPropertyCheck(), new Context($context->root, $realCore));
        $this->assertFails($reporter, 'defines --crm-c-link — did you mean --crm-link-color?');
        self::assertSame(1, $reporter->failures(), $reporter->render());
    }
}
