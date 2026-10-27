<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Schema changes inside a test that runs in a transaction.
 *
 * `Civi\Test\TransactionalInterface` wraps every test method in a transaction
 * and rolls it back afterwards. MySQL commits implicitly on any DDL, so a
 * custom field, a custom group, an extension install or a raw CREATE/ALTER
 * inside setUp() or a test method silently ends that transaction: the
 * rollback then has nothing to roll back, the rows stay, and the next test —
 * or the next run — fails somewhere else entirely. That is the worst kind of
 * flake, because the file that caused it is green.
 *
 * The fix is never a suppression, it is moving the schema work into
 * setUpHeadless() (which runs before the transaction opens) or dropping
 * TransactionalInterface for that class.
 *
 * The whole class is read at once because the schema work is usually one
 * `$this->ensureTestSchema()` away from setUp() — checking method bodies in
 * isolation would see the call and not the DDL.
 *
 * @implements Rule<InClassNode>
 */
final class TransactionalTestDdlRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!self::isTransactional($node->getClassReflection())) {
            return [];
        }

        /** @var array<string, Node\Stmt\ClassMethod> $methods */
        $methods = [];
        foreach ($node->getOriginalNode()->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $methods[$stmt->name->toLowerString()] = $stmt;
            }
        }

        $errors = [];
        foreach (self::reachableFromTransaction($methods) as $method) {
            foreach ((new NodeFinder())->find($method->stmts ?? [], static fn (Node $n): bool => $n instanceof Node\Expr) as $expr) {
                $errors = array_merge($errors, self::check($expr));
            }
        }

        return $errors;
    }

    /**
     * setUp(), tearDown() and the test methods, plus the own helpers they
     * call through `$this->`, `self::` or `static::`.
     *
     * setUpHeadless() is deliberately not a root: it runs before the
     * transaction opens, and is where this rule wants the schema work to end
     * up.
     *
     * @param  array<string, Node\Stmt\ClassMethod> $methods
     * @return list<Node\Stmt\ClassMethod>
     */
    private static function reachableFromTransaction(array $methods): array
    {
        $queue = [];
        foreach ($methods as $name => $method) {
            if ($name === 'setup' || $name === 'teardown' || str_starts_with($name, 'test')) {
                $queue[] = $name;
            }
        }

        $seen = [];
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($seen[$name]) || !isset($methods[$name])) {
                continue;
            }
            $seen[$name] = $methods[$name];
            foreach ((new NodeFinder())->find($methods[$name]->stmts ?? [], static fn (Node $n): bool => self::isOwnCall($n)) as $call) {
                if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall) && $call->name instanceof Node\Identifier) {
                    $queue[] = $call->name->toLowerString();
                }
            }
        }

        return array_values($seen);
    }

    /** `$this->x()`, `self::x()` or `static::x()`. */
    private static function isOwnCall(Node $node): bool
    {
        if ($node instanceof Node\Expr\MethodCall) {
            return $node->var instanceof Node\Expr\Variable && $node->var->name === 'this';
        }

        return $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name
            && in_array($node->class->toLowerString(), ['self', 'static'], true);
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private static function check(Node $expr): array
    {
        if ($expr instanceof Node\Expr\StaticCall) {
            return self::checkStaticCall($expr);
        }
        if ($expr instanceof Node\Expr\FuncCall) {
            return self::checkFuncCall($expr);
        }
        if ($expr instanceof Node\Expr\MethodCall) {
            return self::checkMethodCall($expr);
        }

        return [];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private static function checkStaticCall(Node\Expr\StaticCall $expr): array
    {
        $class = ltrim(Sql::staticClassName($expr) ?? '', '\\');
        $method = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '';

        // \Civi\Api4\CustomField::create()
        if (stripos($class, 'Civi\\Api4\\') === 0) {
            $entity = substr($class, strlen('Civi\\Api4\\'));

            return self::customFieldWrite($entity, $method, '%s::%s()', $expr);
        }

        // CRM_Core_BAO_CustomField::create() is what the API runs underneath.
        if (preg_match('/^CRM_Core_(BAO|DAO)_Custom(Field|Group)$/i', $class) === 1
            && in_array(strtolower($method), TransactionalDdl::DDL_BAO_METHODS, true)) {
            return [self::error(
                sprintf('%s::%s() in a transactional test', $class, $method),
                'ck.test.customFieldInTransaction',
                $expr,
            )];
        }

        if (Sql::isDaoClass($class)) {
            return self::checkSqlArguments($expr->getArgs(), $expr);
        }

        return [];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private static function checkFuncCall(Node\Expr\FuncCall $expr): array
    {
        if (!$expr->name instanceof Node\Name) {
            return [];
        }
        $function = strtolower(ltrim($expr->name->toString(), '\\'));
        if (!in_array($function, ['civicrm_api4', 'civicrm_api3', 'civicrm_api'], true)) {
            return [];
        }
        [$entity, $action] = Sql::apiEntityAndAction($expr);
        if ($entity === null || $action === null) {
            return [];
        }
        $write = self::customFieldWrite($entity, $action, $function . "('%s', '%s')", $expr);
        if ($write !== []) {
            return $write;
        }
        if (strcasecmp(str_replace('_', '', $entity), 'CustomGroup') === 0
            && in_array(strtolower($action), ['update', 'setvalue'], true) && self::apiSetsIsMultiple($expr, $function, $action)) {
            return [self::error(
                sprintf("%s('CustomGroup', '%s') setting is_multiple in a transactional test", $function, $action),
                'ck.test.customFieldInTransaction',
                $expr,
            )];
        }
        if (strcasecmp($entity, 'Extension') === 0 && in_array(strtolower($action), TransactionalDdl::EXTENSION_ACTIONS, true)) {
            return [self::error(
                sprintf("%s('Extension', '%s') in a transactional test", $function, $action),
                'ck.test.extensionInTransaction',
                $expr,
            )];
        }

        return [];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private static function customFieldWrite(string $entity, string $action, string $callFormat, Node $expr): array
    {
        $ddlEntity = TransactionalDdl::ddlEntity($entity, $action);
        if ($ddlEntity === null) {
            return [];
        }

        return [self::error(
            sprintf($callFormat, $ddlEntity, $action) . ' in a transactional test',
            'ck.test.customFieldInTransaction',
            $expr,
        )];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private static function checkMethodCall(Node\Expr\MethodCall $expr): array
    {
        $method = $expr->name instanceof Node\Identifier ? $expr->name->toLowerString() : '';

        if (in_array($method, ['addvalue', 'setvalues'], true) && self::fluentSetsIsMultiple($expr, $method)) {
            $root = $expr->var;
            while ($root instanceof Node\Expr\MethodCall) {
                $root = $root->var;
            }
            if ($root instanceof Node\Expr\StaticCall && $root->name instanceof Node\Identifier
                && strcasecmp(ltrim(Sql::staticClassName($root) ?? '', '\\'), 'Civi\\Api4\\CustomGroup') === 0
                && $root->name->toLowerString() === 'update') {
                return [self::error('CustomGroup::update() setting is_multiple in a transactional test', 'ck.test.customFieldInTransaction', $expr)];
            }
        }

        // An extension manager is the only thing in a test whose install()
        // means "run this extension's SQL"; the receiver has to say so.
        if (in_array($method, TransactionalDdl::EXTENSION_ACTIONS, true) && self::isExtensionManager($expr->var)) {
            return [self::error(
                sprintf('extension %s() in a transactional test', $method),
                'ck.test.extensionInTransaction',
                $expr,
            )];
        }

        if (Sql::isDatabaseCall($expr)) {
            return self::checkSqlArguments($expr->getArgs(), $expr);
        }

        return [];
    }

    /**
     * @param  array<Node\Arg>                              $args
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private static function checkSqlArguments(array $args, Node $expr): array
    {
        foreach ($args as $arg) {
            $sql = Sql::literalString($arg->value);
            $verb = $sql === null ? null : TransactionalDdl::ddlVerb($sql);
            if ($verb !== null) {
                return [self::error(
                    sprintf('%s statement in a transactional test', $verb),
                    'ck.test.ddlInTransaction',
                    $expr,
                )];
            }
        }

        return [];
    }

    /** Flipping is_multiple swaps the custom-value table's unique index for a plain one. */
    private static function apiSetsIsMultiple(Node\Expr\FuncCall $call, string $function, string $action): bool
    {
        $params = CallArgs::value($call, 2, 'params');
        $version = self::item($params, 'version');
        if ($function === 'civicrm_api4' || (($version instanceof Node\Scalar\Int_ || $version instanceof Node\Scalar\String_) && (string) $version->value === '4')) {
            return self::item(self::item($params, 'values'), 'is_multiple') !== null;
        }
        if (strtolower($action) === 'setvalue') {
            $field = self::item($params, 'field');

            return $field !== null && Sql::literalString($field) === 'is_multiple';
        }

        return self::item($params, 'is_multiple') !== null;
    }

    /** `addValue('is_multiple', …)` or `setValues(['is_multiple' => …])`. */
    private static function fluentSetsIsMultiple(Node\Expr\MethodCall $call, string $method): bool
    {
        if ($method === 'addvalue') {
            $field = CallArgs::value($call, 0, 'fieldName');

            return $field !== null && Sql::literalString($field) === 'is_multiple';
        }

        return self::item(CallArgs::value($call, 0, 'values'), 'is_multiple') !== null;
    }

    /** The value a literal array holds under a literal key. */
    private static function item(?Node\Expr $array, string $key): ?Node\Expr
    {
        if (!$array instanceof Node\Expr\Array_) {
            return null;
        }
        foreach ($array->items as $item) {
            if ($item->key !== null && Sql::literalString($item->key) === $key) {
                return $item->value;
            }
        }

        return null;
    }

    /** The class implements TransactionalInterface, directly or inherited. */
    private static function isTransactional(ClassReflection $class): bool
    {
        foreach ($class->getNativeReflection()->getInterfaceNames() as $interface) {
            if ($interface === TransactionalDdl::TRANSACTIONAL_INTERFACE) {
                return true;
            }
        }

        return false;
    }

    private static function isExtensionManager(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\MethodCall) {
            return $expr->name instanceof Node\Identifier
                && $expr->name->toLowerString() === 'getmanager';
        }
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return preg_match('/manager$/i', $expr->name) === 1;
        }

        return false;
    }

    private static function error(string $what, string $identifier, Node $node): \PHPStan\Rules\IdentifierRuleError
    {
        return RuleErrorBuilder::message(TransactionalDdl::message($what))
            ->identifier($identifier)
            ->line($node->getStartLine())
            ->build();
    }
}
