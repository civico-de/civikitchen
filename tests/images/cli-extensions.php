<?php

// Lists the global functions and classes that the given toolbelt classes, and the
// toolbelt classes they reference, use but this PHP lacks (a missing extension).
// Usage: php cli-extensions.php <toolbelt/lib/php/src> <Cli\AbCommand> ...; exit 1 on a miss.

const PREFIX = 'CiviKitchen\\Toolbelt\\';

[$self, $src] = array_pad(array_slice($argv, 0, 2), 2, '');
$queue = array_map(fn($class) => PREFIX . $class, array_slice($argv, 2));
if (!is_dir($src) || !$queue) {
    fwrite(STDERR, "usage: php $self <php source dir> <class below " . PREFIX . "> ...\n");
    exit(2);
}

$seen = $missing = [];
while ($queue) {
    $class = array_shift($queue);
    if (isset($seen[$class])) {
        continue;
    }
    $seen[$class] = true;
    $file = $src . '/' . str_replace('\\', '/', substr($class, strlen(PREFIX))) . '.php';
    if (!is_file($file)) {
        $missing[] = "toolbelt class $class (no $file)";
        continue;
    }
    $tokens = array_values(array_filter(PhpToken::tokenize(file_get_contents($file)), fn($t) => !$t->isIgnorable()));
    $namespace = '';
    $imports = $ownFunctions = [];
    $depth = 0;
    foreach ($tokens as $i => $token) {
        $depth += match ($token->text) { '{' => 1, '}' => -1, default => 0 };
        $next = $tokens[$i + 1] ?? null;
        if ($token->is(T_NAMESPACE) && $next?->is(T_NAME_QUALIFIED)) {
            $namespace = $next->text;
        } elseif ($token->is(T_USE) && $depth === 0 && $next?->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $name = ltrim($next->text, '\\');
            $alias = ($tokens[$i + 2] ?? null)?->is(T_AS) ? $tokens[$i + 3]->text : substr(strrchr('\\' . $name, '\\'), 1);
            $imports[strtolower($alias)] = $name;
        } elseif ($token->is(T_FUNCTION) && $next?->is(T_STRING)) {
            $ownFunctions[strtolower($next->text)] = true;
        }
    }
    foreach ($tokens as $i => $token) {
        $prev = $tokens[$i - 1] ?? null;
        $next = $tokens[$i + 1] ?? null;
        if (!$token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) || $prev?->is([T_USE, T_NAMESPACE, T_AS])) {
            continue;
        }
        $isClass = $prev?->is([T_NEW, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF]) || $next?->is(T_DOUBLE_COLON);
        $isCall = !$isClass && $next?->text === '('
            && !$prev?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST]);
        if (!$isClass && !$isCall) {
            continue;
        }
        $text = $token->text;
        $first = strtolower(strtok(ltrim($text, '\\'), '\\'));
        $name = match (true) {
            $token->is(T_NAME_FULLY_QUALIFIED) => ltrim($text, '\\'),
            isset($imports[$first]) && $isClass => $imports[$first] . substr(ltrim($text, '\\'), strlen($first)),
            $isClass && is_file($src . '/' . str_replace('\\', '/', substr("$namespace\\$text", strlen(PREFIX))) . '.php') => "$namespace\\$text",
            default => $text,
        };
        if (str_starts_with($name, PREFIX)) {
            $queue[] = $name;
        } elseif ($isClass && !in_array(strtolower($name), ['self', 'static', 'parent'], true)
            && !class_exists($name) && !interface_exists($name) && !enum_exists($name)) {
            $missing[] = "class $name at $file:$token->line";
        } elseif ($isCall && !str_contains($name, '\\') && !isset($ownFunctions[strtolower($name)]) && !function_exists($name)) {
            $missing[] = "function $name() at $file:$token->line";
        }
    }
}

$missing = array_unique($missing);
sort($missing);
foreach ($missing as $line) {
    echo "missing: $line\n";
}
exit($missing ? 1 : 0);
