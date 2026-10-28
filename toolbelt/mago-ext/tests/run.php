<?php

declare(strict_types=1);

// Holds every rule to the exact findings its case file expects, through the
// real mago binary. Usage: php tests/run.php [--mago=<binary>] [case-name-substring]
//
// A case: name, command (lint|analyze), code (one, or a list for analyze), paths, includes and patches
// relative to its workspace (toolbelt/ unless 'workspace' names a directory under it),
// args (worker options), env (worker environment), analyzer (mago [analyzer]
// settings), baseline (true: extend the shipped toolbelt/mago/mago.toml, as a
// repo's own config does), expect ("path:line message") and help (substrings every
// expected finding's help must contain), or error (a substring of the output
// of a run that must fail without a report). {toolbelt} in env, help, includes
// and patches values is the toolbelt directory.

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$mago = 'mago';
$filter = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--mago=')) {
        $mago = substr($argument, 7);
    } else {
        $filter = $argument;
    }
}

$sdk = Composer\InstalledVersions::getPrettyVersion('carthage-software/mago');
$binary = trim((string) shell_exec(escapeshellarg($mago) . ' --version 2>&1'));
if (!str_ends_with($binary, ' ' . $sdk)) {
    fwrite(STDERR, "mago binary reports '{$binary}', the extension SDK is {$sdk}\n");
    exit(2);
}

// stdout is mago's frame channel: a worker that fails to start must stay silent there.
$stdout = shell_exec('php ' . escapeshellarg(dirname(__DIR__) . '/worker.php') . ' --disable=civikitchen/no-such-rule 2>/dev/null </dev/null');
if ($stdout !== null && $stdout !== false && $stdout !== '') {
    fwrite(STDERR, "worker.php printed its startup error on stdout:\n{$stdout}\n");
    exit(1);
}

$toolbelt = dirname(__DIR__, 2);
$work = __DIR__ . '/.work';
if (!is_dir($work) && !mkdir($work) && !is_dir($work)) {
    throw new RuntimeException("cannot create {$work}");
}

/** @return string TOML for one case */
function config(array $case, string $workspace): string
{
    $string = static fn (string $item): string => json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $list = static fn (array $items): string => '[' . implode(', ', array_map($string, $items)) . ']';
    $command = ['php', dirname(__DIR__) . '/worker.php', ...($case['args'] ?? [])];
    $env = ['CK_MAGO_WORKSPACE' => $workspace, ...($case['env'] ?? [])];
    $table = implode(', ', array_map(static fn (string $key, string $value): string => $string($key) . ' = ' . $string($value), array_keys($env), $env));

    $extends = ($case['baseline'] ?? false) ? 'extends = ' . $list([dirname(__DIR__, 2) . '/mago/mago.toml']) . "\n" : '';

    return $extends . "php-version = \"" . ($case['php'] ?? '8.1') . "\"\n\n[source]\npaths = {$list($case['paths'])}\nincludes = {$list($case['includes'] ?? [])}\npatches = {$list($case['patches'] ?? [])}\n\n"
        . "[linter]\nminimum-fail-level = \"note\"\n\n"
        . (isset($case['analyzer']) ? "[analyzer]\n" . implode('', array_map(static fn (string $key, mixed $value): string => "{$key} = " . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n", array_keys($case['analyzer']), $case['analyzer'])) . "\n" : '')
        . "[extension-hosts.civikitchen]\ncommand = {$list($command)}\nenvironment = { {$table} }\n";
}

/** The stderr of a worker that refuses its arguments before mago's first frame; empty if it starts. */
function startupError(string $workspace, array $case): string
{
    $env = ['CK_MAGO_WORKSPACE' => $workspace, ...($case['env'] ?? [])];
    $assignments = implode(' ', array_map(static fn (string $key, string $value): string => $key . '=' . escapeshellarg($value), array_keys($env), $env));
    $arguments = implode(' ', array_map('escapeshellarg', $case['args'] ?? []));
    $cmd = sprintf('cd %s && %s php %s %s 2>&1 >/dev/null </dev/null', escapeshellarg($workspace), $assignments, escapeshellarg(dirname(__DIR__) . '/worker.php'), $arguments);

    return (string) shell_exec($cmd);
}

/** @return array{?list<string>, string} "path:line message" for the case's code, sorted (null when mago gave no report or a help substring is missing), and mago's stderr */
function findings(string $mago, string $workspace, string $configPath, array $case): array
{
    $command = $case['command'] === 'lint' ? 'lint --only ' . escapeshellarg($case['code']) : 'analyze';
    $cmd = sprintf('cd %s && %s --workspace . --config %s %s --reporting-format json 2>&1 >%s',
        escapeshellarg($workspace), escapeshellarg($mago), escapeshellarg($configPath), $command, escapeshellarg($configPath . '.json'));
    $stderr = (string) shell_exec($cmd);
    $json = json_decode((string) file_get_contents($configPath . '.json'), true);
    if (!is_array($json) || !isset($json['issues'])) {
        if (!isset($case['error'])) {
            fwrite(STDERR, "no report from: {$cmd}\n{$stderr}\n");
        }
        return [null, $stderr];
    }
    $out = [];
    foreach ($json['issues'] as $issue) {
        if (!in_array($issue['code'], (array) $case['code'], true)) {
            continue;
        }
        $span = $issue['annotations'][0]['span'];
        $file = (string) $span['file_id']['name'];
        $finding = $file . ':' . ($span['start']['line'] + 1) . ' ' . $issue['message'];
        foreach ($case['help'] ?? [] as $help) {
            if (!str_contains((string) ($issue['help'] ?? ''), $help)) {
                fwrite(STDERR, "{$finding}: help lacks {$help}\n");
                return [null, $stderr];
            }
        }
        $out[] = $finding;
    }
    sort($out);

    return [$out, $stderr];
}

$failed = 0;
$ran = 0;
foreach (glob(__DIR__ . '/cases/*.php') ?: [] as $caseFile) {
    foreach (require $caseFile as $case) {
        $case = array_replace($case, array_map(
            static fn (array $values): array => str_replace('{toolbelt}', $toolbelt, $values),
            array_intersect_key($case, ['env' => true, 'help' => true, 'includes' => true, 'patches' => true]),
        ));
        if ($filter !== null && !str_contains($case['name'], $filter)) {
            continue;
        }
        $ran++;
        $configPath = $work . '/' . preg_replace('/[^A-Za-z0-9]+/', '-', $case['name']) . '.toml';
        $workspace = (string) realpath($toolbelt . '/' . ($case['workspace'] ?? '.'));
        file_put_contents($configPath, config($case, $workspace));
        [$actual, $stderr] = findings($mago, $workspace, $configPath, $case);
        if (isset($case['error'])) {
            // A request's error reaches mago's output; a startup error only the worker's own stderr, which mago drops now and then.
            $failedAsExpected = $actual === null && str_contains($stderr . startupError($workspace, $case), $case['error']);
            echo ($failedAsExpected ? 'ok  ' : 'FAIL') . " {$case['name']}" . ($failedAsExpected ? " (error)\n" : "\n  expected an error containing: {$case['error']}\n");
            $failed += $failedAsExpected ? 0 : 1;
            continue;
        }
        $expected = $case['expect'];
        sort($expected);
        if ($actual === $expected) {
            echo "ok   {$case['name']} (" . count($actual) . ")\n";
            continue;
        }
        $failed++;
        echo "FAIL {$case['name']}\n";
        foreach ($actual === null ? [] : array_diff($expected, $actual) as $line) {
            echo "  missing: {$line}\n";
        }
        foreach (array_diff($actual ?? [], $expected) as $line) {
            echo "  extra:   {$line}\n";
        }
        if ($actual !== null && count($actual) !== count($expected)) {
            echo '  count: expected ' . count($expected) . ', got ' . count($actual) . "\n";
        }
    }
}

if ($ran === 0) {
    fwrite(STDERR, "no case matched\n");
    exit(2);
}
echo $failed === 0 ? "all {$ran} cases pass\n" : "{$failed} of {$ran} cases fail\n";
exit($failed === 0 ? 0 : 1);
