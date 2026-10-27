<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A route reachable by GET must not change data.
 *
 * "GET is safe" is not a style preference: browsers, prefetchers, link
 * scanners and corporate proxies fetch URLs on their own, a logged-in user
 * only has to look at a page for an `<img src="…/civicrm/x/delete?id=5">`
 * to fire, and every back button re-runs the write. A handler that creates
 * or deletes on GET is therefore a CSRF hole and a data-integrity problem
 * at the same time.
 *
 * Two kinds of handler are checked: the static methods a repo's xml/Menu
 * declares as page_callback (read from the generated route catalog, see
 * RouteCatalog) and the run() of a CRM_Core_Page subclass. Writes are
 * followed two hops through the class's own methods, because the endpoint
 * itself is usually three lines that delegate.
 *
 * Silent when the handler checks the request method — `$request->getMethod()
 * !== 'POST'` with a 405 answer is the whole point, and a handler that only
 * writes on POST is fine. The other legitimate case, a GET that writes on
 * purpose (a redirect flow with its own CSRF mitigation), is a narrow
 * ignore of `ck.route.mutationOnGet` carrying the reason.
 *
 * @implements Rule<InClassNode>
 */
final class GetMutationRule implements Rule
{
    private RouteCatalog $routes;

    public function __construct(RouteCatalog $routes)
    {
        $this->routes = $routes;
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // PHP's own name for an anonymous class; phpstan's hashes a path.
        $class = $node->getClassReflection()->isAnonymous() ? 'class@anonymous' : $node->getClassReflection()->getName();

        /** @var array<string, Node\Stmt\ClassMethod> $methods */
        $methods = [];
        foreach ($node->getOriginalNode()->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $methods[$stmt->name->toLowerString()] = $stmt;
            }
        }
        // A trait's methods are the class's own, unless the class overrides them.
        $traitFiles = [];
        foreach (self::traitMethods($node->getClassReflection()) as $name => [$method, $file]) {
            if (!isset($methods[$name])) {
                $methods[$name] = $method;
                $traitFiles[spl_object_id($method)] = $file;
            }
        }

        $errors = [];
        foreach (self::handlers($methods, $class, $node->getClassReflection(), $this->routes) as $name => $route) {
            $reachable = self::reachable($methods, $name);
            if (self::checksRequestMethod($reachable)) {
                continue;
            }
            foreach ($reachable as $method) {
                foreach (self::ownNodes($method, static fn (Node $n): bool => $n instanceof Node\Expr, true) as $expr) {
                    $what = Mutation::describe($expr);
                    if ($what === null) {
                        continue;
                    }
                    $error = RuleErrorBuilder::message(MutationOnGet::message(
                        $what,
                        $class . '::' . $methods[$name]->name->toString() . '()',
                        $route,
                        '@phpstan-ignore ck.route.mutationOnGet',
                    ))
                        ->identifier('ck.route.mutationOnGet')
                        ->line($expr->getStartLine());
                    if (isset($traitFiles[spl_object_id($method)])) {
                        $error = $error->file($traitFiles[spl_object_id($method)]);
                    }
                    $errors[] = $error->build();
                }
            }
        }

        return $errors;
    }

    /**
     * The methods of this class a browser enters through, lowercase name =>
     * route path.
     *
     * @param  array<string, Node\Stmt\ClassMethod> $methods
     * @return array<string, string>
     */
    private static function handlers(array $methods, string $class, ClassReflection $reflection, RouteCatalog $routes): array
    {
        $handlers = [];
        foreach ($methods as $name => $method) {
            if (!$method->isStatic()) {
                continue;
            }
            $route = $routes->routeForMethod($class, $method->name->toString());
            if ($route !== null) {
                $handlers[$name] = $route;
            }
        }

        if (isset($methods['run']) && self::isPage($reflection)) {
            $handlers['run'] = $routes->routeForPage($class) ?? '';
        }

        return $handlers;
    }

    /**
     * Methods of the traits a class uses, lowercase name => [method, file].
     *
     * Reflection has no method bodies, so each trait's file is parsed.
     *
     * @return array<string, array{Node\Stmt\ClassMethod, string}>
     */
    private static function traitMethods(ClassReflection $reflection): array
    {
        $found = [];
        foreach ($reflection->getTraits(true) as $trait) {
            $file = $trait->getFileName();
            if ($file === null) {
                continue;
            }
            $ast = (new ParserFactory())->createForHostVersion()->parse((string) file_get_contents($file)) ?? [];
            $ast = (new NodeTraverser(new NameResolver()))->traverse($ast);
            foreach ((new NodeFinder())->findInstanceOf($ast, Node\Stmt\Trait_::class) as $declaration) {
                if (strcasecmp((string) $declaration->namespacedName, $trait->getName()) !== 0) {
                    continue;
                }
                foreach ($declaration->getMethods() as $method) {
                    $found[$method->name->toLowerString()] ??= [$method, $file];
                }
            }
        }

        return $found;
    }

    /** A CRM_Core_Page subclass — its run() IS the route. */
    private static function isPage(ClassReflection $reflection): bool
    {
        foreach ($reflection->getParentClassesNames() as $parent) {
            if ($parent === MutationOnGet::PAGE_BASE) {
                return true;
            }
        }

        return false;
    }

    /**
     * The handler plus the class's own methods it calls, MAX_HOPS deep.
     *
     * An endpoint is typically a few lines of HTTP handling around a helper
     * that does the work, so a rule that only reads the handler body sees
     * nothing at all. Two hops is where it stops: further down the call
     * graph a write is no longer obviously this route's doing.
     *
     * @param  array<string, Node\Stmt\ClassMethod> $methods
     * @return list<Node\Stmt\ClassMethod>
     */
    private static function reachable(array $methods, string $root): array
    {
        $seen = [];
        $level = [$root];
        for ($hop = 0; $hop <= MutationOnGet::MAX_HOPS && $level !== []; $hop++) {
            $next = [];
            foreach ($level as $name) {
                if (isset($seen[$name]) || !isset($methods[$name])) {
                    continue;
                }
                $seen[$name] = $methods[$name];
                foreach (self::localCalls($methods[$name]) as $callee) {
                    $next[] = $callee;
                }
            }
            $level = $next;
        }

        return array_values($seen);
    }

    /**
     * Calls to this class's own methods: `$this->x()`, `self::x()`,
     * `static::x()`.
     *
     * @return list<string>
     */
    /**
     * The method's nodes matching $filter, minus those of a class declared in it:
     * its methods run only when called, an anonymous class's constructor runs
     * right there when $constructors.
     *
     * @param callable(Node): bool $filter
     * @return list<Node>
     */
    private static function ownNodes(Node\Stmt\ClassMethod $method, callable $filter, bool $constructors = false): array
    {
        $visitor = new class ($filter, $constructors) extends NodeVisitorAbstract {
            /** @var list<Node> */
            public array $found = [];

            /** @param callable(Node): bool $filter */
            public function __construct(private $filter, private bool $constructors)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if (($this->filter)($node)) {
                    $this->found[] = $node;
                }
                $runs = match (true) {
                    // phpstan names an analysed anonymous class, so its parser attribute decides.
                    $node instanceof Node\Stmt\Class_ => $this->constructors && $node->getAttribute('anonymousClass') === true,
                    $node instanceof Node\Stmt\ClassMethod => $node->name->toLowerString() === '__construct',
                    default => true,
                };

                return $runs ? null : NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }
        };
        (new NodeTraverser($visitor))->traverse($method->stmts ?? []);

        return $visitor->found;
    }

    private static function localCalls(Node\Stmt\ClassMethod $method): array
    {
        $names = [];
        foreach (self::ownNodes($method, static fn (Node $n): bool => $n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\StaticCall) as $call) {
            if (!$call instanceof Node\Expr\MethodCall && !$call instanceof Node\Expr\StaticCall) {
                continue;
            }
            if (!$call->name instanceof Node\Identifier) {
                continue;
            }
            if ($call instanceof Node\Expr\MethodCall) {
                if ($call->var instanceof Node\Expr\Variable && $call->var->name === 'this') {
                    $names[] = $call->name->toLowerString();
                }
                continue;
            }
            $class = strtolower(ltrim(Sql::staticClassName($call) ?? '', '\\'));
            if ($class === 'self' || $class === 'static') {
                $names[] = $call->name->toLowerString();
            }
        }

        return $names;
    }

    /**
     * Does anything on this path look at the request method?
     *
     * The PSR-7 `$request->getMethod()`, the raw `$_SERVER['REQUEST_METHOD']`,
     * or core's `CRM_Core_Page_AJAX::validateAjaxRequestMethod()`, which
     * demands an XMLHttpRequest header no link or prefetch can send. The comparison value is not inspected:
     * a handler that reads the method at all is deciding on it, and second-
     * guessing which verb it allows would only produce noise.
     *
     * @param list<Node\Stmt\ClassMethod> $methods
     */
    private static function checksRequestMethod(array $methods): bool
    {
        foreach ($methods as $method) {
            foreach (self::ownNodes($method, static fn (Node $n): bool => $n instanceof Node\Expr) as $expr) {
                if ($expr instanceof Node\Expr\MethodCall
                    && $expr->name instanceof Node\Identifier
                    && $expr->name->toLowerString() === 'getmethod') {
                    return true;
                }
                if ($expr instanceof Node\Expr\StaticCall && $expr->name instanceof Node\Identifier
                    && $expr->name->toLowerString() === 'validateajaxrequestmethod'
                    && in_array(strtolower(ltrim(Sql::staticClassName($expr) ?? '', '\\')), ['crm_core_page_ajax', 'self', 'static', 'parent'], true)) {
                    return true;
                }
                if ($expr instanceof Node\Expr\ArrayDimFetch
                    && $expr->var instanceof Node\Expr\Variable
                    && $expr->var->name === '_SERVER'
                    && $expr->dim !== null
                    && Sql::literalString($expr->dim) === 'REQUEST_METHOD') {
                    return true;
                }
            }
        }

        return false;
    }
}
