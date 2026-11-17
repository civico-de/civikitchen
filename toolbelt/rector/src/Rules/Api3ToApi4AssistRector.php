<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector\Rules;

use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;

/**
 * Partial api3 get() -> api4 ARRAY form migration that keeps api3's defaults:
 * adds `checkPermissions => false` and `limit => 25` when absent, and indexes
 * the result by id unless `sequential` is 1.
 */
final class Api3ToApi4AssistRector extends AbstractApi3GetAssistRector {

  protected function api4Call(String_ $entity, String_ $action, array $parts, bool $indexById): ?Expr {
    if ($this->api4ActionCall($entity->value, 'get', NULL) === NULL) {
      return NULL;
    }

    $newItems = [];
    if ($parts['where'] !== []) {
      $whereItems = [];
      foreach ($parts['where'] as [$field, $value]) {
        $whereItems[] = new ArrayItem(new Array_([
          new ArrayItem(new String_($field)),
          new ArrayItem(new String_('=')),
          new ArrayItem($value),
        ]));
      }
      $newItems[] = new ArrayItem(new Array_($whereItems), new String_('where'));
    }
    $hasCheckPermissions = FALSE;
    $hasLimit = FALSE;
    foreach ($parts['top'] as [$clause, $value]) {
      $newItems[] = new ArrayItem($value, new String_($clause));
      $hasLimit = $hasLimit || $clause === 'limit';
      $hasCheckPermissions = $hasCheckPermissions || $clause === 'checkPermissions';
    }
    if (!$hasLimit) {
      $newItems[] = new ArrayItem(new Int_(25), new String_('limit'));
    }
    if (!$hasCheckPermissions) {
      $newItems[] = new ArrayItem(new ConstFetch(new Name('false')), new String_('checkPermissions'));
    }

    $args = [new Arg($entity), new Arg($action), new Arg(new Array_($newItems))];

    return new FuncCall(new Name('civicrm_api4'), $indexById ? [...$args, new Arg(new String_('id'))] : $args);
  }
}
