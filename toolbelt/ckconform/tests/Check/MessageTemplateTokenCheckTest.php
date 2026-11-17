<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\MessageTemplateTokenCheck;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class MessageTemplateTokenCheckTest extends CheckTestCase
{
    public function testSaysNothingForCoreTokensOnly(): void
    {
        $context = $this->myextRepo([
            'msg_templates/welcome_html.tpl' => '<p>Hallo {contact.first_name}, {domain.name}</p>',
        ]);
        $this->assertSilent($this->run_(new MessageTemplateTokenCheck(), $context));
    }

    public function testFilterSyntaxIsNormalAndNeverReported(): void
    {
        $context = $this->myextRepo([
            'msg_templates/welcome_html.tpl' => '{contact.first_name} {contact.is_deceased|boolean} {mailing.name}',
        ]);
        $this->assertSilent($this->run_(new MessageTemplateTokenCheck(), $context));
    }

    public function testSaysNothingWhenTheExtensionRegistersItsOwnTokens(): void
    {
        $context = $this->myextRepo([
            'myext.php' => "<?php\nfunction myext_civicrm_tokens(&\$tokens) {\n  \$tokens['myext'] = ['myext.rufname' => 'Rufname'];\n}\n",
            'msg_templates/welcome_html.tpl' => '<p>Hallo {myext.rufname}</p>',
        ]);
        $this->assertSilent($this->run_(new MessageTemplateTokenCheck(), $context));
    }

    public function testATokenProcessorSubscriberCountsAsRegistration(): void
    {
        $context = $this->myextRepo([
            'Civi/Myext/TokenSubscriber.php' => "<?php\nclass TokenSubscriber extends \\Civi\\Token\\AbstractTokenSubscriber {\n  public function evaluateToken(\$e, \$field, \$row) {}\n}\n",
            'msg_templates/welcome_html.tpl' => '<p>Hallo {myext.rufname}</p>',
        ]);
        $this->assertSilent($this->run_(new MessageTemplateTokenCheck(), $context));
    }

    public function testFailsForAnOwnNamespaceWithNoRegistrationAtAll(): void
    {
        $context = $this->myextRepo([
            'msg_templates/welcome_html.tpl' => '<p>Hallo {myext.rufname}</p>',
        ]);
        $this->assertFails(
            $this->run_(new MessageTemplateTokenCheck(), $context),
            "msg_templates/welcome_html.tpl: token namespace 'myext.' is used but this extension registers no tokens",
        );
    }

    public function testWarnsForAForeignNamespaceBecauseADependencyMayProvideIt(): void
    {
        $context = $this->myextRepo([
            'msg_templates/welcome_html.tpl' => '<p>{sepa.mandate_reference}</p>',
        ]);
        $reporter = $this->run_(new MessageTemplateTokenCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, "fine if a dependency provides 'sepa.'");
    }

    /**
     * Core overwrites unknown contact.* tokens with the empty string, so the
     * value is silently blank.
     */
    public function testWarnsAboutOwnTokensRegisteredUnderTheContactNamespace(): void
    {
        $context = $this->myextRepo([
            'myext.php' => "<?php\nfunction myext_civicrm_tokens(&\$tokens) {\n  \$tokens['contact']['myext_rufname'] = 'Rufname';\n}\n",
        ]);
        $reporter = $this->run_(new MessageTemplateTokenCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns(
            $reporter,
            "myext.php: registers 'myext_rufname' under the contact.* namespace",
        );
    }

    public function testACoreLookingContactTokenKeyIsNotSecondGuessed(): void
    {
        $context = $this->myextRepo([
            'myext.php' => "<?php\nfunction myext_civicrm_tokens(&\$tokens) {\n  \$tokens['contact']['custom_17'] = 'Something';\n}\n",
        ]);
        $this->assertSilent($this->run_(new MessageTemplateTokenCheck(), $context));
    }

    public function testAMgdMessageTemplateIsScannedForTokens(): void
    {
        $context = $this->myextRepo([
            'managed/MessageTemplate.mgd.php' => "<?php\nreturn [['values' => ['msg_html' => '<p>{myext.rufname}</p>']]];\n",
        ]);
        $this->assertFails($this->run_(new MessageTemplateTokenCheck(), $context), 'managed/MessageTemplate.mgd.php');
    }

    public function testAMgdFileWithoutAMessageBodyIsNotAMailTemplate(): void
    {
        $context = $this->myextRepo([
            'managed/SavedSearch.mgd.php' => "<?php\nreturn [['values' => ['name' => '{myext.rufname}']]];\n",
        ]);
        $this->assertSilent($this->run_(new MessageTemplateTokenCheck(), $context));
    }

    public function testSmartyControlTagsAreNotTokens(): void
    {
        $context = $this->myextRepo([
            'templates/CRM/Myext/Page.tpl' => "{if \$foo}{ts}Hi{/ts}{/if}\n{crmURL p='civicrm/myext/thing'}\n",
        ]);
        $this->assertSilent($this->run_(new MessageTemplateTokenCheck(), $context));
    }

    public function testUntrackedTemplatesDoNotDecideTheVerdict(): void
    {
        $context = $this->myextRepo(['README.md' => 'hi']);
        mkdir($context->path('msg_templates'));
        file_put_contents($context->path('msg_templates/x.tpl'), '{myext.rufname}');
        $this->assertSilent($this->run_(new MessageTemplateTokenCheck(), $context));
    }

    /**
     * A git repo whose extension shortname is 'myext', so "does this namespace
     * belong to the extension itself" has something to resolve against.
     *
     * @param array<string, string> $files
     */
    private function myextRepo(array $files): Context
    {
        $files['info.xml'] ??= $this->infoXml(key: 'de.example.myext');

        return $this->repo($files, git: true);
    }

    public function testAnOwnNamespaceInAMessageTemplateStillFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'myext'),
            'msg_templates/myext_receipt.html' => '<p>{myext.amount}</p>',
        ], git: true);
        $this->assertFails($this->run_(new MessageTemplateTokenCheck(), $context), "'myext.' is this extension's own namespace");
    }

    /** Page templates are no message templates, and `${x.y}` is a JavaScript interpolation. */
    public function testPageTemplatesAndJavascriptLiteralsAreNoTokens(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'myext'),
            'templates/CRM/Myext/Page/List.tpl' => "{literal}<script>\nrows.map(r => `<p>\${r.display_name}</p>`);\nalert(`Error: \${e.error_message}`);\n</script>{/literal}\n<p>{x.y}</p>\n",
            'msg_templates/myext_note.html' => "<script>alert(`\${e.error_message}`)</script>",
        ], git: true);
        $this->assertSilent($this->run_(new MessageTemplateTokenCheck(), $context));
    }

    public function testCoreSiteAndGroupTokensAreCore(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'myext'),
            'msg_templates/myext_welcome.html' => '{site.message_header}<p>{group.title} {survey.title} {financial_trxn.trxn_id} {contribution_product.product_id}</p>',
        ], git: true);
        $this->assertSilent($this->run_(new MessageTemplateTokenCheck(), $context));
    }

    /** A one-letter namespace that happens to occur in the short name is no evidence of ownership. */
    public function testAShortNamespaceInsideTheShortNameIsForeign(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'myext'),
            'msg_templates/myext_note.html' => '<p>{e.amount}</p>',
        ], git: true);
        $reporter = $this->run_(new MessageTemplateTokenCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, "token namespace 'e.'");
    }

    /** Ownership is the short name itself or its `<shortname>_` prefix, never a substring. */
    public function testANamespaceMerelyContainingTheShortNameIsForeign(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'myext'),
            'msg_templates/myext_note.html' => '<p>{notmyext.amount}</p>',
        ], git: true);
        $reporter = $this->run_(new MessageTemplateTokenCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, "token namespace 'notmyext.'");

        $prefixed = $this->repo([
            'info.xml' => $this->infoXml(key: 'myext'),
            'msg_templates/myext_note.html' => '<p>{myext_receipt.amount}</p>',
        ], git: true);
        $this->assertFails($this->run_(new MessageTemplateTokenCheck(), $prefixed), "'myext_receipt.' is this extension's own namespace");
    }

    /** CRM_Utils_Token's mailing-group namespaces. */
    public function testCoreSubscriptionTokensAreCore(): void
    {
        $context = $this->myextRepo([
            'msg_templates/welcome_html.tpl' => '{welcome.group} {subscribe.group} {unsubscribe.group} {resubscribe.group}',
        ]);
        $this->assertSilent($this->run_(new MessageTemplateTokenCheck(), $context));
    }

    public function testAngularInterpolationIsNoToken(): void
    {
        $context = $this->myextRepo([
            'msg_templates/preview.html' => '<td>{{row.name}}</td><td>{{ myext.amount }}</td>',
        ]);
        $this->assertSilent($this->run_(new MessageTemplateTokenCheck(), $context));
    }
}
