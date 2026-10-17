<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\CrmScopeCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class CrmScopeCheckTest extends CheckTestCase
{
    public function testAWrappedTsIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => <<<'TPL'
                {crmScope extensionKey='de.example.greeter'}
                  <h1>{ts}Hello{/ts}</h1>
                  <p>{ts}Goodbye{/ts}</p>
                {/crmScope}
                TPL,
        ], git: true);
        $this->assertSilent($this->run_(new CrmScopeCheck(), $context));
    }

    /**
     * smarty_block_ts fills the domain from {crmScope} only when the tag has
     * none of its own — an explicit domain attribute is self-scoping.
     */
    public function testTsWithItsOwnDomainAttributeNeedsNoWrapper(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => <<<'TPL'
                <h1>{ts domain="de.example.greeter"}Hello{/ts}</h1>
                <p>{ts domain='de.example.greeter'}Goodbye{/ts}</p>
                TPL,
        ], git: true);
        $this->assertSilent($this->run_(new CrmScopeCheck(), $context));
    }

    /**
     * ... and because the explicit attribute WINS over the wrapper, a foreign
     * domain is a finding even inside a correct {crmScope}.
     */
    public function testAForeignDomainAttributeFailsEvenInsideTheWrapper(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => <<<'TPL'
                {crmScope extensionKey='de.example.greeter'}
                  <h1>{ts domain="de.example.other"}Hello{/ts}</h1>
                {/crmScope}
                TPL,
        ], git: true);
        $this->assertFails(
            $this->run_(new CrmScopeCheck(), $context),
            'de.example.other'
        );
    }

    public function testAVariableDomainAttributeIsAccepted(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => "<h1>{ts domain=\$domain}Hello{/ts}</h1>\n",
        ], git: true);
        $this->assertSilent($this->run_(new CrmScopeCheck(), $context));
    }

    public function testAnUnwrappedTsFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => "<h1>{ts}Hello{/ts}</h1>\n",
        ], git: true);
        $this->assertFails(
            $this->run_(new CrmScopeCheck(), $context),
            "templates/CRM/Greeter/Page/Foo.tpl: line 1: no {crmScope extensionKey='de.example.greeter'}"
        );
    }

    /** The sighting that motivated the rule: a plausible but foreign key. */
    public function testAForeignExtensionKeyFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => <<<'TPL'
                {crmScope extensionKey='de.example.widget'}
                  {ts}Hello{/ts}
                {/crmScope}
                TPL,
        ], git: true);
        $this->assertFails(
            $this->run_(new CrmScopeCheck(), $context),
            "names 'de.example.widget', not this extension's key 'de.example.greeter'"
        );
    }

    public function testACrmScopeWithoutAnExtensionKeyFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => "{crmScope}{ts}Hello{/ts}{/crmScope}\n",
        ], git: true);
        $this->assertFails(
            $this->run_(new CrmScopeCheck(), $context),
            'names no extensionKey'
        );
    }

    public function testATsBeforeTheWrapperFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => <<<'TPL'
                <h1>{ts}Title{/ts}</h1>
                {crmScope extensionKey='de.example.greeter'}
                  {ts}Body{/ts}
                {/crmScope}
                TPL,
        ], git: true);
        $this->assertFails($this->run_(new CrmScopeCheck(), $context), 'line 1');
    }

    public function testATsAfterTheWrapperFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => <<<'TPL'
                {crmScope extensionKey='de.example.greeter'}
                  {ts}Body{/ts}
                {/crmScope}
                <p>{ts}Footer{/ts}</p>
                TPL,
        ], git: true);
        $this->assertFails($this->run_(new CrmScopeCheck(), $context), 'line 4');
    }

    /** One missing wrapper is one finding, however many strings it covers. */
    public function testRepeatedFindingsCollapseIntoOneMessage(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => "{ts}One{/ts}\n{ts}Two{/ts}\n{ts}Three{/ts}\n",
        ], git: true);
        $reporter = $this->run_(new CrmScopeCheck(), $context);
        self::assertCount(1, $reporter->messages('FAIL'));
        $this->assertFails($reporter, 'lines 1, 2, 3');
    }

    public function testTsWithArgumentsIsRecognised(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => "{ts 1=\$name}Hello %1{/ts}\n",
        ], git: true);
        $this->assertFails($this->run_(new CrmScopeCheck(), $context));
    }

    /** A variable key cannot be judged statically, so it is not guessed at. */
    public function testAVariableExtensionKeyIsAccepted(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => "{crmScope extensionKey=\$extKey}{ts}Hello{/ts}{/crmScope}\n",
        ], git: true);
        $this->assertSilent($this->run_(new CrmScopeCheck(), $context));
    }

    public function testNestedScopesUseTheInnermost(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => <<<'TPL'
                {crmScope extensionKey='de.example.greeter'}
                  {crmScope extensionKey='de.example.widget'}
                    {ts}Borrowed{/ts}
                  {/crmScope}
                  {ts}Own{/ts}
                {/crmScope}
                TPL,
        ], git: true);
        $reporter = $this->run_(new CrmScopeCheck(), $context);
        $this->assertFails($reporter, 'line 3');
        self::assertCount(1, $reporter->messages('FAIL'));
    }

    public function testTsInsideALiteralBlockIsIgnored(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => "{literal}<script>var x = '{ts}';</script>{/literal}\n",
        ], git: true);
        $this->assertSilent($this->run_(new CrmScopeCheck(), $context));
    }

    public function testTsInsideASmartyCommentIsIgnored(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => "{* {ts}old copy{/ts} *}\n",
        ], git: true);
        $this->assertSilent($this->run_(new CrmScopeCheck(), $context));
    }

    /** Line numbers survive the blanking of comments. */
    public function testLineNumbersSurviveMultiLineComments(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => "{*\n  a comment\n*}\n{ts}Hello{/ts}\n",
        ], git: true);
        $this->assertFails($this->run_(new CrmScopeCheck(), $context), 'line 4');
    }

    public function testAMessageTemplateBodyInMgdPhpFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'managed/Welcome.mgd.php' => <<<'PHP'
                <?php
                return [
                  [
                    'name' => 'welcome',
                    'entity' => 'MessageTemplate',
                    'values' => [
                      'msg_subject' => '{ts}Welcome{/ts}',
                      'msg_text' => '{ts}Glad you are here.{/ts}',
                    ],
                  ],
                ];
                PHP,
        ], git: true);
        $this->assertFails($this->run_(new CrmScopeCheck(), $context), 'managed/Welcome.mgd.php');
    }

    public function testAWrappedMessageTemplateBodyIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'managed/Welcome.mgd.php' => <<<'PHP'
                <?php
                return [
                  [
                    'name' => 'welcome',
                    'entity' => 'MessageTemplate',
                    'values' => [
                      'msg_text' => "{crmScope extensionKey='de.example.greeter'}{ts}Welcome{/ts}{/crmScope}",
                    ],
                  ],
                ];
                PHP,
        ], git: true);
        $this->assertSilent($this->run_(new CrmScopeCheck(), $context));
    }

    /** A docblock that names the tags is prose, not a template body. */
    public function testTagsNamedInAPhpCommentAreIgnored(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'managed/Welcome.mgd.php' => <<<'PHP'
                <?php
                /**
                 * Strings are English inside {crmScope}{ts}, translated through l10n/.
                 */
                // A bare {ts} would resolve in core's domain.
                return [
                  [
                    'values' => [
                      'msg_text' => "{crmScope extensionKey='de.example.greeter'}{ts}Welcome{/ts}{/crmScope}",
                    ],
                  ],
                ];
                PHP,
        ], git: true);
        $this->assertSilent($this->run_(new CrmScopeCheck(), $context));
    }

    /** In a PHP string literal the wrapper's own quotes arrive escaped. */
    public function testAnEscapedQuotedExtensionKeyInMgdPhpIsAccepted(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'managed/Welcome.mgd.php' => "<?php\nreturn [['values' => ["
                . "'msg_text' => '{crmScope extensionKey=\\'de.example.greeter\\'}{ts}Welcome{/ts}{/crmScope}'"
                . "]]];\n",
        ], git: true);
        $this->assertSilent($this->run_(new CrmScopeCheck(), $context));
    }

    public function testTemplatesUnderTestsAreIgnored(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'tests/fixtures/Foo.tpl' => "{ts}Hello{/ts}\n",
        ], git: true);
        $this->assertSilent($this->run_(new CrmScopeCheck(), $context));
    }

    public function testAnExtensionWithoutTemplatesIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => "<?php\nnamespace Civi\\Greeter;\nclass Thing {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new CrmScopeCheck(), $context));
    }

    public function testWithoutAKeyAttributeThereIsNothingToCompareAgainst(): void
    {
        // Only info.xml's key attribute names the extension; <file> is the
        // main-file base name and would demand a wrong wrapper value.
        $context = $this->repo([
            'info.xml' => <<<'XML'
                <?xml version="1.0"?>
                <extension type="module">
                  <file>greeter</file>
                  <name>Fixture</name>
                </extension>
                XML,
            'templates/CRM/Greeter/Page/Foo.tpl' => "<h1>{ts}Hello{/ts}</h1>\n",
        ], git: true);
        $this->assertSilent($this->run_(new CrmScopeCheck(), $context));
    }
}
