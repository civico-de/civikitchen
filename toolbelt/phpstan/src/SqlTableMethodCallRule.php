<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * Table names in `$dao->query()` and in the CRM_Utils_SQL_Select builder,
 * matched by method name since SqlSchema judges only `civicrm_` names.
 *
 * @implements Rule<Node\Expr\MethodCall>
 */
final class SqlTableMethodCallRule implements Rule
{
    private SqlSchema $schema;

    public function __construct(SqlSchema $schema)
    {
        $this->schema = $schema;
    }

    public function getNodeType(): string
    {
        return Node\Expr\MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $method = $node->name instanceof Node\Identifier ? $node->name->toLowerString() : '';
        // CRM_Core_DAO::query($query, $i18nRewrite = TRUE)
        if (Sql::isDatabaseCall($node)) {
            $literal = Sql::literalString(CallArgs::value($node, 0, 'query'));

            return $literal === null ? [] : $this->schema->checkSql($literal, sprintf('->%s()', $method));
        }

        // ->from($from, $options = []); ->join($name, $exprs, $args = NULL)
        if ($method === 'from') {
            $literal = Sql::literalString(CallArgs::value($node, 0, 'from'));

            return $literal === null ? [] : $this->schema->checkTableClause($literal, '->from()');
        }
        if ($method === 'join') {
            $literal = Sql::literalString(CallArgs::value($node, 1, 'exprs'));

            return $literal === null ? [] : $this->schema->checkSql($literal, '->join()');
        }

        return [];
    }
}
