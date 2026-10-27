<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * API parameters declared as properties of a custom APIv4 action.
 *
 * On a subclass of AbstractAction every protected property without a leading
 * underscore IS an API parameter: the generic action reads them, `getFields`
 * publishes them, and a caller may leave any of them unset. A
 * property without a default therefore does not produce the API
 * validation error the author expected — it produces PHP's "must not be
 * accessed before initialization" Error, from inside the API kernel, with a
 * stack trace that names no field.
 *
 * `@required` does NOT save it, which is the whole point of this rule:
 * ValidateFieldsSubscriber::onApiPrepare() reads every parameter through its
 * getter FIRST and only then asks whether it was required, so the read that
 * fatals happens before the check that would have produced
 * `Parameter "x" is required.`. Verified in a running install. Core's own
 * dominant form for a mandatory parameter is therefore untyped: a property
 * with no default, an `@var` docblock for the type and `@required`.
 *
 * The same holds for `?T` and `mixed`: a typed property without a default
 * starts uninitialized whatever its type. Properties a trait contributes are
 * parameters too, and one the constructor assigns is initialized. A declared
 * getter that reads it only through `??` or isset() is what the kernel calls, so
 * it is safe too.
 *
 * The mirror image is reported too: `@required` next to a default the kernel
 * accepts (anything but null, '', [] or FALSE) promises a validation that
 * cannot happen, because the parameter is never missing.
 *
 * @implements Rule<InClassNode>
 */
final class Api4ActionPropertyRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();
        $class = $node->getOriginalNode();
        if (!self::isApi4Action($classReflection, $node)) {
            return [];
        }
        $initialized = self::assignedInConstructor($class);

        $errors = [];
        foreach (self::parameters($class, $classReflection) as [$name, $type, $hasDefault, $emptyDefault, $required, $line]) {
            if (str_starts_with($name, '_') || isset($initialized[$name]) || self::hasGuardedGetter($class, $name)) {
                continue;
            }
            $problem = Api4ActionParameter::problem($name, $type, $hasDefault, $emptyDefault, $required);
            if ($problem !== null) {
                $errors[] = RuleErrorBuilder::message($problem[1])->identifier('ck.api4.' . $problem[0])->line($line)->build();
            }
        }

        return $errors;
    }

    /**
     * The protected, non-static properties of the class and of its traits:
     * name, type (null when untyped), default, empty default, @required, line.
     * A trait's properties are reported on its `use` line.
     *
     * @return list<array{string, ?string, bool, bool, bool, int}>
     */
    private static function parameters(Node\Stmt\ClassLike $class, ClassReflection $reflection): array
    {
        $parameters = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Property && $stmt->isProtected() && !$stmt->isStatic()) {
                foreach ($stmt->props as $property) {
                    // An untyped property without a default is implicitly null.
                    $default = $property->default ?? ($stmt->type === null ? new Node\Expr\ConstFetch(new Node\Name('null')) : null);
                    $parameters[$property->name->toString()] = [
                        $property->name->toString(),
                        $stmt->type === null ? null : self::typeToString($stmt->type),
                        $default !== null,
                        $default !== null && self::isEmptyDefault($default),
                        Api4ActionParameter::hasRequiredTag($stmt->getDocComment()?->getText()),
                        $stmt->getStartLine(),
                    ];
                }
            }
            if (!$stmt instanceof Node\Stmt\TraitUse) {
                continue;
            }
            foreach ($stmt->traits as $traitName) {
                $trait = self::traitReflection($reflection, $traitName->toString());
                foreach ($trait?->getNativeReflection()->getProperties() ?? [] as $property) {
                    if (!$property->isProtected() || $property->isStatic() || isset($parameters[$property->getName()])) {
                        continue;
                    }
                    $parameters[$property->getName()] = [
                        $property->getName(),
                        $property->hasType() ? (string) $property->getType() : null,
                        $property->hasDefaultValue(),
                        $property->hasDefaultValue() && in_array($property->getDefaultValue(), [null, '', [], false], true),
                        Api4ActionParameter::hasRequiredTag($property->getDocComment() ?: null),
                        $stmt->getStartLine(),
                    ];
                }
            }
        }

        return array_values($parameters);
    }

    private static function traitReflection(ClassReflection $class, string $name): ?ClassReflection
    {
        foreach ($class->getTraits(true) as $trait) {
            if (strcasecmp($trait->getName(), $name) === 0) {
                return $trait;
            }
        }

        return null;
    }

    /**
     * Properties the constructor assigns through `$this->x = ...`.
     *
     * @return array<string, true>
     */
    private static function assignedInConstructor(Node\Stmt\ClassLike $class): array
    {
        $constructor = $class->getMethod('__construct');
        $assigned = [];
        foreach ((new NodeFinder())->findInstanceOf($constructor?->stmts ?? [], Node\Expr\Assign::class) as $assign) {
            $target = $assign->var;
            if ($target instanceof Node\Expr\PropertyFetch && $target->var instanceof Node\Expr\Variable
                && $target->var->name === 'this' && $target->name instanceof Node\Identifier) {
                $assigned[$target->name->toString()] = true;
            }
        }

        return $assigned;
    }

    /**
     * A declared `get<Name>()` that reads the property only inside `??`, isset(), empty(),
     * or the branch, operand or match arm where it is set — reads an uninitialized one survives.
     */
    private static function hasGuardedGetter(Node\Stmt\ClassLike $class, string $name): bool
    {
        $stmts = $class->getMethod('get' . ucfirst($name))?->stmts;
        if ($stmts === null) {
            return false;
        }
        $finder = new NodeFinder();
        $isRead = static fn (Node $node): bool => $node instanceof Node\Expr\PropertyFetch
            && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier && $node->name->toString() === $name;
        $safe = [];
        foreach ($finder->find($stmts, static fn (Node $node): bool => $node instanceof Node\Expr\Isset_
            || $node instanceof Node\Expr\Empty_ || $node instanceof Node\Expr\BinaryOp\Coalesce || $node instanceof Node\Expr\AssignOp\Coalesce
            || $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\Ternary || $node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\ElseIf_ || self::isConjunction($node) || self::isDisjunction($node)
            || $node instanceof Node\Expr\Match_) as $guard) {
            // These read their operand without the uninitialized error, but not its offsets or call arguments.
            $links = match (true) {
                $guard instanceof Node\Expr\Isset_ => array_merge(...array_map(self::fetchChain(...), $guard->vars)),
                $guard instanceof Node\Expr\Empty_ => self::fetchChain($guard->expr),
                $guard instanceof Node\Expr\BinaryOp\Coalesce => self::fetchChain($guard->left),
                // An assignment target is a write, which creates arrays on the way but not objects.
                $guard instanceof Node\Expr\AssignOp\Coalesce, $guard instanceof Node\Expr\Assign => self::fetchChain($guard->var, arraysOnly: true),
                default => [],
            };
            foreach ($links as $link) {
                if ($isRead($link)) {
                    $safe[spl_object_id($link)] = true;
                }
            }
            $presence = match (true) {
                $guard instanceof Node\Expr\Ternary, $guard instanceof Node\Stmt\If_, $guard instanceof Node\Stmt\ElseIf_ => self::presence($guard->cond, $isRead),
                $guard instanceof Node\Expr\BinaryOp => self::presence($guard->left, $isRead),
                default => null,
            };
            $covered = match (true) {
                $guard instanceof Node\Expr\Ternary && $presence === true && $guard->if !== null => [$guard->if],
                $guard instanceof Node\Expr\Ternary && $presence === false => [$guard->else],
                ($guard instanceof Node\Stmt\If_ || $guard instanceof Node\Stmt\ElseIf_) && $presence === true => $guard->stmts,
                $guard instanceof Node\Stmt\If_ => self::branchesAfterUnset($guard, $isRead),
                self::isConjunction($guard) && $presence === true,
                self::isDisjunction($guard) && $presence === false => [$guard->right],
                $guard instanceof Node\Expr\Match_ => self::coveredArms($guard, $isRead),
                default => [],
            };
            foreach ($finder->find($covered, $isRead) as $read) {
                $safe[spl_object_id($read)] = true;
            }
        }
        $reads = $finder->find($stmts, $isRead);
        $unsafe = array_diff_key(array_flip(array_map(spl_object_id(...), $finder->find(self::untilInitialised($stmts, $isRead), $isRead))), $safe);

        return $reads !== [] && $unsafe === [];
    }

    /**
     * In `match (true)`: the body of an arm whose conditions all say the property is set,
     * and every arm after one whose condition says it is unset.
     *
     * @param  callable(Node): bool $isRead
     * @return list<Node>
     */
    private static function coveredArms(Node\Expr\Match_ $match, callable $isRead): array
    {
        if (!$match->cond instanceof Node\Expr\ConstFetch || $match->cond->name->toLowerString() !== 'true') {
            return [];
        }
        $covered = [];
        foreach ($match->arms as $i => $arm) {
            $presences = array_map(static fn (Node\Expr $cond): ?bool => self::presence($cond, $isRead), $arm->conds ?? []);
            if (in_array(false, $presences, true)) {
                foreach (array_slice($match->arms, $i + 1) as $later) {
                    array_push($covered, ...($later->conds ?? []), ...[$later->body]);
                }

                return $covered;
            }
            if ($presences !== [] && !in_array(null, $presences, true)) {
                $covered[] = $arm->body;
            }
        }

        return $covered;
    }

    /** @phpstan-assert-if-true Node\Expr\BinaryOp\BooleanAnd|Node\Expr\BinaryOp\LogicalAnd $node */
    private static function isConjunction(Node $node): bool
    {
        return $node instanceof Node\Expr\BinaryOp\BooleanAnd || $node instanceof Node\Expr\BinaryOp\LogicalAnd;
    }

    /** @phpstan-assert-if-true Node\Expr\BinaryOp\BooleanOr|Node\Expr\BinaryOp\LogicalOr $node */
    private static function isDisjunction(Node $node): bool
    {
        return $node instanceof Node\Expr\BinaryOp\BooleanOr || $node instanceof Node\Expr\BinaryOp\LogicalOr;
    }

    /**
     * The elseif and else branches after a condition whose being false means the property is set.
     *
     * @param  callable(Node): bool $isRead
     * @return list<Node>
     */
    private static function branchesAfterUnset(Node\Stmt\If_ $if, callable $isRead): array
    {
        foreach ([$if, ...$if->elseifs] as $i => $branch) {
            if (self::presence($branch->cond, $isRead) === false) {
                return [...array_slice($if->elseifs, $i), ...($if->else !== null ? [$if->else] : [])];
            }
        }

        return [];
    }

    /**
     * The expression and the bases it is fetched from: `$this->x` in `$this->x['k']->y`, not the offset.
     *
     * @return list<Node\Expr>
     */
    private static function fetchChain(Node\Expr $expr, bool $arraysOnly = false): array
    {
        $chain = [$expr];
        while ($expr instanceof Node\Expr\ArrayDimFetch
            || (!$arraysOnly && ($expr instanceof Node\Expr\PropertyFetch || $expr instanceof Node\Expr\NullsafePropertyFetch))) {
            $expr = $expr->var;
            $chain[] = $expr;
        }

        return $chain;
    }

    /**
     * The statements up to the one that initialises the property — inside an initialising
     * if, its conditions and each branch up to the assignment — after which reads are safe.
     *
     * @param  array<Node\Stmt>        $stmts
     * @param  callable(Node): bool     $isRead
     * @return list<Node>
     */
    private static function untilInitialised(array $stmts, callable $isRead): array
    {
        $nodes = [];
        foreach ($stmts as $stmt) {
            if (!self::initialises($stmt, $isRead)) {
                $nodes[] = $stmt;
                continue;
            }
            if ($stmt instanceof Node\Stmt\If_) {
                foreach ($stmt->elseifs as $elseif) {
                    array_push($nodes, $elseif->cond, ...self::untilInitialised($elseif->stmts, $isRead));
                }

                return [...$nodes, $stmt->cond, ...self::untilInitialised($stmt->stmts, $isRead), ...self::untilInitialised($stmt->else->stmts ?? [], $isRead)];
            }

            return [...$nodes, $stmt];
        }

        return $nodes;
    }

    /**
     * TRUE when the expression being true means the property is set (`isset($this->x)`),
     * FALSE when its being false does (`empty($this->x)`), else NULL; `!` swaps them.
     *
     * @param callable(Node): bool $isRead
     */
    private static function presence(Node\Expr $expr, callable $isRead): ?bool
    {
        if (self::isConjunction($expr) || self::isDisjunction($expr)) {
            $decisive = self::isConjunction($expr);

            return in_array($decisive, [self::presence($expr->left, $isRead), self::presence($expr->right, $isRead)], true) ? $decisive : null;
        }
        if ($expr instanceof Node\Expr\BooleanNot) {
            $inner = self::presence($expr->expr, $isRead);

            return $inner === null ? null : !$inner;
        }
        // `isset($this->x) === false`, `empty($this->x) != true`.
        if (($expr instanceof Node\Expr\BinaryOp\Identical || $expr instanceof Node\Expr\BinaryOp\NotIdentical
            || $expr instanceof Node\Expr\BinaryOp\Equal || $expr instanceof Node\Expr\BinaryOp\NotEqual)
            && $expr->right instanceof Node\Expr\ConstFetch && in_array($expr->right->name->toLowerString(), ['true', 'false'], true)) {
            $inner = self::presence($expr->left, $isRead);
            $flip = ($expr->right->name->toLowerString() === 'false')
                !== ($expr instanceof Node\Expr\BinaryOp\NotIdentical || $expr instanceof Node\Expr\BinaryOp\NotEqual);

            return $inner === null ? null : $inner !== $flip;
        }
        // A set offset or property of the property means the property is set too.
        $reads = static fn (Node\Expr $operand): bool => array_filter(self::fetchChain($operand), $isRead) !== [];

        return match (true) {
            $expr instanceof Node\Expr\Isset_ && array_filter($expr->vars, $reads) !== [] => true,
            $expr instanceof Node\Expr\Empty_ && $reads($expr->expr) => false,
            default => null,
        };
    }

    /**
     * `$this->x ??= …;`, `$this->x = …;` (also to an offset), or an if whose branches initialise
     * or find it set, up to one whose condition being false means it is set, or through the else.
     *
     * @param callable(Node): bool $isRead
     */
    private static function initialises(Node\Stmt $stmt, callable $isRead): bool
    {
        if ($stmt instanceof Node\Stmt\Expression) {
            return ($stmt->expr instanceof Node\Expr\Assign || $stmt->expr instanceof Node\Expr\AssignOp\Coalesce)
                && array_filter(self::fetchChain($stmt->expr->var, arraysOnly: true), $isRead) !== [];
        }
        if (!$stmt instanceof Node\Stmt\If_) {
            return false;
        }
        foreach ([$stmt, ...$stmt->elseifs] as $branch) {
            $presence = self::presence($branch->cond, $isRead);
            if ($presence !== true && !self::blockInitialises($branch->stmts, $isRead)) {
                return false;
            }
            if ($presence === false) {
                return true;
            }
        }

        return $stmt->else !== null && self::blockInitialises($stmt->else->stmts, $isRead);
    }

    /**
     * A branch that assigns the property or ends in return or throw.
     *
     * @param array<Node\Stmt>    $stmts
     * @param callable(Node): bool $isRead
     */
    private static function blockInitialises(array $stmts, callable $isRead): bool
    {
        $last = end($stmts);

        return $last instanceof Node\Stmt\Return_
            || ($last instanceof Node\Stmt\Expression && $last->expr instanceof Node\Expr\Throw_)
            || array_filter($stmts, static fn (Node\Stmt $inner): bool => self::initialises($inner, $isRead)) !== [];
    }

    /** null, '', [] and FALSE, which ValidateFieldsSubscriber treats as missing. */
    private static function isEmptyDefault(Node\Expr $default): bool
    {
        if ($default instanceof Node\Expr\ConstFetch) {
            return in_array($default->name->toLowerString(), ['null', 'false'], true);
        }

        return ($default instanceof Node\Scalar\String_ && $default->value === '')
            || ($default instanceof Node\Expr\Array_ && $default->items === []);
    }

    /**
     * Whether the class is a custom APIv4 action.
     *
     * Reflection first, so an own intermediate base class counts. The textual
     * fallback keeps the rule alive in a run that cannot see core: every
     * generic action base is `Civi\Api4\Generic\Abstract*Action`.
     */
    private static function isApi4Action(ClassReflection $class, InClassNode $node): bool
    {
        for ($parent = $class->getParentClass(); $parent !== null; $parent = $parent->getParentClass()) {
            if ($parent->getName() === 'Civi\\Api4\\Generic\\AbstractAction') {
                return true;
            }
        }

        $original = $node->getOriginalNode();
        if (!$original instanceof Node\Stmt\Class_ || $original->extends === null) {
            return false;
        }

        return Api4ActionParameter::isGenericActionBase($original->extends->toString());
    }

    private static function typeToString(Node\Identifier|Node\Name|Node\ComplexType $type): string
    {
        if ($type instanceof Node\NullableType) {
            return '?' . self::typeToString($type->type);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map([self::class, 'typeToString'], $type->types));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map([self::class, 'typeToString'], $type->types));
        }

        return $type->toString();
    }
}
