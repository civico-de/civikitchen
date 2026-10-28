<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector\Rules;

use CiviKitchen\Rector\CallArgs;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Type\ArrayType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use Rector\PhpParser\Node\Value\ValueResolver;
use Rector\Rector\AbstractRector;

/**
 * Rewrite the deprecated CRM_Utils_Array::value('k', $arr) into
 * $arr['k'] ?? NULL. This is one of the footguns cklint already BANS —
 * ckmodernize fixes it.
 *
 * Only where both agree: value() returns a stored NULL where `??` falls back
 * to the default, so a non-NULL default bails, and so does a subject that may
 * be anything but an array, ArrayAccess or NULL (value() returns the default there).
 */
final class CrmUtilsArrayValueToCoalesceRector extends AbstractRector {

  public function __construct(
    private readonly ValueResolver $valueResolver,
  ) {}

  public function getNodeTypes(): array {
    return [StaticCall::class];
  }

  public function refactor(Node $node): ?Node {
    if (!$node instanceof StaticCall) {
      return NULL;
    }
    if (!$this->isName($node->class, 'CRM_Utils_Array') || !$this->isName($node->name, 'value')) {
      return NULL;
    }
    $args = CallArgs::byName($node, ['key', 'list', 'default']);
    if (!isset($args['key'], $args['list'])) {
      return NULL;
    }
    if (isset($args['default']) && !$this->valueResolver->isNull($args['default'])) {
      return NULL;
    }
    $list = $this->getType($args['list']);
    $indexable = TypeCombinator::union(new ArrayType(new MixedType(), new MixedType()), new ObjectType('ArrayAccess'), new NullType());
    if (!$indexable->isSuperTypeOf($list)->yes()) {
      return NULL;
    }

    return new Coalesce(new ArrayDimFetch($args['list'], $args['key']), new ConstFetch(new Name('null')));
  }
}
