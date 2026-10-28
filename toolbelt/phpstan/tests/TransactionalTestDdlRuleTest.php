<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\PHPStan\TransactionalTestDdlRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<TransactionalTestDdlRule>
 */
final class TransactionalTestDdlRuleTest extends RuleTestCase
{

    use ScansFixtures;

    private const ADVICE = ' — MySQL commits implicitly on DDL, which ends the test transaction and leaves the rows '
        . 'behind — move it to setUpHeadless().';

    public function testSchemaChangesInsideTheTransactionAreReported(): void
    {
        $this->analyse(
            [
                __DIR__ . '/fixtures/api4-stubs.php',
                __DIR__ . '/fixtures/generic-stubs.php',
                __DIR__ . '/fixtures/civi-test-stubs.php',
                __DIR__ . '/fixtures/transactional-test-ddl.php',
            ],
            [
                ['CustomField::create() in a transactional test' . self::ADVICE, 16],
                ["civicrm_api4('CustomGroup', 'create') in a transactional test" . self::ADVICE, 19],
                ['CREATE statement in a transactional test' . self::ADVICE, 20],
                ['DROP statement in a transactional test' . self::ADVICE, 21],
                ['extension install() in a transactional test' . self::ADVICE, 23],
                ['ALTER statement in a transactional test' . self::ADVICE, 28],
                ["civicrm_api4('CustomGroup', 'create') in a transactional test" . self::ADVICE, 52],
                ['CustomField::create() in a transactional test' . self::ADVICE, 53],
                ['TRUNCATE statement in a transactional test' . self::ADVICE, 54],
                ['RENAME statement in a transactional test' . self::ADVICE, 60],
                ['CREATE statement in a transactional test' . self::ADVICE, 81],
                ['CustomGroup::delete() in a transactional test' . self::ADVICE, 86],
                ['CustomField::delete() in a transactional test' . self::ADVICE, 87],
                ['CustomField::update() in a transactional test' . self::ADVICE, 88],
                ['CRM_Core_BAO_CustomField::create() in a transactional test' . self::ADVICE, 89],
                ['CRM_Core_BAO_CustomGroup::create() in a transactional test' . self::ADVICE, 90],
                ['CustomField::Create() in a transactional test' . self::ADVICE, 91],
                ["civicrm_api4('CustomField', 'Create') in a transactional test" . self::ADVICE, 92],
                ["civicrm_api3('CustomField', 'create') in a transactional test" . self::ADVICE, 93],
                ["civicrm_api4('CustomGroup', 'create') in a transactional test" . self::ADVICE, 107],
                ['CustomField::create() in a transactional test' . self::ADVICE, 112],
                ['DROP statement in a transactional test' . self::ADVICE, 123],
                ['CustomGroup::update() setting is_multiple in a transactional test' . self::ADVICE, 143],
                ["civicrm_api4('CustomGroup', 'update') setting is_multiple in a transactional test" . self::ADVICE, 144],
                ["civicrm_api3('CustomGroup', 'setvalue') setting is_multiple in a transactional test" . self::ADVICE, 145],
                ['CustomGroup::update() setting is_multiple in a transactional test' . self::ADVICE, 146],
                ["civicrm_api3('CustomGroup', 'update') setting is_multiple in a transactional test" . self::ADVICE, 147],
                ["civicrm_api('CustomGroup', 'update') setting is_multiple in a transactional test" . self::ADVICE, 148],
                ["civicrm_api('CustomGroup', 'update') setting is_multiple in a transactional test" . self::ADVICE, 149],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new TransactionalTestDdlRule();
    }
}
