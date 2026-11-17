<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;

/**
 * A call's argument for one parameter, passed by position or by name, without
 * a reflected callee. A spread or first-class callable yields null.
 */
final class CallArgs
{
    /** The expression bound to parameter $name at $position, or null. */
    public static function value(Node\Expr\CallLike $call, int $position, string $name): ?Node\Expr
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }
        foreach ($call->getArgs() as $index => $arg) {
            if ($arg->unpack) {
                return null;
            }
            if ($arg->name === null ? $index === $position : $arg->name->toString() === $name) {
                return $arg->value;
            }
        }

        return null;
    }
}
