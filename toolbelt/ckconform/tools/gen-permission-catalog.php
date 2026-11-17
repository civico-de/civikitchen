<?php

declare(strict_types=1);

/**
 * Regenerate src/PermissionCatalog.php from a CiviCRM checkout.
 *
 * Usage:
 *   php tools/gen-permission-catalog.php <core-dir> [out-file]
 *
 * Token-scans the permission maps of core, its components and ext/; a
 * permission is a top-level key mapped to an array or assigned to
 * `$permissions['x']`. CMS prefixes come from translatePermission() calls.
 */

if ($argc < 2) {
    fwrite(STDERR, "usage: gen-permission-catalog.php <core-dir> [out-file]\n");
    exit(64);
}

$coreDir = rtrim($argv[1], '/');
if (!is_file($coreDir . '/CRM/Core/Permission.php')) {
    fwrite(STDERR, "not a CiviCRM core checkout: $coreDir/CRM/Core/Permission.php is missing\n");
    exit(66);
}

/** @return list<PhpToken> */
function codeTokens(string $file): array
{
    return array_values(array_filter(
        PhpToken::tokenize((string) file_get_contents($file)),
        static fn (PhpToken $token): bool => !$token->isIgnorable(),
    ));
}

function unquote(string $text): string
{
    $inner = substr($text, 1, -1);

    return $text[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner) : stripcslashes($inner);
}

/**
 * Permission names declared in the bodies of functions whose name matches $pattern.
 *
 * @return list<string>
 */
function declaredPermissions(string $file, string $pattern): array
{
    $tokens = codeTokens($file);
    $found = [];
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if (!$tokens[$i]->is(T_FUNCTION) || !($tokens[$i + 1] ?? null)?->is(T_STRING)
            || preg_match($pattern, $tokens[$i + 1]->text) !== 1
        ) {
            continue;
        }
        for ($j = $i + 2; $j < $n && !$tokens[$j]->is(['{', ';']); $j++);
        $braces = 0;
        // `$actions = ['add' => …]; foreach ($actions as $action => …) $permissions[$action . ' x'] = …`
        $arrayKeys = [];
        $loopKeys = [];
        // One entry per open bracket: true when it holds a permission's own
        // definition, whose keys (label, implies) are not permissions.
        $values = [];
        for (; $j < $n; $j++) {
            $text = $tokens[$j]->text;
            $braces += $text === '{' ? 1 : ($text === '}' ? -1 : 0);
            if ($braces === 0) {
                break;
            }
            if ($tokens[$j]->is(T_VARIABLE) && $tokens[$j + 1]->text === '=' && $tokens[$j + 2]->text === '[') {
                $arrayKeys[$text] = literalKeys($tokens, $j + 2);
            }
            if ($tokens[$j]->is(T_FOREACH) && $tokens[$j + 3]->is(T_AS) && $tokens[$j + 5]->is(T_DOUBLE_ARROW)) {
                $loopKeys[$tokens[$j + 4]->text] = $arrayKeys[$tokens[$j + 2]->text] ?? [];
            }
            if ($text === '$permissions' && $tokens[$j + 1]->text === '[' && $tokens[$j + 3]->text === '.'
                && $tokens[$j + 4]->is(T_CONSTANT_ENCAPSED_STRING) && $tokens[$j + 5]->text === ']'
            ) {
                foreach ($loopKeys[$tokens[$j + 2]->text] ?? [] as $key) {
                    $found[] = $key . unquote($tokens[$j + 4]->text);
                }
            }
            if (in_array($text, ['[', '('], true)) {
                $before = $tokens[$j - 1]->is(T_ARRAY) ? $j - 2 : $j - 1;
                $values[] = $tokens[$before]->is(T_DOUBLE_ARROW) || ($tokens[$before]->text === '=' && $tokens[$before - 1]->text === ']');
            } elseif (in_array($text, [']', ')'], true)) {
                array_pop($values);
            }
            if (!$tokens[$j]->is(T_CONSTANT_ENCAPSED_STRING) || in_array(true, $values, true)) {
                continue;
            }
            $mapsToArray = ($tokens[$j + 1] ?? null)?->is(T_DOUBLE_ARROW) && ($tokens[$j + 2] ?? null)?->is(['[', T_ARRAY]);
            $assigned = $tokens[$j - 1]->text === '[' && $tokens[$j - 2]->text === '$permissions'
                && ($tokens[$j + 1] ?? null)?->text === ']' && ($tokens[$j + 2] ?? null)?->text === '=';
            if ($mapsToArray || $assigned) {
                $found[] = unquote($tokens[$j]->text);
            }
        }
        $i = $j;
    }

    return $found;
}

/**
 * The string keys at the top level of the array literal opening at $open.
 *
 * @param  list<PhpToken> $tokens
 * @return list<string>
 */
function literalKeys(array $tokens, int $open): array
{
    $keys = [];
    for ($depth = 0, $k = $open, $n = count($tokens); $k < $n; $k++) {
        $depth += in_array($tokens[$k]->text, ['[', '('], true) ? 1 : (in_array($tokens[$k]->text, [']', ')'], true) ? -1 : 0);
        if ($depth === 0) {
            break;
        }
        if ($depth === 1 && $tokens[$k]->is(T_CONSTANT_ENCAPSED_STRING) && $tokens[$k + 1]->is(T_DOUBLE_ARROW)) {
            $keys[] = unquote($tokens[$k]->text);
        }
    }

    return $keys;
}

$permissions = [
    ...declaredPermissions($coreDir . '/CRM/Core/Permission.php', '/^getCorePermissions$/'),
    ...declaredPermissions($coreDir . '/CRM/Core/Permission/Base.php', '/^getAvailablePermissions$/'),
];
foreach (glob($coreDir . '/CRM/*/Info.php') ?: [] as $info) {
    array_push($permissions, ...declaredPermissions($info, '/^getPermissions$/'));
}
$extensions = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($coreDir . '/ext', FilesystemIterator::SKIP_DOTS),
    static fn (SplFileInfo $file): bool => !($file->isDir() && in_array($file->getFilename(), ['tests', 'vendor', 'node_modules'], true)),
));
foreach ($extensions as $file) {
    if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.php')
        && stripos((string) file_get_contents($file->getPathname()), '_civicrm_permission') !== false
    ) {
        array_push($permissions, ...declaredPermissions($file->getPathname(), '/_civicrm_permission(?:List)?$/i'));
    }
}
$permissions = array_values(array_unique($permissions));
sort($permissions);

$prefixes = [];
foreach (glob($coreDir . '/CRM/Core/Permission/*.php') ?: [] as $file) {
    $tokens = codeTokens($file);
    foreach ($tokens as $i => $token) {
        if ($token->text === 'translatePermission' && $tokens[$i + 1]->text === '(' && $tokens[$i - 1]->text === '->'
            && ($tokens[$i + 4] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING)
        ) {
            $prefixes[] = unquote($tokens[$i + 4]->text);
        }
    }
}
$prefixes = array_values(array_unique($prefixes));
sort($prefixes);

$version = 'unknown';
if (preg_match('#<version_no>([^<]+)</version_no>#', (string) @file_get_contents($coreDir . '/xml/version.xml'), $m) === 1) {
    $version = trim($m[1]);
}

$list = static fn (array $values): string => implode('', array_map(
    static fn (string $value): string => '        ' . var_export($value, true) . ",\n",
    $values,
));

$out = <<<PHP
<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * The CiviCRM permission catalog — GENERATED, do not edit.
 *
 * Regenerate with:
 *   php tools/gen-permission-catalog.php <core-dir>
 *
 * Generated from CiviCRM {$version}.
 */
final class PermissionCatalog
{
    /**
     * Permissions core and its bundled extensions declare.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
{$list($permissions)}    ];

    /**
     * Prefixes core hands to the CMS unjudged (`Drupal:administer users`).
     *
     * @var list<string>
     */
    public const CMS_PREFIXES = [
{$list($prefixes)}    ];
}

PHP;

$target = $argv[2] ?? dirname(__DIR__) . '/src/PermissionCatalog.php';
if (file_put_contents($target, $out) === false) {
    fwrite(STDERR, "could not write $target\n");
    exit(73);
}

fwrite(STDOUT, sprintf("%s: %d permissions, %d CMS prefixes (CiviCRM %s)\n", $target, count($permissions), count($prefixes), $version));
