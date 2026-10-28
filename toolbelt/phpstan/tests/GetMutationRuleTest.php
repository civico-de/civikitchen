<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\PHPStan\GetMutationRule;
use CiviKitchen\PHPStan\RouteCatalog;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<GetMutationRule>
 */
final class GetMutationRuleTest extends RuleTestCase
{

    use ScansFixtures;

    private const ADVICE = ' — a route a browser can GET must not change data — check the request method, '
        . 'or document the exception with @phpstan-ignore ck.route.mutationOnGet.';

    private ?string $catalog = null;

    private ?string $extensionDir = null;

    public function testWritesReachableFromAGetRouteAreReported(): void
    {
        $this->catalog = __DIR__ . '/fixtures/route-catalog.json';
        $this->analyseFixtures(self::declaredRouteErrors());
    }

    /**
     * A route declared only in xml/Menu, with no generated catalog beside
     * it, must still be seen — otherwise adding a route and forgetting to
     * regenerate would silently switch the gate off.
     */
    public function testRoutesAreReadFromTheMenuXmlWhenNoCatalogExists(): void
    {
        $this->catalog = null;
        $this->extensionDir = __DIR__ . '/fixtures/repo';
        $this->analyseFixtures(self::declaredRouteErrors());
    }

    /** No routes anywhere: only the page class, which is one by inheritance. */
    public function testPageClassesAreRoutesWithoutAnyDeclaration(): void
    {
        $this->catalog = null;
        $this->extensionDir = null;
        $this->analyseFixtures([
            [
                'DELETE in CRM_Core_DAO::executeQuery() writes from CiviKitchen\Fixtures\Routes\WidgetPage::run(), '
                . 'which is reachable by GET' . self::ADVICE,
                60,
            ],
            [
                'Widget::create() writes from CiviKitchen\\Fixtures\\Routes\\JsonPage::run(), '
                . 'which is reachable by GET' . self::ADVICE,
                101,
            ],
            [
                'Widget::create() writes from CiviKitchen\\Fixtures\\Routes\\TraitPage::run(), '
                . 'which is reachable by GET' . self::ADVICE,
                109,
            ],
            ...self::unlistedPageErrors(),
        ]);
    }

    /**
     * The same writes, attributed to their declared routes — the catalog and
     * the xml/Menu path must report identically.
     *
     * @return list<array{string, int}>
     */
    private static function declaredRouteErrors(): array
    {
        return [
            [
                'Widget::createDraft() writes from CiviKitchen\Fixtures\Routes\GreeterEndpoint::handle(), '
                . 'which answers GET civicrm/greeter/new' . self::ADVICE,
                35,
            ],
            [
                "civicrm_api3('Contact', 'delete') writes from CiviKitchen\\Fixtures\\Routes\\GreeterEndpoint::handle(), "
                . 'which answers GET civicrm/greeter/new' . self::ADVICE,
                36,
            ],
            [
                "civicrm_api4('Contact', 'create') writes from CiviKitchen\\Fixtures\\Routes\\GreeterEndpoint::handle(), "
                . 'which answers GET civicrm/greeter/new' . self::ADVICE,
                37,
            ],
            [
                'UPDATE in crm_core_dao::executeQuery() writes from CiviKitchen\Fixtures\Routes\GreeterEndpoint::handle(), '
                . 'which answers GET civicrm/greeter/new' . self::ADVICE,
                38,
            ],
            [
                'DELETE in CRM_Core_DAO::executeQuery() writes from CiviKitchen\Fixtures\Routes\WidgetPage::run(), '
                . 'which answers GET civicrm/widget' . self::ADVICE,
                60,
            ],
            [
                'Widget::create() writes from CiviKitchen\\Fixtures\\Routes\\JsonPage::run(), '
                . 'which is reachable by GET' . self::ADVICE,
                101,
            ],
            [
                'Widget::create() writes from CiviKitchen\\Fixtures\\Routes\\TraitPage::run(), '
                . 'which is reachable by GET' . self::ADVICE,
                109,
            ],
            ...self::unlistedPageErrors(),
        ];
    }

    /**
     * The writes of pages no route list names: DAO/BAO calls, a trait helper,
     * anonymous pages and a page holding nested classes.
     *
     * @return list<array{string, int}>
     */
    private static function unlistedPageErrors(): array
    {
        $from = ' writes from CiviKitchen\\Fixtures\\Routes\\LedgerPage::run(), which is reachable by GET' . self::ADVICE;

        return [
            ['CRM_Core_BAO_Ledger::writeRecord()' . $from, 123],
            ['->save() on a DAO/BAO' . $from, 125],
            ['->delete() on a DAO/BAO' . $from, 126],
            ['INSERT in ->query()' . $from, 128],
            [
                'INSERT in CRM_Core_DAO::executeQuery() writes from CiviKitchen\\Fixtures\\Routes\\StorePage::run(), '
                . 'which is reachable by GET' . self::ADVICE,
                141,
            ],
            ...array_map(
                static fn (int $line): array => ['Widget::create() writes from class@anonymous::run(), which is reachable by GET' . self::ADVICE, $line],
                [162, 176, 214, 225],
            ),
            [
                'Widget::create() writes from CiviKitchen\\Fixtures\\Routes\\GuardLeakPage::run(), '
                . 'which is reachable by GET' . self::ADVICE,
                202,
            ],
            [
                'Widget::create() writes from CiviKitchen\\Fixtures\\Routes\\BuilderPage::run(), '
                . 'which is reachable by GET' . self::ADVICE,
                253,
            ],
        ];
    }

    /**
     * @param list<array{string, int}> $errors
     */
    private function analyseFixtures(array $errors): void
    {
        $this->analyse(
            [
                __DIR__ . '/fixtures/generic-stubs.php',
                __DIR__ . '/fixtures/route-stubs.php',
                __DIR__ . '/fixtures/get-mutation.php',
            ],
            $errors,
        );
    }

    protected function getRule(): Rule
    {
        return new GetMutationRule(new RouteCatalog($this->catalog, $this->extensionDir));
    }
}
