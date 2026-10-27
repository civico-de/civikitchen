<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\Mago\Linter\Support\Syntax;
use CiviKitchen\PHPStan\Api4Fluent;
use CiviKitchen\PHPStan\ClassLookup;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/** Syntax questions the APIv4 hooks share. */
final class Api4Calls
{
    private function __construct()
    {
    }

    /** The argument bound to parameter $name at $position; null when a spread hides the binding. */
    public static function argument(CallExpression $call, int $position, string $name): ?Node
    {
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked) {
                return null;
            }
            if ($argument->name === null ? $argument->index === $position : $argument->name === $name) {
                return $argument->value;
            }
        }

        return null;
    }

    public static function typeOf(NodeAnalysisContext $context, ?Node $node): ?Type
    {
        return $node === null ? null : $context->analysis->getExpressionType($node);
    }

    /**
     * The entity a `\Civi\Api4\X::…` class reference names, in X's declared
     * spelling: php resolves class names case-insensitively.
     */
    public static function entityOfClassReference(SourceFile $source, Node $class, ClassLookup $classes): ?string
    {
        $class = Syntax::unwrap($source, $class);
        if ($class->kind !== NodeKind::Identifier) {
            return null;
        }
        $resolved = $source->getResolvedName($class)?->name;
        if ($resolved === null) {
            return null;
        }
        $resolved = ltrim($resolved, '\\');

        return Api4Fluent::entityFromClass($classes->declaredName($resolved) ?? $resolved);
    }
}
