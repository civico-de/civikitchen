<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A failed query that nobody ever hears about.
 *
 * When a `try` reaches the database directly and its `catch` neither
 * rethrows nor logs at error level, the failure becomes a substituted value:
 * an empty list where rows were expected, a zero where a sum was. The caller
 * cannot tell that apart from a legitimately empty result, so the bug
 * surfaces as wrong data days later instead of as an exception now. An
 * honest error beats a silent fallback.
 *
 * `\Civi::log()->debug()`/`info()` does not count: those levels are off on
 * every production site, so the branch is still silent where it matters.
 *
 * APIv4 calls are deliberately out of scope — they raise typed exceptions
 * that callers legitimately catch for control flow (an absent record, a
 * permission denial), and the catch-all here would drown that in noise.
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
