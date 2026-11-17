<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\PermissionClosureCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class PermissionClosureCheckTest extends CheckTestCase
{
    private const HOOK = <<<'PHP'
        <?php
        function myext_civicrm_permission(&$permissions) {
          $permissions['administer MyExt'] = ['label' => 'administer MyExt'];
          $permissions['access MyExt reports'] = ['label' => 'access MyExt reports'];
        }
        PHP;

    public function testSaysNothingWhenEveryUsedPermissionIsCoreOrDefined(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'xml/Menu/myext.xml' => $this->menu('access CiviCRM;administer MyExt'),
            'Civi/Api4/Action/Thing.php' => "<?php\nCRM_Core_Permission::check('access MyExt reports');\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testPseudoPermissionsAreAccepted(): void
    {
        $context = $this->repo([
            'managed/Thing.mgd.php' => "<?php\nreturn [['permission' => ['*always allow*']]];\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testAlwaysDenyIsAccepted(): void
    {
        $context = $this->repo([
            'schema/Thing.entityType.php' => "<?php\nreturn ['getFields' => fn() => ['secret' => ['permission' => ['*always deny*']]]];\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    /**
     * An entity with a column called `permission` defines that column with an
     * associative array; its title, SQL type and input type are not permissions.
     */
    public function testAFieldNamedPermissionIsNotAPermissionSpec(): void
    {
        $context = $this->repo([
            'schema/Denial.entityType.php' => <<<'PHP'
                <?php
                return [
                  'getFields' => fn() => [
                    'permission' => [
                      'title' => 'Permission',
                      'sql_type' => 'varchar(255)',
                      'input_type' => 'Text',
                      'required' => TRUE,
                    ],
                    'legacy' => array('permission' => array('title' => 'Legacy permission')),
                    'secret' => [
                      'title' => 'Secret',
                      'permission' => ['administer SomeOtherExtension'],
                    ],
                  ],
                ];
                PHP,
        ], git: true);
        $reporter = $this->run_(new PermissionClosureCheck(), $context);
        $this->assertPasses($reporter);
        self::assertSame([$this->unknown('schema/Denial.entityType.php')], $reporter->messages('warn'));
    }

    public function testAnUnclosedPermissionListYieldsNothing(): void
    {
        $context = $this->repo([
            'managed/Thing.mgd.php' => "<?php\n\$x = ['permission' => ['administer SomeOtherExtension'\n\$z = 'tail string';\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testAnAttributeOrClosureInsideASpecDoesNotEndIt(): void
    {
        $context = $this->repo([
            'managed/Thing.mgd.php' => <<<'PHP'
                <?php
                return ['permission' => [#[A] function () { return 'noise string'; }, 'administer SomeOtherExtension']];
                PHP,
        ], git: true);
        self::assertSame(
            [$this->unknown('managed/Thing.mgd.php')],
            $this->run_(new PermissionClosureCheck(), $context)->messages('warn'),
        );
    }

    /** Defined with plain literals, so only a correctly decoded escape matches. */
    private const APOSTROPHE_HOOK = <<<'PHP'
        <?php
        function myext_civicrm_permission(&$permissions) {
          $permissions["generate any user's JWT"] = ['label' => 'JWT'];
          $permissions["validate any user's credentials"] = ['label' => 'Credentials'];
        }
        PHP;

    public function testEscapesInPermissionLiteralsFollowPhpStringRules(): void
    {
        $context = $this->repo([
            'myext.php' => self::APOSTROPHE_HOOK,
            'managed/Thing.mgd.php' => <<<'PHP'
                <?php
                return [
                  ['permission' => 'generate any user\'s JWT'],
                  ['permission' => ["validate any user\x27s credentials"]],
                ];
                PHP,
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testArraySubscriptsInsideASpecAreNotPermissions(): void
    {
        $context = $this->repo([
            'Civi/Api4/Getter.php' => <<<'PHP'
                <?php
                $record = ['permission' => [$reportInstance['permission']]];
                PHP,
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testCallArgumentsInsideASpecAreNotPermissions(): void
    {
        $context = $this->repo([
            'managed/Thing.mgd.php' => <<<'PHP'
                <?php
                return ['permission' => array(array('administer SomeOtherExtension'), E::ts('not a permission'))];
                PHP,
        ], git: true);
        self::assertSame(
            [$this->unknown('managed/Thing.mgd.php')],
            $this->run_(new PermissionClosureCheck(), $context)->messages('warn'),
        );
    }

    /**
     * Declared by core's afform extension through hook_civicrm_permission and
     * hook_civicrm_permissionList.
     */
    public function testAfformPermissionsAreKnown(): void
    {
        $context = $this->repo([
            'ang/afformThing.aff.php' => "<?php\nreturn ['permission' => ['@afformPageToken', 'manage own afform']];\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    /** financialacls builds its names in a loop: `$action . ' contributions of all types'`. */
    public function testLoopBuiltCorePermissionsAreKnown(): void
    {
        $context = $this->repo([
            'CRM/Foo/Page.php' => "<?php\nCRM_Core_Permission::check('view contributions of all types');\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    /**
     * Core writes OR groups as nested lists; every leaf counts, including
     * those after the first inner list closes.
     */
    public function testEveryLeafOfANestedPermissionListIsRead(): void
    {
        $context = $this->repo([
            'schema/Contact.entityType.php' => <<<'PHP'
                <?php
                return ['getFields' => fn() => ['api_key' => [
                  'permission' => [['administer CiviCRM', 'edit api keys'], ['administer SomeOtherExtension']],
                ]]];
                PHP,
        ], git: true);
        $reporter = $this->run_(new PermissionClosureCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, "permission 'administer SomeOtherExtension'");
    }

    public function testEscapesInACheckLiteralFollowPhpStringRules(): void
    {
        $context = $this->repo([
            'myext.php' => self::APOSTROPHE_HOOK,
            'CRM/Myext/Page/Thing.php' => "<?php\nif (CRM_Core_Permission::check('generate any user\\'s JWT')) {}\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testEscapesInAHookAssignmentFollowPhpStringRules(): void
    {
        $context = $this->repo([
            'myext.php' => <<<'PHP'
                <?php
                function myext_civicrm_permission(&$permissions) {
                  $permissions['edit my client\'s tokens'] = ['label' => 'Tokens'];
                }
                PHP,
            'xml/Menu/myext.xml' => $this->menu("edit my client's tokens"),
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testEscapesInAReturnedPermissionMapFollowPhpStringRules(): void
    {
        $context = $this->repo([
            'myext.php' => <<<'PHP'
                <?php
                function myext_civicrm_permission(&$permissions) {
                  $permissions += ['edit my client\'s notes' => ['label' => 'Notes']];
                }
                PHP,
            'CRM/Myext/Page/Thing.php' => "<?php\nCRM_Core_Permission::check(\"edit my client's notes\");\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testCheckCallsMatchClassAndMethodCaseInsensitively(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'CRM/Myext/Page/Lower.php' => "<?php\ncrm_core_permission::Check('acces MyExt reports');\n",
            'CRM/Myext/Page/Upper.php' => "<?php\n\\CRM_Core_Permission::CHECK('administer MyExtt');\n",
        ], git: true);
        $reporter = $this->run_(new PermissionClosureCheck(), $context);
        $this->assertFails($reporter, "CRM/Myext/Page/Lower.php: permission 'acces MyExt reports'");
        $this->assertFails($reporter, "CRM/Myext/Page/Upper.php: permission 'administer MyExtt'");
    }

    public function testAClosingBraceInAStringDoesNotEndTheHookBody(): void
    {
        $context = $this->repo([
            'myext.php' => <<<'PHP'
                <?php
                function myext_civicrm_permission(&$permissions) {
                  $note = 'closing } brace';
                  $permissions += ['administer My Ext' => ['label' => 'My Ext']];
                }
                PHP,
            'CRM/Myext/Page/Thing.php' => "<?php\nCRM_Core_Permission::check('administer My Ext');\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testAnOpeningBraceInAStringDoesNotExtendTheHookBody(): void
    {
        $context = $this->repo([
            'myext.php' => <<<'PHP'
                <?php
                function myext_civicrm_permission(&$permissions) {
                  $note = 'opening { brace';
                  $permissions['administer MyExt'] = ['label' => 'administer MyExt'];
                }
                function myext_civicrm_config(&$config) {
                  $map = ['some unrelated label' => 1];
                }
                PHP,
            'xml/Menu/myext.xml' => $this->menu('some unrelated labell'),
        ], git: true);
        $reporter = $this->run_(new PermissionClosureCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, "permission 'some unrelated labell'");
    }

    /**
     * A typo turns the guard into an always-no that nothing at runtime reports.
     */
    public function testFailsForATypoOfAnOwnPermissionInMenuXml(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'xml/Menu/myext.xml' => $this->menu('administer MyEXt'),
        ], git: true);
        $this->assertFails(
            $this->run_(new PermissionClosureCheck(), $context),
            "xml/Menu/myext.xml: permission 'administer MyEXt' is never defined, but this extension defines 'administer MyExt'",
        );
    }

    public function testFailsForANearMissInAPhpPermissionCheck(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'CRM/Myext/Page/Thing.php' => "<?php\nif (CRM_Core_Permission::check('acces MyExt reports')) {}\n",
        ], git: true);
        $this->assertFails($this->run_(new PermissionClosureCheck(), $context), "'acces MyExt reports'");
    }

    public function testFailsForANearMissInAnAffJson(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'ang/afsearchThing.aff.json' => '{"permission": ["administer MyExtt"]}',
        ], git: true);
        $this->assertFails($this->run_(new PermissionClosureCheck(), $context), 'afsearchThing.aff.json');
    }

    public function testWarnsForAWhollyUnknownPermissionBecauseItMayComeFromADependency(): void
    {
        $context = $this->repo([
            'xml/Menu/myext.xml' => $this->menu('access CiviCRM;administer SomeOtherExtension'),
        ], git: true);
        $reporter = $this->run_(new PermissionClosureCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, "permission 'administer SomeOtherExtension' is neither a known core permission");
    }

    public function testDefinedButUnusedPermissionsAreNotReported(): void
    {
        $context = $this->repo(['myext.php' => self::HOOK], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testSemicolonAndCommaListsAreBothSplit(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'xml/Menu/myext.xml' => $this->menu('access CiviCRM,administer MyExt;view all contacts'),
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testCommaSeparatedPhpPermissionExpressionIsSplit(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'managed/Thing.mgd.php' => <<<'PHP'
                <?php
                return [['permission' => 'administer MyExt,view all contacts']];
                PHP,
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testNumericPermissionStringsAreCheckedLikeAnyOther(): void
    {
        $context = $this->repo([
            'managed/Thing.mgd.php' => "<?php\nreturn [['permission' => '1'], ['permission' => ['2']]];\n",
        ], git: true);
        $reporter = $this->run_(new PermissionClosureCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter, "managed/Thing.mgd.php: permission '2' is neither a known core permission");
    }

    public function testNonLiteralPermissionsAreIgnored(): void
    {
        $context = $this->repo([
            'CRM/Myext/Page/Thing.php' => "<?php\nCRM_Core_Permission::check(\$this->permission);\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    /**
     * Unrelated strings in a file holding the hook must not enter the definition
     * set; an over-wide set turns warnings into false FAILs.
     */
    public function testOnlyTheHookBodyContributesDefinitions(): void
    {
        $context = $this->repo([
            'myext.php' => <<<'PHP'
                <?php
                function myext_civicrm_permission(&$permissions) {
                  $permissions['administer MyExt'] = ['label' => 'administer MyExt'];
                }
                function myext_civicrm_config(&$config) {
                  $map = ['some unrelated label' => 1];
                }
                PHP,
            'xml/Menu/myext.xml' => $this->menu('some unrelated labell'),
        ], git: true);
        $reporter = $this->run_(new PermissionClosureCheck(), $context);
        $this->assertPasses($reporter);
        $this->assertWarns($reporter);
    }

    public function testAnArrowFunctionInsideASpecDoesNotEndIt(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'managed/Thing.mgd.php' => "<?php\nreturn ['permission' => [fn() => ['x' => 'noise string'], 'administer MyExtt']];\n",
        ], git: true);
        $this->assertFails($this->run_(new PermissionClosureCheck(), $context), "permission 'administer MyExtt'");
    }

    public function testListAndNamedArgumentsOfACheckCallAreRead(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'CRM/Myext/Page/List.php' => "<?php\nCRM_Core_Permission::check(['administer MyExtt']);\n",
            'CRM/Myext/Page/Nested.php' => "<?php\nCRM_Core_Permission::check([['access CiviCRM', 'acces MyExt reports']]);\n",
            'CRM/Myext/Page/Named.php' => "<?php\nCRM_Core_Permission::check(permissions: 'administer MyEXt');\n",
            'CRM/Myext/Page/NamedList.php' => "<?php\nCRM_Core_Permission::check(permissions: array('access MyExt reportss'));\n",
        ], git: true);
        $reporter = $this->run_(new PermissionClosureCheck(), $context);
        $this->assertFails($reporter, "CRM/Myext/Page/List.php: permission 'administer MyExtt'");
        $this->assertFails($reporter, "CRM/Myext/Page/Nested.php: permission 'acces MyExt reports'");
        $this->assertFails($reporter, "CRM/Myext/Page/Named.php: permission 'administer MyEXt'");
        $this->assertFails($reporter, "CRM/Myext/Page/NamedList.php: permission 'access MyExt reportss'");
    }

    public function testAStringThatIsOnlyPartOfAnExpressionIsNotAPermission(): void
    {
        $context = $this->repo([
            'CRM/Myext/Page/Thing.php' => "<?php\nCRM_Core_Permission::check('add contributions of type ' . \$type);\n",
            'ang/afformThing.aff.php' => <<<'PHP'
                <?php
                return [
                  ['permission' => "@afform:" . $name],
                  ['permission' => ['edit all ' . $what, $prefix . ' records']],
                  ['permission' => [$ok ? 'access one thing' : 'access other thing']],
                ];
                PHP,
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testAByReferenceHookIsRead(): void
    {
        $context = $this->repo([
            'myext.php' => <<<'PHP'
                <?php
                function &myext_civicrm_permission(&$permissions) {
                  $permissions += ['administer My Ext' => ['label' => 'My Ext']];
                  return $permissions;
                }
                PHP,
            'CRM/Myext/Page/Thing.php' => "<?php\nCRM_Core_Permission::check('administer My Ext');\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testEveryPermissionProviderInAFileContributesDefinitions(): void
    {
        $context = $this->repo([
            'Civi/Myext/Providers.php' => <<<'PHP'
                <?php
                class First { public static function getPermissions() { return ['administer First Thing' => []]; } }
                class Second { public static function getPermissions() { return ['administer Second Thing' => []]; } }
                PHP,
            'CRM/Myext/Page/Thing.php' => "<?php\nCRM_Core_Permission::check('administer Second Thing');\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testASubscriptOnAStringInsideASpecIsNotAList(): void
    {
        $context = $this->repo([
            'managed/Thing.mgd.php' => "<?php\nreturn ['permission' => ['abc'['2'], \"a\$b\"['3'], <<<X\nabc\nX['4']]];\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    /**
     * Each boundary of the UTF-8 byte length, plus the C1 range and NUL that
     * entity decoding does not produce.
     */
    public function testUnicodeEscapesResolveToUtf8(): void
    {
        $context = $this->repo([
            'myext.php' => "<?php\nfunction myext_civicrm_permission(&\$permissions) {\n"
                . "  \$permissions['administer My Ext \u{0} \u{7f} \u{80} \u{9f} \u{7ff} \u{800} \u{ffff} \u{10000} \u{10ffff} end'] = [];\n}\n",
            'CRM/Myext/Page/Thing.php' => "<?php\nCRM_Core_Permission::check("
                . '"administer My Ext \u{0} \u{7F} \u{80} \u{9f} \u{7ff} \u{0800} \u{ffff} \u{10000} \u{10FFFF} end"'
                . ");\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testUntrackedFilesDoNotDecideTheVerdict(): void
    {
        $context = $this->repo(['myext.php' => self::HOOK], git: true);
        file_put_contents($context->path('scratch.php'), "<?php\nCRM_Core_Permission::check('administer MyExtt');\n");
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    private function unknown(string $file): string
    {
        return "$file: permission 'administer SomeOtherExtension' is neither a known core permission "
            . 'nor defined by this extension — fine if a dependency defines it, a silent always-no otherwise';
    }

    private function menu(string $arguments): string
    {
        return <<<XML
            <?xml version="1.0"?>
            <menu>
              <item>
                <path>civicrm/myext/thing</path>
                <access_arguments>{$arguments}</access_arguments>
              </item>
            </menu>
            XML;
    }

    public function testFailsForATypoInAnApi4EntityPermissionsMap(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'Civi/Api4/Thing.php' => <<<'PHP'
                <?php
                namespace Civi\Api4;
                class Thing {
                  public static function permissions(): array {
                    return [
                      'meta' => ['access CiviCRM'],
                      'default' => [['administer MyExt', 'administer CiviCRM']],
                      'get' => ['acces MyExt reports'],
                    ];
                  }
                }
                PHP,
        ], git: true);
        $reporter = $this->run_(new PermissionClosureCheck(), $context);
        $this->assertFails($reporter, "'acces MyExt reports' is never defined");
        self::assertCount(1, $reporter->messages('FAIL'));
    }

    public function testFailsForATypoAssignedInAnApi4PermissionsMethod(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'Civi/Api4/Thing.php' => <<<'PHP'
                <?php
                namespace Civi\Api4;
                class Thing {
                  public static function permissions() {
                    $permissions = \CRM_Core_Permission::getEntityActionPermissions()['default'];
                    $permissions['export'] = ['administer MyExtt'];
                    return $permissions;
                  }
                }
                PHP,
        ], git: true);
        $this->assertFails($this->run_(new PermissionClosureCheck(), $context), "'administer MyExtt' is never defined");
    }

    public function testFailsForATypoInAPluralPermissionsList(): void
    {
        $context = $this->repo([
            'myext.php' => self::HOOK,
            'ang/myext.ang.php' => "<?php\nreturn ['js' => ['ang/myext.js'], 'permissions' => ['administer MyExt', 'access MyExt report']];\n",
        ], git: true);
        $this->assertFails($this->run_(new PermissionClosureCheck(), $context), "'access MyExt report' is never defined");
    }

    public function testAFieldNamedPermissionsIsNotAPermissionSpec(): void
    {
        $context = $this->repo([
            'schema/Role.entityType.php' => "<?php\nreturn ['getFields' => fn() => ['permissions' => ['title' => 'Permissions', 'sql_type' => 'text']]];\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testAPermissionsMethodOutsideApi4IsNoActionMap(): void
    {
        $context = $this->repo([
            'CRM/Myext/Page/Thing.php' => <<<'PHP'
                <?php
                namespace Civi\Myext;
                class Thing {
                  public function permissions(): array {
                    return ['label' => ['Some page title']];
                  }
                }
                PHP,
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    public function testANestedPermissionsKeyInAnAngularModuleIsNotRead(): void
    {
        $context = $this->repo([
            'ang/myext.ang.php' => "<?php\nreturn ['js' => ['ang/myext.js'], 'settings' => ['permissions' => ['Some label text']]];\n",
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    /** @return iterable<string, array{string}> */
    public static function corePermissionsTheOldListMissed(): iterable
    {
        yield 'CiviEvent' => ['view event participants'];
        yield 'api keys, plural in core' => ['edit own api keys'];
        yield 'SMS' => ['send SMS'];
        yield 'synthetic cms: permission' => ['cms:administer users'];
        yield 'CMS-native prefix' => ['Drupal:administer users'];
    }

    /** @dataProvider corePermissionsTheOldListMissed */
    public function testCorePermissionsAreKnown(string $permission): void
    {
        $context = $this->repo(['myext.php' => self::HOOK, 'xml/Menu/myext.xml' => $this->menu($permission)], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    /** A core permission one edit away from an own one is no typo of it. */
    public function testACorePermissionNearAnOwnOneIsSilent(): void
    {
        $context = $this->repo([
            'myext.php' => "<?php\nfunction myext_civicrm_permission(&\$permissions) {\n  \$permissions['edit own events'] = ['label' => 'x'];\n}\n",
            'xml/Menu/myext.xml' => $this->menu('edit all events'),
        ], git: true);
        $this->assertSilent($this->run_(new PermissionClosureCheck(), $context));
    }

    /** Core maps an unknown cms: name to always-deny. */
    public function testAnUnknownCmsPermissionWarns(): void
    {
        $context = $this->repo(['myext.php' => self::HOOK, 'xml/Menu/myext.xml' => $this->menu('cms:administer everything')], git: true);
        $this->assertWarns($this->run_(new PermissionClosureCheck(), $context), 'cms:administer everything');
    }

    public function testACaseDriftOfAnOwnPermissionStillFails(): void
    {
        $context = $this->repo(['myext.php' => self::HOOK, 'xml/Menu/myext.xml' => $this->menu('administer myext')], git: true);
        $this->assertFails($this->run_(new PermissionClosureCheck(), $context), "defines 'administer MyExt'");
    }
}
