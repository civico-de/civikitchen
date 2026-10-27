<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\Mago\Workspace;
use CiviKitchen\PHPStan\ExtensionBoundary;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Mago\Sdk\Analyzer\Metadata\TypeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Type\EnumType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * A class-like's CRM_/Civi\ dependencies outside core, the extension itself and its
 * <requires>, and legacy UI bases in its ancestry. See ArchitectureTest.
 */
final class ExtensionBoundaryHook implements NodeAnalysisHook
{
    private const CIVICRM = '~^(CRM_|Civi\\\\)~';

    /** Name references that are not classes. */
    private const NOT_CLASSES = [NodeKind::FunctionCall, NodeKind::ConstantAccess];

    /** @var array<string, array{regexes: list<string>, missing: list<string>}> */
    private array $allowed = [];

    public function getTargets(): array
    {
        return [NodeKind::Class_, NodeKind::Interface, NodeKind::Trait, NodeKind::Enum];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::TargetSubtree];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $source = $context->source;
        $root = Workspace::extensionRoot($source);
        $name = SqlNodes::declaredName($source, $context->node);
        if ($root === null || $name === null) {
            return;
        }
        $allowed = $this->allowed[$root] ??= ExtensionBoundary::allowed($root);
        if ($allowed['regexes'] === []) {
            return;
        }
        $class = $context->codebase->getClassLike(strtolower($name));
        foreach ($this->dependencies($context, $class) as [$dependency, $anchor]) {
            if (!self::forbidden($dependency, $allowed['regexes'])) {
                continue;
            }
            $context->report(Level::Error, 'extension-boundary', Issue::new("{$name} should not depend on {$dependency}", $anchor)
                ->withHelp(ExtensionBoundary::because($allowed['missing'])));
        }
        if ($class === null) {
            return;
        }
        foreach (ExtensionBoundary::LEGACY_UI_BASES as $base) {
            if (in_array(strtolower($base), $class->parentClasses, true)) {
                $context->report(Level::Error, 'legacy-ui-base', Issue::new("{$name} should not extend {$base}", $context->node->span)
                    ->withHelp(ExtensionBoundary::LEGACY_UI_BECAUSE));
            }
        }
    }

    /** @param list<string> $regexes */
    private static function forbidden(string $dependency, array $regexes): bool
    {
        if (preg_match(self::CIVICRM, $dependency) !== 1 || preg_match(ExtensionBoundary::coreRegex(), $dependency) === 1) {
            return false;
        }
        foreach ($regexes as $regex) {
            if (preg_match($regex, $dependency) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every referenced class with the span phpat reports it at: its own inside
     * a method body, else the member's, else the class-like's.
     *
     * @return list<array{string, Span}>
     */
    private function dependencies(NodeAnalysisContext $context, ?ClassLikeMetadata $class): array
    {
        $source = $context->source;
        $identifiers = [];
        foreach ($source->getDescendants($context->node, NodeKind::Identifier) as $identifier) {
            $identifiers[$identifier->span->start] = $identifier;
        }
        $dependencies = [];
        foreach ($source->getResolvedNames($context->node) as $resolved) {
            $identifier = $identifiers[$resolved->span->start] ?? null;
            if ($identifier !== null && !self::namesNonClass($source, $identifier)) {
                $dependencies[] = [$resolved->name, self::anchor($source, $identifier, $context->node)];
            }
        }
        if ($class === null) {
            return $dependencies;
        }
        // `parent` names the direct parent, as phpat counts it; a nested anonymous class has its own.
        $parent = $class->directParentClass === null ? null : $context->codebase->getClassLike($class->directParentClass)?->originalName;
        foreach ($parent === null ? [] : $source->getDescendants($context->node, NodeKind::Keyword) as $keyword) {
            if (strtolower($source->getText($keyword)) === 'parent' && !self::inAnonymousClass($source, $keyword)) {
                $dependencies[] = [$parent, self::anchor($source, $keyword, $context->node)];
            }
        }
        $methods = SqlNodes::methods($source, $context->node);
        $ids = array_map(static fn (string $method): MemberIdentifier => new MemberIdentifier($class->name, $method), $class->methods);
        foreach ($context->codebase->getMultipleMethods($ids) as $method) {
            $node = $methods[strtolower($method?->name ?? '')] ?? null;
            if ($node === null) {
                continue;
            }
            foreach ([$method->returnType, ...array_map(static fn ($parameter) => $parameter->type, $method->parameters)] as $type) {
                foreach (self::docblockClasses($type) as $dependency) {
                    $dependencies[] = [$dependency, $node->span];
                }
            }
        }
        $ids = array_map(static fn (string $property): MemberIdentifier => new MemberIdentifier($class->name, $property), $class->properties);
        foreach ($context->codebase->getMultipleProperties($ids) as $property) {
            $member = self::member($source, $context->node, $property?->nameLocation?->file === $source->path ? $property->nameLocation->span : null);
            foreach ($member === null ? [] : self::docblockClasses($property->type) as $dependency) {
                $dependencies[] = [$dependency, $member->span];
            }
        }

        return $dependencies;
    }

    private static function namesNonClass(SourceFile $source, Node $identifier): bool
    {
        $parent = $source->getParent($identifier);
        if ($parent?->kind === NodeKind::Expression) {
            $parent = $source->getParent($parent);
        }

        return in_array($parent?->kind, self::NOT_CLASSES, true);
    }

    private static function inAnonymousClass(SourceFile $source, Node $node): bool
    {
        foreach ($source->getAncestors($node) as $ancestor) {
            if ($ancestor->kind === NodeKind::AnonymousClass) {
                return true;
            }
        }

        return false;
    }

    private static function anchor(SourceFile $source, Node $identifier, Node $classLike): Span
    {
        for ($node = $source->getParent($identifier); $node !== null && $node->id !== $classLike->id; $node = $source->getParent($node)) {
            if ($node->kind === NodeKind::MethodBody) {
                return $identifier->span;
            }
            if ($node->kind === NodeKind::ClassLikeMember) {
                $member = $source->getChildren($node)[0] ?? $node;

                return $member->kind === NodeKind::TraitUse ? $classLike->span : $member->span;
            }
        }

        return $classLike->span;
    }

    /** The member declaration of a class-like that contains the span. */
    private static function member(SourceFile $source, Node $classLike, ?Span $span): ?Node
    {
        foreach ($span === null ? [] : $source->getChildren($classLike) as $child) {
            $member = $child->kind === NodeKind::ClassLikeMember ? ($source->getChildren($child)[0] ?? null) : null;
            if ($member !== null && $member->span->contains($span)) {
                return $member;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function docblockClasses(?TypeMetadata $type): array
    {
        $names = [];
        if ($type !== null && $type->fromDocblock) {
            self::collect($type->type, $names);
        }

        return $names;
    }

    /** @param list<string> $names */
    private static function collect(mixed $value, array &$names): void
    {
        if ($value instanceof NamedObjectType || $value instanceof EnumType) {
            $names[] = $value->name;
        }
        if (is_object($value) || is_array($value)) {
            foreach (is_object($value) ? get_object_vars($value) : $value as $child) {
                self::collect($child, $names);
            }
        }
    }
}
