<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Field names passed to a fluent APIv4 builder rooted in `\Civi\Api4\X::action()`,
 * read per function body; only literals and constants count as names.
 *
 * @implements Rule<Node\FunctionLike>
 */
final class Api4FluentFieldRule implements Rule
{
    /** Builder methods whose leading argument is a field name, with core's parameter name. */
    private const FIRST_ARG_IS_FIELD = [
        'addwhere' => 'fieldName', 'addvalue' => 'fieldName', 'addorderby' => 'fieldName', 'addgroupby' => 'field',
    ];

    /** Builder methods taking a whole clause array, with core's parameter name. */
    private const ARRAY_ARG = [
        'setselect' => 'select', 'setwhere' => 'where', 'setvalues' => 'values',
        'setorderby' => 'orderBy', 'setgroupby' => 'groupBy',
    ];

    /** Clauses where a select alias is a legal name. */
    private const ALIAS_CLAUSES = ['addorderby', 'addgroupby', 'setorderby', 'setgroupby'];

    private Api4Contract $contract;

    public function __construct(Api4Contract $contract)
    {
        $this->contract = $contract;
    }

    public function getNodeType(): string
    {
        return Node\FunctionLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $bound = [];
        foreach ($node->getParams() as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $bound[] = $param->var->name;
            }
        }
        foreach ($node instanceof Node\Expr\Closure ? $node->uses : [] as $use) {
            if (is_string($use->var->name)) {
                $bound[] = $use->var->name;
            }
        }

        $errors = [];
        foreach (Api4Fluent::builders($node->getStmts() ?? [], $bound, $scope) as $builder) {
            if (!Api4Catalog::hasCompleteFields($builder['entity'])) {
                continue;
            }
            $aliases = Api4Fluent::aliases($builder['links'], $scope);
            $shadowed = Api4Fluent::joinAliases($builder['links'], $scope);
            foreach ($builder['links'] as $link) {
                $errors = array_merge($errors, $this->checkLink($builder['entity'], $link, $scope, $aliases, $shadowed, $builder['escapes']));
            }
        }

        return $errors;
    }

    /**
     * @param  ?list<string> $aliases
     * @param  ?list<string> $shadowed
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function checkLink(string $entity, MethodCall $link, Scope $scope, ?array $aliases, ?array $shadowed, bool $escapes): array
    {
        if (!$link->name instanceof Node\Identifier) {
            return [];
        }
        $method = $link->name->toLowerString();
        // A builder handed elsewhere, or a select not fully known, may hold any alias.
        if (($escapes || $aliases === null) && in_array($method, self::ALIAS_CLAUSES, true)) {
            return [];
        }
        // A join whose entity is not known may rebind any implicit name.
        $checkJoins = !$escapes && $shadowed !== null && !in_array($method, ['addvalue', 'setvalues'], true);

        $errors = [];
        $clause = $link->name->toString() . '()';
        foreach (array_diff($this->fieldsOf($method, $link, $scope), $aliases ?? []) as $field) {
            if (str_contains($field, '.')) {
                if ($checkJoins) {
                    $errors = array_merge($errors, $this->contract->checkJoinField($entity, $field, $clause, $shadowed));
                }
                continue;
            }
            $errors = array_merge($errors, $this->contract->checkField($entity, $field, $clause));
        }

        return array_map(
            static fn ($error) => RuleErrorBuilder::message($error->getMessage())
                ->identifier($error->getIdentifier())
                ->line($link->getStartLine())
                ->build(),
            $errors,
        );
    }

    /**
     * The field names one builder call passes.
     *
     * @return list<string>
     */
    private function fieldsOf(string $method, MethodCall $link, Scope $scope): array
    {
        if (isset(self::FIRST_ARG_IS_FIELD[$method])) {
            $arg = CallArgs::value($link, 0, self::FIRST_ARG_IS_FIELD[$method]);
            $field = $arg === null ? null : $this->contract->literalString($arg, $scope);

            return $field === null ? [] : [$field];
        }
        if ($method === 'addselect') {
            $fields = [];
            foreach ($link->isFirstClassCallable() ? [] : $link->getArgs() as $arg) {
                // addSelect(...$columns) — a spread is not a name we know.
                $field = $arg->unpack || $arg->name !== null ? null : $this->contract->literalString($arg->value, $scope);
                if ($field !== null) {
                    $fields[] = $field;
                }
            }

            return $fields;
        }
        $arg = isset(self::ARRAY_ARG[$method]) ? CallArgs::value($link, 0, self::ARRAY_ARG[$method]) : null;
        if ($arg === null) {
            return [];
        }
        $type = $scope->getType($arg);

        return match ($method) {
            // [['field', '=', 1], ['OR', [...]]]
            'setwhere' => $this->contract->fieldsFromWhere($type),
            // A plain list of names; the keys would only ever be 0, 1, 2.
            'setselect', 'setgroupby' => $this->contract->listOfStrings($type),
            // setValues / setOrderBy are keyed by field name.
            default => $this->contract->keys($type),
        };
    }
}
