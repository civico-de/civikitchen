<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

use PhpToken;

/** Token-level views of PHP source that a regex cannot provide. */
final class PhpSource
{
    /** Tokens that open a bracketed group inside an argument list. */
    private const OPENERS = ['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE];

    /**
     * PHP source with comment bodies blanked via the tokenizer, so a name in
     * prose is not read as code. Newlines are kept so line numbers still match.
     */
    public static function withoutComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * The tokens that are code: whitespace, comments and the open tag are
     * dropped, so adjacent tokens are adjacent in the syntax.
     *
     * @return list<PhpToken>
     */
    public static function codeTokens(string $source): array
    {
        return array_values(array_filter(
            @PhpToken::tokenize($source),
            static fn (PhpToken $t): bool => !$t->isIgnorable(),
        ));
    }

    /**
     * The class, function or constant name a token spells, without a leading
     * backslash; null for any other token. PHP compares these case-insensitively.
     */
    public static function name(PhpToken $token): ?string
    {
        return $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) ? ltrim($token->text, '\\') : null;
    }

    /** The last segment of a name token (`AutoSubscriber` for `\Civi\Core\Service\AutoSubscriber`). */
    public static function shortName(PhpToken $token): ?string
    {
        $name = self::name($token);

        return $name === null ? null : substr($name, (int) strrpos('\\' . $name, '\\'));
    }

    /**
     * The arguments of the call whose `(` is at $open in code tokens, each with
     * its name when passed as `name: value`; null when the list never closes.
     *
     * @param  list<PhpToken> $tokens
     * @return list<array{?string, list<PhpToken>}>|null
     */
    public static function arguments(array $tokens, int $open): ?array
    {
        $arguments = [];
        $current = [];
        $depth = 0;
        for ($i = $open + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if ($depth === 0 && $token->is([',', ')'])) {
                if ($current !== []) {
                    $named = count($current) > 1 && $current[1]->text === ':'
                        && preg_match('/^[A-Za-z_\x80-\xff][\w\x80-\xff]*$/', $current[0]->text) === 1;
                    $arguments[] = $named ? [$current[0]->text, array_slice($current, 2)] : [null, $current];
                }
                if ($token->text === ')') {
                    return $arguments;
                }
                $current = [];
                continue;
            }
            $depth += $token->is(self::OPENERS) ? 1 : ($token->is([')', ']', '}']) ? -1 : 0);
            $current[] = $token;
        }

        return null;
    }

    /**
     * The tokens of the parameter $name, passed by name or at $position:
     * positional arguments come first, so their index is their position.
     *
     * @param  list<array{?string, list<PhpToken>}> $arguments
     * @return list<PhpToken>|null
     */
    public static function argument(array $arguments, int $position, string $name): ?array
    {
        foreach ($arguments as [$argumentName, $tokens]) {
            if ($argumentName === $name) {
                return $tokens;
            }
        }
        $positional = $arguments[$position] ?? null;

        return $positional !== null && $positional[0] === null ? $positional[1] : null;
    }
}
