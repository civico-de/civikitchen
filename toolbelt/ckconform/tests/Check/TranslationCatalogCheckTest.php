<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\TranslationCatalogCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class TranslationCatalogCheckTest extends CheckTestCase
{
    /** Shipping no translations at all is not a defect. */
    public function testAnExtensionWithoutL10nIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello')"),
        ], git: true);
        $this->assertSilent($this->run_(new TranslationCatalogCheck(), $context));
    }

    public function testACompiledCatalogThatMatchesIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertSilent($this->run_(new TranslationCatalogCheck(), $context));
    }

    /** Message-template bodies in .mgd.php are Smarty — their {ts} counts. */
    public function testAMgdMessageTemplateStringIsWarnedWhenMissing(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'managed/MessageTemplate.mgd.php' => "<?php\nreturn [['params' => ['values' => ['msg_html' => '{crmScope extensionKey=\'de.example.greeter\'}{ts}Donation received{/ts}{/crmScope}']]]];\n",
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $reporter = $this->run_(new TranslationCatalogCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'Donation received');
    }

    /** CRM_Core_I18n only ever looks under l10n/<locale>/LC_MESSAGES/. */
    public function testACatalogOutsideLcMessagesFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello')"),
            'l10n/de_DE/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertFails(
            $this->run_(new TranslationCatalogCheck(), $context),
            'LC_MESSAGES'
        );
    }

    public function testAPoWithoutAMoFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
        ], git: true);
        $this->assertFails(
            $this->run_(new TranslationCatalogCheck(), $context),
            'no compiled l10n/de_DE/LC_MESSAGES/greeter.mo'
        );
    }

    /** The point of the whole check: content, not mtime. */
    public function testAMoMissingATranslatedStringFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello'); E::ts('Goodbye')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo'], ['Goodbye', 'Tschüss']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertFails(
            $this->run_(new TranslationCatalogCheck(), $context),
            "1 translated string(s) from greeter.po are missing from it ('Goodbye')"
        );
    }

    /** A .po edited after the last msgfmt run: same msgid, older text in the .mo. */
    public function testAMoWithAnOutdatedTranslationFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello'); E::ts('Goodbye')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo'], ['Goodbye', 'Tschüss']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Servus', 'Goodbye' => 'Tschüss']),
        ], git: true);
        $this->assertFails(
            $this->run_(new TranslationCatalogCheck(), $context),
            "1 string(s) carry another translation than in greeter.po ('Hello')"
        );
    }

    /** Only plain entries are compared by text; a context entry has its own translation. */
    public function testAContextEntryIsNotComparedWithThePlainOne(): void
    {
        $po = $this->po([['Hello', 'Hallo']]) . "\nmsgctxt \"phone\"\nmsgid \"Hello\"\nmsgstr \"Hallo?\"\n";
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $po,
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo', "phone\x04Hello" => 'Hallo?']),
        ], git: true);
        $this->assertSilent($this->run_(new TranslationCatalogCheck(), $context));
    }

    public function testACompleteCatalogUnderTheCompletePolicyIsSilent(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'civikitchen.yaml' => $this->policyFixture('translations_complete=true'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello')"),
            'l10n/greeter.pot' => $this->po([['Hello', '']]),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertSilent($this->run_(new TranslationCatalogCheck(), $context));
    }

    public function testTheCompletePolicyFailsOnAStringMissingFromTheCatalog(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'civikitchen.yaml' => $this->policyFixture('translations_complete=true'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello'); E::ts('Goodbye')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertFails($this->run_(new TranslationCatalogCheck(), $context), "appear in it as no msgid ('Goodbye')");
    }

    public function testTheCompletePolicyFailsOnAnUntranslatedEntry(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'civikitchen.yaml' => $this->policyFixture('translations_complete=true'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello'); E::ts('Goodbye')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo'], ['Goodbye', '']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertFails($this->run_(new TranslationCatalogCheck(), $context), "1 entr(y/ies) without a translation or marked fuzzy ('Goodbye')");
    }

    public function testTheCompletePolicyFailsOnAFuzzyEntry(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'civikitchen.yaml' => $this->policyFixture('translations_complete=true'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello'); E::ts('Goodbye')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo'], ['Goodbye', 'Tschüss']], fuzzy: 2),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertFails($this->run_(new TranslationCatalogCheck(), $context), "('Goodbye')");
    }

    public function testTheCompletePolicyFailsOnAnEmptyPluralForm(): void
    {
        $po = <<<'PO'
            msgid "One item"
            msgid_plural "%1 items"
            msgstr[0] "Ein Eintrag"
            msgstr[1] ""
            PO;
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'civikitchen.yaml' => $this->policyFixture('translations_complete=true'),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $po,
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(["One item\0%1 items" => "Ein Eintrag\0"]),
        ], git: true);
        $this->assertFails($this->run_(new TranslationCatalogCheck(), $context), "('One item')");
    }

    public function testTheCompletePolicyFailsOnAnUntranslatedContextBesideATranslatedOne(): void
    {
        $po = <<<'PO'
            msgid "Open"
            msgstr "Öffnen"

            msgctxt "toolbar"
            msgid "Open"
            msgstr ""
            PO;
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'civikitchen.yaml' => $this->policyFixture('translations_complete=true'),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $po,
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Open' => 'Öffnen']),
        ], git: true);
        $this->assertFails($this->run_(new TranslationCatalogCheck(), $context), "('Open')");
    }

    public function testAPartlyTranslatedPluralMustStillBeInTheMo(): void
    {
        $po = <<<'PO'
            msgid "One item"
            msgid_plural "%1 items"
            msgstr[0] "Ein Eintrag"
            msgstr[1] ""
            PO;
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $po,
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo([]),
        ], git: true);
        $this->assertFails($this->run_(new TranslationCatalogCheck(), $context), 'missing from it');
    }

    public function testAMoWithAnOutdatedContextOrPluralTranslationFails(): void
    {
        $po = <<<'PO'
            msgctxt "toolbar"
            msgid "Open"
            msgstr "Öffnen"

            msgid "One item"
            msgid_plural "%1 items"
            msgstr[0] "Ein Eintrag"
            msgstr[1] "%1 Einträge"
            PO;
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $po,
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo([
                "toolbar\x04Open" => 'Aufmachen',
                "One item\0%1 items" => "Ein Ding\0%1 Dinge",
            ]),
        ], git: true);
        $this->assertFails(
            $this->run_(new TranslationCatalogCheck(), $context),
            "2 string(s) carry another translation than in greeter.po ('One item', 'Open')",
        );
    }

    public function testTheCompletePolicyFailsOnAMissingPluralForm(): void
    {
        $po = <<<'PO'
            msgid ""
            msgstr "Plural-Forms: nplurals=2; plural=(n != 1);\n"

            msgid "One item"
            msgid_plural "%1 items"
            msgstr[0] "Ein Eintrag"
            PO;
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'civikitchen.yaml' => $this->policyFixture('translations_complete=true'),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $po,
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(["One item\0%1 items" => 'Ein Eintrag']),
        ], git: true);
        $this->assertFails($this->run_(new TranslationCatalogCheck(), $context), "('One item')");
    }

    public function testALanguageWithOnePluralFormNeedsOneForm(): void
    {
        $po = <<<'PO'
            msgid ""
            msgstr "Plural-Forms: nplurals=1; plural=0;\n"

            msgid "One item"
            msgid_plural "%1 items"
            msgstr[0] "%1 件"
            PO;
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'civikitchen.yaml' => $this->policyFixture('translations_complete=true'),
            'l10n/ja_JP/LC_MESSAGES/greeter.po' => $po,
            'l10n/ja_JP/LC_MESSAGES/greeter.mo' => $this->mo(["One item\0%1 items" => '%1 件']),
        ], git: true);
        $this->assertSilent($this->run_(new TranslationCatalogCheck(), $context));
    }

    /** msgfmt does not compile what has no translation yet. */
    public function testAnUntranslatedEntryNeedNotBeInTheMo(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello'); E::ts('Goodbye')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo'], ['Goodbye', '']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertSilent($this->run_(new TranslationCatalogCheck(), $context));
    }

    /** Nor what a translator flagged as unreviewed. */
    public function testAFuzzyEntryNeedNotBeInTheMo(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello'); E::ts('Goodbye')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo'], ['Goodbye', 'Tschüss']], fuzzy: 2),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertSilent($this->run_(new TranslationCatalogCheck(), $context));
    }

    public function testAnObsoleteEntryNeedNotBeInTheMo(): void
    {
        $po = $this->po([['Hello', 'Hallo']]) . "\n#~ msgid \"Removed\"\n#~ msgstr \"Entfernt\"\n";
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $po,
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertSilent($this->run_(new TranslationCatalogCheck(), $context));
    }

    public function testAnUnreadableMoFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => "this is not a gettext catalog at all\n",
        ], git: true);
        $this->assertFails(
            $this->run_(new TranslationCatalogCheck(), $context),
            'not a readable gettext catalog'
        );
    }

    /** A half-written catalog is a diagnosis, not a PHP warning. */
    public function testATruncatedMoFails(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => substr($this->mo(['Hello' => 'Hallo']), 0, 30),
        ], git: true);
        $this->assertFails(
            $this->run_(new TranslationCatalogCheck(), $context),
            'not a readable gettext catalog'
        );
    }

    public function testABigEndianMoIsReadToo(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo'], bigEndian: true),
        ], git: true);
        $this->assertSilent($this->run_(new TranslationCatalogCheck(), $context));
    }

    /** A .pot is the extraction template — there is nothing to compile. */
    public function testAPotNeedsNoMo(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello')"),
            'l10n/greeter.pot' => $this->po([['Hello', '']]),
        ], git: true);
        $this->assertSilent($this->run_(new TranslationCatalogCheck(), $context));
    }

    public function testAMsgidSplitOverSeveralLinesIsAssembled(): void
    {
        $po = <<<'PO'
            msgid ""
            msgstr "Content-Type: text/plain; charset=UTF-8\n"

            msgid ""
            "Hello "
            "world"
            msgstr "Hallo Welt"
            PO;
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello world')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $po,
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello world' => 'Hallo Welt']),
        ], git: true);
        $this->assertSilent($this->run_(new TranslationCatalogCheck(), $context));
    }

    /** msgctxt and plural entries are stored joined in the .mo. */
    public function testContextAndPluralEntriesAreMatched(): void
    {
        $po = <<<'PO'
            msgctxt "toolbar"
            msgid "Open"
            msgstr "Öffnen"

            msgid "One item"
            msgid_plural "%1 items"
            msgstr[0] "Ein Eintrag"
            msgstr[1] "%1 Einträge"
            PO;
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $po,
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo([
                "toolbar\x04Open" => 'Öffnen',
                "One item\0%1 items" => "Ein Eintrag\0%1 Einträge",
            ]),
        ], git: true);
        $this->assertSilent($this->run_(new TranslationCatalogCheck(), $context));
    }

    public function testAStringInNoCatalogWarns(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello'); E::ts('Brand new')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $reporter = $this->run_(new TranslationCatalogCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, "1 string(s) passed to E::ts()/{ts} appear in it as no msgid ('Brand new')");
    }

    public function testASmartyTsStringInNoCatalogWarns(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => "{crmScope extensionKey='de.example.greeter'}"
                . "{ts}Brand new{/ts}{/crmScope}\n",
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $reporter = $this->run_(new TranslationCatalogCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, "'Brand new'");
    }

    /** A body full of Smarty tags is not a fixed string; it is not judged. */
    public function testASmartyTsBodyWithTagsIsNotJudged(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'templates/CRM/Greeter/Page/Foo.tpl' => "{crmScope extensionKey='de.example.greeter'}"
                . "{ts}Hi {\$name}{/ts}{/crmScope}\n",
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertSilent($this->run_(new TranslationCatalogCheck(), $context));
    }

    public function testADynamicFirstArgumentWarns(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php('E::ts($label)'),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $reporter = $this->run_(new TranslationCatalogCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'non-literal first argument');
    }

    /** An interpolated double-quoted string is a runtime value, not a msgid. */
    public function testAnInterpolatedFirstArgumentWarns(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php('E::ts("Hello $name")'),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertWarns($this->run_(new TranslationCatalogCheck(), $context), 'non-literal first argument');
    }

    public function testTheCivixExtensionUtilAliasIsRecognised(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("CRM_Greeter_ExtensionUtil::ts('Brand new')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertWarns($this->run_(new TranslationCatalogCheck(), $context), "'Brand new'");
    }

    /** Test fixtures are not shipped UI copy. */
    public function testStringsInTestsAreNotJudged(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'tests/phpunit/ThingTest.php' => $this->php("E::ts('Never shipped')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertSilent($this->run_(new TranslationCatalogCheck(), $context));
    }

    /** Every catalog is stale on its own account. */
    public function testEachCatalogIsJudgedSeparately(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Brand new')"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
            'l10n/fr_FR/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Bonjour']]),
            'l10n/fr_FR/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Bonjour']),
        ], git: true);
        $reporter = $this->run_(new TranslationCatalogCheck(), $context);
        $this->assertPasses($reporter);
        self::assertCount(2, $reporter->messages('warn'));
    }

    private function php(string $body): string
    {
        return "<?php\n\nnamespace Civi\\Greeter;\n\nuse CRM_Greeter_ExtensionUtil as E;\n\n"
            . "class Thing\n{\n    public function label()\n    {\n        return $body;\n    }\n}\n";
    }

    /**
     * @param list<array{0: string, 1: string}> $entries msgid, msgstr
     * @param int                               $fuzzy   1-based index of the entry to flag fuzzy, 0 for none
     */
    private function po(array $entries, int $fuzzy = 0): string
    {
        $blocks = ["msgid \"\"\nmsgstr \"Content-Type: text/plain; charset=UTF-8\\n\""];
        foreach ($entries as $index => [$msgid, $msgstr]) {
            $block = $index + 1 === $fuzzy ? "#, fuzzy\n" : '';
            $blocks[] = $block . "msgid \"$msgid\"\nmsgstr \"$msgstr\"";
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * A real gettext binary catalog: header, an original and a translation
     * table of (length, offset) pairs, then the NUL-terminated strings.
     *
     * @param array<string, string> $entries
     */
    private function mo(array $entries, bool $bigEndian = false): string
    {
        $format = $bigEndian ? 'N' : 'V';
        $magic = $bigEndian ? "\x95\x04\x12\xde" : "\xde\x12\x04\x95";
        $keys = array_keys($entries);
        sort($keys);
        $count = count($keys);
        $originalTable = 28;
        $translationTable = $originalTable + $count * 8;
        $offset = $translationTable + $count * 8;

        $originals = '';
        $translations = '';
        $blob = '';
        foreach ($keys as $key) {
            $originals .= pack($format . '2', strlen($key), $offset);
            $blob .= $key . "\0";
            $offset += strlen($key) + 1;
        }
        foreach ($keys as $key) {
            $value = $entries[$key];
            $translations .= pack($format . '2', strlen($value), $offset);
            $blob .= $value . "\0";
            $offset += strlen($value) + 1;
        }

        $header = $magic
            . pack($format . '4', 0, $count, $originalTable, $translationTable)
            . pack($format . '2', 0, $offset);

        return $header . $originals . $translations . $blob;
    }

    /** @return iterable<string, array{string}> */
    public static function literalCalls(): iterable
    {
        yield 'comment before the string' => ["E::ts(/* ctx */ 'Hello')"];
        yield 'named text argument' => ["E::ts(text: 'Hello')"];
        yield 'named params first' => ["E::ts(params: ['domain' => 'x'], text: 'Hello')"];
        yield 'method in another case' => ["E::TS('Hello')"];
    }

    /** @dataProvider literalCalls */
    public function testALiteralFirstArgumentIsALiteral(string $call): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php($call),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertSilent($this->run_(new TranslationCatalogCheck(), $context));
    }

    /** Near misses: a call in a comment or string is no call at all. */
    public function testACallInACommentOrStringIsNotJudged(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("'E::ts(\$label)' /* E::ts('Brand new') */"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertSilent($this->run_(new TranslationCatalogCheck(), $context));
    }

    public function testAConcatenatedFirstArgumentWarns(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'de.example.greeter'),
            'Civi/Greeter/Thing.php' => $this->php("E::ts('Hello' . \$suffix)"),
            'l10n/de_DE/LC_MESSAGES/greeter.po' => $this->po([['Hello', 'Hallo']]),
            'l10n/de_DE/LC_MESSAGES/greeter.mo' => $this->mo(['Hello' => 'Hallo']),
        ], git: true);
        $this->assertWarns($this->run_(new TranslationCatalogCheck(), $context), 'non-literal first argument');
    }
}
