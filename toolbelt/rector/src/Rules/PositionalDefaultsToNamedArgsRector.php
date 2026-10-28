<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\MethodReflection;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\PhpParser\Node\Value\ValueResolver;
use Rector\Rector\AbstractRector;
use Rector\Reflection\ReflectionResolver;
use Rector\ValueObject\PhpVersion;
use Rector\VersionBonding\Contract\MinPhpVersionInterface;

/**
 * Drop positional arguments that only repeat a parameter's default, and name
 * whatever has to survive after them:
 *
 *   CRM_Utils_Request::retrieve('delete', 'String', NULL, FALSE, NULL, 'POST')
 *   CRM_Utils_Request::retrieve('delete', 'String', method: 'POST')
 *
 * Signatures come from reflection where the callee is autoloadable (the
 * extension's own code) and otherwise from the SIGNATURES map below — an
 * extension's rector run has no CiviCRM core on its autoloader, so the core
 * offenders have to be declared. Extend it per project via configure().
 *
 * Named arguments and omitted defaults bind to the callee that runs, so a
 * reflected callee must be the only one that can: a user function, or a
 * declared method that no subclass or implementation can replace. Callees
 * that read func_get_args()/func_num_args() see the dropped arguments.
 */
final class PositionalDefaultsToNamedArgsRector extends AbstractRector implements ConfigurableRectorInterface, MinPhpVersionInterface {

  /**
   * Callee => parameters in declaration order. A list entry is a required
   * parameter (its name); a keyed entry is "name => default value".
   *
   * @var array<string, array<int|string, mixed>>
   */
  private const SIGNATURES = [
    'CRM_Utils_Request::retrieve' => [
      'name', 'type',
      'store' => NULL, 'abort' => FALSE, 'default' => NULL, 'method' => 'REQUEST',
    ],
    'CRM_Utils_System::url' => [
      'path' => '', 'query' => '', 'absolute' => FALSE, 'fragment' => NULL,
      'htmlize' => TRUE, 'frontend' => FALSE, 'forceBackend' => FALSE,
    ],
  ];

  /**
   * @var array<string, array<int|string, mixed>>
   */
  private array $signatures = self::SIGNATURES;

  public function __construct(
    private readonly ValueResolver $valueResolver,
    private readonly ReflectionResolver $reflectionResolver,
  ) {}

  /**
   * @param array<string, array<int|string, mixed>> $configuration
   */
  public function configure(array $configuration): void {
    $this->signatures = $configuration + self::SIGNATURES;
  }

  public function provideMinPhpVersion(): int {
    // Named arguments arrived in PHP 8.0.
    return PhpVersion::PHP_80;
  }

  public function getNodeTypes(): array {
    return [StaticCall::class, MethodCall::class, FuncCall::class];
  }

  public function refactor(Node $node): ?Node {
    if (!$node instanceof StaticCall && !$node instanceof MethodCall && !$node instanceof FuncCall) {
      return NULL;
    }
    $args = $node->args;
    foreach ($args as $arg) {
      // Already named, spread, or a placeholder from a first-class callable.
      if (!$arg instanceof Arg || $arg->name !== NULL || $arg->unpack) {
        return NULL;
      }
    }
    $parameters = $this->resolveParameters($node);
    if ($parameters === NULL || count($args) > count($parameters)) {
      return NULL;
    }

    $newArgs = [];
    $dropped = 0;
    foreach ($args as $position => $arg) {
      [$name, $hasDefault, $default] = $parameters[$position];
      if ($hasDefault && $this->isLiteralDefault($arg->value, $default)) {
        $dropped++;
        continue;
      }
      $newArgs[] = $dropped === 0 ? $arg : new Arg($arg->value, $arg->byRef, FALSE, $arg->getAttributes(), new Identifier($name));
    }
    if ($dropped === 0) {
      return NULL;
    }

    $node->args = $newArgs;
    return $node;
  }

  /**
   * Parameters as [name, hasDefault, default], or NULL when unresolvable.
   *
   * @return array<int, array{string, bool, mixed}>|NULL
   */
  private function resolveParameters(StaticCall|MethodCall|FuncCall $node): ?array {
    $declared = $this->signatures[$this->resolveCalleeName($node)] ?? NULL;
    if ($declared !== NULL) {
      $parameters = [];
      foreach ($declared as $key => $value) {
        $parameters[] = is_int($key) ? [(string) $value, FALSE, NULL] : [$key, TRUE, $value];
      }
      return $parameters;
    }
    return $this->reflectParameters($node);
  }

  private function resolveCalleeName(StaticCall|MethodCall|FuncCall $node): string {
    if ($node instanceof StaticCall) {
      $class = $this->getName($node->class);
      $method = $this->getName($node->name);
      return $class !== NULL && $method !== NULL ? ltrim($class, '\\') . '::' . $method : '';
    }
    return $node instanceof FuncCall ? (string) $this->getName($node) : '';
  }

  /**
   * @return array<int, array{string, bool, mixed}>|NULL
   */
  private function reflectParameters(StaticCall|MethodCall|FuncCall $node): ?array {
    $reflection = $this->reflectionResolver->resolveFunctionLikeReflectionFromCall($node);
    if ($reflection === NULL || !$this->bindsStatically($node, $reflection) || $this->readsArgumentList($reflection)) {
      return NULL;
    }
    $variants = $reflection->getVariants();
    if (count($variants) !== 1) {
      return NULL;
    }
    $parameters = [];
    foreach ($variants[0]->getParameters() as $parameter) {
      if ($parameter->isVariadic()) {
        return NULL;
      }
      $defaults = $parameter->getDefaultValue()?->getConstantScalarValues() ?? [];
      $parameters[] = count($defaults) === 1
        ? [$parameter->getName(), TRUE, $defaults[0]]
        : [$parameter->getName(), FALSE, NULL];
    }
    return $parameters;
  }

  /**
   * The resolved callee is the one that runs. Internal callees are out: their
   * parameter names are not ours to bet on.
   */
  private function bindsStatically(StaticCall|MethodCall|FuncCall $node, FunctionReflection|MethodReflection $reflection): bool {
    if ($reflection instanceof FunctionReflection) {
      return !$reflection->isBuiltin();
    }
    $class = $reflection->getDeclaringClass();
    // A method served by __call/__callStatic (@method) has no parameters to name.
    if (!$reflection instanceof ExtendedMethodReflection || $class->isBuiltin()
      || !$class->getNativeReflection()->hasMethod($reflection->getName())) {
      return FALSE;
    }
    if ($reflection->isPrivate() || $reflection->isFinalByKeyword()->yes() || $class->isFinalByKeyword()) {
      return TRUE;
    }
    if ($node instanceof MethodCall) {
      $receivers = $this->getType($node->var)->getObjectClassReflections();

      return count($receivers) === 1 && $receivers[0]->isFinalByKeyword();
    }

    // Foo::, self:: and parent:: name the method; static:: dispatches late.
    return $node instanceof StaticCall && $node->class instanceof Name && !$this->isName($node->class, 'static');
  }

  /**
   * The callee's body counts its arguments, or cannot be read to rule it out.
   * Parsed plainly: rector's AstResolver re-decorates the file under refactor
   * and drops its pending changes. Any same-named callee in the file counts.
   */
  private function readsArgumentList(FunctionReflection|MethodReflection $reflection): bool {
    $file = $reflection instanceof FunctionReflection ? $reflection->getFileName() : $reflection->getDeclaringClass()->getFileName();
    $source = $file !== NULL && is_file($file) ? file_get_contents($file) : FALSE;
    $stmts = $source === FALSE ? NULL : (new ParserFactory())->createForHostVersion()->parse($source);
    if ($stmts === NULL) {
      return TRUE;
    }
    $finder = new NodeFinder();
    // Functions reflect fully qualified, declarations carry the short name.
    $short = strtolower(substr((string) strrchr('\\' . $reflection->getName(), '\\'), 1));
    $found = FALSE;
    foreach ($finder->find($stmts, static fn (Node $n): bool => $n instanceof ClassMethod || $n instanceof Function_) as $callee) {
      if (!($callee instanceof ClassMethod || $callee instanceof Function_) || $callee->name->toLowerString() !== $short) {
        continue;
      }
      $found = TRUE;
      if ($finder->findFirst($callee->getStmts() ?? [], static fn (Node $n): bool => $n instanceof FuncCall && $n->name instanceof Name
        && in_array($n->name->toLowerString(), ['func_get_args', 'func_num_args', 'func_get_arg'], TRUE)) !== NULL) {
        return TRUE;
      }
    }
    return !$found;
  }

  /**
   * Only literals may be dropped — a call or variable could have side effects
   * or resolve to the default only by coincidence.
   */
  private function isLiteralDefault(Node\Expr $expr, mixed $default): bool {
    if (!$expr instanceof Scalar && !$expr instanceof ConstFetch) {
      return FALSE;
    }
    return $default === NULL ? $this->valueResolver->isNull($expr) : $this->valueResolver->isValue($expr, $default);
  }
}
