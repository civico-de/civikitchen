<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;

/**
 * Recognising a write in the AST: an APIv4 or APIv3 write, a DAO/BAO write
 * method, or literal INSERT/UPDATE/DELETE.
 */
final class Mutation
{
    /** APIv4 actions that write. */
    public const API4_WRITE_ACTIONS = ['create', 'save', 'update', 'delete', 'replace'];

    /** APIv3 actions that write. */
    public const API3_WRITE_ACTIONS = ['create', 'delete', 'setvalue', 'replace', 'update'];

    /** BAO/DAO methods that write a row. */
    public const DAO_WRITE_METHODS = [
        'save', 'delete', 'create', 'add', 'del', 'writerecord', 'deleterecord', 'setisactive',
    ];

    /** Statements that change rows. */
    private const DML_KEYWORDS = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'];

    /** A short description of the write, or null when this is not one. */
    public static function describe(Node $expr): ?string
    {
        if ($expr instanceof Node\Expr\StaticCall) {
            return self::describeStaticCall($expr);
        }
        if ($expr instanceof Node\Expr\FuncCall) {
            return self::describeFuncCall($expr);
        }
        if ($expr instanceof Node\Expr\MethodCall) {
            return self::describeMethodCall($expr);
        }

        return null;
    }

    private static function describeStaticCall(Node\Expr\StaticCall $expr): ?string
    {
        $class = ltrim(Sql::staticClassName($expr) ?? '', '\\');
        $method = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '';
        if ($method === '') {
            return null;
        }

        if (stripos($class, 'Civi\\Api4\\') === 0 && self::isApi4WriteAction($method)) {
            return sprintf('%s::%s()', substr($class, strlen('Civi\\Api4\\')), $method);
        }

        if (Sql::isDaoClass($class)) {
            if (in_array(strtolower($method), self::DAO_WRITE_METHODS, true)) {
                return sprintf('%s::%s()', $class, $method);
            }
            $sql = self::sqlArgument($expr->getArgs());

            return $sql === null ? null : sprintf('%s in %s::%s()', $sql, $class, $method);
        }

        return null;
    }

    private static function describeFuncCall(Node\Expr\FuncCall $expr): ?string
    {
        if (!$expr->name instanceof Node\Name) {
            return null;
        }
        $function = strtolower(ltrim($expr->name->toString(), '\\'));
        if (!in_array($function, ['civicrm_api4', 'civicrm_api3', 'civicrm_api'], true)) {
            return null;
        }
        [$entity, $action] = Sql::apiEntityAndAction($expr);
        if ($entity === null || $action === null) {
            return null;
        }
        $writes = $function === 'civicrm_api4'
            ? self::isApi4WriteAction($action)
            : in_array(strtolower($action), self::API3_WRITE_ACTIONS, true);

        return $writes ? sprintf("%s('%s', '%s')", $function, $entity, $action) : null;
    }

    private static function describeMethodCall(Node\Expr\MethodCall $expr): ?string
    {
        $method = $expr->name instanceof Node\Identifier ? $expr->name->toLowerString() : '';
        if ($method === '') {
            return null;
        }

        // A `->save()`/`->delete()` only counts on something that names
        // itself a DAO/BAO; on an arbitrary object it is not a database write.
        if (in_array($method, ['save', 'delete'], true) && self::looksLikeRecord($expr->var)) {
            return sprintf('->%s() on a DAO/BAO', $method);
        }

        if (Sql::isDatabaseCall($expr)) {
            $sql = self::sqlArgument($expr->getArgs());

            return $sql === null ? null : sprintf('%s in ->%s()', $sql, $method);
        }

        return null;
    }

    /**
     * create/save/update/delete/replace, plus custom actions built on them
     * (`Widget::createDraft()`).
     */
    public static function isApi4WriteAction(string $action): bool
    {
        if (in_array(strtolower($action), self::API4_WRITE_ACTIONS, true)) {
            return true;
        }
        foreach (self::API4_WRITE_ACTIONS as $prefix) {
            if (str_starts_with($action, $prefix) && preg_match('/^' . $prefix . '[A-Z]/', $action) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<Node\Arg> $args
     */
    private static function sqlArgument(array $args): ?string
    {
        foreach ($args as $arg) {
            $sql = Sql::literalString($arg->value);
            $keyword = $sql === null ? null : self::dmlKeyword($sql);
            if ($keyword !== null) {
                return $keyword;
            }
        }

        return null;
    }

    /** INSERT, UPDATE, DELETE or REPLACE when a statement starts with one. */
    public static function dmlKeyword(string $sql): ?string
    {
        $trimmed = ltrim($sql);
        foreach (self::DML_KEYWORDS as $keyword) {
            if (preg_match('/^' . $keyword . '\b/i', $trimmed) === 1) {
                return strtoupper($keyword);
            }
        }

        return null;
    }

    private static function looksLikeRecord(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\New_) {
            return $expr->class instanceof Node\Name && Sql::isDaoClass($expr->class->toString());
        }
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return preg_match('/(dao|bao)$/i', $expr->name) === 1;
        }
        if ($expr instanceof Node\Expr\StaticCall) {
            return Sql::isDaoClass(Sql::staticClassName($expr));
        }

        return false;
    }
}
