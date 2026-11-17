<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A `try` reaching the database directly whose `catch` neither rethrows nor
 * logs at error level or above. APIv4 calls are out of scope: their typed
 * exceptions are legitimately caught for control flow.
 *
 * @implements Rule<Node\Stmt\TryCatch>
 */
final class NoSilentDatabaseCatchRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Stmt\TryCatch::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!self::containsDatabaseCall($node->stmts, $scope)) {
            return [];
        }

        $errors = [];
        foreach ($node->catches as $catch) {
            if (self::handles($catch)) {
                continue;
            }
            $errors[] = RuleErrorBuilder::message(DatabaseCatch::message($catch->stmts === []))->identifier('ck.db.silentCatch')->line($catch->getStartLine())->build();
        }

        return $errors;
    }

    /**
     * @param array<Node\Stmt> $stmts
     */
    private static function containsDatabaseCall(array $stmts, Scope $scope): bool
    {
        return (new NodeFinder())->findFirst($stmts, static fn (Node $n): bool => Sql::isDatabaseCall($n, $scope)) !== null;
    }

    /** Does the catch body make the failure visible? */
    private static function handles(Node\Stmt\Catch_ $catch): bool
    {
        $found = (new NodeFinder())->findFirst(
            $catch->stmts,
            static fn (Node $n): bool => $n instanceof Node\Expr\Throw_ || self::isLoudLog($n),
        );

        return $found !== null;
    }

    /** \Civi::log()->error(...), ->log(LogLevel::ERROR, ...) and the levels above. */
    private static function isLoudLog(Node $node): bool
    {
        if (!($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)
            || !$node->name instanceof Node\Identifier) {
            return false;
        }
        $method = $node->name->toLowerString();
        $level = $method === 'log' ? self::logLevel(CallArgs::value($node, 0, 'level')) : $method;
        if (!in_array($level, DatabaseCatch::LOUD_LEVELS, true)) {
            return false;
        }
        // The receiver only has to be something that produces a logger.
        $receiver = $node->var;
        if ($receiver instanceof Node\Expr\StaticCall) {
            return $receiver->name instanceof Node\Identifier && $receiver->name->toLowerString() === 'log';
        }

        return $receiver instanceof Node\Expr\Variable
            || $receiver instanceof Node\Expr\PropertyFetch
            || $receiver instanceof Node\Expr\MethodCall;
    }

    /** The lowercased level of `'error'` or `LogLevel::ERROR`; null when unreadable. */
    private static function logLevel(?Node\Expr $level): ?string
    {
        if ($level instanceof Node\Expr\ClassConstFetch && $level->class instanceof Node\Name
            && $level->name instanceof Node\Identifier && strcasecmp($level->class->getLast(), 'LogLevel') === 0) {
            return $level->name->toLowerString();
        }
        $literal = $level === null ? null : Sql::literalString($level);

        return $literal === null ? null : strtolower($literal);
    }
}
