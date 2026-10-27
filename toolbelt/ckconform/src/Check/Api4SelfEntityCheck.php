<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * An APIv4 entity called by name from JavaScript that exists nowhere.
 *
 * Api4EntityCheck reads PHP and asks core about `\Civi\Api4\Foo`. It is blind to
 * a React component calling `getEntities('LedgerAdapter', ...)` for an entity
 * nobody ever wrote: every request 500s, and a `catch {}` with a hardcoded
 * fallback list makes that read as deliberate for as long as nobody looks.
 *
 * A string counts as an entity name only where APIv4 receives one: the entity
 * argument of `CRM.api4()` / `crmApi4()`, the segment after `ajax/api4/` in a
 * literal URL, and the matching argument of every wrapper the repo defines
 * around those. Wrappers are found by following parameters, to a fixpoint:
 * `getEntities(entity)` -> `apiCall(entity, action)` -> `${API_BASE}/${entity}`.
 * An event name handed to `emit('FormSaved')` never reaches such a position.
 *
 * A wrapper is a declared function (`function f`, `const f =`) or one assigned
 * to a named object (`api.f = ...`). A call reaches it in its own file, or in a
 * file that imports it from a repo path (`import { f } from '@/api'`,
 * `import * as api from './api'`, a default import), also through re-exports
 * (`export * from './civicrm'`). Relative paths resolve exactly; `@/` and `~/` take
 * the nearest file whose path ends in the rest. Object and class methods are not
 * followed: their names (`get`, `load`) are shared by every Map and loader.
 * Neither are wrappers from a package, nor functions declared inside a named
 * function: those, like any local binding, only hide a same-named wrapper.
 * Beyond its position, a finding needs a multi-word CamelCase name
 * (LedgerAdapter, not Email) that neither core nor this extension defines.
 */
/**
 * Declared wrapper names by file, each file's default export, its imports
 * (named by local name, namespaces by alias) and its exports, resolved to files.
 *
 * @phpstan-type Scope array{
 *     declared: array<string, array<string, true>>,
 *     defaults: array<string, string>,
 *     imports: array<string, array{array<string, array{string, list<string>}>, array<string, list<string>>}>,
 *     exports: array<string, list<array{string, string, list<string>}>>,
 * }
 */
final class Api4SelfEntityCheck implements Check
{
    /** Built artefacts restate the source; a finding there is the same one twice. */
    private const SKIP = ['dist', 'node_modules', 'vendor', 'packages', 'build'];

    private const EXTENSIONS = ['js', 'jsx', 'ts', 'tsx', 'mjs'];

    /** Core's JavaScript APIs, keyed like wrappers, with the argument that takes the entity. */
    private const CORE_APIS = ['#crmApi4' => [0], '#CRM.api4' => [0]];

    private const ENTITY = '[A-Z][a-z0-9]+(?:[A-Z][a-z0-9]+)+';

    private const IDENT = '[A-Za-z_$][\w$]*';

    /** An optional type argument list, one level of nesting deep: `<T>`, `<Array<Row>>`. */
    private const GENERIC = '(?:<(?:[^<>()]|<[^<>()]*>)*>)?';

    /** Words before a parenthesis that open a statement or expression, never a function. */
    private const KEYWORDS = [
        'await', 'catch', 'delete', 'do', 'else', 'for', 'if', 'in', 'new', 'of', 'return', 'switch',
        'throw', 'typeof', 'void', 'while', 'with', 'yield',
    ];

    /** Words after which a slash starts a regex literal rather than a division. */
    private const REGEX_AFTER = ['return', 'typeof', 'case', 'do', 'else', 'in', 'of', 'yield', 'await', 'void', 'delete', 'throw', 'new'];

    public function name(): string
    {
        return 'api4-self-entity';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        // Without core there is no way to tell a missing entity from one we
        // simply do not define ourselves, and guessing would mean noise.
        if ($context->coreDir === null || !$context->isGitRepo()) {
            return;
        }

        $sources = [];
        $functions = [];
        $modules = [];
        $scope = ['declared' => [], 'defaults' => [], 'imports' => [], 'exports' => []];
        $scanned = false;
        foreach ($context->trackedFiles() as $file) {
            if (!$this->scannable($file)) {
                continue;
            }
            $scanned = true;
            $source = $context->read($file);
            if ($source === null) {
                continue;
            }
            $sources[$file] = [self::blank($source, strings: false), self::blank($source, strings: true)];
            $functions[$file] = self::functions($sources[$file][1]);
            $modules[$file] = self::imports(...$sources[$file]);
            foreach ($functions[$file] as [$key]) {
                if ($key !== null && !str_contains($key, '.')) {
                    $scope['declared'][$key][$file] = true;
                }
            }
            $default = '/(?<![\w$.])export\s+default\s+(?:(?:async\s+)?function\s*\*?\s*(' . self::IDENT . ')|(' . self::IDENT . ')\s*(?:;|$))/m';
            if (preg_match($default, $sources[$file][1], $match) === 1) {
                $scope['defaults'][$file] = $match[1] !== '' ? $match[1] : $match[2];
            }
        }

        if (!$scanned) {
            return;
        }

        $stems = [];
        foreach (array_keys($sources) as $file) {
            $stem = (string) preg_replace('#\.[cm]?[jt]sx?$#', '', $file);
            $stems[$stem][] = $file;
            if (basename($stem) === 'index') {
                $stems[dirname($stem)][] = $file;
            }
        }
        $memo = [];
        foreach ($modules as $file => [$named, $namespaces, $exports]) {
            $scope['imports'][$file] = [[], []];
            foreach ($named as $local => [$exported, $module]) {
                $scope['imports'][$file][0][$local] = [$exported, self::targets($file, $module, $stems, $memo)];
            }
            foreach ($namespaces as $alias => $module) {
                $scope['imports'][$file][1][$alias] = self::targets($file, $module, $stems, $memo);
            }
            foreach ($exports as [$as, $original, $module]) {
                $scope['exports'][$file][] = [$as, $original, $module === null ? [$file] : self::targets($file, $module, $stems, $memo)];
            }
        }

        $apis = self::apis($sources, $functions, $scope);
        $external = CoreApi4::declaredExternal($context);
        $dangling = [];
        foreach ($sources as $file => [$text, $code]) {
            foreach (self::candidates($file, $text, $code, $apis, $scope, $functions[$file]) as $name) {
                if ($context->shipsApi4Entity($name) || in_array($name, $external, true)
                    || CoreApi4::classFile((string) $context->coreDir, $name) !== null
                    || CoreApi4::inRequiredExtension($context, $name)
                ) {
                    continue;
                }
                $dangling[$name][$file] = true;
            }
        }

        if ($dangling === []) {
            $reporter->ok('every APIv4 entity named in an api4 call, an api4 URL or a wrapper of them exists');

            return;
        }

        $parts = [];
        foreach ($dangling as $name => $files) {
            $parts[] = $name . ' (' . implode(', ', array_keys($files)) . ')';
        }
        $reporter->fail(
            'JavaScript calls APIv4 entities that exist nowhere: ' . implode('; ', $parts)
            . ' — every such request 500s, and whatever catches it is hiding that'
        );
    }

    /**
     * Entity-shaped literals in an APIv4 entity position.
     *
     * @param array<string, list<int>> $apis
     * @param Scope $scope
     * @param list<array{?string, list<?string>, int, int, list<string>}> $functions
     * @return list<string>
     */
    private static function candidates(string $file, string $text, string $code, array $apis, array $scope, array $functions): array
    {
        // Parameters and local declarations hide a wrapper of the same name in their
        // scope; a declaration that defines a wrapper itself does not.
        $local = [];
        $defines = [];
        foreach ($functions as [$key, , $start, $end, $bound]) {
            foreach ($bound as $name) {
                $local[$name][] = [$start, $end];
            }
            if ($key !== null) {
                $defines[$key][] = $start;
            }
        }
        foreach (self::declarations($code, 0, strlen($code)) as [$name, $from, $to]) {
            $semicolon = strpos($code, ';', $from);
            foreach ($defines[$name] ?? [] as $start) {
                if ($start > $from && ($semicolon === false || $semicolon > $start)) {
                    continue 2;
                }
            }
            $local[$name][] = [$from, $to];
        }

        $names = [];
        foreach (self::calls($file, $text, $code, $apis, $scope) as [$positions, $args, $at, $binding, $callee]) {
            // An injected `crmApi4` is still core's.
            foreach (isset(self::CORE_APIS['#' . $callee]) ? [] : $local[$binding] ?? [] as [$from, $to]) {
                if ($at > $from && $at < $to) {
                    continue 2;
                }
            }
            foreach ($positions as $position) {
                if (preg_match('/^[\'"`](' . self::ENTITY . ')[\'"`]$/', $args[$position] ?? '', $match) === 1) {
                    $names[] = $match[1];
                }
            }
        }
        preg_match_all('#[\'"`][^\'"`\s]*ajax/api4/(' . self::ENTITY . ')/#', $text, $matches);

        return array_values(array_unique([...$names, ...$matches[1]]));
    }

    /**
     * Every wrapper key with the argument positions that carry an entity name,
     * core's APIs included.
     *
     * @param array<string, array{string, string}> $sources
     * @param array<string, list<array{?string, list<?string>, int, int, list<string>}>> $functions
     * @param Scope $scope
     * @return array<string, list<int>>
     */
    private static function apis(array $sources, array $functions, array $scope): array
    {
        $wrappers = [];
        foreach ($functions as $file => $records) {
            // A URL base constant counts in the file that defines it.
            $urls = ['ajax\/api4'];
            preg_match_all(
                '/\b(?:const|let|var)\s+(' . self::IDENT . ')\s*(?::[^=;]*)?=[^;\n]*[\'"`][^\'"`]*ajax\/api4\/?[\'"`]/',
                $sources[$file][0],
                $constants,
            );
            foreach ($constants[1] as $constant) {
                $urls[] = '(?<![\w$])' . preg_quote($constant, '/') . '(?![\w$])';
            }
            usort($records, static fn (array $a, array $b): int => $a[2] <=> $b[2]);
            foreach ($records as $i => [$key, $params, $start, $end]) {
                if ($key === null) {
                    continue;
                }
                // Nested functions and block declarations that redeclare a parameter hide the outer one.
                $shadows = [];
                for ($j = $i + 1; isset($records[$j]) && $records[$j][2] < $end; $j++) {
                    if ($records[$j][2] > $start && $records[$j][3] <= $end) {
                        foreach ($records[$j][4] as $param) {
                            $shadows[$param][] = [$records[$j][2] - $start, $records[$j][3] - $records[$j][2]];
                        }
                    }
                }
                if (array_filter($params) !== []) {
                    foreach (self::declarations($sources[$file][1], $start, $end) as [$name, $from, $to]) {
                        if (in_array($name, $params, true)) {
                            $shadows[$name][] = [$from - $start, $to - $from];
                        }
                    }
                }
                $wrappers[] = [$file, $file . '#' . $key, $params, $start, $end, $shadows, $urls];
            }
        }

        $apis = self::CORE_APIS;
        do {
            $changed = false;
            foreach ($wrappers as [$file, $key, $params, $start, $end, $shadows, $urls]) {
                [$text, $code] = $sources[$file];
                foreach ($params as $position => $param) {
                    if ($param === null || in_array($position, $apis[$key] ?? [], true)) {
                        continue;
                    }
                    $body = [substr($text, $start, $end - $start), substr($code, $start, $end - $start)];
                    foreach ($shadows[$param] ?? [] as [$from, $length]) {
                        $body = array_map(static fn (string $view): string => substr_replace($view, str_repeat(' ', $length), $from, $length), $body);
                    }
                    if (self::forwards($file, $body[0], $body[1], $param, $urls, $apis, $scope)) {
                        $apis[$key][] = $position;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return $apis;
    }

    /**
     * Whether a body hands the parameter on as an entity name: into an API's
     * entity argument, or as the path segment right after an api4 prefix.
     *
     * @param list<string> $urls
     * @param array<string, list<int>> $apis
     * @param Scope $scope
     */
    private static function forwards(string $file, string $text, string $code, string $param, array $urls, array $apis, array $scope): bool
    {
        $glue = '[\s\'"`+${}]*';
        $segment = '/(?:' . implode('|', $urls) . ')' . $glue . '\/' . $glue . '(?:encodeURIComponent\s*\(\s*)?'
            . preg_quote($param, '/') . '(?![\w$])/';
        if (preg_match($segment, $text) === 1) {
            return true;
        }
        foreach (self::calls($file, $text, $code, $apis, $scope) as [$positions, $args]) {
            foreach ($positions as $position) {
                if (($args[$position] ?? null) === $param) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Calls that reach a known API: entity positions, argument texts, offset,
     * the name a local binding would have to hide (callee or receiver), and the callee.
     *
     * @param array<string, list<int>> $apis
     * @param Scope $scope
     * @return list<array{list<int>, list<string>, int, string, string}>
     */
    private static function calls(string $file, string $text, string $code, array $apis, array $scope): array
    {
        $pattern = '/(?<![\w$])(' . self::IDENT . ')\s*' . self::GENERIC . '\s*\(/';
        if (preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            throw new \RuntimeException('api4-self-entity: cannot scan ' . $file . ': ' . preg_last_error_msg());
        }

        $calls = [];
        foreach ($matches as $match) {
            $before = substr($code, max(0, $match[0][1] - 80), min(80, $match[0][1]));
            if (preg_match('/\b(?:function|new)\s*$/', $before) === 1) {
                continue;
            }
            $receiver = null;
            if (preg_match('/(' . self::IDENT . ')\s*\??\.\s*$/', $before, $owner) === 1) {
                $receiver = $owner[1];
            } elseif (preg_match('/\.\s*$/', $before) === 1) {
                continue;
            }
            $positions = [];
            foreach (self::keys($file, $receiver, $match[1][0], $scope) as $key) {
                array_push($positions, ...($apis[$key] ?? []));
            }
            $open = $match[0][1] + strlen($match[0][0]) - 1;
            $close = $positions === [] ? null : self::closer($code, $open);
            if ($close === null) {
                continue;
            }
            $args = [];
            foreach (self::parts($code, $open + 1, $close, types: false) as [$from, $to]) {
                $args[] = trim(substr($text, $from, $to - $from));
            }
            $callee = $receiver === null ? $match[1][0] : $receiver . '.' . $match[1][0];
            $calls[] = [array_values(array_unique($positions)), $args, $match[0][1], $receiver ?? $match[1][0], $callee];
        }

        return $calls;
    }

    /**
     * The wrapper keys a call can reach: its own file's, core's, and the one
     * behind the name or namespace it imports from a repo module.
     *
     * @param Scope $scope
     * @return list<string>
     */
    private static function keys(string $file, ?string $receiver, string $name, array $scope): array
    {
        $local = $receiver === null ? $name : $receiver . '.' . $name;
        $keys = [$file . '#' . $local, '#' . $local];
        [$named, $namespaces] = $scope['imports'][$file] ?? [[], []];
        if ($receiver === null && isset($named[$name])) {
            array_push($keys, ...self::exported($named[$name][1], $named[$name][0], $scope));
        } elseif ($receiver !== null && isset($namespaces[$receiver])) {
            array_push($keys, ...self::exported($namespaces[$receiver], $name, $scope));
        }

        return $keys;
    }

    /**
     * The wrapper keys behind an exported name of some modules, through re-exports.
     *
     * @param list<string> $files
     * @param Scope $scope
     * @param array<string, true> $seen
     * @return list<string>
     */
    private static function exported(array $files, string $name, array $scope, array &$seen = []): array
    {
        $keys = [];
        foreach ($files as $file) {
            if (isset($seen[$file . '#' . $name])) {
                continue;
            }
            $seen[$file . '#' . $name] = true;
            $local = $name === 'default' ? ($scope['defaults'][$file] ?? null) : $name;
            if ($local !== null && isset($scope['declared'][$local][$file])) {
                $keys[] = $file . '#' . $local;
                continue;
            }
            foreach ($scope['exports'][$file] ?? [] as [$as, $original, $from]) {
                if ($as === $name || ($as === '*' && $name !== 'default')) {
                    array_push($keys, ...self::exported($from, $as === '*' ? $name : $original, $scope, $seen));
                }
            }
        }

        return $keys;
    }

    /**
     * The scanned files a module path names: relative paths from the importing
     * file, `@/` and `~/` from a source root this check does not know.
     *
     * @param array<string, list<string>> $stems
     * @param array<string, list<string>> $memo
     * @return list<string>
     */
    private static function targets(string $file, string $module, array $stems, array &$memo): array
    {
        $module = (string) preg_replace('#\.[cm]?[jt]sx?$#', '', $module);
        if (str_starts_with($module, '.')) {
            $path = [];
            foreach (explode('/', dirname($file) . '/' . $module) as $segment) {
                if ($segment === '..' && $path === []) {
                    return [];
                }
                if ($segment === '..') {
                    array_pop($path);
                } elseif ($segment !== '.' && $segment !== '') {
                    $path[] = $segment;
                }
            }

            return $stems[implode('/', $path)] ?? [];
        }
        if (!isset($memo[$module])) {
            $rest = substr($module, 2);
            $memo[$module] = [];
            foreach ($stems as $stem => $files) {
                if ($rest !== '' && ($stem === $rest || str_ends_with($stem, '/' . $rest))) {
                    array_push($memo[$module], ...$files);
                }
            }
        }
        // Of several matches, the alias root is the one nearest the importing file.
        $shared = [];
        foreach ($memo[$module] as $target) {
            $shared[$target] = strspn($file ^ $target, "\0");
        }

        return $shared === [] ? [] : array_keys($shared, max($shared), true);
    }

    /**
     * What a module imports from and re-exports out of the repo's own files
     * (relative, `@/` or `~/` paths): named imports by local name with the
     * exported name, namespace aliases, and exports as [name, original, path],
     * the path null for the module's own names. Statements inside strings do not count.
     *
     * @return array{array<string, array{string, string}>, array<string, string>, list<array{string, string, ?string}>}
     */
    private static function imports(string $text, string $code): array
    {
        $repo = '[\'"]((?:\.|[@~]\/)[^\'"]*)[\'"]';
        $from = '\s*(?:from\s*|=\s*require\s*\(\s*)' . $repo;
        $item = '/^\s*(?:type\s+)?(' . self::IDENT . ')(?:\s*(?:as|:)\s*(' . self::IDENT . '))?\s*$/';
        $live = static fn (array $match): bool => $code[$match[0][1]] === $text[$match[0][1]];

        $named = [];
        preg_match_all('/\b(import|const|let|var)\s+(?:type\s+)?(?:(' . self::IDENT . ')\s*,\s*)?\{([^}]*)\}' . $from . '/', $text, $lists, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach (array_filter($lists, $live) as $list) {
            if ($list[1][0] === 'import' && $list[2][0] !== '') {
                $named[$list[2][0]] = ['default', $list[4][0]];
            }
            foreach (explode(',', $list[3][0]) as $entry) {
                if (preg_match($item, $entry, $import) === 1) {
                    $named[$import[2] ?? $import[1]] = [$import[1], $list[4][0]];
                }
            }
        }
        preg_match_all('/\bimport\s+(?:type\s+)?(' . self::IDENT . ')\s*from\s*' . $repo . '/', $text, $defaults, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach (array_filter($defaults, $live) as $default) {
            $named[$default[1][0]] = ['default', $default[2][0]];
        }
        $namespaces = [];
        preg_match_all('/\b(?:import\s+\*\s+as|const|let|var)\s+(' . self::IDENT . ')' . $from . '/', $text, $aliases, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach (array_filter($aliases, $live) as $alias) {
            $namespaces[$alias[1][0]] = $alias[2][0];
        }

        $exports = [];
        preg_match_all('/\bexport\s+(?:type\s+)?\{([^}]*)\}(?:\s*from\s*[\'"]([^\'"]*)[\'"])?/', $text, $lists, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach (array_filter($lists, $live) as $list) {
            $module = isset($list[2]) ? $list[2][0] : null;
            if ($module !== null && preg_match('#^(?:\.|[@~]/)#', $module) !== 1) {
                continue;
            }
            foreach (explode(',', $list[1][0]) as $entry) {
                if (preg_match($item, $entry, $export) === 1) {
                    $exports[] = [$export[2] ?? $export[1], $export[1], $module];
                }
            }
        }
        preg_match_all('/\bexport\s*\*\s*from\s*' . $repo . '/', $text, $stars, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach (array_filter($stars, $live) as $star) {
            $exports[] = ['*', '*', $star[1][0]];
        }

        return [$named, $namespaces, $exports];
    }

    /**
     * Functions: wrapper key (null when it cannot be called by a known name),
     * parameter names (null when destructured), body offsets, and every name
     * the parameter list binds, destructured ones included.
     *
     * @return list<array{?string, list<?string>, int, int, list<string>}>
     */
    private static function functions(string $code): array
    {
        $functions = [];
        $offset = 0;
        while (($open = strpos($code, '(', $offset)) !== false) {
            $offset = $open + 1;
            $close = self::closer($code, $open);
            $body = $close === null ? null : self::opensBody($code, $close);
            $key = $body === null ? false : self::key(substr($code, max(0, $open - 200), min(200, $open)));
            if ($close === null || $body === null || $key === false) {
                continue;
            }
            $params = [];
            foreach (self::parts($code, $open + 1, $close, types: true) as [$from, $to]) {
                $params[] = preg_match('/^\s*(?:\.\.\.)?(' . self::IDENT . ')/', substr($code, $from, $to - $from), $param) === 1
                    ? $param[1]
                    : null;
            }
            $functions[] = [$key, $params, $body, self::bodyEnd($code, $body), self::binds($code, $open + 1, $close, '')];
        }

        // `entity => ...` has no parenthesis to start from.
        preg_match_all('/(?<![\w$])(' . self::IDENT . ')\s*=>/', $code, $arrows, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($arrows as $arrow) {
            $key = self::key(substr($code, max(0, $arrow[0][1] - 200), min(200, $arrow[0][1])));
            if ($key !== false) {
                $body = $arrow[0][1] + strlen($arrow[0][0]);
                $functions[] = [$key, [$arrow[1][0]], $body, self::bodyEnd($code, $body), [$arrow[1][0]]];
            }
        }

        // A function declared inside a named one is local to it, not a wrapper; inside
        // an anonymous one (a module IIFE, an Angular factory) it still is.
        usort($functions, static fn (array $a, array $b): int => $a[2] <=> $b[2]);
        $open = [];
        foreach ($functions as $i => [$key, , $start, $end]) {
            while ($open !== [] && end($open)[0] <= $start) {
                array_pop($open);
            }
            if ($key !== null && !str_contains($key, '.') && in_array(true, array_column($open, 1), true)) {
                $functions[$i][0] = null;
            }
            $open[] = [$end, $key !== null];
        }

        return $functions;
    }

    /**
     * The names a parameter list (`$pattern` empty) or a destructuring pattern
     * (`{` or `[`) binds; type annotations, default values and keys bind none.
     *
     * @return list<string>
     */
    private static function binds(string $code, int $from, int $to, string $pattern): array
    {
        $names = [];
        $modifiers = $pattern === '' ? '(?:(?:public|private|protected|readonly|override)\s+)*' : '';
        foreach (self::parts($code, $from, $to, types: $pattern === '') as [$start, $end]) {
            if (preg_match('/\G\s*(?:\.\.\.\s*)?' . $modifiers . '(?:(' . self::IDENT . ')\s*(:\s*)?|([{[]))/', $code, $match, 0, $start) !== 1) {
                continue;
            }
            $at = $start + strlen($match[0]);
            if (($match[1] ?? '') !== '' && ($pattern !== '{' || ($match[2] ?? '') === '')) {
                $names[] = $match[1];
                continue;
            }
            if (($match[1] ?? '') !== '' && preg_match('/\G' . self::IDENT . '/', $code, $target, 0, $at) === 1) {
                // `{ key: target }` binds the target.
                $names[] = $target[0];
                continue;
            }
            $bracket = ($match[3] ?? '') !== '' ? $at - 1 : $at;
            $close = in_array($code[$bracket] ?? '', ['{', '['], true) ? self::closer($code, $bracket) : null;
            if ($close !== null && $close < $end && !($pattern === '{' && ($match[3] ?? '') === '[')) {
                array_push($names, ...self::binds($code, $bracket + 1, $close, $code[$bracket]));
            }
        }

        return $names;
    }

    /**
     * Block-scoped redeclarations between $start and $end: the name and the
     * range it hides the outer one in, `const`/`let`/`var` (destructured too)
     * and `catch` bindings; a `for (const x ...)` binding covers the loop only.
     *
     * @return list<array{string, int, int}>
     */
    private static function declarations(string $code, int $start, int $end): array
    {
        $declarations = [];
        $pattern = '/(?<![\w$.])(?:(?:const|let|var)(?![\w$])\s*(?:(' . self::IDENT . ')|[{[])|function\s*\*?\s*(' . self::IDENT . ')|catch\s*\()/';
        preg_match_all($pattern, substr($code, $start, $end - $start), $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $blocks = self::blockEnds($code, array_map(static fn (array $match): int => $start + $match[0][1], $matches), $start, $end);
        foreach ($matches as $m => $match) {
            if (($match[2][0] ?? '') !== '') {
                $match[1] = $match[2];
            }
            $at = $start + $match[0][1];
            $last = $at + strlen($match[0][0]) - 1;
            $close = ($match[1][0] ?? '') === '' ? self::closer($code, $last) : null;
            if (str_starts_with($match[0][0], 'catch')) {
                $names = $close === null ? [] : self::binds($code, $last + 1, $close, '');
                $to = $close === null ? $end : min($end, self::bodyEnd($code, $close + 1));
            } else {
                $names = ($match[1][0] ?? '') !== '' ? [$match[1][0]] : ($close === null ? [] : self::binds($code, $last + 1, $close, $code[$last]));
                $head = max(0, $at - 40);
                $loop = preg_match('/\bfor\s*(?:await\s*)?(\()\s*$/', substr($code, $head, $at - $head), $for, PREG_OFFSET_CAPTURE) === 1
                    ? self::closer($code, $head + $for[1][1])
                    : null;
                $to = $loop === null ? $blocks[$m] : min($end, self::bodyEnd($code, $loop + 1));
            }
            foreach ($names as $name) {
                $declarations[] = [$name, $at, $to];
            }
        }

        return $declarations;
    }

    /**
     * The wrapper key of a function from the code before its parameters: `name`
     * when declared, `owner.name` when assigned to an object's property, null
     * for anything else that is a function, false when it is not one.
     */
    private static function key(string $head): string|false|null
    {
        $end = '\s*' . self::GENERIC . '\s*$/';
        if (preg_match('/(' . self::IDENT . ')' . $end, $head, $match, PREG_OFFSET_CAPTURE) === 1
            && !in_array($match[1][0], ['async', 'function'], true)
        ) {
            if (in_array($match[1][0], self::KEYWORDS, true)) {
                return false;
            }
            $before = substr($head, 0, $match[1][1]);
            if (preg_match('/\bfunction\s*\*?\s*$/', $before) === 1) {
                return $match[1][0];
            }

            // A method of an object or class; anything else is a call, as in `ok ? f(x) : {}`.
            return preg_match('/(?:^|[{};,])\s*(?:(?:async|static|get|set|public|private|protected|readonly|override|\*)\s*)*$/', $before) === 1
                ? null
                : false;
        }
        $assigned = '/(' . self::IDENT . ')\s*(?::[^=;{}(),:]*)?=\s*(?:async\s+)?(?:function\s*\*?\s*)?' . $end;
        if (preg_match($assigned, $head, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $before = substr($head, 0, $match[1][1]);
        if (preg_match('/\b(?:const|let|var)\s+$/', $before) === 1) {
            return $match[1][0];
        }

        return preg_match('/(' . self::IDENT . ')\s*\??\.\s*$/', $before, $owner) === 1 ? $owner[1] . '.' . $match[1][0] : null;
    }

    /** Where the body starts when the parameter list closing at $close is followed by one. */
    private static function opensBody(string $code, int $close): ?int
    {
        $length = strlen($code);
        $i = $close + 1;
        while ($i < $length && ctype_space($code[$i])) {
            $i++;
        }
        if (($code[$i] ?? '') === ':') {
            // A return type: brackets nest in it, and one that starts with `{` is an object type.
            $type = ++$i;
            while ($type < $length && ctype_space($code[$type])) {
                $type++;
            }
            $depth = 0;
            for ($i = $type; $i < $length; $i++) {
                $char = $code[$i];
                if ($depth === 0 && $i > $type && ($char === '{' || substr($code, $i, 2) === '=>')) {
                    break;
                }
                if ($char === '=' && ($code[$i + 1] ?? '') === '>') {
                    $i++;
                } elseif (str_contains('([{<', $char)) {
                    $depth++;
                } elseif (str_contains(')]}>', $char)) {
                    if (--$depth < 0) {
                        return null;
                    }
                } elseif ($depth === 0 && str_contains(';,=', $char)) {
                    return null;
                }
            }
        }
        if (($code[$i] ?? '') === '{') {
            return $i;
        }

        return substr($code, $i, 2) === '=>' ? $i + 2 : null;
    }

    /** End of a block body, or of an arrow's expression body. */
    private static function bodyEnd(string $code, int $body): int
    {
        $length = strlen($code);
        while ($body < $length && ctype_space($code[$body])) {
            $body++;
        }
        if (($code[$body] ?? '') === '{') {
            return (self::closer($code, $body) ?? $length - 1) + 1;
        }
        $depth = 0;
        for ($i = $body; $i < $length; $i++) {
            $char = $code[$i];
            if (str_contains('([{', $char)) {
                $depth++;
            } elseif (str_contains(')]}', $char) && --$depth < 0) {
                return $i;
            } elseif ($depth === 0 && ($char === ';' || $char === ',')) {
                return $i;
            } elseif ($depth === 0 && $char === "\n" && preg_match('/\G\s*[?:.+\-*\/%&|^<>=]/', $code, $continued, 0, $i) !== 1) {
                // A line break ends the expression unless the next line continues it.
                return $i;
            }
        }

        return $length;
    }

    /** The bracket matching the one at $open. */
    private static function closer(string $code, int $open): ?int
    {
        $depth = 0;
        $length = strlen($code);
        for ($i = $open; $i < $length; $i++) {
            if (str_contains('([{', $code[$i])) {
                $depth++;
            } elseif (str_contains(')]}', $code[$i]) && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * For each offset (ascending), the end of the block that contains it, or
     * $limit when it reaches that far: one pass instead of one per offset.
     *
     * @param list<int> $offsets
     * @return list<int>
     */
    private static function blockEnds(string $code, array $offsets, int $from, int $limit): array
    {
        $stack = [];
        $closes = [];
        $owners = [];
        $next = 0;
        for ($i = $from; $i < $limit; $i++) {
            while (isset($offsets[$next]) && $offsets[$next] <= $i) {
                $owners[$next++] = $stack === [] ? null : $stack[count($stack) - 1];
            }
            if ($code[$i] === '{') {
                $stack[] = $i;
            } elseif ($code[$i] === '}' && $stack !== []) {
                $closes[array_pop($stack)] = $i;
            }
        }

        return array_map(
            static fn (?int $owner): int => $owner === null ? $limit : $closes[$owner] ?? $limit,
            array_pad($owners, count($offsets), null),
        );
    }

    /**
     * Comma-separated parts at the top level; parameter lists also nest `<...>` types.
     *
     * @return list<array{int, int}>
     */
    private static function parts(string $code, int $start, int $end, bool $types): array
    {
        $parts = [];
        $depth = 0;
        $angles = 0;
        $from = $start;
        for ($i = $start; $i < $end; $i++) {
            $char = $code[$i];
            // `<` opens a type argument list right after a name; `a > b` in a default compares.
            if ($types && $char === '<' && preg_match('/[\w$]/', $code[$i - 1]) === 1) {
                $angles++;
                $depth++;
            } elseif ($types && $char === '>' && $angles > 0 && $code[$i - 1] !== '=') {
                $angles--;
                $depth--;
            } elseif (str_contains('([{', $char)) {
                $depth++;
            } elseif (str_contains(')]}', $char)) {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = [$from, $i];
                $from = $i + 1;
            }
        }
        if (trim(substr($code, $from, $end - $from)) !== '') {
            $parts[] = [$from, $end];
        }

        return $parts;
    }

    /**
     * The source with comments, and optionally string and regex contents,
     * replaced by spaces: brackets and commas inside them stop counting,
     * offsets stay put. JSX text is code to this scanner, so a quote right
     * after a letter (`don't`), `://` and `src/*` start nothing.
     */
    private static function blank(string $source, bool $strings): string
    {
        $erase = [];
        $length = strlen($source);
        $templates = [];
        $depth = 0;
        $inTemplate = false;
        $i = 0;
        while ($i < $length) {
            if ($inTemplate) {
                $j = $i;
                while ($j < $length && $source[$j] !== '`' && substr($source, $j, 2) !== '${') {
                    $j += $source[$j] === '\\' ? 2 : 1;
                }
                $j = min($j, $length);
                if ($strings) {
                    $erase[] = [$i, $j];
                }
                $inTemplate = false;
                if (substr($source, $j, 2) === '${') {
                    $templates[] = $depth++;
                    $j++;
                }
                $i = $j + 1;
                continue;
            }
            $char = $source[$i];
            $next = $source[$i + 1] ?? '';
            $previous = $i > 0 ? $source[$i - 1] : '';
            if ($char === '/' && !str_contains('/*>', $next) && self::regexStarts($source, $i)) {
                $j = self::regexEnd($source, $i);
                if ($j !== null) {
                    if ($strings) {
                        $erase[] = [$i + 1, $j];
                    }
                    $i = $j + 1;
                    continue;
                }
            }
            if (($char === '/' && $next === '/' && $previous !== ':')
                || ($char === '/' && $next === '*' && preg_match('/[\w$.]/', $previous) !== 1)
            ) {
                $end = $next === '/' ? strpos($source, "\n", $i) : strpos($source, '*/', $i + 2);
                $end = $end === false ? $length : ($next === '/' ? $end : $end + 2);
                $erase[] = [$i, $end];
                $i = $end;
                continue;
            }
            if (($char === '\'' || $char === '"') && preg_match('/[\w$]/', $previous) !== 1) {
                $j = $i + 1;
                while ($j < $length && $source[$j] !== $char && $source[$j] !== "\n") {
                    $j += $source[$j] === '\\' ? 2 : 1;
                }
                if ($strings) {
                    $erase[] = [$i + 1, min($j, $length)];
                }
                $i = $j + 1;
                continue;
            }
            if ($char === '`') {
                $inTemplate = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($templates !== [] && end($templates) === $depth) {
                    array_pop($templates);
                    $inTemplate = true;
                }
            }
            $i++;
        }

        $out = '';
        $at = 0;
        foreach ($erase as [$from, $to]) {
            $out .= substr($source, $at, $from - $at) . preg_replace('/[^\n]/', ' ', substr($source, $from, $to - $from));
            $at = max($at, $to);
        }

        return $out . substr($source, $at);
    }

    /** Whether the slash at $i opens a regex literal: it follows an operator or a keyword, not a value. */
    private static function regexStarts(string $source, int $i): bool
    {
        $j = $i - 1;
        while ($j >= 0 && ctype_space($source[$j])) {
            $j--;
        }
        $last = substr($source, max(0, $j - 1), min(2, $j + 1));
        if ($j < 0 || $last === '=>' || (str_contains('(,=:[!&|?{};+-*%~^', $source[$j]) && $last !== '++' && $last !== '--')) {
            return true;
        }

        return preg_match('/(?<![\w$.])(' . implode('|', self::REGEX_AFTER) . ')$/', substr($source, max(0, $j - 9), min(10, $j + 1))) === 1;
    }

    /** The closing slash of a regex literal opened at $i, or null when the line has none. */
    private static function regexEnd(string $source, int $i): ?int
    {
        $length = strlen($source);
        $class = false;
        for ($j = $i + 1; $j < $length && $source[$j] !== "\n"; $j++) {
            if ($source[$j] === '\\') {
                $j++;
            } elseif ($source[$j] === '[') {
                $class = true;
            } elseif ($source[$j] === ']') {
                $class = false;
            } elseif ($source[$j] === '/' && !$class) {
                return $j;
            }
        }

        return null;
    }

    private function scannable(string $file): bool
    {
        if (Context::isUnder($file, self::SKIP)) {
            return false;
        }
        // Assertion DSLs put arbitrary strings in first-argument position —
        // `expect(text).not.toBe('TitleParagraph')` is not an API call. Only
        // shipped code can 500 in a browser, so that is what this reads.
        if (Context::isUnder($file, ['test', 'tests', '__tests__'])
            || preg_match('/\.(test|spec)\.[jt]sx?$/', $file) === 1) {
            return false;
        }

        return in_array(pathinfo($file, PATHINFO_EXTENSION), self::EXTENSIONS, true);
    }
}
