<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\PHPStan\Api4Catalog;
use CiviKitchen\PHPStan\Api4Contract;
use CiviKitchen\PHPStan\Api4FunctionCallRule;
use CiviKitchen\PHPStan\Api4StaticCallableRule;
use CiviKitchen\PHPStan\Api4StaticCallRule;
use CiviKitchen\PHPStan\ReflectionClassLookup;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * civicrm_api4() and `\Civi\Api4\X::action()`: typos are reported, a
 * differently cased action and the metadata actions' own columns are not.
 *
 * @extends RuleTestCase<Rule<\PhpParser\Node>>
 */
final class Api4CallRulesTest extends RuleTestCase
{

    use ScansFixtures;

    private ?Rule $rule = null;

    public function testFunctionCalls(): void
    {
        $this->rule = new Api4FunctionCallRule($this->contract(), self::createReflectionProvider());
        $this->analyse($this->files(), [
            ['APIv4 field Contact.display_nam does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — select', 13],
            ['APIv4 action Contact::gett does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — civicrm_api4()', 14],
            ['APIv4 field Contact.contact_typ does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — groupBy', 16],
            ['APIv4 field Contact.display_nam does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — select', 36],
            ['APIv4 action Contact::gett does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — civicrm_api4()', 37],
            ['APIv4 field Contact.frist_name does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — select', 52],
            ['APIv4 field Group.tags does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — select', 55],
            ['APIv4 entity Contatc does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — did you mean Contact? (civicrm_api4())', 66],
            ['APIv4 entity contact does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — did you mean Contact? (civicrm_api4())', 67],
        ]);
    }

    /** An imported alias is core's function; a namespace's own civicrm_api4() is not. */
    public function testFunctionCallsByResolvedName(): void
    {
        $this->rule = new Api4FunctionCallRule($this->contract(), self::createReflectionProvider());
        $gett = 'APIv4 action Contact::gett does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — civicrm_api4()';
        $this->analyse([...$this->stubs(), __DIR__ . '/fixtures/api4-call-names.php'], [[$gett, 10], [$gett, 11]]);
    }

    public function testStaticCalls(): void
    {
        $this->rule = new Api4StaticCallRule($this->contract());
        $this->analyse($this->files(), [
            ['APIv4 action Contact::gett does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — fluent APIv4 call', 15],
            ['APIv4 action Contact::gett does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — fluent APIv4 call', 45],
        ]);
    }

    public function testStaticCallables(): void
    {
        $this->rule = new Api4StaticCallableRule($this->contract());
        $this->analyse($this->files(), [
            ['APIv4 action Contact::gte does not exist in CiviCRM ' . Api4Catalog::CORE_VERSION . ' — fluent APIv4 call', 73],
        ]);
    }

    protected function getRule(): Rule
    {
        return $this->rule ?? throw new \LogicException('set the rule before analysing');
    }

    private function contract(): Api4Contract
    {
        return new Api4Contract(__DIR__, new ReflectionClassLookup(self::createReflectionProvider()));
    }

    /** @return list<string> */
    private function files(): array
    {
        return [...$this->stubs(), __DIR__ . '/fixtures/api4-calls.php'];
    }

    /** @return list<string> */
    private function stubs(): array
    {
        return [__DIR__ . '/fixtures/api4-stubs.php', __DIR__ . '/fixtures/generic-stubs.php', __DIR__ . '/fixtures/api4-function-stub.php'];
    }
}
