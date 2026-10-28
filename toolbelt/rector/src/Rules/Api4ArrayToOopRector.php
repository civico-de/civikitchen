<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;

/**
 * Convert the api4 ARRAY form to the idiomatic OO builder — fully SAFE: same API
 * version, same semantics, same Result. Pure style.
 *
 *   civicrm_api4('Contact', 'get', ['where' => [['x', '=', 1]], 'select' => ['id'], 'limit' => 5])
 *   -> \Civi\Api4\Contact::get()->addWhere('x', '=', 1)->addSelect('id')->setLimit(5)->execute()
 *
 * checkPermissions becomes the action() argument (api4 defaults TRUE in both
 * forms, so an absent value maps to no argument). AND/OR/NOT where rows become
 * addClause(). Bails on anything it doesn't model yet (join/having/chain/
 * groupBy-with-keys, non-literal entity/action/params, the $index argument, an
 * entity without its own Civi\Api4 class).
 */
final class Api4ArrayToOopRector extends AbstractApiCallAssistRector {

  public function getNodeTypes(): array {
    return [FuncCall::class];
  }

  public function refactor(Node $node): ?Node {
    $match = $this->matchLiteralApiCall($node, 'civicrm_api4');
    if ($match === NULL) {
      return NULL;
    }
    [$entity, $action, $params] = $match;

    $permArg = NULL;
    $methods = [];

    foreach ($params->items as $item) {
      if (!$item->key instanceof String_) {
        return NULL;
      }
      $key = $item->key->value;
      $value = $item->value;

      switch ($key) {
        case 'checkPermissions':
          $permArg = $value;
          break;

        case 'where':
          if (!$value instanceof Array_) {
            return NULL;
          }
          foreach ($value->items as $row) {
            if ($row->key !== NULL || !$row->value instanceof Array_) {
              return NULL;
            }
            $whereCall = self::whereCall($row->value);
            if ($whereCall === NULL) {
              return NULL;
            }
            $methods[] = $whereCall;
          }
          break;

        case 'select':
          if ($value instanceof Array_) {
            $selectArgs = [];
            foreach ($value->items as $sel) {
              if ($sel->key !== NULL) {
                return NULL;
              }
              $selectArgs[] = new Arg($sel->value);
            }
            $methods[] = ['addSelect', $selectArgs];
          }
          else {
            $methods[] = ['setSelect', [new Arg($value)]];
          }
          break;

        case 'orderBy':
          if (!$value instanceof Array_) {
            return NULL;
          }
          foreach ($value->items as $ord) {
            if (!$ord->key instanceof String_) {
              return NULL;
            }
            $methods[] = ['addOrderBy', [new Arg(new String_($ord->key->value)), new Arg($ord->value)]];
          }
          break;

        case 'limit':
          $methods[] = ['setLimit', [new Arg($value)]];
          break;

        case 'offset':
          $methods[] = ['setOffset', [new Arg($value)]];
          break;

        case 'values':
          $methods[] = ['setValues', [new Arg($value)]];
          break;

        default:
          // join, having, chain, groupBy-with-aliases, ... — not modeled yet.
          return NULL;
      }
    }

    $expr = $this->api4ActionCall($entity->value, $action->value, $permArg);
    if ($expr === NULL) {
      return NULL;
    }
    foreach ($methods as [$name, $methodArgs]) {
      $expr = new MethodCall($expr, $name, $methodArgs);
    }

    return new MethodCall($expr, 'execute');
  }

  /**
   * One where row as a builder call: `['OR', [[...], ...]]` (also AND/NOT) is
   * addClause(), `[field, 'op', value?]` is addWhere(). A fourth isExpression cell
   * bails: only DAOGetAction::addWhere() takes it, other actions drop it silently.
   *
   * @return array{string, list<Arg>}|null
   */
  private static function whereCall(Array_ $row): ?array {
    $cells = [];
    foreach ($row->items as $cell) {
      if ($cell->key !== NULL || $cell->unpack) {
        return NULL;
      }
      $cells[] = $cell->value;
    }
    if ($cells !== [] && $cells[0] instanceof String_ && in_array(strtoupper($cells[0]->value), ['AND', 'OR', 'NOT'], TRUE)) {
      // addClause() wraps a condition whose item [0] is no array, so only a
      // literal list of clauses keeps its meaning.
      $conditions = $cells[1] ?? NULL;
      $first = $conditions instanceof Array_ ? $conditions->items[0] ?? NULL : NULL;
      $isClauseList = $first !== NULL && $first->key === NULL && $first->value instanceof Array_;
      return count($cells) === 2 && in_array($cells[0]->value, ['AND', 'OR', 'NOT'], TRUE) && $isClauseList
        ? ['addClause', [new Arg($cells[0]), new Arg($cells[1])]]
        : NULL;
    }
    if (count($cells) < 2 || count($cells) > 3 || !$cells[1] instanceof String_) {
      return NULL;
    }

    return ['addWhere', array_map(static fn (Node\Expr $cell): Arg => new Arg($cell), $cells)];
  }
}
