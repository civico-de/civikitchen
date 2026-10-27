<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\HookDispatchNameCheck;
use CiviKitchen\Ckconform\HookSurface;
use CiviKitchen\Ckconform\Tests\CheckTestCase;
use CiviKitchen\Toolbelt\Filesystem\Directory;

final class HookDispatchNameCheckTest extends CheckTestCase
{
    public function testSilentWithoutPhpFiles(): void
    {
        $context = $this->repo([]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testPassesOnCorrectPrefixAndKnownHook(): void
    {
        // The fixture info.xml uses key/file 'fixture'.
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function fixture_civicrm_config(&$config) {
            }

            function fixture_civicrm_post($op, $objectName, $objectId, &$objectRef) {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    /** postSave_<table> and queueRun_<runner> carry an argument after the hook name. */
    public function testPassesOnParameterisedHooks(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function fixture_civicrm_postSave_civicrm_campaign($dao) {
            }

            function fixture_civicrm_queueRun_task($queue, $items, &$outcomes) {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testFailsOnAParameterisedHookUnderAForeignPrefix(): void
    {
        $context = $this->repo(['fixture.php' => "<?php\nfunction otherext_civicrm_postSave_civicrm_campaign(\$dao) {\n}\n"]);
        $this->assertFails(
            $this->run_(new HookDispatchNameCheck(), $context),
            "fixture.php: otherext_civicrm_postSave_civicrm_campaign() will never fire — the hook prefix of this extension is 'fixture', so the function must be named fixture_civicrm_postSave_civicrm_campaign()",
        );
    }

    /** Core never dispatches the bare name: the table or runner is always appended. */
    public function testFailsOnABareParameterisedHook(): void
    {
        $context = $this->repo(['fixture.php' => "<?php\nfunction fixture_civicrm_postSave(\$dao) {\n}\n"]);
        $this->assertFails(
            $this->run_(new HookDispatchNameCheck(), $context),
            'fixture.php: fixture_civicrm_postSave() will never fire — core dispatches postSave with the table or runner appended: fixture_civicrm_postSave_<name>()',
        );
    }

    /** Hook names match case-insensitively, as function_exists() does. */
    public function testFailsOnABareParameterisedHookInAnyCase(): void
    {
        $context = $this->repo(['fixture.php' => "<?php\nfunction fixture_civicrm_queuerun(\$queue) {\n}\n"]);
        $this->assertFails(
            $this->run_(new HookDispatchNameCheck(), $context),
            'fixture.php: fixture_civicrm_queuerun() will never fire — core dispatches queuerun with the table or runner appended: fixture_civicrm_queuerun_<name>()',
        );
    }

    /**
     * The shared CI checks a sibling extension out into .civikitchen-siblings/;
     * its hooks carry ITS prefix and must never be judged as this repo's —
     * also in fallback (non-git) mode, where files come from a disk walk.
     */
    public function testASiblingCheckoutIsNotThisReposCode(): void
    {
        $context = $this->repo([
            '.civikitchen-siblings/other/other.php' => "<?php\nfunction other_civicrm_config(&\$config) {\n}\n",
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    /**
     * A directory with its own info.xml is another extension, e.g. a test
     * fixture the repo installs beside itself; its hooks carry its prefix.
     */
    public function testANestedExtensionIsNotThisExtensionsCode(): void
    {
        foreach ([false, true] as $git) {
            $context = $this->repo([
                '.docker/fixtures/island/info.xml' => $this->infoXml(key: 'island'),
                '.docker/fixtures/island/island.php' => "<?php\nfunction island_civicrm_config(&\$config) {\n}\n",
            ], $git);
            $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
        }
    }

    public function testADirectoryWithoutItsOwnInfoXmlIsStillThisExtensionsCode(): void
    {
        $context = $this->repo([
            '.docker/fixtures/island/island.php' => "<?php\nfunction island_civicrm_config(&\$config) {\n}\n",
        ]);
        $this->assertFails(
            $this->run_(new HookDispatchNameCheck(), $context),
            "island_civicrm_config() will never fire — the hook prefix of this extension is 'fixture'",
        );
    }

    /**
     * A dependency inside the same repository publishes its hooks like any
     * other: its directory is named freely, so the key decides which neighbour
     * is read.
     */
    public function testReadsASameRepositoryDependencyWhoseDirectoryDiffersFromItsKey(): void
    {
        $context = $this->monorepoExtension(
            [],
            [
                'info.xml' => $this->infoXml(
                    extra: "  <requires>\n    <ext>de.civico.ckmonobase</ext>\n  </requires>",
                ),
                'fixture.php' => "<?php\nfunction fixture_civicrm_monobaseThing(&\$items) {\n}\n",
            ],
            [
                'info.xml' => $this->infoXml(key: 'de.civico.ckmonobase'),
                'base.php' => "<?php\nfunction base_dispatch() {\n"
                    . "    Civi::dispatcher()->dispatch('hook_civicrm_monobaseThing', \$e);\n}\n",
            ],
        );
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testFailsOnForeignPrefix(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function otherext_civicrm_post($op, $objectName, $objectId, &$objectRef) {
            }
            PHP,
        ]);
        $reporter = $this->run_(new HookDispatchNameCheck(), $context);
        $this->assertFails($reporter, 'otherext_civicrm_post() will never fire');
        $this->assertFails($reporter, "fixture_civicrm_post()");
    }

    public function testFailsOnForeignPrefixInNestedFile(): void
    {
        $context = $this->repo(['CRM/Fixture/Extras.php' => <<<'PHP'
            <?php
            function legacyext_civicrm_buildForm($formName, &$form) {
            }
            PHP,
        ]);
        $this->assertFails(
            $this->run_(new HookDispatchNameCheck(), $context),
            'CRM/Fixture/Extras.php',
        );
    }

    public function testWarnsOnUnknownHookSuffix(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function fixture_civicrm_postCommmit($op, $objectName, $objectId, $objectRef) {
            }
            PHP,
        ]);
        $reporter = $this->run_(new HookDispatchNameCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, "unknown hook suffix 'postCommmit'");
    }

    public function testCivixGlueIsExempt(): void
    {
        // civix generates dispatch glue under its own naming; renaming it is not
        // the extension author's job.
        $context = $this->repo(['fixture.civix.php' => <<<'PHP'
            <?php
            function _fixturehelper_civicrm_setThing() {
            }

            function civixgenerated_civicrm_managed(&$entities) {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testClassMethodsAreExempt(): void
    {
        // A method named like a hook is not a hook — depth tracking must not
        // mistake it for a global function.
        $context = $this->repo(['CRM/Fixture/Hooks.php' => <<<'PHP'
            <?php
            class CRM_Fixture_Hooks {
              public function otherext_civicrm_post($op) {
                $label = "brace {$op} inside a string";
                $fn = function ($x) { return $x; };
                return $label . $fn($op);
              }
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testUnderscorePrefixedHelpersAreExempt(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function _otherext_civicrm_helper() {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testTestDirectoryIsExempt(): void
    {
        $context = $this->repo(['tests/phpunit/FakeHookTest.php' => <<<'PHP'
            <?php
            function otherext_civicrm_post($op) {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testMainFileOnDiskWinsOverDriftedInfoXml(): void
    {
        // info.xml says 'stale', but foo.php + foo.civix.php are what CiviCRM
        // loads, so hooks named foo_* do fire and must not be reported.
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'stale'),
            'foo.php' => <<<'PHP'
                <?php
                function foo_civicrm_config(&$config) {
                }
                PHP,
            'foo.civix.php' => "<?php\n",
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testUsesTrackedFilesOnlyInAGitRepo(): void
    {
        $context = $this->repo(['fixture.php' => "<?php\n"], git: true);
        file_put_contents($context->path('untracked.php'), <<<'PHP'
            <?php
            function otherext_civicrm_post($op) {
            }
            PHP);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testPolicyDeclaredHookSuffixIsSilent(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "known_hooks=acmeConnectors,otherHook\n",
            'fixture.php' => <<<'PHP'
                <?php
                function fixture_civicrm_acmeConnectors(&$connectors) {
                }
                PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    /** A prefix with underscores must be parsed, not silently skipped. */
    public function testAnUnderscorePrefixIsStillChecked(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'acme_widget'),
            'acme_widget.php' => "<?php\nfunction otherext_civicrm_post(\$op) {\n}\n",
        ]);
        $this->assertFails($this->run_(new HookDispatchNameCheck(), $context), 'otherext');
    }

    public function testAnUnderscorePrefixOwnHookPasses(): void
    {
        $context = $this->repo([
            'info.xml' => $this->infoXml(key: 'acme_widget'),
            'acme_widget.php' => "<?php\nfunction acme_widget_civicrm_post(\$op) {\n}\n",
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testFailsOnARemovedHook(): void
    {
        // hook_civicrm_tabs went away in 5.31; the function is dead code that
        // reads like a working feature.
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function fixture_civicrm_tabs(&$tabs, $contactID) {
            }
            PHP,
        ]);
        $reporter = $this->run_(new HookDispatchNameCheck(), $context);
        $this->assertFails($reporter, 'will never fire');
        $this->assertFails($reporter, 'hook_civicrm_tabset');
    }

    public function testWarnsOnAHookCoreMarkedDeprecated(): void
    {
        // From the generated catalog: core carries @deprecated on dupeQuery.
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function fixture_civicrm_dupeQuery($obj, $type, &$query) {
            }
            PHP,
        ]);
        $reporter = $this->run_(new HookDispatchNameCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'hook_civicrm_dupeQuery is deprecated');
    }

    public function testWarnsOnAHookDeprecatedOnlyInTheDocs(): void
    {
        // apiWrappers carries no marker in core at all — only the dev docs say
        // it is deprecated, so it can only come from the curated list.
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function fixture_civicrm_apiWrappers(&$wrappers, $apiRequest) {
            }
            PHP,
        ]);
        $reporter = $this->run_(new HookDispatchNameCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'civi.api.prepare');
    }

    /**
     * Ordinary core hooks that a hand-maintained list is prone to miss must not
     * be reported as typos.
     */
    public function testHooksAbsentFromTheOldHandWrittenListAreSilent(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function fixture_civicrm_alterRedirect(&$url, &$context) {
            }

            function fixture_civicrm_cryptoRotateKey($tag, $log) {
            }

            function fixture_civicrm_scanClasses(&$classes) {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    /**
     * A third-party hook may share a name with one core has since dropped. The
     * repo declaring it is the authority on its own dispatch.
     */
    public function testAPolicyDeclaredSuffixOutranksCoreHistory(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "known_hooks=tabs\n",
            'fixture.php' => <<<'PHP'
                <?php
                function fixture_civicrm_tabs(&$tabs) {
                }
                PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    /** A foreign prefix is reported once, not also as deprecated/removed. */
    public function testAForeignPrefixOnARemovedHookReportsOnlyThePrefix(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function otherext_civicrm_tabs(&$tabs) {
            }
            PHP,
        ]);
        $reporter = $this->run_(new HookDispatchNameCheck(), $context);
        $this->assertFails($reporter, 'the hook prefix of this extension');
        self::assertSame(1, $reporter->failures());
    }

    public function testWarnsOnATypoInAListenerString(): void
    {
        // The listener registers fine; the event just never arrives.
        $context = $this->repo(['Civi/Fixture/Listener.php' => <<<'PHP'
            <?php
            \Civi::dispatcher()->addListener('hook_civicrm_pots', 'fixture_on_post');
            PHP,
        ]);
        $reporter = $this->run_(new HookDispatchNameCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, "unknown hook suffix 'pots'");
    }

    public function testFailsOnAListenerStringForARemovedHook(): void
    {
        $context = $this->repo(['Civi/Fixture/Subscriber.php' => <<<'PHP'
            <?php
            class Subscriber extends \Civi\Core\Service\AutoSubscriber {
              public static function getSubscribedEvents(): array {
                return ['hook_civicrm_tabs' => 'onTabs'];
              }
            }
            PHP,
        ]);
        $reporter = $this->run_(new HookDispatchNameCheck(), $context);
        $this->assertFails($reporter, 'will never fire');
        $this->assertFails($reporter, 'hook_civicrm_tabset');
    }

    public function testListenerStringsForLiveHooksAreSilent(): void
    {
        // '&' (by-reference marker) and '::Entity' (self_ scope) wrap the same
        // name; dotted events are a namespace the catalog cannot judge.
        $context = $this->repo(['Civi/Fixture/Subscriber.php' => <<<'PHP'
            <?php
            class Subscriber extends \Civi\Core\Service\AutoSubscriber {
              public static function getSubscribedEvents(): array {
                return [
                  'hook_civicrm_post' => 'onPost',
                  '&hook_civicrm_triggerInfo' => 'onTriggerInfo',
                  'hook_civicrm_pre::Contact' => 'onContactPre',
                  'civi.dao.postInsert' => 'onInsert',
                ];
              }
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testWarnsOnATypoInAListenerMethod(): void
    {
        // EventScanner binds hook_*/on_*/self_* methods by name — a typo'd
        // method registers a listener no event will ever reach.
        $context = $this->repo(['Civi/Fixture/Hooks.php' => <<<'PHP'
            <?php
            class Hooks implements \Civi\Core\HookInterface {
              public function hook_civicrm_pots($op): void {
              }
            }
            PHP,
        ]);
        $reporter = $this->run_(new HookDispatchNameCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, 'scan-classes listener');
        $this->assertWarns($reporter, "unknown hook suffix 'pots'");
    }

    public function testListenerMethodsForLiveHooksAreSilent(): void
    {
        // All three EventScanner prefixes; on_civi_* maps to a dotted event
        // outside the catalog's reach and must not be judged.
        $context = $this->repo(['Civi/Fixture/Hooks.php' => <<<'PHP'
            <?php
            class Hooks implements \Civi\Core\HookInterface {
              public function hook_civicrm_post($op): void {
              }
              public function on_hook_civicrm_pre($event): void {
              }
              public function self_hook_civicrm_pre($event): void {
              }
              public function on_civi_api_respond($event): void {
              }
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testPolicyDeclaredSuffixCoversListenerForms(): void
    {
        // An extension dispatching its own hook names it as a string too; the
        // civikitchen.yaml declaration is the authority for every binding form.
        $context = $this->repo([
            '__policy_fixture' => "known_hooks=acmeConnectors\n",
            'Civi/Fixture/Registry.php' => <<<'PHP'
                <?php
                class Registry implements \Civi\Core\HookInterface {
                  public function hook_civicrm_acmeConnectors(&$connectors): void {
                  }
                  public function collect(): void {
                    \Civi::dispatcher()->dispatch('hook_civicrm_acmeConnectors');
                  }
                }
                PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    /**
     * A hook another extension invents is defined where that extension
     * dispatches it. With the dependency present under the ext dir, the
     * implementing repo declares nothing — in both dispatch forms.
     */
    public function testARequiredExtensionPublishesTheHooksItDispatches(): void
    {
        $extDir = Directory::temporary('ckconform-ext');
        mkdir($extDir . '/org.example.acme/Civi/Acme', 0777, true);
        mkdir($extDir . '/org.example.acme/tests', 0777, true);
        file_put_contents($extDir . '/org.example.acme/info.xml', $this->infoXml(key: 'org.example.acme'));
        file_put_contents($extDir . '/org.example.acme/Civi/Acme/Registry.php', <<<'PHP'
            <?php
            class Registry {
              public function collect(array &$connectors, array &$widgets): void {
                \Civi::dispatcher()->dispatch('hook_civicrm_acmeConnectors', GenericHookEvent::create(['connectors' => &$connectors]));
                \CRM_Utils_Hook::singleton()->invoke(['widgets'], $widgets, \CRM_Utils_Hook::$_nullObject,
                  \CRM_Utils_Hook::$_nullObject, \CRM_Utils_Hook::$_nullObject, \CRM_Utils_Hook::$_nullObject,
                  \CRM_Utils_Hook::$_nullObject, 'civicrm_acmeWidgets');
              }
            }
            PHP);
        // A dispatch in the dependency's tests is not a hook it publishes.
        file_put_contents($extDir . '/org.example.acme/tests/T.php', "<?php\n\\Civi::dispatcher()->dispatch('hook_civicrm_acmeTestOnly');\n");
        putenv('CK_EXT_DIR=' . $extDir);
        try {
            $context = $this->repo([
                'info.xml' => $this->infoXml(extra: '<requires><ext>org.example.acme</ext></requires>'),
                'fixture.php' => <<<'PHP'
                    <?php
                    function fixture_civicrm_acmeConnectors(array &$connectors): void {
                    }
                    function fixture_civicrm_acmeWidgets(array &$widgets): void {
                    }
                    PHP,
            ]);
            $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));

            $context = $this->repo([
                'info.xml' => $this->infoXml(extra: '<requires><ext>org.example.acme</ext></requires>'),
                'fixture.php' => "<?php\nfunction fixture_civicrm_acmeTestOnly(): void {\n}\n",
            ]);
            $this->assertWarns($this->run_(new HookDispatchNameCheck(), $context), "unknown hook suffix 'acmeTestOnly'");
        } finally {
            putenv('CK_EXT_DIR');
            Directory::remove($extDir);
        }
    }

    /** Without the dependency on disk there is nothing to read; the declaration route stays. */
    public function testAMissingRequiredExtensionPublishesNothing(): void
    {
        putenv('CK_EXT_DIR=' . sys_get_temp_dir() . '/ckconform-no-such-ext-dir');
        try {
            $context = $this->repo([
                'info.xml' => $this->infoXml(extra: '<requires><ext>org.example.acme</ext></requires>'),
                'fixture.php' => "<?php\nfunction fixture_civicrm_acmeConnectors(array &\$c): void {\n}\n",
            ]);
            $reporter = $this->run_(new HookDispatchNameCheck(), $context);
            $this->assertWarns($reporter, "unknown hook suffix 'acmeConnectors'");
            $this->assertWarns($reporter, 'known_hooks=');
        } finally {
            putenv('CK_EXT_DIR');
        }
    }

    /** Core finds hook functions with function_exists(), which ignores case. */
    public function testAPrefixInAnotherCaseStillFires(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function Fixture_civicrm_post($op, $objectName, $objectId, &$objectRef) {
            }
            function FIXTURE_CIVICRM_config(&$config) {
            }
            PHP,
        ]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testARemovedHookInAnotherCaseFails(): void
    {
        $context = $this->repo(['fixture.php' => <<<'PHP'
            <?php
            function fixture_civicrm_Tabs(&$tabs, $contactID) {
            }
            PHP,
        ]);
        $this->assertFails($this->run_(new HookDispatchNameCheck(), $context), 'hook_civicrm_tabs was removed');
    }

    /** Listener strings bind by exact event name, so case drift there stays a finding. */
    public function testAListenerStringInAnotherCaseStillWarns(): void
    {
        $context = $this->repo(['Civi/Fixture/Listener.php' => <<<'PHP'
            <?php
            \Civi::dispatcher()->addListener('hook_civicrm_Post', 'fixture_listener');
            PHP,
        ]);
        $this->assertWarns($this->run_(new HookDispatchNameCheck(), $context), "unknown hook suffix 'Post'");
    }

    public function testADispatchSiteWithACommentOrAnotherCasePublishesItsHook(): void
    {
        $dependency = Directory::temporary('ckconform-dep');
        mkdir($dependency . '/acme', 0777, true);
        try {
            file_put_contents($dependency . '/acme/info.xml', '<?xml version="1.0"?><extension key="acme" type="module"><file>acme</file></extension>');
            file_put_contents($dependency . '/acme/acme.php', <<<'PHP'
                <?php
                \Civi::dispatcher()->dispatch /* first */ ('hook_civicrm_acmeConnectors', $event);
                \Civi::dispatcher()->Dispatch('hook_civicrm_acmeWidgets', $event);
                PHP);
            self::assertSame(['acmeConnectors', 'acmeWidgets'], HookSurface::dispatchedSuffixes($dependency . '/acme'));
        } finally {
            Directory::remove($dependency);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function hooksDispatchedOutsideTheHookClass(): iterable
    {
        yield 'search_kit' => ['searchKitTasks'];
        yield 'CRM_Utils_System' => ['alterExternUrl'];
        yield 'oauth-client' => ['oauthProviders'];
    }

    /** @dataProvider hooksDispatchedOutsideTheHookClass */
    public function testHooksCoreDispatchesOutsideTheHookClassAreKnown(string $suffix): void
    {
        $context = $this->repo(['fixture.php' => "<?php\nfunction fixture_civicrm_{$suffix}(&\$a) {}\n"]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    /** An own prefix followed by more words names a helper, not a misnamed hook. */
    public function testAnOwnHelperWithCivicrmInItsNameIsNotAHook(): void
    {
        $context = $this->repo(['fixture.php' => "<?php\nfunction fixture_minimum_civicrm_version() {}\n"]);
        $this->assertSilent($this->run_(new HookDispatchNameCheck(), $context));
    }

    public function testAForeignPrefixSharingTheStartStillFails(): void
    {
        $context = $this->repo(['fixture.php' => "<?php\nfunction fixtureother_civicrm_post() {}\n"]);
        $this->assertFails($this->run_(new HookDispatchNameCheck(), $context), 'will never fire');
    }
}
