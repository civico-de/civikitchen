<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;
use PhpToken;

/**
 * A `setUpHeadless()` chain from `ck_headless(` or `\Civi\Test::headless(` that
 * never calls `->apply()`, directly or via a variable: CiviTestListener discards
 * the return value, so the builder installs nothing.
 */
final class HeadlessBuilderAppliedCheck implements Check
{
    public function name(): string
    {
        return 'headless-builder-applied';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        foreach ($context->tracked('*.php') as $file) {
            if (!str_contains($file, '/tests/') && !str_starts_with($file, 'tests/')) {
                continue;
            }
            $source = $context->read($file);
            if ($source === null || !str_contains($source, 'setUpHeadless')) {
                continue;
            }
            $tokens = array_values(array_filter(
                PhpToken::tokenize($source),
                static fn (PhpToken $t): bool => !$t->isIgnorable(),
            ));
            foreach ($this->unappliedChains($tokens) as $line) {
                $reporter->failAt(
                    $file,
                    $line,
                    "$file:$line: setUpHeadless() builds a CiviEnvBuilder that is never applied — "
                    . 'CiviTestListener discards the return value, so end the chain in ->apply()'
                );
            }
        }
    }

    /**
     * @param list<PhpToken> $tokens
     * @return list<int> lines of chain starts inside setUpHeadless() bodies
     */
    private function unappliedChains(array $tokens): array
    {
        $aliases = $this->civiTestAliases($tokens);
        $lines = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!$tokens[$i]->is(T_FUNCTION) || !($tokens[$i + 1] ?? null)?->is(T_STRING)
                || strcasecmp($tokens[$i + 1]->text, 'setUpHeadless') !== 0) {
                continue;
            }
            $open = $i + 2;
            while ($open < $count && $tokens[$open]->text !== '{' && $tokens[$open]->text !== ';') {
                $open++;
            }
            if ($open >= $count || $tokens[$open]->text === ';') {
                continue;
            }
            $close = $this->matching($tokens, $open);
            for ($j = $open + 1; $j < $close; $j++) {
                $paren = $this->chainStart($tokens, $j, $aliases);
                if ($paren === null) {
                    // An argument list belongs to the chain or call it follows.
                    if ($tokens[$j]->text === '(') {
                        $j = $this->matching($tokens, $j);
                    }
                    continue;
                }
                $after = $this->matching($tokens, $paren) + 1;
                if (!$this->applied($tokens, $after) && !$this->appliedLater($tokens, $j, $after, $close)) {
                    $lines[] = $tokens[$j]->line;
                }
                $j = $after - 1;
            }
            $i = $close;
        }

        return $lines;
    }

    /**
     * Lower-cased names a top-level `use Civi\Test [as X];` gives the class.
     *
     * @param list<PhpToken> $tokens
     * @return list<string>
     */
    private function civiTestAliases(array $tokens): array
    {
        $aliases = [];
        $depth = 0;
        foreach ($tokens as $k => $token) {
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            } elseif ($depth === 0 && $token->is(T_USE)
                && ($tokens[$k + 1] ?? null)?->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                && strcasecmp(ltrim($tokens[$k + 1]->text, '\\'), 'Civi\\Test') === 0) {
                $aliases[] = ($tokens[$k + 2] ?? null)?->is(T_AS) && ($tokens[$k + 3] ?? null)?->is(T_STRING)
                    ? strtolower($tokens[$k + 3]->text) : 'test';
            } elseif ($depth === 0 && $token->is(T_USE) && strcasecmp(ltrim($tokens[$k + 1]->text ?? '', '\\'), 'Civi') === 0
                && ($tokens[$k + 2] ?? null)?->is(T_NS_SEPARATOR) && ($tokens[$k + 3] ?? null)?->text === '{') {
                $aliases = [...$aliases, ...$this->groupAliases($tokens, $k + 4)];
            }
        }

        return $aliases;
    }

    /**
     * Aliases of `Test` inside `use Civi\{Test as T, Api4\Contact};`.
     *
     * @param list<PhpToken> $tokens
     * @return list<string>
     */
    private function groupAliases(array $tokens, int $k): array
    {
        $aliases = [];
        for ($n = count($tokens); $k < $n && $tokens[$k]->text !== '}'; $k++) {
            if ($tokens[$k]->is(T_STRING) && strcasecmp($tokens[$k]->text, 'Test') === 0
                && in_array($tokens[$k - 1]->text, ['{', ','], true)) {
                $aliases[] = ($tokens[$k + 1] ?? null)?->is(T_AS) && ($tokens[$k + 2] ?? null)?->is(T_STRING)
                    ? strtolower($tokens[$k + 2]->text) : 'test';
            }
        }

        return $aliases;
    }

    /**
     * Index of the `(` opening the chain's first call, or null when $j starts no chain.
     *
     * @param list<PhpToken> $tokens
     * @param list<string> $aliases
     */
    private function chainStart(array $tokens, int $j, array $aliases): ?int
    {
        $previous = $tokens[$j - 1]->text;
        if (in_array($previous, ['->', '?->', '::', 'function'], true)) {
            return null;
        }
        $token = $tokens[$j];
        if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && strcasecmp(ltrim($token->text, '\\'), 'ck_headless') === 0
            && ($tokens[$j + 1] ?? null)?->text === '(') {
            return $j + 1;
        }
        $coreBuilder = $token->is([T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED])
            ? strcasecmp(ltrim($token->text, '\\'), 'Civi\\Test') === 0
            : $token->is(T_STRING) && in_array(strtolower($token->text), $aliases, true);
        if ($coreBuilder && ($tokens[$j + 1] ?? null)?->text === '::'
            && strcasecmp($tokens[$j + 2]->text ?? '', 'headless') === 0
            && ($tokens[$j + 3] ?? null)?->text === '(') {
            return $j + 3;
        }

        return null;
    }

    /**
     * Whether any `->method(` call of the chain continuing at $k is `apply`.
     *
     * @param list<PhpToken> $tokens
     */
    private function applied(array $tokens, int $k): bool
    {
        while (in_array($tokens[$k]->text ?? '', ['->', '?->'], true)
            && ($tokens[$k + 2] ?? null)?->text === '(') {
            if (strcasecmp($tokens[$k + 1]->text, 'apply') === 0) {
                return true;
            }
            $k = $this->matching($tokens, $k + 2) + 1;
        }

        return false;
    }

    /**
     * For `$var = <chain>`: whether a later chain on `$var` before $close applies it.
     *
     * @param list<PhpToken> $tokens
     */
    private function appliedLater(array $tokens, int $start, int $after, int $close): bool
    {
        $variable = $tokens[$start - 2] ?? null;
        if ($tokens[$start - 1]->text !== '=' || $variable === null || !$variable->is(T_VARIABLE)
            || in_array($tokens[$start - 3]->text ?? '', ['->', '?->', '::'], true)) {
            return false;
        }
        for ($k = $after; $k < $close; $k++) {
            if ($tokens[$k]->is(T_VARIABLE) && $tokens[$k]->text === $variable->text && $this->applied($tokens, $k + 1)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<PhpToken> $tokens */
    private function matching(array $tokens, int $open): int
    {
        $depth = 0;
        $count = count($tokens);
        for ($k = $open; $k < $count; $k++) {
            $text = $tokens[$k]->text;
            if (in_array($text, ['(', '[', '{', '${'], true) || $tokens[$k]->is(T_CURLY_OPEN)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true) && --$depth === 0) {
                return $k;
            }
        }

        return $count - 1;
    }
}
