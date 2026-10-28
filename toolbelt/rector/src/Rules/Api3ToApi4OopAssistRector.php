<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector\Rules;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;

/**
 * Like Api3ToApi4AssistRector, but emits the idiomatic OOP builder form:
 *
 *   civicrm_api3('Contact', 'get', ['first_name' => 'Bob', 'return' => ['id']])
 *   -> \Civi\Api4\Contact::get(FALSE)->addWhere('first_name', '=', 'Bob')
 *        ->addSelect('id')->setLimit(25)->execute()
 *
 * Same safe subset + guardrails (checkPermissions becomes the get() argument,
 * defaulting to FALSE to preserve api3 behavior; limit defaults to 25). Same
 * bail-outs (non-get, operators, chaining, options beyond limit/offset,
 * non-literal params). A result read only as `$r['values']`/`$r['count']`
 * gets `->indexBy('id')` unless `sequential` is 1; its rows follow api4's
 * fields and default filters. Preview only.
 */
final class Api3ToApi4OopAssistRector extends AbstractApi3GetAssistRector {

  protected function api4Call(String_ $entity, String_ $action, array $parts, bool $indexById): ?Expr {
    // The builder emits clauses in a fixed order, so only the value of each
    // top-level clause matters here, not its position in the source array.
    $select = NULL;
    $limit = NULL;
    $offset = NULL;
    $checkPermissions = NULL;
    foreach ($parts['top'] as [$clause, $value]) {
      match ($clause) {
        'select' => $select = $value,
        'limit' => $limit = $value,
        'offset' => $offset = $value,
        'checkPermissions' => $checkPermissions = $value,
      };
    }

    // checkPermissions -> the get() argument. Absent => FALSE (api3 default).
    if ($checkPermissions === NULL) {
      $permArg = $this->bool(FALSE);
    }
    elseif ($checkPermissions instanceof Int_) {
      $permArg = $this->bool($checkPermissions->value !== 0);
    }
    else {
      $permArg = $checkPermissions;
    }

    $expr = $this->api4ActionCall($entity->value, 'get', $permArg);
    if ($expr === NULL) {
      return NULL;
    }
    foreach ($parts['where'] as [$field, $value]) {
      $expr = new MethodCall($expr, 'addWhere', [new Arg(new String_($field)), new Arg(new String_('=')), new Arg($value)]);
    }
    // classifyApi3GetParams() hands select over as a list literal.
    if ($select instanceof Array_ && $select->items !== []) {
      $selectArgs = [];
      foreach ($select->items as $sel) {
        $selectArgs[] = new Arg($sel->value);
      }
      $expr = new MethodCall($expr, 'addSelect', $selectArgs);
    }
    // Guardrail: preserve the api3 get() default cap of 25.
    $expr = new MethodCall($expr, 'setLimit', [new Arg($limit ?? new Int_(25))]);
    if ($offset !== NULL) {
      $expr = new MethodCall($expr, 'setOffset', [new Arg($offset)]);
    }

    $expr = new MethodCall($expr, 'execute');

    return $indexById ? new MethodCall($expr, 'indexBy', [new Arg(new String_('id'))]) : $expr;
  }

  private function bool(bool $value): ConstFetch {
    return new ConstFetch(new Name($value ? 'true' : 'false'));
  }
}
