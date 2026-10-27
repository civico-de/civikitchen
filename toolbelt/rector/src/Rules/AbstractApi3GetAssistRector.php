<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Goto_;
use PhpParser\Node\Stmt\Unset_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParametersAcceptor;
use Rector\NodeTypeResolver\Node\AttributeKey;

/**
 * Shared scope analysis of the api3 get() rewriters. A literal call is
 * rewritten as a bare statement, or as `$v = civicrm_api3(...);` in a
 * function whose every other use of `$v` is a later read of `$v['values']`
 * or `$v['count']`. Those reads become `$v->getArrayCopy()` (`$v[$key]` for
 * one row) and `$v->countFetched()`, and the call selects `id` and keys the
 * rows by it unless `sequential` is 1, as api3 does. Any other use of the
 * result (returned, passed on, `['id']`, written, global scope) leaves the
 * call alone. The rows are api4's: its fields, types and default filters
 * (is_deleted, is_test, is_template) instead of api3's entity defaults.
 */
abstract class AbstractApi3GetAssistRector extends AbstractApiCallAssistRector {

  /** api3 envelope key => the Result method that reads the same thing. */
  private const ENVELOPE = ['values' => 'getArrayCopy', 'count' => 'countFetched'];

  /** Built-ins that read or write the local scope by variable name. */
  private const SCOPE_FUNCTIONS = ['compact', 'extract', 'get_defined_vars', 'parse_str'];

  /**
   * The api4 call for the classified params, or NULL to bail. $indexById:
   * key the rows by id, the way api3 does unless `sequential` is 1.
   *
   * @param array{where: list<array{string, Expr}>, top: list<array{'select'|'limit'|'offset'|'checkPermissions', Expr}>, sequential: ?Expr} $parts
   */
  abstract protected function api4Call(String_ $entity, String_ $action, array $parts, bool $indexById): ?Expr;

  public function getNodeTypes(): array {
    return [ClassMethod::class, Function_::class, Closure::class, Expression::class];
  }

  /**
   * A function is visited before its statements: assigned calls are
   * rewritten together with their reads there, bare calls on their own.
   */
  public function refactor(Node $node): ?Node {
    if ($node instanceof Expression) {
      $call = $node->expr instanceof FuncCall ? $this->api4Get($node->expr, FALSE) : NULL;
      if ($call === NULL) {
        return NULL;
      }
      $node->expr = $call;

      return $node;
    }

    return $node instanceof FunctionLike && $this->rewriteResults($node) ? $node : NULL;
  }

  /**
   * $used: the result is read, so the entity must key its rows by id (a DAO
   * entity) and `sequential` must be a literal.
   */
  private function api4Get(FuncCall $call, bool $used): ?Expr {
    $match = $this->matchLiteralApiCall($call, 'civicrm_api3');
    if ($match === NULL) {
      return NULL;
    }
    [$entity, $action, $params] = $match;
    if (strtolower($action->value) !== 'get') {
      return NULL;
    }
    $parts = $this->classifyApi3GetParams($params);
    if ($parts === NULL) {
      return NULL;
    }
    if (!$used) {
      return $this->api4Call($entity, $action, $parts, FALSE);
    }

    $class = 'Civi\\Api4\\' . $entity->value;
    $sequential = $parts['sequential'] === NULL ? FALSE : self::literalIsOne($parts['sequential']);
    if (!$this->reflectionProvider->hasClass($class) || !$this->reflectionProvider->getClass($class)->is('Civi\\Api4\\Generic\\DAOEntity')
      || $sequential === NULL) {
      return NULL;
    }
    // api3 returns id with every row; api4 drops the duplicate select.
    foreach ($parts['top'] as $i => [$clause, $value]) {
      if ($clause === 'select' && $value instanceof Array_ && $value->items !== []
        && !in_array('id', array_map(static fn (?ArrayItem $item): mixed => $item?->value instanceof String_ ? $item->value->value : NULL, $value->items), TRUE)) {
        $parts['top'][$i][1] = new Array_([...$value->items, new ArrayItem(new String_('id'))]);
      }
    }

    return $this->api4Call($entity, $action, $parts, !$sequential);
  }

  /**
   * `$expr == 1` for a scalar literal, the test api3 applies to `sequential`;
   * NULL when $expr is not a literal.
   */
  private static function literalIsOne(Expr $expr): ?bool {
    if ($expr instanceof Int_ || $expr instanceof Float_ || $expr instanceof String_) {
      return $expr->value == 1;
    }
    if ($expr instanceof ConstFetch && in_array($expr->name->toLowerString(), ['true', 'false', 'null'], TRUE)) {
      return $expr->name->toLowerString() === 'true';
    }

    return NULL;
  }

  /**
   * Rewrite each local variable that only ever holds api3 get() results and
   * is only read through the envelope keys; leave every other one alone.
   */
  private function rewriteResults(FunctionLike $scope): bool {
    if ($scope->returnsByRef()) {
      return FALSE;
    }
    $found = ['opaque' => FALSE, 'assigns' => [], 'vars' => []];
    $this->collect($scope, [], $found, TRUE);
    if ($found['opaque']) {
      return FALSE;
    }

    // Reads are replaced by identity once all calls are built: a call can
    // reuse a value node that holds another variable's read.
    $replacements = [];
    $changed = FALSE;
    foreach ($found['assigns'] as $name => $assigns) {
      $targets = [];
      $calls = [];
      foreach ($assigns as $assign) {
        $targets[spl_object_id($assign[0]->var)] = TRUE;
        $calls[] = $this->api4Get($assign[0]->expr, TRUE);
      }
      $reads = [];
      foreach ($found['vars'][$name] as [$var, $path]) {
        if (isset($targets[spl_object_id($var)])) {
          continue;
        }
        $method = $this->envelopeRead($path);
        if ($method === NULL || !self::dominated($path, $assigns)) {
          continue 2;
        }
        $reads[] = [$path, $method];
      }
      if (in_array(NULL, $calls, TRUE)) {
        continue;
      }

      foreach ($assigns as $i => [$assign]) {
        $assign->expr = $calls[$i];
      }
      $changed = TRUE;
      foreach ($reads as [$path, $method]) {
        $fetch = $path[count($path) - 1][0];
        [$user, $slot] = $path[count($path) - 2];
        // A row read `$v['values'][$key]` reads the Result directly, no copy.
        $replacements += $method === 'getArrayCopy' && $user instanceof ArrayDimFetch && $slot === 'var' && $user->dim !== NULL
          ? [spl_object_id($user) => new ArrayDimFetch(new Variable($name), $user->dim)]
          : [spl_object_id($fetch) => new MethodCall(new Variable($name), $method)];
      }
    }
    $this->traverseNodesWithCallable($scope, static fn (Node $node): ?Node => $replacements[spl_object_id($node)] ?? NULL);
    return $changed;
  }

  /**
   * Walk one variable scope: nested functions, closures (but their `use`
   * list) and classes are scopes of their own. Each path entry is
   * [ancestor, subnode name, index in that subnode's array or NULL].
   *
   * @param list<array{Node, string, ?int}> $path
   * @param array{opaque: bool, assigns: array<string, list<array{Assign, Node, string, int}>>, vars: array<string, list<array{Variable, list<array{Node, string, ?int}>}>>} $found
   */
  private function collect(Node $node, array $path, array &$found, bool $root = FALSE): void {
    if ($node instanceof Variable) {
      if (is_string($node->name)) {
        $found['vars'][$node->name][] = [$node, $path];
      }
      else {
        $found['opaque'] = TRUE;
      }

      return;
    }
    if (!$root && ($node instanceof ClassLike || $node instanceof Function_ || $node instanceof ClassMethod)) {
      return;
    }
    if ($node instanceof Eval_ || $node instanceof Include_ || $node instanceof Goto_
      || ($node instanceof FuncCall && $node->name instanceof Name && in_array($node->name->toLowerString(), self::SCOPE_FUNCTIONS, TRUE))) {
      $found['opaque'] = TRUE;
    }
    $parent = $path === [] ? NULL : $path[count($path) - 1];
    if ($node instanceof Expression && $node->expr instanceof Assign && $node->expr->var instanceof Variable && is_string($node->expr->var->name)
      && $node->expr->expr instanceof FuncCall && $this->isName($node->expr->expr, 'civicrm_api3') && $parent !== NULL && $parent[2] !== NULL) {
      $found['assigns'][$node->expr->var->name][] = [$node->expr, $parent[0], $parent[1], $parent[2]];
    }

    foreach ($node->getSubNodeNames() as $slot) {
      if (!$root && $node instanceof Closure && $slot !== 'uses') {
        continue;
      }
      $child = $node->{$slot};
      if ($child instanceof Node) {
        $this->collect($child, [...$path, [$node, $slot, NULL]], $found);
      }
      elseif (is_array($child)) {
        foreach ($child as $index => $item) {
          if ($item instanceof Node) {
            $this->collect($item, [...$path, [$node, $slot, $index]], $found);
          }
        }
      }
    }
  }

  /**
   * The Result method for a variable read as `$v['values']` or
   * `$v['count']` in a position a method call can take, else NULL.
   *
   * @param list<array{Node, string, ?int}> $path
   */
  private function envelopeRead(array $path): ?string {
    $last = count($path) - 1;
    [$fetch, $slot] = $path[$last];
    if (!$fetch instanceof ArrayDimFetch || $slot !== 'var' || !$fetch->dim instanceof String_ || !isset(self::ENVELOPE[$fetch->dim->value])) {
      return NULL;
    }
    foreach ($path as [$ancestor, $ancestorSlot]) {
      if ($ancestor instanceof ArrowFunction || $ancestor instanceof Unset_
        || (($ancestor instanceof Assign || $ancestor instanceof AssignOp || $ancestor instanceof AssignRef) && $ancestorSlot === 'var')
        || ($ancestor instanceof Foreach_ && in_array($ancestorSlot, ['keyVar', 'valueVar'], TRUE))) {
        return NULL;
      }
    }
    // The chain `$v['values'][...]` ends at the expression that uses it.
    $top = $last;
    while ($top > 0 && $path[$top - 1][0] instanceof ArrayDimFetch && $path[$top - 1][1] === 'var') {
      $top--;
    }
    $user = $top > 0 ? $path[$top - 1][0] : NULL;
    $byValue = match (TRUE) {
      $user instanceof AssignRef, $user instanceof PreInc, $user instanceof PostInc, $user instanceof PreDec, $user instanceof PostDec => FALSE,
      $user instanceof Isset_ => $top < $last,
      $user instanceof ArrayItem => !$user->byRef,
      $user instanceof Foreach_ => !$user->byRef && !self::bindsReference($user->valueVar),
      $user instanceof Assign => !self::bindsReference($user->var),
      $user instanceof Arg => $top > 1 && $this->passesByValue($path[$top - 2][0], $user),
      default => TRUE,
    };

    return $byValue ? self::ENVELOPE[$fetch->dim->value] : NULL;
  }

  /**
   * Whether a destructuring target binds a reference (`[&$a] = ...`), which
   * would write through to the array it unpacks.
   */
  private static function bindsReference(?Node $target): bool {
    if (!$target instanceof Array_ && !$target instanceof List_) {
      return FALSE;
    }
    foreach ($target->items as $item) {
      if ($item !== NULL && ($item->byRef || self::bindsReference($item->value))) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Whether the callee takes $arg by value; FALSE when that is unknown.
   */
  private function passesByValue(Node $call, Arg $arg): bool {
    $scope = $call->getAttribute(AttributeKey::SCOPE);
    if (!$scope instanceof Scope || $arg->unpack || !isset($call->args) || !is_array($call->args)) {
      return FALSE;
    }
    $classes = [];
    if ($call instanceof FuncCall && $call->name instanceof Name) {
      if (!$this->reflectionProvider->hasFunction($call->name, $scope)) {
        return FALSE;
      }
      $variants = $this->reflectionProvider->getFunction($call->name, $scope)->getVariants();
    }
    else {
      if ($call instanceof StaticCall && $call->class instanceof Name && $call->name instanceof Identifier) {
        $class = $scope->resolveName($call->class);
        $classes = $this->reflectionProvider->hasClass($class) ? [$this->reflectionProvider->getClass($class)] : [];
      }
      elseif (($call instanceof MethodCall || $call instanceof NullsafeMethodCall) && $call->name instanceof Identifier) {
        $classes = $this->getType($call->var)->getObjectClassReflections();
      }
      elseif ($call instanceof New_ && $call->class instanceof Name) {
        $class = $scope->resolveName($call->class);
        $classes = $this->reflectionProvider->hasClass($class) ? [$this->reflectionProvider->getClass($class)] : [];
      }
      $method = $call instanceof New_ ? '__construct' : ($call->name instanceof Identifier ? $call->name->toString() : NULL);
      $variants = [];
      foreach ($classes as $class) {
        if ($method === NULL || !$class->hasNativeMethod($method)) {
          return FALSE;
        }
        array_push($variants, ...$class->getNativeMethod($method)->getVariants());
      }
    }
    $position = array_search($arg, $call->args, TRUE);
    if ($variants === [] || !is_int($position)) {
      return FALSE;
    }
    foreach (array_slice($call->args, 0, $position) as $before) {
      if (!$before instanceof Arg || $before->unpack) {
        return FALSE;
      }
    }

    return array_filter($variants, static fn (ParametersAcceptor $variant): bool => !self::parameterByValue($variant, $arg, $position)) === [];
  }

  /**
   * Whether the parameter $arg lands in is by value (an extra positional
   * argument is); FALSE when no parameter takes a named one.
   */
  private static function parameterByValue(ParametersAcceptor $variant, Arg $arg, int $position): bool {
    $parameters = $variant->getParameters();
    $variadic = $variant->isVariadic() && $parameters !== [] ? $parameters[count($parameters) - 1] : NULL;
    if ($arg->name !== NULL) {
      $named = array_filter($parameters, static fn ($parameter): bool => $parameter->getName() === $arg->name->toString());
      $parameter = $named === [] ? $variadic : reset($named);
      if ($parameter === NULL) {
        return FALSE;
      }
    }
    else {
      $parameter = $parameters[$position] ?? $variadic;
    }

    return $parameter === NULL || $parameter->passedByReference()->no();
  }

  /**
   * Whether the read runs after one of the assignments in the same block,
   * so the variable holds a rewritten result there.
   *
   * @param list<array{Node, string, ?int}> $path
   * @param list<array{Assign, Node, string, int}> $assigns
   */
  private static function dominated(array $path, array $assigns): bool {
    foreach ($assigns as [, $owner, $slot, $index]) {
      foreach ($path as [$ancestor, $ancestorSlot, $ancestorIndex]) {
        if ($ancestor === $owner && $ancestorSlot === $slot && $ancestorIndex > $index) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

}
