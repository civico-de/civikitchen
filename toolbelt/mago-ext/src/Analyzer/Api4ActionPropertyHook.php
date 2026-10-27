<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\Mago\Linter\Support\Syntax;
use CiviKitchen\Mago\Workspace;
use CiviKitchen\PHPStan\Api4ActionParameter;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\PropertyMetadata;
use Mago\Sdk\Analyzer\Metadata\TypeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;
use Mago\Sdk\Syntax\TriviaKind;
use LogicException;
use RuntimeException;

/**
 * APIv4 action parameters a caller can leave unset: typed without a default
 * fatals before the kernel checks @required; @required with a default is never enforced.
 */
final class Api4ActionPropertyHook implements NodeAnalysisHook
{
    private const ABSTRACT_ACTION = 'civi\\api4\\generic\\abstractaction';

    public function getTargets(): array
    {
        return [NodeKind::Class_, NodeKind::AnonymousClass];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $source = $context->source;
        if (!self::isApi4Action($source, $context->node, $context->codebase)) {
            return;
        }
        $members = array_map(
            static fn (Node $member): Node => $source->getChildren($member)[0],
            array_values(array_filter($source->getChildren($context->node), static fn (Node $child): bool => $child->kind === NodeKind::ClassLikeMember)),
        );
        /** @var array<string, Node> $methods by lowercase name */
        $methods = [];
        foreach ($members as $member) {
            if ($member->kind === NodeKind::Method) {
                $methods[strtolower(self::textOf($source, $member, NodeKind::LocalIdentifier))] = $member;
            }
        }
        $initialized = isset($methods['__construct']) ? self::assignedIn($source, $methods['__construct']) : [];

        foreach (self::parameters($source, $members, $context->codebase) as [$name, $type, $hasDefault, $emptyDefault, $required, $at]) {
            $getter = $methods[strtolower('get' . $name)] ?? null;
            if (str_starts_with($name, '_') || isset($initialized[$name]) || ($getter !== null && GuardedGetter::guards($source, $getter, $name))) {
                continue;
            }
            $problem = Api4ActionParameter::problem($name, $type, $hasDefault, $emptyDefault, $required);
            if ($problem !== null) {
                $code = 'api4-' . strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $problem[0]));
                $context->report(Level::Error, $code, Issue::new($problem[1], $at->span));
            }
        }
    }

    /** A subclass of AbstractAction, or by name alone when core is not in the codebase. */
    private static function isApi4Action(SourceFile $source, Node $class, Codebase $codebase): bool
    {
        $extends = self::childOf($source, $class, NodeKind::Extends);
        $parent = $extends === null ? null : self::childOf($source, $extends, NodeKind::Identifier);
        $name = $parent === null ? null : ltrim($source->getResolvedName($parent)?->name ?? $source->getText($parent), '\\');
        if ($name === null) {
            return false;
        }
        $lineage = array_map('strtolower', [$name, ...$codebase->getClassAncestors($name)]);

        return in_array(self::ABSTRACT_ACTION, $lineage, true) || Api4ActionParameter::isGenericActionBase($name);
    }

    /**
     * The protected, non-static properties of the class and of its traits: name, type
     * (null when untyped), default, empty default, @required, and the node to report on.
     *
     * @param  list<Node> $members
     * @return list<array{string, ?string, bool, bool, bool, Node}>
     */
    private static function parameters(SourceFile $source, array $members, Codebase $codebase): array
    {
        $parameters = [];
        foreach ($members as $member) {
            if ($member->kind === NodeKind::Property) {
                $property = $source->getChildren($member)[0];
                $modifiers = array_map(
                    static fn (Node $modifier): string => strtolower($source->getText($modifier)),
                    array_filter($source->getChildren($property), static fn (Node $child): bool => $child->kind === NodeKind::Modifier),
                );
                if (!in_array('protected', $modifiers, true) || in_array('static', $modifiers, true)) {
                    continue;
                }
                $hint = self::childOf($source, $property, NodeKind::Hint);
                $required = Api4ActionParameter::hasRequiredTag(self::docComment($source, $member));
                foreach ($source->getDescendants($property, NodeKind::PropertyItem) as $item) {
                    $item = $source->getChildren($item)[0];
                    $name = ltrim(self::textOf($source, $item, NodeKind::DirectVariable), '$');
                    $default = self::childOf($source, $item, NodeKind::Expression);
                    // An untyped property without a default is implicitly null.
                    $hasDefault = $default !== null || $hint === null;
                    $parameters[$name] = [$name, $hint === null ? null : self::hintToString($source, $hint),
                        $hasDefault, $default === null ? $hasDefault : self::isEmptyDefault($source->getText($default)), $required, $member];
                }
            }
            if ($member->kind !== NodeKind::TraitUse) {
                continue;
            }
            foreach ($source->getChildren($member) as $trait) {
                if ($trait->kind !== NodeKind::Identifier) {
                    continue;
                }
                $traitName = ltrim($source->getResolvedName($trait)?->name ?? $source->getText($trait), '\\');
                foreach ($codebase->getTrait($traitName)->properties ?? [] as $propertyName) {
                    $property = $codebase->getProperty($traitName, $propertyName);
                    $name = ltrim($propertyName, '$');
                    if ($property === null || $property->readVisibility !== Visibility::Protected
                        || $property->flags->contains(MetadataFlags::STATIC) || isset($parameters[$name])) {
                        continue;
                    }
                    $parameters[$name] = self::traitParameter($name, $property, $member);
                }
            }
        }

        return array_values($parameters);
    }

    /**
     * A trait property from the trait's own source text: metadata widens its default to the declared type.
     *
     * @return array{string, ?string, bool, bool, bool, Node}
     */
    private static function traitParameter(string $name, PropertyMetadata $property, Node $use): array
    {
        $file = $property->nameLocation?->file;
        $text = $file === null ? false : file_get_contents(Workspace::absolute($file));
        if ($text === false) {
            throw new RuntimeException("cannot read the source of trait property \${$name}");
        }
        $slice = static fn (?TypeMetadata $type): ?string => $type === null || $type->fromDocblock ? null
            : substr($text, $type->location->span->start, $type->location->span->length());
        $type = $slice($property->declaredType);
        $default = $slice($property->defaultType);
        // An untyped property without a default is implicitly null.
        $hasDefault = $property->flags->contains(MetadataFlags::HAS_DEFAULT) || $type === null;
        $start = $property->declaredType?->location->span->start ?? $property->nameLocation?->span->start ?? 0;
        $m = [];
        $doc = preg_match('~(/\*\*(?:(?!\*/).)*\*/)\s*(?:#\[[^\]]*\]\s*)*(?:(?:public|protected|private|static|readonly|var)\s+)*$~si', substr($text, 0, $start), $m) === 1 ? $m[1] : null;

        return [$name, $type, $hasDefault, $hasDefault && ($default === null || self::isEmptyDefault($default)), Api4ActionParameter::hasRequiredTag($doc), $use];
    }

    /** @return array<string, true> properties the constructor assigns through `$this->x = ...` */
    private static function assignedIn(SourceFile $source, Node $constructor): array
    {
        $assigned = [];
        foreach ($source->getDescendants($constructor, NodeKind::Assignment) as $assignment) {
            [$target, $operator] = $source->getChildren($assignment);
            $target = Syntax::unwrap($source, $target);
            $target = $target->kind === NodeKind::Access ? $source->getChildren($target)[0] : $target;
            if ($source->getText($operator) !== '=' || $target->kind !== NodeKind::PropertyAccess) {
                continue;
            }
            [$object, $selector] = $source->getChildren($target);
            $identifier = $source->getChildren($selector)[0] ?? null;
            if ($source->getText($object) === '$this' && $identifier?->kind === NodeKind::LocalIdentifier) {
                $assigned[$source->getText($identifier)] = true;
            }
        }

        return $assigned;
    }

    /** The last doc comment between the previous code and the member, as PHP-Parser attaches it. */
    private static function docComment(SourceFile $source, Node $member): ?string
    {
        $doc = null;
        $end = $member->span->start;
        foreach (array_reverse($source->getTrivia()) as $trivia) {
            if ($trivia->span->end > $end) {
                continue;
            }
            if (trim(substr($source->contents, $trivia->span->end, $end - $trivia->span->end)) !== '') {
                break;
            }
            $doc ??= $trivia->kind === TriviaKind::DocBlockComment ? $source->getText($trivia->span) : null;
            $end = $trivia->span->start;
        }

        return $doc;
    }

    /** null, '', [] and FALSE, which ValidateFieldsSubscriber treats as missing; read by token, so parentheses and spacing do not count. */
    private static function isEmptyDefault(string $default): bool
    {
        $text = '';
        foreach (array_slice(token_get_all("<?php {$default};"), 1, -1) as $token) {
            $code = is_array($token) ? $token[1] : $token;
            $text .= in_array($code, ['(', ')'], true) || (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) ? '' : $code;
        }

        return in_array(ltrim(strtolower($text), '\\'), ['null', 'false', "''", '""', '[]', 'array'], true);
    }

    private static function hintToString(SourceFile $source, Node $hint): string
    {
        $inner = $source->getChildren($hint)[0];
        $hints = static fn (string $glue): string => implode($glue, array_map(
            static fn (Node $part): string => self::hintToString($source, $part),
            array_values(array_filter($source->getChildren($inner), static fn (Node $child): bool => $child->kind === NodeKind::Hint)),
        ));

        return match ($inner->kind) {
            NodeKind::NullableHint => '?' . $hints(''),
            NodeKind::UnionHint => $hints('|'),
            NodeKind::IntersectionHint, NodeKind::ParenthesizedHint => $hints('&'),
            NodeKind::Identifier => ltrim($source->getResolvedName($inner)?->name ?? $source->getText($inner), '\\'),
            default => $source->getText($inner),
        };
    }

    /** The text of a child the grammar guarantees, such as a method's or a property item's name. */
    private static function textOf(SourceFile $source, Node $node, NodeKind $kind): string
    {
        $child = self::childOf($source, $node, $kind) ?? throw new LogicException("{$node->kind->value} without {$kind->value}");

        return $source->getText($child);
    }

    private static function childOf(SourceFile $source, Node $node, NodeKind $kind): ?Node
    {
        foreach ($source->getChildren($node) as $child) {
            if ($child->kind === $kind) {
                return $child;
            }
        }

        return null;
    }
}
