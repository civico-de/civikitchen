<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\PHPStan\Sql;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\FileAnalysis;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * Direct database traffic and literal values in mago's syntax tree: the
 * counterpart of Sql and CallArgs, certain or silent in the same places.
 */
final class SqlNodes
{
    public const CALLS = [NodeKind::FunctionCall, NodeKind::MethodCall, NodeKind::StaticMethodCall];

    private const WRAPPERS = [NodeKind::Expression, NodeKind::Call, NodeKind::Parenthesized, NodeKind::Variable, NodeKind::CompositeString, NodeKind::Access];

    private const DOUBLE_QUOTED_ESCAPES = ['n' => "\n", 't' => "\t", 'r' => "\r", 'v' => "\v", 'e' => "\e", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'];

    private function __construct()
    {
    }

    /** Strips the Expression, Call, Access, parenthesis and Variable wrappers. */
    public static function unwrap(SourceFile $source, Node $node): Node
    {
        while (in_array($node->kind, self::WRAPPERS, true)) {
            $next = $source->getChildren($node)[0] ?? null;
            if ($next === null) {
                break;
            }
            $node = $next;
        }

        return $node;
    }

    /** The receiver of a method call, unwrapped. */
    public static function receiver(SourceFile $source, Node $call): Node
    {
        return self::unwrap($source, $source->getChildren($call)[0]);
    }

    /** A method or function name as written, null when dynamic. */
    public static function name(SourceFile $source, Node $call): ?string
    {
        return CallExpression::fromNode($source, $call)->getName($source);
    }

    /** The resolved class of `X::m()` or `new X()`, `self`/`static`/`parent` as written; null when dynamic. */
    public static function className(SourceFile $source, Node $node): ?string
    {
        $class = $node->kind === NodeKind::Instantiation
            ? self::unwrap($source, $source->getChildren($node)[1])
            : self::unwrap($source, $source->getChildren($node)[0]);

        return match ($class->kind) {
            NodeKind::Identifier => $source->getResolvedName($class)?->name ?? ltrim($source->getText($class), '\\'),
            NodeKind::Keyword => $source->getText($class),
            default => null,
        };
    }

    /** `$name` for a plain variable, else null. */
    public static function variableName(SourceFile $source, Node $node): ?string
    {
        $node = self::unwrap($source, $node);

        return $node->kind === NodeKind::DirectVariable ? substr($source->getText($node), 1) : null;
    }

    /** The argument bound to parameter $name at $position; null when a spread hides the binding. */
    public static function argument(SourceFile $source, Node $call, int $position, string $name): ?Node
    {
        foreach (CallExpression::fromNode($source, $call)->arguments as $argument) {
            if ($argument->unpacked) {
                return null;
            }
            if ($argument->name === null ? $argument->index === $position : $argument->name === $name) {
                return $argument->value;
            }
        }

        return null;
    }

    /** @return list<Node> every argument value, spreads included */
    public static function argumentValues(SourceFile $source, Node $call): array
    {
        return array_map(static fn ($argument): Node => $argument->value, CallExpression::fromNode($source, $call)->arguments);
    }

    /**
     * A literal string, one concatenated from literals, or the leading
     * literal part of an interpolated string: Sql::literalString's reading.
     */
    public static function literalString(SourceFile $source, ?Node $node): ?string
    {
        if ($node === null) {
            return null;
        }
        $node = self::unwrap($source, $node);
        if ($node->kind === NodeKind::Literal) {
            $literal = $source->getChildren($node)[0] ?? null;

            return $literal?->kind === NodeKind::LiteralString ? self::decodeQuoted($source->getText($literal)) : null;
        }
        if ($node->kind === NodeKind::Binary) {
            [$left, $operator, $right] = $source->getChildren($node);
            if ($source->getText($operator) !== '.') {
                return null;
            }
            $prefix = self::literalString($source, $left);

            return $prefix === null ? null : $prefix . (self::literalString($source, $right) ?? '');
        }
        if ($node->kind === NodeKind::InterpolatedString || $node->kind === NodeKind::DocumentString) {
            return self::stringParts($source, $node);
        }

        return null;
    }

    /** The value a literal array holds under a literal key. */
    public static function arrayItem(SourceFile $source, ?Node $array, string $key): ?Node
    {
        $array = $array === null ? null : self::unwrap($source, $array);
        if ($array?->kind !== NodeKind::Array && $array?->kind !== NodeKind::LegacyArray) {
            return null;
        }
        foreach ($source->getChildren($array) as $element) {
            $pair = $element->kind === NodeKind::ArrayElement ? ($source->getChildren($element)[0] ?? null) : $element;
            if ($pair?->kind !== NodeKind::KeyValueArrayElement) {
                continue;
            }
            [$itemKey, $value] = $source->getChildren($pair);
            if (self::literalString($source, $itemKey) === $key) {
                return $value;
            }
        }

        return null;
    }

    /** The fully qualified name of a class-like, from its declaration node. */
    public static function declaredName(SourceFile $source, Node $classLike): ?string
    {
        foreach ($source->getChildren($classLike) as $child) {
            if ($child->kind === NodeKind::LocalIdentifier) {
                return $source->getResolvedName($child)?->name ?? $source->getText($child);
            }
        }

        return null;
    }

    /** Lower-case method name => Method node, as declared in a class-like body. */
    public static function methods(SourceFile $source, Node $classLike): array
    {
        $methods = [];
        foreach ($source->getChildren($classLike) as $member) {
            $method = $member->kind === NodeKind::ClassLikeMember ? ($source->getChildren($member)[0] ?? null) : null;
            if ($method?->kind !== NodeKind::Method) {
                continue;
            }
            $name = self::methodName($source, $method);
            if ($name !== null) {
                $methods[strtolower($name)] ??= $method;
            }
        }

        return $methods;
    }

    /** A method's name as declared. */
    public static function methodName(SourceFile $source, Node $method): ?string
    {
        foreach ($source->getChildren($method) as $child) {
            if ($child->kind === NodeKind::LocalIdentifier) {
                return $source->getText($child);
            }
        }

        return null;
    }

    public static function isStatic(SourceFile $source, Node $method): bool
    {
        foreach ($source->getChildren($method) as $child) {
            if ($child->kind === NodeKind::Modifier && strtolower($source->getText($child)) === 'static') {
                return true;
            }
        }

        return false;
    }

    /** The method body's descendants of the given kinds; none for an abstract method. */
    public static function bodyNodes(SourceFile $source, Node $method, array $kinds): array
    {
        $found = [];
        foreach ($source->getChildren($method) as $child) {
            if ($child->kind !== NodeKind::MethodBody) {
                continue;
            }
            foreach ($source->getDescendants($child) as $node) {
                if (in_array($node->kind, $kinds, true)) {
                    $found[] = $node;
                }
            }
        }

        return $found;
    }

    /** `$this->x()`, `self::x()` or `static::x()`: the lower-case x, else null. */
    public static function ownCall(SourceFile $source, Node $call): ?string
    {
        $name = match ($call->kind) {
            NodeKind::MethodCall => self::variableName($source, self::receiver($source, $call)) === 'this' ? self::name($source, $call) : null,
            NodeKind::StaticMethodCall => in_array(strtolower((string) self::className($source, $call)), ['self', 'static'], true) ? self::name($source, $call) : null,
            default => null,
        };

        return $name === null ? null : strtolower($name);
    }

    /**
     * Does this call reach the database directly, bypassing APIv4? With file
     * analysis, `->execute()` on an expression typed as the SQL builder counts.
     */
    public static function isDatabaseCall(SourceFile $source, Node $node, ?FileAnalysis $analysis = null, ?Codebase $codebase = null): bool
    {
        if ($node->kind === NodeKind::StaticMethodCall) {
            $method = self::name($source, $node);

            return Sql::isDaoClass(self::className($source, $node))
                && $method !== null && in_array(strtolower($method), Sql::DAO_QUERY_METHODS, true);
        }
        if ($node->kind !== NodeKind::MethodCall) {
            return false;
        }
        $method = strtolower((string) self::name($source, $node));
        $receiver = self::receiver($source, $node);
        if ($method === 'execute') {
            return self::isSqlSelect($source, $receiver, $analysis, $codebase);
        }

        // $dao->query(...) / $dao->find(TRUE) on a DAO instance variable.
        return in_array($method, ['query', 'find', 'fetch'], true) && self::looksLikeDaoVariable($source, $receiver);
    }

    /** CRM_Core_DAO itself or a generated DAO/BAO, by `new` or by a variable named like one. */
    public static function looksLikeDaoVariable(SourceFile $source, Node $node): bool
    {
        if ($node->kind === NodeKind::Instantiation) {
            return Sql::isDaoClass(self::className($source, $node));
        }
        $name = self::variableName($source, $node);

        return $name !== null && preg_match('/dao$/i', $name) === 1;
    }

    private static function isSqlSelect(SourceFile $source, Node $node, ?FileAnalysis $analysis, ?Codebase $codebase): bool
    {
        if ($analysis !== null && $codebase !== null && self::isTypedSqlSelect($analysis->getExpressionType($node), $codebase)) {
            return true;
        }
        while ($node->kind === NodeKind::MethodCall) {
            $node = self::receiver($source, $node);
        }
        $class = in_array($node->kind, [NodeKind::StaticMethodCall, NodeKind::Instantiation], true) ? self::className($source, $node) : null;

        return $class !== null && strcasecmp(ltrim($class, '\\'), Sql::SQL_SELECT) === 0;
    }

    /** Every member of the type is the SQL builder or a subclass of it. */
    private static function isTypedSqlSelect(?\Mago\Sdk\Analyzer\Type $type, Codebase $codebase): bool
    {
        if ($type === null) {
            return false;
        }
        $builder = strtolower(Sql::SQL_SELECT);
        foreach ($type->atomicTypes as $atomic) {
            if (!$atomic instanceof NamedObjectType) {
                return false;
            }
            $name = strtolower(ltrim($atomic->name, '\\'));
            if ($name !== $builder && !in_array($builder, $codebase->getClass($name)?->parentClasses ?? [], true)) {
                return false;
            }
        }

        return true;
    }

    /** All parts when every part is literal, else the leading literal part, else null. */
    private static function stringParts(SourceFile $source, Node $string): ?string
    {
        $nowdoc = $string->kind === NodeKind::DocumentString && preg_match('/^<<<\s*\'/', $source->getText($string)) === 1;
        $quote = $string->kind === NodeKind::InterpolatedString ? '"' : '';
        $text = '';
        foreach ($source->getChildren($string) as $index => $part) {
            $literal = $part->kind === NodeKind::StringPart ? ($source->getChildren($part)[0] ?? null) : null;
            if ($literal?->kind !== NodeKind::LiteralStringPart) {
                return $index === 0 ? null : $text;
            }
            $raw = $source->getText($literal);
            $text .= $nowdoc ? $raw : self::decodeEscapes($raw, $quote);
        }

        return $text;
    }

    /** The value of a quoted literal as PHP reads it. */
    private static function decodeQuoted(string $literal): string
    {
        $literal = ltrim($literal, 'bB');
        $body = substr($literal, 1, -1);
        if ($literal[0] === "'") {
            return (string) preg_replace('/\\\\([\\\\\'])/', '$1', $body);
        }

        return self::decodeEscapes($body, '"');
    }

    /** Double-quoted escapes; $quote is the escapable delimiter ('' in a heredoc). */
    private static function decodeEscapes(string $body, string $quote): string
    {
        return (string) preg_replace_callback(
            '/\\\\(?:([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\}|(.))/s',
            static function (array $m) use ($quote): string {
                if (($m[1] ?? '') !== '') {
                    return chr(octdec($m[1]) & 0xFF);
                }
                if (($m[2] ?? '') !== '') {
                    return chr((int) hexdec($m[2]));
                }
                if (($m[3] ?? '') !== '') {
                    return (string) mb_chr((int) hexdec($m[3]), 'UTF-8');
                }
                $char = $m[4];

                return isset(self::DOUBLE_QUOTED_ESCAPES[$char]) && ($char !== '"' || $quote === '"')
                    ? self::DOUBLE_QUOTED_ESCAPES[$char]
                    : $m[0];
            },
            $body,
        );
    }
}
