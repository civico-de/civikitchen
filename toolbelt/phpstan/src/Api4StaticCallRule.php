<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * `\Civi\Api4\Entity::action()`: the entity and action half of the fluent
 * form, a magic static PHP itself cannot check.
 *
 * @implements Rule<StaticCall>
 */
final class Api4StaticCallRule implements Rule
{
    private Api4Contract $contract;

    public function __construct(Api4Contract $contract)
    {
        $this->contract = $contract;
    }

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return self::check($this->contract, $node, $scope);
    }

    /**
     * Shared with Api4StaticCallableRule: `Contact::get(...)` is the same call.
     *
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public static function check(Api4Contract $contract, StaticCall $node, Scope $scope): array
    {
        $entity = Api4Fluent::entityFromStaticCall($node, $scope);
        if ($entity === null || !$node->name instanceof Node\Identifier) {
            return [];
        }

        $errors = $contract->checkEntity($entity, '\\Civi\\Api4\\' . $entity);
        if ($errors !== []) {
            return $errors;
        }

        return $contract->checkAction($entity, $node->name->toString(), 'fluent APIv4 call');
    }
}
