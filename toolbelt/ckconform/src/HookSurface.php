<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * The scan surface shared by the hook checks: legitimate hook prefixes, the
 * files that may declare hooks, and the global functions a file defines.
 */
final class HookSurface
{
    /**
     * Every legitimate hook prefix: info.xml's `<file>`, the key's last segment
     * and the root main file's basename. The first entry is the canonical one.
     *
     * @return list<string>
     */
    public static function expectedPrefixes(Context $context): array
    {
        $info = $context->infoXml();
        if ($info === null) {
            return [];
        }

        $prefixes = [];
        $file = trim((string) ($info->file ?? ''));
        if ($file !== '') {
            $prefixes[] = $file;
        }
        $key = trim((string) ($info['key'] ?? ''));
        if ($key !== '') {
            $segments = explode('.', $key);
            $prefixes[] = (string) end($segments);
        }
        // The main file, recognised by its civix twin: root `foo.php` plus
        // `foo.civix.php` dispatches under `foo`, whatever info.xml claims.
        foreach ($context->isGitRepo() ? $context->trackedFiles() : $context->findFiles('') as $candidate) {
            if (preg_match('#^([A-Za-z0-9_]+)\.civix\.php$#', $candidate, $m) === 1
                && $context->exists($m[1] . '.php')
            ) {
                $prefixes[] = $m[1];
            }
        }

        return array_values(array_unique(array_filter(
            $prefixes,
            static fn (string $p): bool => preg_match('/^[A-Za-z0-9_]+$/', $p) === 1,
        )));
    }

    /**
     * Whether a function prefix is one of $expected. Core looks hook
     * functions up with function_exists(), which ignores case.
     *
     * @param list<string> $expected
     */
    public static function hasPrefix(string $prefix, array $expected): bool
    {
        return in_array(strtolower($prefix), array_map('strtolower', $expected), true);
    }

    /**
     * Shipped *.php source files, minus the generated civix glue.
     *
     * @return list<string>
     */
    public static function candidates(Context $context): array
    {
        return $context->sourceFiles(
            '',
            ['.php'],
            static fn (string $file): bool => !str_ends_with($file, '.civix.php'),
        );
    }

    /**
     * The hook suffixes an extension directory dispatches, read from
     * `dispatch('hook_civicrm_x', …)` and `invoke([...], …, 'civicrm_x')` sites.
     *
     * @return list<string>
     */
    public static function dispatchedSuffixes(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => !($file->isDir()
                && in_array($file->getFilename(), ['.git', 'vendor', 'node_modules', 'tests'], true)),
        ));
        $suffixes = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (!str_contains($contents, '_civicrm_')) {
                continue;
            }
            foreach (self::dispatchSites($contents) as $suffix) {
                $suffixes[$suffix] = true;
            }
        }
        $found = array_keys($suffixes);
        sort($found);

        return $found;
    }

    /**
     * Does the `{` at $open start the body of an if, elseif or else?
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function opensConditional(array $tokens, int $open): bool
    {
        $i = $open - 1;
        while ($i >= 0 && is_array($tokens[$i]) && in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
            $i--;
        }
        if ($i >= 0 && is_array($tokens[$i]) && $tokens[$i][0] === \T_ELSE) {
            return true;
        }
        if ($i < 0 || $tokens[$i] !== ')') {
            return false;
        }
        for ($depth = 0; $i >= 0; $i--) {
            $depth += $tokens[$i] === ')' ? 1 : ($tokens[$i] === '(' ? -1 : 0);
            if ($depth === 0) {
                break;
            }
        }
        do {
            $i--;
        } while ($i >= 0 && is_array($tokens[$i]) && in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT], true));

        return $i >= 0 && is_array($tokens[$i]) && in_array($tokens[$i][0], [\T_IF, \T_ELSEIF], true);
    }

    /**
     * Hook suffixes named at `dispatch(` (first argument) and `invoke(` (last
     * string argument) call sites of one file. Method names are
     * case-insensitive, and comments may sit anywhere in the call.
     *
     * @return list<string>
     */
    private static function dispatchSites(string $contents): array
    {
        $tokens = PhpSource::codeTokens($contents);
        $found = [];
        foreach ($tokens as $i => $token) {
            $method = strtolower($token->text);
            if (!$token->is(T_STRING) || !in_array($method, ['dispatch', 'invoke'], true)
                || ($tokens[$i + 1] ?? null)?->text !== '('
            ) {
                continue;
            }
            $literals = [];
            foreach (PhpSource::arguments($tokens, $i + 1) ?? [] as [, $argument]) {
                if (count($argument) === 1 && $argument[0]->is(T_CONSTANT_ENCAPSED_STRING)) {
                    $literals[] = substr($argument[0]->text, 1, -1);
                }
            }
            $name = $method === 'dispatch' ? ($literals[0] ?? null) : ($literals[count($literals) - 1] ?? null);
            if ($name !== null && preg_match('/^(?:hook_)?civicrm_([a-zA-Z]+)$/', $name, $m) === 1) {
                $found[] = $m[1];
            }
        }

        return $found;
    }

    /**
     * Top-level function names with their declaration line, by brace depth.
     * Interpolation tokens (`{$`, `${`) open a brace too and must be counted.
     *
     * @return array<string, int> function name => line
     */
    public static function globalFunctions(string $contents): array
    {
        $tokens = @token_get_all($contents);
        // Open blocks, true for an if/elseif/else body: a function declared
        // only inside such guards (`if (!function_exists(…))`) is still global.
        $blocks = [];
        $names = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '{') {
                    $blocks[] = self::opensConditional($tokens, $i);
                } elseif ($token === '}') {
                    array_pop($blocks);
                }
                continue;
            }

            if ($token[0] === \T_CURLY_OPEN || $token[0] === \T_DOLLAR_OPEN_CURLY_BRACES) {
                $blocks[] = false;
                continue;
            }

            if ($token[0] !== \T_FUNCTION || in_array(false, $blocks, true)) {
                continue;
            }

            // Skip whitespace/comments and a by-reference '&' to reach the name.
            for ($j = $i + 1; $j < $n; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && in_array($next[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($next === '&') {
                    continue;
                }
                if (is_array($next) && $next[0] === \T_STRING && !str_starts_with($next[1], '_')) {
                    $names[$next[1]] = $next[2];
                }
                break;
            }
        }

        return $names;
    }
}
