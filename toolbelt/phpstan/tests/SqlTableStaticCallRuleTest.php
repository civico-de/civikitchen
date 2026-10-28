<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\PHPStan\SchemaCatalog;
use CiviKitchen\PHPStan\SqlSchema;
use CiviKitchen\PHPStan\SqlTableStaticCallRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<SqlTableStaticCallRule>
 */
final class SqlTableStaticCallRuleTest extends RuleTestCase
{

    use ScansFixtures;

    private ?SqlSchema $schema = null;

    public function testUnknownCoreTablesAreReported(): void
    {
        $version = SchemaCatalog::CORE_VERSION;
        $this->schema = new SqlSchema(__DIR__ . '/fixtures/no-such-repo');
        $this->analyse(
            [__DIR__ . '/fixtures/sql-tables.php'],
            [
                ["SQL table civicrm_widget_rule does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 12],
                ["SQL table civicrm_contakt does not exist in CiviCRM $version — CRM_Core_DAO::singleValueQuery()", 13],
                ["SQL table civicrm_emails does not exist in CiviCRM $version — CRM_Core_DAO::executeUnbufferedQuery()", 14],
                ["SQL table civicrm_gadget does not exist in CiviCRM $version — CRM_Utils_SQL_Select::from()", 17],
                ["SQL table civicrm_widget_named does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 62],
                ["SQL table civicrm_widget_cased does not exist in CiviCRM $version — crm_core_dao::executeQuery()", 63],
                ["SQL table civicrm_gadget_cased does not exist in CiviCRM $version — CRM_Utils_SQL_Select::from()", 64],
                ["SQL table civicrm_myext_thing does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 82],
                ["SQL table civicrm_contcat does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 83],
                ["SQL table civicrm_contcat does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 84],
                ["SQL table civicrm_emial does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 85],
                ["SQL table civicrm_contact_mirror does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 86],
                ["SQL table civicrm_contcat does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 87],
                ["SQL table civicrm_contcat does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 88],
                ["SQL table civicrm_contcat does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 89],
                ["SQL table civicrm_contcat does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 90],
                ["SQL table civicrm_contcat does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 93],
            ],
        );
    }

    /** The repo's own schema and the configured list take names off the list. */
    public function testDeclaredTablesAreSilent(): void
    {
        $version = SchemaCatalog::CORE_VERSION;
        $this->schema = new SqlSchema(__DIR__ . '/fixtures/repo', ['civicrm_contakt', 'civicrm_gadget']);
        $this->analyse(
            [__DIR__ . '/fixtures/sql-tables.php'],
            [
                ["SQL table civicrm_emails does not exist in CiviCRM $version — CRM_Core_DAO::executeUnbufferedQuery()", 14],
                ["SQL table civicrm_widget_named does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 62],
                ["SQL table civicrm_widget_cased does not exist in CiviCRM $version — crm_core_dao::executeQuery()", 63],
                ["SQL table civicrm_gadget_cased does not exist in CiviCRM $version — CRM_Utils_SQL_Select::from()", 64],
                ["SQL table civicrm_contcat does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 83],
                ["SQL table civicrm_contcat does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 84],
                ["SQL table civicrm_emial does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 85],
                ["SQL table civicrm_contact_mirror does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 86],
                ["SQL table civicrm_contcat does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 87],
                ["SQL table civicrm_contcat does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 88],
                ["SQL table civicrm_contcat does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 89],
                ["SQL table civicrm_contcat does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 90],
                ["SQL table civicrm_contcat does not exist in CiviCRM $version — CRM_Core_DAO::executeQuery()", 93],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new SqlTableStaticCallRule($this->schema ?? new SqlSchema(__DIR__ . '/fixtures/no-such-repo'));
    }
}
