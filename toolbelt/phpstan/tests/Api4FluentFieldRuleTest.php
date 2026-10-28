<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\PHPStan\Api4Catalog;
use CiviKitchen\PHPStan\Api4Contract;
use CiviKitchen\PHPStan\Api4FluentFieldRule;
use CiviKitchen\PHPStan\ReflectionClassLookup;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * The regression guard for the address-fields-on-Contact class of bug.
 *
 * `addSelect('street_address')` on a Contact is accepted by APIv4 and comes
 * back empty; only the catalog can say that the field lives on Address. The
 * negative half of the fixture is the more important one — the join form and
 * the option-value suffixes are how the correct code looks, and a rule that
 * flags those would be turned off fleet-wide within a day.
 *
 * The join half is the same bargain: the right-hand side of a KNOWN implicit
 * join is checkable, an explicit alias, a custom group and a multi-level path
 * are not, and the fixture pins both directions.
 *
 * @extends RuleTestCase<Api4FluentFieldRule>
 */
final class Api4FluentFieldRuleTest extends RuleTestCase
{

    use ScansFixtures;

    public function testAddressFieldsOnContactAreReportedAndJoinsAreNot(): void
    {
        $this->analyse(
            [__DIR__ . '/fixtures/api4-stubs.php', __DIR__ . '/fixtures/generic-stubs.php', __DIR__ . '/fixtures/api4-fluent-fields.php'],
            [
                ['APIv4 field Contact.street_address does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — addSelect()', 14],
                ['APIv4 field Contact.postal_code does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — addSelect()', 14],
                ['APIv4 field Contact.city does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — addSelect()', 14],
                ['APIv4 field Contact.country_id does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — addSelect()', 14],
                ['APIv4 field Address.no_such_field does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — address_primary.no_such_field in addSelect()', 47],
                ['APIv4 field Email.nope does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — email_primary.nope in addWhere()', 47],
                ['APIv4 field Contact.street_address does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — addSelect()', 83],
                ['APIv4 field Contact.srot_name does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — addOrderBy()', 99],
                ['APIv4 field Contact.frist_name does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — addWhere()', 107],
                ['APIv4 field Contact.frist_name does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — addWhere()', 114],
                ['APIv4 field Activity.subjcet does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — addSelect()', 116],
                ['APIv4 field Contact.frist_name does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — addValue()', 118],
                ['APIv4 field Group.tags does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — addSelect()', 146],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new Api4FluentFieldRule(new Api4Contract(__DIR__, new ReflectionClassLookup(self::createReflectionProvider())));
    }
}
