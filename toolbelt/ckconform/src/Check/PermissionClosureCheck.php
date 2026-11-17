<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\PermissionCatalog;
use CiviKitchen\Ckconform\Reporter;

/**
 * A permission string used in the repo but never defined by it or core
 * (::check() on an unknown string is false forever). A near-miss on an own
 * permission fails; an unknown string may come from a dependency and warns.
 */
final class PermissionClosureCheck implements Check
{
    /**
     * Not permissions at all but the sentinels CiviCRM accepts in the same slot.
     *
     * @var list<string>
     */
    private const PSEUDO_PERMISSIONS = ['*always allow*', '*always deny*', '*allow*', '\*always allow\*', '1', '0'];

    /** Tokens after which `[` is a subscript rather than a list. */
    private const SUBSCRIPTABLE = [
        T_VARIABLE, ']', ')', '}', T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE,
        T_CONSTANT_ENCAPSED_STRING, T_END_HEREDOC, '"',
    ];

    /** Tokens that open a bracketed group. */
    private const OPENERS = ['[', '(', '{', T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES];

    /** Tokens that end a list element or a call argument. */
    private const ELEMENT_END = [',', ')', ']'];

    public function name(): string
    {
        return 'permission-closure';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $files = $context->isGitRepo() ? $context->trackedUnder('') : $context->findFiles('');
        if ($files === []) {
            return;
        }

        [$defined, $used] = $this->scan($context, $files);

        foreach ($used as $permission => $where) {
            // A numeric string became an integer array key.
            $permission = (string) $permission;
            if ($this->isKnown($permission, $defined)) {
                continue;
            }

            $near = $this->nearestDefined($permission, $defined);
            $origin = implode(', ', array_slice($where, 0, 3));
            if ($near !== null) {
                $reporter->fail(
                    "$origin: permission '$permission' is never defined, but this extension defines "
                    . "'$near' — a typo makes the check a silent always-no",
                );
                continue;
            }

            $reporter->warn(
                "$origin: permission '$permission' is neither a known core permission nor defined by "
                . 'this extension — fine if a dependency defines it, a silent always-no otherwise',
            );
        }
    }

    /**
     * @param list<string> $defined
     */
    private function isKnown(string $permission, array $defined): bool
    {
        return in_array($permission, self::PSEUDO_PERMISSIONS, true)
            || in_array($permission, PermissionCatalog::PERMISSIONS, true)
            || in_array($permission, $defined, true)
            // `Drupal:administer users` goes to the CMS unjudged; `cms:` names are in the catalog.
            || preg_match('/^(?:' . implode('|', PermissionCatalog::CMS_PREFIXES) . '):/', $permission) === 1;
    }

    /**
     * The defined permission the used string was almost certainly meant to be:
     * case-only drift at any length, Levenshtein up to 3 only on long strings.
     *
     * @param  list<string> $defined
     */
    private function nearestDefined(string $permission, array $defined): ?string
    {
        foreach ($defined as $candidate) {
            if (strcasecmp($permission, $candidate) === 0) {
                return $candidate;
            }
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($defined as $candidate) {
            if (strlen($candidate) < 8) {
                continue;
            }
            $distance = levenshtein(strtolower($permission), strtolower($candidate));
            if ($distance > 0 && $distance <= 3 && $distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * The permissions the repo defines, and the files each used permission
     * appears in.
     *
     * @param  list<string> $files
     * @return array{list<string>, array<string, list<string>>}
     */
    private function scan(Context $context, array $files): array
    {
        $defined = [];
        $used = [];
        foreach ($files as $file) {
            $contents = $context->read($file);
            if ($contents === null) {
                continue;
            }

            $permissions = [];
            if (preg_match('#(^|/)xml/Menu/[^/]+\.xml$#', $file) === 1) {
                $permissions = $this->fromMenuXml($contents);
            } elseif (str_ends_with($file, '.php')) {
                $tokens = $this->tokens($contents);
                array_push($defined, ...$this->definedIn($tokens));
                $permissions = $this->fromPhp($tokens, str_ends_with($file, '.ang.php'));
            } elseif (str_ends_with($file, '.aff.json')) {
                $permissions = $this->fromAffJson($contents);
            }

            foreach ($permissions as $permission) {
                // Every leaf of a comma/semicolon expression must exist,
                // whatever its AND/OR shape.
                foreach (preg_split('/[;,]/', $permission) ?: [] as $part) {
                    $part = trim($part);
                    if ($part === '') {
                        continue;
                    }
                    $used[$part] ??= [];
                    if (!in_array($file, $used[$part], true)) {
                        $used[$part][] = $file;
                    }
                }
            }
        }

        ksort($used);
        $defined = array_values(array_unique(array_filter(
            $defined,
            static fn (string $p): bool => $p !== '' && !str_contains($p, '$'),
        )));

        return [$defined, $used];
    }

    /**
     * Permissions a PHP file declares: hook_civicrm_permission assignments and
     * the array literal a hook may return, plus APIv4 permission providers
     * where the string is greppable.
     *
     * @param  list<\PhpToken> $tokens
     * @return list<string>
     */
    private function definedIn(array $tokens): array
    {
        $defined = [];

        // $permissions['administer CiviFoo'] = ... — the canonical hook body.
        foreach (array_keys($tokens) as $i) {
            if (self::tokenIs($tokens, $i, '$permissions') && self::tokenIs($tokens, $i + 1, '[')
                && self::tokenIs($tokens, $i + 2, T_CONSTANT_ENCAPSED_STRING) && self::tokenIs($tokens, $i + 3, ']')
            ) {
                $defined[] = $this->literal($tokens[$i + 2]->text);
            }
        }

        // Only inside the function body: a wider scan would donate unrelated
        // strings to the definition set and turn a warning into a false FAIL.
        foreach (['/^\w*_civicrm_permission$/i', '/^getPermissions$/i'] as $name) {
            array_push($defined, ...$this->arrayKeyLiterals($this->functionBodies($tokens, $name)));
        }

        return $defined;
    }

    /**
     * The `{ … }` tokens of every function body named $name, concatenated; a
     * missing closing brace yields the rest of the file.
     *
     * @param  list<\PhpToken> $tokens
     * @return list<\PhpToken>
     */
    private function functionBodies(array $tokens, string $name): array
    {
        $bodies = [];
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            // `function &name()` returns by reference.
            $at = self::tokenIs($tokens, $i + 1, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) ? $i + 2 : $i + 1;
            if (!$tokens[$i]->is(T_FUNCTION) || !self::tokenIs($tokens, $at, T_STRING)
                || preg_match($name, $tokens[$at]->text) !== 1
            ) {
                continue;
            }
            $start = $at + 1;
            while (isset($tokens[$start]) && !$tokens[$start]->is(['{', ';'])) {
                $start++;
            }
            if (!self::tokenIs($tokens, $start, '{')) {
                continue;
            }
            $i = $this->closingIndex($tokens, $start) ?? $count - 1;
            array_push($bodies, ...array_slice($tokens, $start, $i - $start + 1));
        }

        return $bodies;
    }

    /**
     * `'administer CiviFoo' => [...]` keys — only single-line strings with a
     * space, so ordinary config arrays in the same body do not inflate the
     * definition set.
     *
     * @param  list<\PhpToken> $tokens
     * @return list<string>
     */
    private function arrayKeyLiterals(array $tokens): array
    {
        $found = [];
        foreach ($tokens as $i => $token) {
            if (!$token->is(T_CONSTANT_ENCAPSED_STRING) || !self::tokenIs($tokens, $i + 1, T_DOUBLE_ARROW)) {
                continue;
            }
            $literal = $this->literal($token->text);
            if (strlen($literal) >= 4 && str_contains($literal, ' ') && !str_contains($literal, "\n")) {
                $found[] = $literal;
            }
        }

        return $found;
    }

    /**
     * Every element of <access_arguments> (';' is AND, ',' is OR) must exist.
     *
     * @return list<string>
     */
    private function fromMenuXml(string $contents): array
    {
        $found = [];
        if (preg_match_all('#<access_arguments>(.*?)</access_arguments>#s', $contents, $matches) > 0) {
            foreach ($matches[1] as $raw) {
                foreach (preg_split('/[;,]/', $raw) ?: [] as $part) {
                    $part = trim(html_entity_decode($part));
                    if ($part !== '') {
                        $found[] = $part;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * ::check() literals and 'permission' => … specs (mgd records, APIv4 actions,
     * Afform metadata in PHP). Variables and constants are not resolved.
     *
     * @param  list<\PhpToken> $tokens
     * @return list<string>
     */
    private function fromPhp(array $tokens, bool $angularModule): array
    {
        $found = [];
        foreach (array_keys($tokens) as $i) {
            // PHP resolves class and method names case-insensitively.
            if (self::tokenIs($tokens, $i, [T_STRING, T_NAME_FULLY_QUALIFIED])
                && strcasecmp(ltrim($tokens[$i]->text, '\\'), 'CRM_Core_Permission') === 0
                && self::tokenIs($tokens, $i + 1, T_DOUBLE_COLON) && self::tokenIs($tokens, $i + 2, T_STRING)
                && strcasecmp($tokens[$i + 2]->text, 'check') === 0 && self::tokenIs($tokens, $i + 3, '(')
            ) {
                // check(permissions: …) names its argument.
                $named = self::tokenIs($tokens, $i + 4, T_STRING) && self::tokenIs($tokens, $i + 5, ':');
                array_push($found, ...$this->valueLiterals($tokens, $named ? $i + 6 : $i + 4));
            }
        }

        array_push($found, ...$this->permissionSpecs($tokens, $angularModule));

        return array_values(array_filter(
            array_map('trim', $found),
            static fn (string $p): bool => $p !== '' && !str_contains($p, '$'),
        ));
    }

    /**
     * @return list<\PhpToken> the code tokens, without whitespace and comments
     */
    private function tokens(string $contents): array
    {
        return array_values(array_filter(
            \PhpToken::tokenize($contents),
            static fn (\PhpToken $t): bool => !$t->isIgnorable(),
        ));
    }

    /**
     * @param list<\PhpToken>                $tokens
     * @param int|string|list<int|string>    $kind
     */
    private static function tokenIs(array $tokens, int $i, int|string|array $kind): bool
    {
        return isset($tokens[$i]) && $tokens[$i]->is($kind);
    }

    /**
     * `'permission' => 'x'`, `'permissions' => ['x', ['y', 'z']]` and an APIv4
     * permissions() map, nested to any depth; an array with a key is not a spec.
     *
     * @param  list<\PhpToken> $tokens
     * @return list<string>
     */
    private function permissionSpecs(array $tokens, bool $angularModule): array
    {
        $count = count($tokens);
        $found = [];
        $depth = 0;
        for ($i = 0; $i + 2 < $count; $i++) {
            $depth += $tokens[$i]->is(self::OPENERS) ? 1 : ($tokens[$i]->is([']', ')', '}']) ? -1 : 0);
            $plural = $tokens[$i]->is(["'permissions'", '"permissions"'])
                && (!$angularModule || $depth === 1);
            if (($plural || $tokens[$i]->is(["'permission'", '"permission"'])) && $tokens[$i + 1]->is(T_DOUBLE_ARROW)) {
                array_push($found, ...$this->valueLiterals($tokens, $i + 2));
            }
        }

        // An APIv4 entity's permissions(): action name => permission list,
        // returned as a literal or assigned as $permissions['action'] = [...].
        $body = $this->inNamespace($tokens, 'Civi\\Api4\\') ? $this->functionBodies($tokens, '/^permissions$/i') : [];
        foreach (array_keys($body) as $i) {
            if (self::tokenIs($body, $i, T_CONSTANT_ENCAPSED_STRING) && self::tokenIs($body, $i + 1, T_DOUBLE_ARROW)) {
                array_push($found, ...$this->valueLiterals($body, $i + 2));
            } elseif (self::tokenIs($body, $i, '[') && self::tokenIs($body, $i + 1, T_CONSTANT_ENCAPSED_STRING)
                && self::tokenIs($body, $i + 2, ']') && self::tokenIs($body, $i + 3, '=')
            ) {
                array_push($found, ...$this->valueLiterals($body, $i + 4));
            }
        }

        return $found;
    }

    /**
     * Whether the file declares a namespace starting with $prefix.
     *
     * @param list<\PhpToken> $tokens
     */
    private function inNamespace(array $tokens, string $prefix): bool
    {
        foreach ($tokens as $i => $token) {
            if ($token->is(T_NAMESPACE) && self::tokenIs($tokens, $i + 1, [T_NAME_QUALIFIED, T_STRING])
                && str_starts_with(ltrim($tokens[$i + 1]->text, '\\') . '\\', $prefix)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The strings of the expression at $i when it is a string literal or a list
     * of them; a string that is only an operand of a larger expression is none.
     *
     * @param  list<\PhpToken> $tokens
     * @return list<string>
     */
    private function valueLiterals(array $tokens, int $i): array
    {
        if (self::tokenIs($tokens, $i, T_CONSTANT_ENCAPSED_STRING)) {
            return self::tokenIs($tokens, $i + 1, self::ELEMENT_END) ? [$this->literal($tokens[$i]->text)] : [];
        }

        return self::tokenIs($tokens, $i, ['[', T_ARRAY]) ? $this->listLiterals($tokens, $i) : [];
    }

    /**
     * The string leaves of the list literal opening at $start, skipping nested
     * calls, closures and partial elements; a key or an unclosed list yields none.
     *
     * @param  list<\PhpToken> $tokens
     * @return list<string>
     */
    private function listLiterals(array $tokens, int $start): array
    {
        $strings = [];
        $depth = 0;
        $previous = null;
        for ($j = $start, $count = count($tokens); $j < $count; $j++) {
            $token = $tokens[$j];
            $opensList = ($token->is('[') && !($previous?->is(self::SUBSCRIPTABLE) ?? false))
                || ($token->is('(') && ($previous?->is(T_ARRAY) ?? false));
            if ($token->is(T_DOUBLE_ARROW) || $token->is('}')) {
                return [];
            }
            if ($opensList) {
                $depth++;
            } elseif ($token->is([']', ')'])) {
                if (--$depth === 0) {
                    return $strings;
                }
            } elseif ($token->is(self::OPENERS)) {
                $close = $this->closingIndex($tokens, $j);
                if ($close === null) {
                    return [];
                }
                $j = $close;
                $token = $tokens[$j];
            } elseif ($token->is(T_FN)) {
                // An arrow function's body runs to the end of its element.
                while ($j + 1 < $count && !$tokens[$j + 1]->is(self::ELEMENT_END)) {
                    $j = $tokens[$j + 1]->is(self::OPENERS) ? ($this->closingIndex($tokens, $j + 1) ?? $count) : $j + 1;
                }
                if ($j >= $count) {
                    return [];
                }
                $token = $tokens[$j];
            } elseif ($token->is(T_CONSTANT_ENCAPSED_STRING) && ($previous?->is(['[', '(', ',']) ?? false)
                && self::tokenIs($tokens, $j + 1, self::ELEMENT_END)
            ) {
                $strings[] = $this->literal($token->text);
            }
            $previous = $token;
        }

        return [];
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function closingIndex(array $tokens, int $open): ?int
    {
        $depth = 0;
        for ($j = $open, $count = count($tokens); $j < $count; $j++) {
            if ($tokens[$j]->is(self::OPENERS)) {
                $depth++;
            } elseif ($tokens[$j]->is([']', ')', '}']) && --$depth === 0) {
                return $j;
            }
        }

        return null;
    }

    /**
     * The value of a T_CONSTANT_ENCAPSED_STRING token, escapes resolved by
     * PHP's single- or double-quote rules.
     */
    private function literal(string $text): string
    {
        $text = ltrim($text, 'bB');
        $body = substr($text, 1, -1);
        if ($text[0] === "'") {
            return preg_replace('/\\\\([\\\\\'])/', '$1', $body) ?? $body;
        }

        return preg_replace_callback(
            '/\\\\(?:([nrtvef\\\\$"])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/',
            static fn (array $m): string => match (true) {
                ($m[4] ?? '') !== '' => self::utf8((int) hexdec($m[4])),
                ($m[3] ?? '') !== '' => chr((int) hexdec($m[3])),
                ($m[2] ?? '') !== '' => chr((int) octdec($m[2]) & 255),
                default => ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f"][$m[1]] ?? $m[1],
            },
            $body,
        ) ?? $body;
    }

    /**
     * The UTF-8 bytes of a code point, as PHP encodes a `\u{…}` escape.
     */
    private static function utf8(int $codePoint): string
    {
        return match (true) {
            $codePoint < 0x80 => chr($codePoint),
            $codePoint < 0x800 => chr(0xC0 | ($codePoint >> 6)) . chr(0x80 | ($codePoint & 0x3F)),
            $codePoint < 0x10000 => chr(0xE0 | ($codePoint >> 12)) . chr(0x80 | (($codePoint >> 6) & 0x3F))
                . chr(0x80 | ($codePoint & 0x3F)),
            default => chr(0xF0 | ($codePoint >> 18)) . chr(0x80 | (($codePoint >> 12) & 0x3F))
                . chr(0x80 | (($codePoint >> 6) & 0x3F)) . chr(0x80 | ($codePoint & 0x3F)),
        };
    }

    /**
     * @return list<string>
     */
    private function fromAffJson(string $contents): array
    {
        $decoded = json_decode($contents, true);
        if (!is_array($decoded) || !isset($decoded['permission'])) {
            return [];
        }
        $value = $decoded['permission'];
        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter(
            array_map(static fn ($v): string => is_string($v) ? trim($v) : '', $values),
            static fn (string $p): bool => $p !== '',
        ));
    }
}
