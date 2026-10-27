<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector\Rules;

use CiviKitchen\Rector\CallArgs;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PHPStan\Reflection\ReflectionProvider;
use Rector\Rector\AbstractRector;

/**
 * Shared helpers of the civicrm_api3/civicrm_api4 call rewriters: match a
 * literal `<function>('Entity', 'action', [...])` and map its parts; the
 * concrete rule bails (NULL) on anything outside its safe subset.
 */
abstract class AbstractApiCallAssistRector extends AbstractRector {

  /**
   * api3 keys that steer the call rather than filter it (api/v3/utils.php);
   * a dotted key (`option.limit`, `return.x`, `api.x`) is one too.
   */
  private const API3_CONTROL_KEYS = [
    'action', 'entity', 'debug', 'prettyprint', 'rowCount', 'sort', 'offset',
    'option_offset', 'option_limit', 'option_sort', 'custom', 'IDS_request_uri', 'IDS_user_agent',
  ];

  public function __construct(
    protected readonly ReflectionProvider $reflectionProvider,
  ) {}

  /**
   * `\Civi\Api4\<Entity>::<action>($checkPermissions)`, or NULL when that
   * static call would not run the same action: no class under exactly that
   * name (dynamic entities such as Custom_* resolve through CustomValue), an
   * action whose first parameter is not $checkPermissions, or a magic action
   * given a non-literal flag (__callStatic honours only a literal FALSE).
   */
  protected function api4ActionCall(string $entity, string $action, ?Expr $checkPermissions): ?StaticCall {
    $class = 'Civi\\Api4\\' . $entity;
    if (preg_match('/^[A-Za-z_]\w*$/', $entity) !== 1 || preg_match('/^[A-Za-z_]\w*$/', $action) !== 1
      || !$this->reflectionProvider->hasClass($class) || $this->reflectionProvider->getClass($class)->getName() !== $class) {
      return NULL;
    }
    $reflection = $this->reflectionProvider->getClass($class);
    if ($reflection->hasNativeMethod($action)) {
      $method = $reflection->getNativeMethod($action);
      $first = $method->getVariants()[0]->getParameters()[0] ?? NULL;
      $reachable = $method->isStatic() && $method->isPublic()
        && ($first === NULL ? $checkPermissions === NULL : $first->getName() === 'checkPermissions');
    }
    else {
      $reachable = $reflection->hasNativeMethod('__callStatic')
        && ($checkPermissions === NULL || ($checkPermissions instanceof ConstFetch && in_array($checkPermissions->name->toLowerString(), ['true', 'false'], TRUE)));
    }

    return $reachable ? new StaticCall(new FullyQualified($class), $action, $checkPermissions === NULL ? [] : [new Arg($checkPermissions)]) : NULL;
  }

  /**
   * Entity, action and params array of a literal API call, or NULL to bail
   * (wrong function, dynamic entity/action, non-literal params, arguments
   * that do not map onto the signature). A missing params argument counts as
   * an empty array, matching both API runtimes.
   *
   * @return array{String_, String_, Array_}|null
   */
  protected function matchLiteralApiCall(Node $node, string $function): ?array {
    if (!$node instanceof FuncCall || !$this->isName($node, $function)) {
      return NULL;
    }
    // civicrm_api4()'s fourth $index reshapes the Result (indexBy, itemAt,
    // column) in ways the builder does not spell, so it is left out and bails.
    $args = CallArgs::byName($node, ['entity', 'action', 'params']);
    $entity = $args['entity'] ?? NULL;
    $action = $args['action'] ?? NULL;
    if (!$entity instanceof String_ || !$action instanceof String_) {
      return NULL;
    }
    $params = $args['params'] ?? new Array_([]);
    if (!$params instanceof Array_) {
      return NULL;
    }

    return [$entity, $action, $params];
  }

  /**
   * Classify a literal api3 get() params array into where rows and top-level
   * clauses, or NULL to bail on anything outside the safe subset (non-string
   * keys, `api.*` chaining, array filter values, options beyond limit/offset).
   *
   * `where` is a list of [field, value expr]; `top` is an ordered list of
   * [clause, value expr] with clause one of select|checkPermissions|limit|
   * offset, in source order so the array rule can reproduce it verbatim.
   * `sequential` is handed over on its own, it only shapes the result;
   * `version` is dropped, civicrm_api3() overwrites it anyway. `select` is
   * always a list literal, the comma list api3 also accepts split up.
   *
   * @return array{where: list<array{string, Expr}>, top: list<array{'select'|'limit'|'offset'|'checkPermissions', Expr}>, sequential: ?Expr}|null
   */
  protected function classifyApi3GetParams(Array_ $params): ?array {
    $where = [];
    $top = [];
    $sequential = NULL;

    foreach ($params->items as $item) {
      if (!$item->key instanceof String_) {
        return NULL;
      }
      $key = $item->key->value;
      $value = $item->value;

      if ($key === 'sequential') {
        $sequential = $value;
        continue;
      }
      if ($key === 'version') {
        continue;
      }
      if (in_array($key, self::API3_CONTROL_KEYS, TRUE) || str_contains($key, '.')) {
        return NULL;
      }
      if ($key === 'return') {
        $select = self::api3Return($value);
        if ($select === NULL) {
          return NULL;
        }
        $top[] = ['select', $select];
        continue;
      }
      if ($key === 'check_permissions') {
        $top[] = ['checkPermissions', $value];
        continue;
      }
      if ($key === 'options') {
        $options = $this->limitOffsetOptions($value);
        if ($options === NULL) {
          return NULL;
        }
        if ($options['limit'] !== NULL) {
          $top[] = ['limit', $options['limit']];
        }
        if ($options['offset'] !== NULL) {
          $top[] = ['offset', $options['offset']];
        }
        continue;
      }
      // An array value is an api3 operator or IN list, not an `=` filter.
      if (!$this->getType($value)->isArray()->no()) {
        return NULL;
      }
      $where[] = [$key, $value];
    }

    return ['where' => $where, 'top' => $top, 'sequential' => $sequential];
  }

  /**
   * api3 `return` as a list of field names: a literal list as it is, a
   * literal comma list split the way api3 splits it; NULL otherwise.
   */
  private static function api3Return(Expr $value): ?Array_ {
    if ($value instanceof String_) {
      $fields = array_filter(explode(',', str_replace(' ', '', $value->value)), static fn (string $f): bool => $f !== '');

      return new Array_(array_map(static fn (string $f): ArrayItem => new ArrayItem(new String_($f)), array_values($fields)));
    }
    if (!$value instanceof Array_) {
      return NULL;
    }
    foreach ($value->items as $item) {
      if ($item->key !== NULL || $item->unpack) {
        return NULL;
      }
    }

    return $value;
  }

  /**
   * limit/offset of an api3 `options` array; NULL bails on any other option.
   *
   * @return array{limit: ?Expr, offset: ?Expr}|null
   */
  protected function limitOffsetOptions(Expr $value): ?array {
    if (!$value instanceof Array_) {
      return NULL;
    }
    $options = ['limit' => NULL, 'offset' => NULL];
    foreach ($value->items as $opt) {
      if (!$opt->key instanceof String_ || !array_key_exists($opt->key->value, $options)) {
        return NULL;
      }
      $options[$opt->key->value] = $opt->value;
    }

    return $options;
  }

}
