<?php

declare(strict_types=1);

/**
 * Regenerate src/HookCatalog.php from a CiviCRM checkout.
 *
 * Usage:
 *   php tools/gen-hook-catalog.php <core-dir> [out-file]
 *
 * Token-scans CRM/Utils/Hook.php plus the literal hook_civicrm_* dispatch sites
 * in CRM/, Civi/, ext/ and api/. Deprecated means a `@deprecated` tag or a
 * deprecation warning in the method body. Removed and docs-only deprecated
 * hooks are hand-kept in HookDispatchNameCheck.
 */

if ($argc < 2) {
    fwrite(STDERR, "usage: gen-hook-catalog.php <core-dir> [out-file]\n");
    exit(64);
}

$coreDir = rtrim($argv[1], '/');

$hookFile = $coreDir . '/CRM/Utils/Hook.php';
if (!is_file($hookFile)) {
    fwrite(STDERR, "not a CiviCRM core checkout: $hookFile is missing\n");
    exit(66);
}

/**
 * Public static methods of CRM_Utils_Hook, with docblock, body and line.
 *
 * @return array<string, array{doc: string, body: string, line: int}>
 */
function scanHookClass(string $file): array
{
    $tokens = token_get_all(file_get_contents($file));
    $methods = [];
    $doc = '';

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }
        if ($token[0] === T_DOC_COMMENT) {
            $doc = $token[1];
            continue;
        }
        if ($token[0] !== T_FUNCTION) {
            continue;
        }

        for ($j = $i + 1; $j < $n; $j++) {
            $next = $tokens[$j];
            if (is_array($next) && $next[0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($next) && $next[0] === T_STRING) {
                $methods[$next[1]] = [
                    'doc' => $doc,
                    'body' => methodBody($tokens, $j),
                    'line' => $next[2],
                ];
            }
            break;
        }
        $doc = '';
    }

    return $methods;
}

/** Source text from a method name token to the end of its body. */
function methodBody(array $tokens, int $start): string
{
    $depth = 0;
    $seen = false;
    $out = '';

    for ($i = $start, $n = count($tokens); $i < $n; $i++) {
        $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        $out .= $text;
        if ($text === '{') {
            $depth++;
            $seen = true;
        } elseif ($text === '}') {
            $depth--;
            if ($seen && $depth === 0) {
                break;
            }
        }
    }

    return $out;
}

/** The `@deprecated` line of a docblock, cleaned up for a one-line message. */
function deprecatedNote(string $doc): ?string
{
    if (!preg_match('/@deprecated\s*(.*)$/m', $doc, $m)) {
        return null;
    }
    // Docblock lines keep their leading '*'; an empty tag leaves just that.
    $note = trim(preg_replace('/\s+/', ' ', trim($m[1], " \t*")));

    return $note === '' ? 'deprecated' : 'deprecated ' . $note;
}

// ---------------------------------------------------------------------------
// Core: the live dispatch surface, plus whatever it marks as deprecated.

$methods = scanHookClass($hookFile);

// Not hooks: the singleton accessor and the invoke/dispatch plumbing around it.
$notHooks = ['singleton', 'invoke', 'invokeViaUF', 'commonInvoke', 'commonBuildModuleList', 'runHooks'];

$live = [];
$deprecated = [];

foreach ($methods as $name => $info) {
    if (in_array($name, $notHooks, true) || str_starts_with($name, '__')) {
        continue;
    }
    $live[$name] = true;

    $note = deprecatedNote($info['doc']);
    $source = 'CRM/Utils/Hook.php:' . $info['line'];

    // Some hooks only log a deprecation warning from the method body.
    if ($note === null && preg_match('/deprecated(?:Function)?Warning\s*\(/', $info['body'])) {
        $note = 'deprecated — core logs a deprecation warning when it fires';
    }
    if ($note !== null) {
        $deprecated[$name] = ['note' => $note, 'source' => $source];
    }
}

require_once dirname(__DIR__) . '/src/Autoloader.php';
CiviKitchen\Ckconform\Autoloader::register();
foreach (['CRM', 'Civi', 'ext', 'api'] as $directory) {
    foreach (CiviKitchen\Ckconform\HookSurface::dispatchedSuffixes($coreDir . '/' . $directory) as $suffix) {
        $live[$suffix] = true;
    }
}

ksort($live);
ksort($deprecated);

// ---------------------------------------------------------------------------

$version = 'unknown';
$versionXml = $coreDir . '/xml/version.xml';
if (is_file($versionXml) && preg_match('#<version_no>([^<]+)</version_no>#', file_get_contents($versionXml), $m)) {
    $version = trim($m[1]);
}

$render = static function (array $entries, int $indent): string {
    if ($entries === []) {
        return '';
    }
    $pad = str_repeat(' ', $indent);
    $out = '';
    foreach ($entries as $name => $entry) {
        $out .= sprintf(
            "%s// %s\n%s%s => %s,\n",
            $pad,
            $entry['source'],
            $pad,
            var_export((string) $name, true),
            var_export($entry['note'], true),
        );
    }

    return $out;
};

$liveList = '';
foreach (array_chunk(array_keys($live), 5) as $chunk) {
    $liveList .= '        ' . implode(', ', array_map(static fn ($h) => var_export($h, true), $chunk)) . ",\n";
}

$out = <<<PHP
<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * The CiviCRM hook catalog — GENERATED, do not edit.
 *
 * Regenerate with:
 *   php tools/gen-hook-catalog.php <core-dir>
 *
 * Generated from CiviCRM {$version}. Deprecated entries carry the core line they
 * were derived from, so a surprising one can be audited without rerunning the
 * generator. Hooks that no longer exist, and hooks deprecated only in the dev
 * docs, are NOT here — see HookDispatchNameCheck for those and for why.
 */
final class HookCatalog
{
    /**
     * The core release this was generated from.
     *
     * CI reads this to fetch the matching core release, so the drift gate
     * always compares against the exact release rather than a moving branch.
     * Bumping core is therefore deliberate: regenerate, and this moves with it.
     */
    public const CORE_VERSION = '{$version}';

    /**
     * Hook suffixes CiviCRM core currently dispatches.
     *
     * @var list<string>
     */
    public const LIVE = [
{$liveList}    ];

    /**
     * Hooks core still fires but has marked for removal: suffix => reason.
     *
     * @var array<string, string>
     */
    public const DEPRECATED = [
{$render($deprecated, 8)}    ];
}

PHP;

// The drift test regenerates into a temp file rather than over the committed one.
$target = $argv[2] ?? dirname(__DIR__) . '/src/HookCatalog.php';
if (file_put_contents($target, $out) === false) {
    fwrite(STDERR, "could not write $target\n");
    exit(73);
}

fwrite(STDOUT, sprintf(
    "%s: %d live, %d deprecated (CiviCRM %s)\n",
    $target,
    count($live),
    count($deprecated),
    $version,
));
