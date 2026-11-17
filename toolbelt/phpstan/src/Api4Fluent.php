<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;

/**
 * Reading the entity out of a fluent APIv4 chain's innermost static call; the
 * return type (mostly DAOGetAction) does not name it.
 */
final class Api4Fluent
{
    /** Namespace segments under Civi\Api4\ that are not entities. */
    private const NOT_ENTITIES = ['Generic', 'Action', 'Utils', 'Query', 'Service', 'Event', 'Provider'];

    /** The entity name a `\Civi\Api4\X::y()` call addresses. */
    public static function entityFromStaticCall(StaticCall $node, Scope $scope): ?string
    {
        if (!$node->class instanceof Name) {
            return null;
        }
        $class = $scope->resolveName($node->class);
        // Class names resolve case-insensitively; the reflection has the declared spelling.
        $class = ((new ObjectType($class))->getObjectClassReflections()[0] ?? null)?->getName() ?? $class;

        return self::entityFromClass($class);
    }

    /** The entity a declared class name directly under Civi\Api4\ stands for, or null. */
    public static function entityFromClass(string $class): ?string
    {
        if (!str_starts_with($class, 'Civi\\Api4\\')) {
            return null;
        }
        $rest = substr($class, strlen('Civi\\Api4\\'));
        if (str_contains($rest, '\\') || in_array($rest, self::NOT_ENTITIES, true)) {
            return null;
        }

        return Api4Catalog::CLASS_ALIASES[$rest] ?? $rest;
    }

    /**
     * The APIv4 builders a function body holds, each with every link that configures it.
     *
     * A builder is a chain rooted in `\Civi\Api4\X::action()` or a variable
     * assigned once from one; `escapes` marks one that leaves the body.
     *
     * @param  array<Node>  $body
     * @param  list<string> $boundOutside variables the body did not assign: parameters, closure uses
     * @return list<array{entity: string, links: list<MethodCall>, escapes: bool}>
     */
    public static function builders(array $body, array $boundOutside, Scope $scope): array
    {
        $captured = [];
        $own = [];
        self::ownNodes($body, $own, $captured);

        $writes = array_fill_keys($boundOutside, [null]);
        $occurrences = [];
        $inner = [];
        foreach ($own as $node) {
            if ($node instanceof Variable && is_string($node->name)) {
                $occurrences[$node->name] = ($occurrences[$node->name] ?? 0) + 1;
            } elseif ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name)) {
                $writes[$node->var->name][] = $node->expr;
            } elseif ($node instanceof MethodCall && $node->var instanceof MethodCall) {
                $inner[spl_object_id($node->var)] = true;
            }
            foreach (self::otherWrites($node) as $name) {
                $writes[$name][] = null;
            }
        }

        // name => entity, for variables written once, from an APIv4 chain.
        $variables = [];
        $assignedChain = [];
        foreach ($writes as $name => $exprs) {
            $root = count($exprs) === 1 && $exprs[0] !== null ? self::unwind($exprs[0])[0] : null;
            $entity = $root instanceof StaticCall ? self::builderEntity($root, $scope) : null;
            if ($entity !== null) {
                $variables[$name] = $entity;
                $assignedChain[spl_object_id($exprs[0])] = (string) $name;
            }
        }

        $builders = [];
        $allowed = [];
        foreach ($own as $node) {
            if (!$node instanceof MethodCall || isset($inner[spl_object_id($node)])) {
                continue;
            }
            [$root, $links] = self::unwind($node);
            $name = $assignedChain[spl_object_id($node)]
                ?? ($root instanceof Variable && is_string($root->name) && isset($variables[$root->name]) ? $root->name : null);
            if ($name !== null) {
                $key = '$' . $name;
                $entity = $variables[$name];
                $allowed[$name] = ($allowed[$name] ?? 0) + ($root instanceof Variable ? 1 : 0);
            } else {
                $key = '#' . spl_object_id($node);
                $entity = $root instanceof StaticCall ? self::builderEntity($root, $scope) : null;
            }
            if ($entity === null) {
                continue;
            }
            $builders[$key]['entity'] = $entity;
            $builders[$key]['links'] = array_merge($builders[$key]['links'] ?? [], $links);
            $builders[$key]['escapes'] = false;
        }

        foreach ($variables as $name => $entity) {
            $key = '$' . $name;
            if (isset($builders[$key])) {
                // The assignment target is the one occurrence besides the chain roots.
                $builders[$key]['escapes'] = isset($captured[$name])
                    || ($occurrences[$name] ?? 0) > ($allowed[$name] ?? 0) + 1;
            }
        }

        return array_values($builders);
    }

    /**
     * Aliases the builder's select defines (`SUM(line_total) AS total`); null
     * when a select argument is not fully known.
     *
     * @param  list<MethodCall> $links
     * @return ?list<string>
     */
    public static function aliases(array $links, Scope $scope): ?array
    {
        return self::linkStrings($links, $scope, ['addselect', 'setselect'], self::aliasOf(...));
    }

    /**
     * Aliases the builder bound with an explicit `->addJoin('Entity AS x')`,
     * which shadow an implicit join of the same name.
     *
     * Null when a join's entity is not a known string.
     *
     * @param  list<MethodCall> $links
     * @return ?list<string>
     */
    public static function joinAliases(array $links, Scope $scope): ?array
    {
        $aliases = [];
        foreach ($links as $link) {
            $method = $link->name instanceof Identifier ? $link->name->toLowerString() : '';
            $entities = match ($method) {
                'addjoin' => [CallArgs::value($link, 0, 'entity')],
                'setjoin' => self::joinEntities(CallArgs::value($link, 0, 'join')),
                default => [],
            };
            foreach ($entities as $entity) {
                $strings = $entity === null ? [] : $scope->getType($entity)->getConstantStrings();
                if ($strings === []) {
                    return null;
                }
                foreach ($strings as $string) {
                    $aliases[] = self::joinAliasOf($string->getValue());
                }
            }
        }

        return array_values(array_filter($aliases, is_string(...)));
    }

    /**
     * The entity of each join in a literal `setJoin([[entity, …], …])`.
     *
     * @return list<?Expr>
     */
    private static function joinEntities(?Expr $joins): array
    {
        if (!$joins instanceof Expr\Array_) {
            return [null];
        }

        return array_map(
            static fn (Node\ArrayItem $join): ?Expr => $join->value instanceof Expr\Array_ ? ($join->value->items[0] ?? null)?->value : null,
            $joins->items,
        );
    }

    /**
     * String arguments the links passed to any of the named methods, mapped
     * through $map; nulls are dropped. Null when an argument is not a known
     * string or a list of known strings.
     *
     * @param  list<MethodCall>              $links
     * @param  list<string>                  $methods lowercased method names
     * @param  callable(string): ?string     $map
     * @return ?list<string>
     */
    private static function linkStrings(array $links, Scope $scope, array $methods, callable $map): ?array
    {
        $mapped = [];
        foreach ($links as $link) {
            if (!$link->name instanceof Identifier || !in_array($link->name->toLowerString(), $methods, true)) {
                continue;
            }
            foreach ($link->getArgs() as $arg) {
                $type = $scope->getType($arg->value);
                // The iterable value type also covers entries added under a condition.
                $values = $type->isArray()->yes() ? $type->getIterableValueType() : $type;
                $known = $type->isIterableAtLeastOnce()->no() || $values->isConstantScalarValue()->yes();
                if ($arg->unpack || !$known) {
                    return null;
                }
                foreach ($values->getConstantStrings() as $string) {
                    $value = $map($string->getValue());
                    if ($value !== null) {
                        $mapped[] = $value;
                    }
                }
            }
        }

        return $mapped;
    }

    /** The name `Address AS addr` binds — `Address` when it binds none. */
    private static function joinAliasOf(string $join): ?string
    {
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:\s+AS\s+([A-Za-z_][A-Za-z0-9_]*))?$/i', trim($join), $m) !== 1) {
            return null;
        }

        return $m[2] ?? $m[1];
    }

    /** The name a select expression binds, if it binds one. */
    private static function aliasOf(string $select): ?string
    {
        if (preg_match('/\sAS\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $select, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** The entity of `\Civi\Api4\X::action()` when the action's clauses name X's fields. */
    private static function builderEntity(StaticCall $call, Scope $scope): ?string
    {
        if (!$call->name instanceof Identifier || !Api4Contract::readsEntityFields($call->name->toString())) {
            return null;
        }

        return self::entityFromStaticCall($call, $scope);
    }

    /**
     * The innermost expression of a method chain and its links, inner first.
     *
     * @return array{Expr, list<MethodCall>}
     */
    private static function unwind(Expr $expr): array
    {
        $links = [];
        while ($expr instanceof MethodCall) {
            array_unshift($links, $expr);
            $expr = $expr->var;
        }

        return [$expr, $links];
    }

    /**
     * Every node of the body outside nested functions and classes; the
     * variable names those nested scopes mention go to $captured.
     *
     * @param array<mixed>       $nodes
     * @param list<Node>         $own
     * @param array<string, true> $captured
     */
    private static function ownNodes(array $nodes, array &$own, array &$captured): void
    {
        foreach ($nodes as $node) {
            if (is_array($node)) {
                self::ownNodes($node, $own, $captured);
                continue;
            }
            if (!$node instanceof Node) {
                continue;
            }
            if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
                foreach ((new NodeFinder())->findInstanceOf($node, Variable::class) as $variable) {
                    if (is_string($variable->name)) {
                        $captured[$variable->name] = true;
                    }
                }
                continue;
            }
            $own[] = $node;
            foreach ($node->getSubNodeNames() as $name) {
                self::ownNodes([$node->$name], $own, $captured);
            }
        }
    }

    /**
     * Variables a node writes other than by a plain `$x = ...`.
     *
     * @return list<string>
     */
    private static function otherWrites(Node $node): array
    {
        $targets = match (true) {
            $node instanceof Assign => $node->var instanceof Variable ? [] : [$node->var],
            $node instanceof Node\Expr\AssignRef, $node instanceof Node\Expr\AssignOp => [$node->var],
            $node instanceof Node\Stmt\Foreach_ => [$node->keyVar, $node->valueVar],
            $node instanceof Node\Stmt\Catch_ => [$node->var],
            $node instanceof Node\Stmt\Global_ => $node->vars,
            $node instanceof Node\Stmt\StaticVar => [$node->var],
            $node instanceof Node\Stmt\Unset_ => $node->vars,
            default => [],
        };
        $names = [];
        foreach ((new NodeFinder())->findInstanceOf(array_filter($targets), Variable::class) as $variable) {
            if (is_string($variable->name)) {
                $names[] = $variable->name;
            }
        }

        return $names;
    }
}
