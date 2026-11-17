<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Policy;
use CiviKitchen\Ckconform\Reporter;

/**
 * A tracked package.json or composer.json declaring dependencies without a
 * tracked lockfile, or with one .gitignore excludes. Workspace members use the
 * root's lockfile; platform packages (php, ext-*) are no dependencies.
 */
final class LockfileCheck implements Check
{
    private const JS_LOCKFILES = ['package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'bun.lock', 'bun.lockb'];

    private const IGNORABLE_LOCKFILES = ['package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'bun.lock', 'bun.lockb', 'composer.lock'];

    public function name(): string
    {
        return 'lockfile';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        foreach ($this->lockedManifests($context) as $manifest) {
            if (!$this->hasLockfile($context, $manifest)) {
                $reporter->fail("{$manifest} has no tracked lockfile (builds are unreproducible)");
            }
        }

        if ($this->composerDeclaresUntrackedLock($context)) {
            $reporter->fail('composer.json declares dependencies but composer.lock is not tracked');
        }

        foreach ($this->ignoredLockfiles($context) as $path) {
            $reporter->fail(".gitignore excludes {$path} — lockfiles belong in the repo");
        }
    }

    /**
     * Lockfile paths git would ignore, asked via check-ignore next to each
     * tracked manifest and at the root.
     *
     * @return list<string>
     */
    private function ignoredLockfiles(Context $context): array
    {
        $dirs = ['js' => [], 'composer' => []];
        foreach ($this->lockedManifests($context) as $manifest) {
            $dirs['js'][] = $this->directoryOf($manifest);
        }
        foreach ($context->tracked('composer.json', Context::outsideNodeModules(...)) as $manifest) {
            if ($this->composerDeclaresDependencies($context, $manifest)) {
                $dirs['composer'][] = $this->directoryOf($manifest);
            }
        }

        $ignored = [];
        foreach (self::IGNORABLE_LOCKFILES as $name) {
            foreach (array_unique($dirs[$name === 'composer.lock' ? 'composer' : 'js']) as $dir) {
                if ($context->isIgnored($dir . $name)) {
                    $ignored[] = $dir . $name;
                    break;
                }
            }
        }

        return $ignored;
    }

    private function directoryOf(string $manifest): string
    {
        $dir = dirname($manifest);

        return $dir === '.' ? '' : $dir . '/';
    }

    /**
     * The package.json files that need a lockfile beside them: those that
     * declare dependencies or workspaces, minus workspace members.
     *
     * @return list<string>
     */
    private function lockedManifests(Context $context): array
    {
        $manifests = $context->tracked('package.json', Context::outsideNodeModules(...));
        $patterns = [];
        foreach ($manifests as $manifest) {
            $workspaces = $context->json($manifest)['workspaces'] ?? [];
            $workspaces = is_array($workspaces['packages'] ?? null) ? $workspaces['packages'] : $workspaces;
            foreach (is_array($workspaces) ? $workspaces : [] as $pattern) {
                $patterns[] = [$this->directoryOf($manifest), $pattern];
            }
        }
        foreach ($context->tracked('pnpm-workspace.yaml') as $file) {
            $packages = Policy::parseYaml($context->read($file) ?? '')['packages'] ?? [];
            foreach (is_array($packages) ? $packages : [] as $pattern) {
                $patterns[] = [$this->directoryOf($file), $pattern];
            }
        }

        return array_values(array_filter(
            $manifests,
            fn (string $manifest): bool => $this->declaresPackages($context, $manifest)
                && !$this->isWorkspaceMember($manifest, $patterns),
        ));
    }

    private function declaresPackages(Context $context, string $manifest): bool
    {
        $json = $context->json($manifest);
        if ($json === null) {
            return true;
        }
        foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies', 'workspaces'] as $key) {
            if (($json[$key] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0: string, 1: mixed}> $patterns workspace root directory and glob, `!` negating */
    private function isWorkspaceMember(string $manifest, array $patterns): bool
    {
        $directory = dirname($manifest);
        $globs = [];
        foreach ($patterns as [$root, $pattern]) {
            if (is_string($pattern) && $root !== $this->directoryOf($manifest) && str_starts_with($directory . '/', $root)) {
                $globs[$root][] = $pattern;
            }
        }
        foreach ($globs as $root => $rootGlobs) {
            if (self::workspacesInclude($rootGlobs, substr($directory, strlen((string) $root)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * npm's reading of a workspace list, applied to pnpm-workspace.yaml too: an odd run of `!`
     * negates, a later pattern inside a negation lifts it, and a backslash separates paths
     * except in a negation, where it escapes the next character.
     *
     * @param list<string> $patterns
     */
    private static function workspacesInclude(array $patterns, string $path): bool
    {
        $included = [];
        $negated = [];
        foreach ($patterns as $pattern) {
            $bangs = strspn($pattern, '!');
            $glob = $bangs % 2 === 1
                ? preg_replace_callback('#\\\\([^/])#s', static fn (array $escape): string => str_contains('*?[', $escape[1]) ? "[{$escape[1]}]" : $escape[1], substr($pattern, $bangs))
                : str_replace('\\', '/', substr($pattern, $bangs));
            $glob = preg_replace('#^\.?/+#', '', $glob ?? throw new \RuntimeException("workspace glob {$pattern} could not be read: " . preg_last_error_msg()))
                ?? throw new \RuntimeException("workspace glob {$pattern} could not be read: " . preg_last_error_msg());
            // npm reads `a//b` as `a/b` and `a/x/../b` as `a/b`.
            $glob = preg_replace('#/{2,}#', '/', $glob) ?? $glob;
            do {
                $glob = preg_replace('#(?<![^/])(?!\.\.(?:/|$))[^/]+/\.\.(?:/|$)#', '', $glob, 1, $resolved) ?? $glob;
            } while ($resolved > 0);
            if ($bangs % 2 === 1) {
                $negated[] = $glob;
                continue;
            }
            $glob = rtrim($glob, '/');
            $negated = array_filter($negated, static fn (string $negation): bool => !self::globMatches($negation, $glob));
            $included[] = $glob;
        }
        $matches = static fn (string $glob): bool => self::globMatches($glob, $path);

        return array_filter($included, $matches) !== [] && array_filter($negated, $matches) === [];
    }

    /**
     * Workspace globs as npm reads them: `*`, `?` and `[...]` stay within one path segment,
     * a `**` segment spans any number of them, including none, and `{a,b}` lists alternatives.
     */
    private static function globMatches(string $glob, string $path): bool
    {
        foreach (self::expandBraces($glob) as $expanded) {
            // A name that is not UTF-8 is matched byte by byte.
            $unicode = preg_match('//u', $path) === 1 ? 'u' : '';
            $matched = preg_match('#^' . self::globRegex(rtrim($expanded, '/')) . '$#' . $unicode, $path);
            if ($matched === false) {
                throw new \RuntimeException("workspace path {$path} could not be matched: " . preg_last_error_msg());
            }
            if ($matched === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * `a/{b,{c,d}}` as `a/b`, `a/c` and `a/d`; a brace without a top-level comma stays literal.
     *
     * @return list<string>
     */
    private static function expandBraces(string $glob): array
    {
        $depth = 0;
        $open = 0;
        $commas = [];
        for ($i = 0, $length = strlen($glob); $i < $length; $i++) {
            $char = $glob[$i];
            if ($char === '{' && $depth++ === 0) {
                $open = $i;
                $commas = [];
            } elseif ($char === ',' && $depth === 1) {
                $commas[] = $i;
            } elseif ($char === '}' && $depth > 0 && --$depth === 0 && $commas !== []) {
                $bounds = [$open, ...$commas, $i];
                $expanded = [];
                for ($k = 0; $k < count($bounds) - 1; $k++) {
                    $alternative = substr($glob, $bounds[$k] + 1, $bounds[$k + 1] - $bounds[$k] - 1);
                    array_push($expanded, ...self::expandBraces(substr($glob, 0, $open) . $alternative . substr($glob, $i + 1)));
                }

                return $expanded;
            }
        }

        return [$glob];
    }

    private static function globRegex(string $glob): string
    {
        return preg_replace_callback(
            '#/\*\*(?![^/])|(?<![^/])\*\*/|(?<![^/])\*\*$|\*\*?|\?|\[([!^]?)(\]?[^\]/]*)\]|[^*?\[/]+|.#su',
            static fn (array $token): string => match (true) {
                $token[0] === '/**' => '(?:/.*)?',
                $token[0] === '**/' => '(?:.*/)?',
                $token[0] === '**' && $glob === '**' => '.*',
                // A `**` inside a segment is a plain `*`.
                $token[0] === '*' || $token[0] === '**' => '[^/]*',
                $token[0] === '?' => '[^/]',
                ($token[2] ?? '') !== '' => self::globClass($token[1] !== '', $token[2]),
                default => preg_quote($token[0], '#'),
            },
            $glob,
        ) ?? throw new \RuntimeException("workspace glob {$glob} could not be translated: " . preg_last_error_msg());
    }

    /** A glob class as a regex that never matches `/`; an inverted range matches nothing, as in npm. */
    private static function globClass(bool $negated, string $content): string
    {
        preg_match_all('#(.)(?:-(.))?#su', $content, $items, PREG_SET_ORDER);
        $class = '';
        foreach ($items as $item) {
            $from = $item[1];
            $to = ($item[2] ?? '') === '' ? $from : $item[2];
            if ($from <= $to) {
                $class .= preg_quote($from, '#') . ($to === $from ? '' : '-' . preg_quote($to, '#'));
            }
        }
        if ($class === '') {
            return $negated ? '[^/]' : '(?!)';
        }

        return $negated ? '(?!/)[^' . $class . ']' : '[' . $class . ']';
    }

    private function hasLockfile(Context $context, string $manifest): bool
    {
        $dir = dirname($manifest);
        foreach (self::JS_LOCKFILES as $lock) {
            $candidate = $dir === '.' ? $lock : "{$dir}/{$lock}";
            if ($context->isTracked($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function composerDeclaresUntrackedLock(Context $context): bool
    {
        return $context->isTracked('composer.json')
            && $this->composerDeclaresDependencies($context, 'composer.json')
            && !$context->isTracked('composer.lock');
    }

    /** Whether `require` names a real package; platform packages (getcomposer.org/doc/01-basic-usage.md#platform-packages) install nothing. */
    private function composerDeclaresDependencies(Context $context, string $manifest): bool
    {
        $require = $context->json($manifest)['require'] ?? null;
        foreach (is_array($require) ? array_keys($require) : [] as $package) {
            if (preg_match('/^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|ext-.+|lib-.+|composer(?:-plugin-api|-runtime-api)?)$/i', (string) $package) !== 1) {
                return true;
            }
        }

        return false;
    }

}
