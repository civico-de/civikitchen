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
 * ASSISTED, deliberately partial api3 -> api4 migration to the ARRAY form
 * (minimal-change: keeps a function call, just restructures the params). A
 * literal `civicrm_api3('Entity', 'get', [...])` becomes the api4 array form,
 * keeping api3's call defaults with two guardrails:
 *   - add `checkPermissions => false` if absent  (api3 PHP default is FALSE,
 *     api4 default is TRUE)
 *   - add `limit => 25` if absent               (api3 `get` silently capped at 25)
 *
 * A result read only as `$r['values']`/`$r['count']` is indexed by id (the
 * fourth civicrm_api4() argument) unless `sequential` is 1; its rows follow
 * api4's fields and default filters. See AbstractApi3GetAssistRector.
 *
 * Sibling of Api3ToApi4OopAssistRector (which emits the idiomatic OOP builder).
 * Pick one via `ckmodernize --api=array` / `--api` (oop). Both BAIL on anything
 * unsafe (non-get actions, operator/array filter values, `api.*` chaining,
 * options beyond limit/offset, non-literal entity/action/params). Preview only.
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
