<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;

/**
 * Recognising direct database traffic in the AST.
 *
 * Shared by the two rules that care about it: DDL inside a transactional
 * test, and a catch block that swallows a query failure. Both work on plain
 * literals and resolved names — the point is to be certain or silent, so an
 * expression this cannot read is simply not database traffic as far as the
 * rules are concerned.
 */
final class Sql
{
    /** DAO entry points that put a statement on the wire. */
    public const DAO_QUERY_METHODS = [
        'executequery', 'singlevaluequery', 'executeunbufferedquery', 'executeswitchquery',
        'query', 'executeconstantquery',
    ];

    public const SQL_SELECT = 'CRM_Utils_SQL_Select';

    /** Statements MySQL commits implicitly, ending any open transaction. */
    private const DDL_KEYWORDS = ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'];

    /**
     * Does this call reach the database directly, bypassing APIv4?
     *
     * With a scope, `->execute()` on a variable typed as the SQL builder
     * counts too; without one, only a builder chain in sight does.
     */
    public static function isDatabaseCall(Node $node, ?Scope $scope = null): bool
    {
        if ($node instanceof Node\Expr\StaticCall) {
            return self::isDaoClass(self::staticClassName($node))
                && $node->name instanceof Node\Identifier
                && in_array($node->name->toLowerString(), self::DAO_QUERY_METHODS, true);
        }

        if (!$node instanceof Node\Expr\MethodCall || !$node->name instanceof Node\Identifier) {
            return false;
        }
        $method = $node->name->toLowerString();
        if ($method === 'execute') {
            return self::isSqlSelect($node->var, $scope);
        }

        // $dao->query(...) / $dao->find(TRUE) on a DAO instance variable.
        return in_array($method, ['query', 'find', 'fetch'], true) && self::looksLikeDaoVariable($node->var);
    }

    /** A CRM_Utils_SQL_Select, whose execute() runs CRM_Core_DAO::executeQuery(). */
    private static function isSqlSelect(Node\Expr $expr, ?Scope $scope): bool
    {
        if ($scope !== null && (new ObjectType(self::SQL_SELECT))->isSuperTypeOf($scope->getType($expr))->yes()) {
            return true;
        }
        while ($expr instanceof Node\Expr\MethodCall) {
            $expr = $expr->var;
        }
        $class = null;
        if ($expr instanceof Node\Expr\StaticCall) {
            $class = self::staticClassName($expr);
        } elseif ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            $class = $expr->class->toString();
        }

        return $class !== null && strcasecmp(ltrim($class, '\\'), self::SQL_SELECT) === 0;
    }

    /**
     * A literal string, including one built by concatenating literals.
     *
     * Heredocs and simple concatenation are how long SQL is written; a
     * statement assembled from a variable is not readable here and is left
     * alone rather than guessed at. An interpolated string yields only its
     * leading literal part, which is enough to see the statement's verb and
     * its first table.
     */
    public static function literalString(?Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }
        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $left = self::literalString($expr->left);

            return $left === null ? null : $left . (self::literalString($expr->right) ?? '');
        }
        if ($expr instanceof Node\Scalar\InterpolatedString) {
            $first = $expr->parts[0] ?? null;

            return $first instanceof Node\InterpolatedStringPart ? $first->value : null;
        }

        return null;
    }

    /**
     * A DDL statement that would commit an open test transaction.
     *
     * CREATE/DROP TEMPORARY TABLE is the documented exception: MySQL and
     * MariaDB run it without an implicit commit.
     */
    public static function isDdlLiteral(string $sql): bool
    {
        $trimmed = ltrim($sql);
        if (preg_match('/^(CREATE|DROP)\s+TEMPORARY\s/i', $trimmed) === 1) {
            return false;
        }
        foreach (self::DDL_KEYWORDS as $keyword) {
            if (preg_match('/^' . $keyword . '\b/i', $trimmed) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Literal entity and action of a civicrm_api/api3/api4() call, each null
     * when unreadable. All three name their first parameters $entity, $action.
     *
     * @return array{?string, ?string}
     */
    public static function apiEntityAndAction(Node\Expr\FuncCall $call): array
    {
        $entity = CallArgs::value($call, 0, 'entity');
        $action = CallArgs::value($call, 1, 'action');

        return [
            $entity === null ? null : self::literalString($entity),
            $action === null ? null : self::literalString($action),
        ];
    }

    /** The resolved class name of a static call, as written. */
    public static function staticClassName(Node\Expr\StaticCall $node): ?string
    {
        return $node->class instanceof Node\Name ? $node->class->toString() : null;
    }

    /** CRM_Core_DAO itself, or a generated DAO/BAO class. */
    public static function isDaoClass(?string $class): bool
    {
        if ($class === null) {
            return false;
        }
        $class = ltrim($class, '\\');

        return strcasecmp($class, 'CRM_Core_DAO') === 0
            || preg_match('/^CRM_[A-Za-z0-9]+_(DAO|BAO)_/i', $class) === 1;
    }

    /**
     * Whether an expression is plausibly a DAO object.
     *
     * Only a `new CRM_..._DAO_...` or a variable named like one; anything
     * else would make `->find()` on an arbitrary object database traffic.
     */
    private static function looksLikeDaoVariable(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\New_) {
            return $expr->class instanceof Node\Name && self::isDaoClass($expr->class->toString());
        }
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return preg_match('/dao$/i', $expr->name) === 1;
        }

        return false;
    }
}
