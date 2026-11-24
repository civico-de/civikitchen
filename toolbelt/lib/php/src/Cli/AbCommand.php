<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Ab\JUnit;
use CiviKitchen\Toolbelt\Browser\BrowserDiff;
use CiviKitchen\Toolbelt\Browser\BrowserProbe;
use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Surface\SurfaceDiff;
use Closure;
use RuntimeException;
use Throwable;

/** Runs one check on two Standalone images and names the tests that pass on the first and fail on the second. Runs on the host. */
final class AbCommand implements Command
{
    public const JUNIT = '/tmp/ck-ab.xml';
    private const FIRST_PORT = 8400;

    private const USAGE = <<<'TXT'
ck ab — run the same check on two CiviCRM versions and compare the results

  ck ab <image A> <image B> [options] -- <command> [arguments ...]

  <image>              a Standalone image, or a civikitchen tag such as standalone-6.16,
                       standalone, standalone-beta or standalone-master
  --mount <src>:<dst>[:ro]
                       mount a host path into both app containers (repeatable),
                       e.g. an extension: --mount "$PWD:/var/www/html/ext/myext"
  --mount-a, --mount-b <src>:<dst>[:ro]
                       mount into one side only, e.g. two work trees of a branch;
                       with them, A and B may be the same image
  --locales <list>     install these core language files in both, e.g. it_IT,fr_FR
                       (sets CIVIKITCHEN_LOCALES; default: en_US only)
  --name <project>     compose project prefix (default: ck-ab-<A>-vs-<B>; uses <project>-a / <project>-b)
  --out <dir>          for the JUnit reports, surface/browser diffs, screenshots, and with --keep,
                       the compose files (default: ./ck-ab-<A>-vs-<B>)
  --json <path>        write a structured JSON comparison report to <path>
  --surface            capture API, route, and Afform surface on both sides and report surface diff
  --page <route>       probe an authenticated CiviCRM route (civicrm/...) in Playwright on both sides (repeatable)
  --anon-page <route>  probe an unauthenticated CiviCRM route (civicrm/...) in Playwright on both sides (repeatable)
  --browser            start a headless Chrome beside each app for browser tests
                       (Civi\Test\MinkBase), e.g. -- ckcoretest --e2e --ext afform/mock ...
  --no-a11y            disable axe-core WCAG 2.1 AA checks during browser probing (enabled by default)
  --expose             publish free localhost ports on both stacks and print A_URL / B_URL
  --port-a <port>      explicit localhost port for stack A (default: free port from 8400)
  --port-b <port>      explicit localhost port for stack B (default: free port from 8400)
  --keep               leave both stacks running

The command runs in each app container as www-data, from /var/www/html. When it
writes a JUnit report to /tmp/ck-ab.xml, the results are compared per test:

  ck ab standalone standalone-beta -- ckcoretest --log-junit /tmp/ck-ab.xml tests/phpunit/api/v4/Query

Exit status 1: a test passes on A and fails on B, or without reports, the
command passes on A and fails on B. Runs on the host through scaffold/ck.
TXT;

    private readonly StandaloneStack $stack;
    private readonly string $checkoutRoot;

    /**
     * @param (Closure(int): bool)|null $portFree
     */
    public function __construct(
        private readonly Runner $runner = new Runner(),
        ?string $checkoutRoot = null,
        private readonly ?BrowserProbe $browserProbe = null,
        private readonly ?Closure $portFree = null,
    ) {
        $this->stack = new StandaloneStack($runner);
        $this->checkoutRoot = $checkoutRoot ?? dirname(__DIR__, 5);
    }

    public function run(array $arguments): int
    {
        if ($arguments === [] || in_array($arguments[0], ['-h', '--help'], true)) {
            echo self::USAGE, "\n";
            return $arguments === [] ? 2 : 0;
        }
        $options = $this->parse($arguments);
        if ($this->runner->which('docker') === null) {
            throw new RuntimeException('ab runs on the host and needs docker on PATH');
        }
        $label = StandaloneStack::label($options['images']['A']) . '-vs-' . StandaloneStack::label($options['images']['B']);
        $projectPrefix = $options['name'] ?? "ck-ab-{$label}";
        $out = $options['out'] ?? getcwd() . "/ck-ab-{$label}";
        if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
            throw new RuntimeException("cannot create {$out}");
        }
        $out = (string) realpath($out);

        $browserRequested = $options['pages'] !== [] || $options['anon_pages'] !== [];
        $needsPorts = $browserRequested || $options['expose'] || $options['port_a'] !== null || $options['port_b'] !== null;
        $ports = [];
        $urls = [];
        if ($needsPorts) {
            $taken = array_values(array_filter([$options['port_a'], $options['port_b']], static fn(?int $port): bool => $port !== null));
            $portA = $options['port_a'] ?? $this->freePort($taken);
            $taken[] = $portA;
            $portB = $options['port_b'] ?? $this->freePort($taken);
            $ports = ['A' => $portA, 'B' => $portB];
            $urls = [
                'A' => StandaloneStack::siteUrl($portA),
                'B' => StandaloneStack::siteUrl($portB),
            ];
        }

        $files = [];
        $results = [];
        $surfaces = [];
        $browserReports = [];
        $probe = $browserRequested ? ($this->browserProbe ?? new BrowserProbe($this->checkoutRoot, $this->runner)) : null;
        try {
            foreach ($options['images'] as $side => $image) {
                $files[$side] = $this->stack->prepare(
                    "{$projectPrefix}-" . strtolower($side),
                    $image,
                    $options['mounts'][$side],
                    $options['locales'],
                    "{$out}/{$side}.compose.json",
                    $ports[$side] ?? null,
                    $options['browser'],
                );
            }
            foreach ($files as $file) {
                $this->stack->up($file, false);
            }
            foreach ($files as $side => $file) {
                $this->stack->up($file, true);
                $results[$side] = ['image' => $options['images'][$side], 'core' => $this->stack->core($file)];
            }
            if ($urls !== []) {
                echo "ck ab: A_URL={$urls['A']}\n";
                echo "ck ab: B_URL={$urls['B']}\n";
            }
            foreach ($files as $side => $file) {
                if ($options['command'] !== []) {
                    echo "ck ab: {$side} ({$results[$side]['core']}): " . implode(' ', $options['command']) . "\n";
                    $results[$side]['status'] = $this->runner->passthrough([...StandaloneStack::exec($file), ...$options['command']]);
                    $results[$side]['junit'] = $this->fetchReport($file, "{$out}/{$side}.junit.xml");
                } else {
                    $results[$side]['status'] = 0;
                    $results[$side]['junit'] = null;
                }
                if ($options['surface']) {
                    $surfaces[$side] = $this->captureSurface($side, $file, "{$out}/{$side}.surface.json");
                }
                if ($probe !== null) {
                    $browserReports[$side] = $this->captureBrowser(
                        $probe,
                        $side,
                        $urls[$side],
                        $out,
                        $options['pages'],
                        $options['anon_pages'],
                        $options['a11y'],
                    );
                }
            }
        } finally {
            foreach ($files as $side => $file) {
                if ($options['keep']) {
                    $urlNote = isset($urls[$side]) ? " ({$urls[$side]})" : '';
                    echo "ck ab: kept {$side}: docker compose -f {$file} exec -u www-data app bash{$urlNote}\n";
                    continue;
                }
                if (!$this->stack->down($file)) {
                    fwrite(STDERR, "ck ab: could not remove the stack of {$file}\n");
                    continue;
                }
                unlink($file);
            }
        }
        return $this->report($results, $surfaces, $options['surface'], $browserReports, $browserRequested, $urls, $options['command'] !== [], $options['json'], $out);
    }

    /**
     * @param array<string, array{image: string, core: string, status: int, junit: ?string}> $results
     * @param array<string, array{data: ?array<string, mixed>, error: ?string}> $surfaces
     * @param array<string, array{data: ?array<string, mixed>, error: ?string}> $browserReports
     * @param array<string, string> $urls
     */
    private function report(
        array $results,
        array $surfaces,
        bool $surfaceRequested,
        array $browserReports,
        bool $browserRequested,
        array $urls,
        bool $hasCommand,
        ?string $jsonPath,
        string $out,
    ): int {
        foreach ($results as $side => $result) {
            echo "ck ab: {$side} {$result['image']} ({$result['core']}): exit {$result['status']}\n";
        }
        ['A' => $a, 'B' => $b] = $results;

        $groups = ['regression' => [], 'fixed' => [], 'both' => [], 'gone' => [], 'only_on_b' => [], 'passed_both' => []];
        $counts = ['a' => 0, 'b' => 0, 'regression' => 0, 'fixed' => 0, 'both' => 0, 'gone' => 0, 'only_on_b' => 0, 'passed_both' => 0];

        if (!$hasCommand) {
            $mode = 'browser';
            $exitCode = 0;
        } elseif ($a['junit'] === null && $b['junit'] === null) {
            $mode = 'exit-status';
            echo 'ck ab: no JUnit report at ' . self::JUNIT . "; comparing exit status only\n";
            if ($a['status'] === 0 && $b['status'] !== 0) {
                echo "ck ab: the command passes on A and fails on B\n";
                $exitCode = 1;
            } else {
                echo $a['status'] === $b['status'] ? "ck ab: same exit status on both\n" : "ck ab: the command fails on A and passes on B\n";
                $exitCode = 0;
            }
        } elseif ($a['junit'] === null || $b['junit'] === null) {
            throw new RuntimeException('only ' . ($a['junit'] === null ? 'B' : 'A') . ' wrote ' . self::JUNIT . '; the other side stopped before its tests ran');
        } else {
            $mode = 'junit';
            $onA = JUnit::outcomes($a['junit']);
            $onB = JUnit::outcomes($b['junit']);
            if ($onA === [] || $onB === []) {
                throw new RuntimeException('a JUnit report holds no test case; nothing was compared');
            }
            foreach ($onA as $test => $outcome) {
                $other = $onB[$test] ?? JUnit::SKIPPED;
                if ($outcome === JUnit::SKIPPED) {
                    continue;
                }
                if ($other === JUnit::SKIPPED) {
                    $groups['gone'][] = $test;
                } elseif ($outcome !== $other) {
                    $groups[$outcome === JUnit::PASSED ? 'regression' : 'fixed'][] = $test;
                } elseif ($outcome === JUnit::FAILED) {
                    $groups['both'][] = $test;
                } elseif ($outcome === JUnit::PASSED) {
                    $groups['passed_both'][] = $test;
                }
            }
            $groups['only_on_b'] = array_map('strval', array_keys(array_diff_key($onB, $onA)));
            $counts = [
                'a' => count($onA),
                'b' => count($onB),
                'regression' => count($groups['regression']),
                'fixed' => count($groups['fixed']),
                'both' => count($groups['both']),
                'gone' => count($groups['gone']),
                'only_on_b' => count($groups['only_on_b']),
                'passed_both' => count($groups['passed_both']),
            ];

            echo "ck ab: {$counts['a']} tests on A, {$counts['b']} on B, {$counts['only_on_b']} only on B\n";
            $titles = [
                'regression' => 'pass on A, fail on B',
                'fixed' => 'fail on A, pass on B',
                'both' => 'fail on both',
                'gone' => 'ran on A, skipped or missing on B',
            ];
            foreach ($titles as $group => $title) {
                echo "ck ab: {$title}: " . count($groups[$group]) . "\n";
                foreach ($groups[$group] as $test) {
                    echo "  {$test}\n";
                }
            }
            $exitCode = $groups['regression'] === [] ? 0 : 1;
        }

        $surfaceDiff = null;
        if ($surfaceRequested) {
            $surfaceDiff = $this->summarizeSurface($surfaces, $out);
        }

        $browserDiff = null;
        if ($browserRequested) {
            $browserDiff = $this->summarizeBrowser($browserReports, $out);
            if (!$hasCommand && (isset($browserDiff['error']) || (($browserDiff['counts']['risk_flags'] ?? 0) > 0))) {
                $exitCode = 1;
            }
        }

        if ($jsonPath !== null) {
            $payload = [
                'A' => $a,
                'B' => $b,
                'mode' => $mode,
                'groups' => $groups,
                'counts' => $counts,
                'exit_code' => $exitCode,
            ];
            if ($urls !== []) {
                $payload['urls'] = $urls;
            }
            if ($surfaceDiff !== null) {
                $payload['surface_diff'] = $surfaceDiff;
            }
            if ($browserDiff !== null) {
                $payload['browser_diff'] = $browserDiff;
            }
            $this->writeJson($jsonPath, $payload);
        }

        return $exitCode;
    }

    /**
     * @param 'A'|'B' $side
     * @param list<string> $pages
     * @param list<string> $anonPages
     * @return array{data: ?array<string, mixed>, error: ?string}
     */
    private function captureBrowser(
        BrowserProbe $probe,
        string $side,
        string $siteUrl,
        string $out,
        array $pages,
        array $anonPages,
        bool $runA11y,
    ): array {
        $target = "{$out}/{$side}.browser.json";
        if (is_file($target)) {
            unlink($target);
        }
        try {
            $report = $probe->probe($siteUrl, $out, $side, $pages, $anonPages, $runA11y);
            if (!is_file($target)) {
                $this->writeJson($target, $report);
            }
            return ['data' => $report, 'error' => null];
        } catch (Throwable $e) {
            fwrite(STDERR, "ck ab: browser probe failed on {$side}: {$e->getMessage()}\n");
            return ['data' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, array{data: ?array<string, mixed>, error: ?string}> $browserReports
     * @return array<string, mixed>
     */
    private function summarizeBrowser(array $browserReports, string $out): array
    {
        $dataA = $browserReports['A']['data'] ?? null;
        $dataB = $browserReports['B']['data'] ?? null;
        if ($dataA !== null && $dataB !== null) {
            $diff = BrowserDiff::compare($dataA, $dataB);
            $diffArray = $diff->toArray();
            $this->writeJson("{$out}/browser-diff.json", $diffArray);
            echo $diff->formatSummary();
            return $diffArray;
        }

        $errors = [];
        foreach (['A', 'B'] as $side) {
            $sideError = $browserReports[$side]['error'] ?? null;
            if ($sideError !== null) {
                $errors[] = "{$side}: {$sideError}";
            }
        }
        $message = $errors !== [] ? implode('; ', $errors) : 'browser probe data missing';
        $errorPayload = ['error' => $message];
        $this->writeJson("{$out}/browser-diff.json", $errorPayload);
        echo "ck ab: browser comparison failed: {$message}\n";
        return $errorPayload;
    }

    /** @param list<int> $taken ports this run assigned but has not bound yet */
    private function freePort(array $taken): int
    {
        $free = $this->portFree ?? static function (int $port): bool {
            $server = @stream_socket_server("tcp://127.0.0.1:{$port}");
            if ($server === false) {
                return false;
            }
            fclose($server);
            return true;
        };
        for ($port = self::FIRST_PORT; $port <= 65535; $port++) {
            if (!in_array($port, $taken, true) && $free($port)) {
                return $port;
            }
        }
        throw new RuntimeException('no free port for a stack');
    }

    /**
     * @return array{data: ?array<string, mixed>, error: ?string}
     */
    private function captureSurface(string $side, string $file, string $target): array
    {
        if (is_file($target)) {
            unlink($target);
        }

        $primaryError = '';
        $primary = $this->runner->captureSeparate([...StandaloneStack::exec($file), 'ck', 'surface']);
        if ($primary['status'] === 0) {
            try {
                $decoded = SurfaceDiff::decodeJson($primary['stdout'], 'ck surface');
                $this->writeJson($target, $decoded);
                return ['data' => $decoded, 'error' => null];
            } catch (RuntimeException $e) {
                $primaryError = $e->getMessage();
            }
        } else {
            $detail = trim($primary['stderr'] !== '' ? $primary['stderr'] : $primary['stdout']);
            $primaryError = "ck surface exited {$primary['status']}" . ($detail !== '' ? ": {$detail}" : '');
        }

        // Older images lack `ck surface`; fall back to invoking surface-dump.php via cv scr.
        $fallbackError = '';
        $fallback = $this->runner->captureSeparate([
            ...StandaloneStack::exec($file),
            'cv',
            'scr',
            '/opt/civikitchen/toolbelt/lib/surface-dump.php',
        ]);
        if ($fallback['status'] === 0) {
            try {
                $decoded = SurfaceDiff::decodeJson($fallback['stdout'], 'cv scr surface-dump.php');
                $this->writeJson($target, $decoded);
                return ['data' => $decoded, 'error' => null];
            } catch (RuntimeException $e) {
                $fallbackError = $e->getMessage();
            }
        } else {
            $detail = trim($fallback['stderr'] !== '' ? $fallback['stderr'] : $fallback['stdout']);
            $fallbackError = "cv scr surface-dump.php exited {$fallback['status']}" . ($detail !== '' ? ": {$detail}" : '');
        }

        // Published images predating surface-dump.php can still run the host checkout's script.
        $hostDump = $this->checkoutRoot . '/toolbelt/lib/surface-dump.php';
        if (is_file($hostDump)) {
            $copied = $this->runner->captureSeparate([
                'docker',
                'compose',
                '-f',
                $file,
                'cp',
                $hostDump,
                'app:/tmp/ck-surface-dump.php',
            ]);
            if ($copied['status'] === 0) {
                $hostRun = $this->runner->captureSeparate([
                    ...StandaloneStack::exec($file),
                    'cv',
                    'scr',
                    '/tmp/ck-surface-dump.php',
                ]);
                if ($hostRun['status'] === 0) {
                    try {
                        $decoded = SurfaceDiff::decodeJson($hostRun['stdout'], 'cv scr /tmp/ck-surface-dump.php');
                        $this->writeJson($target, $decoded);
                        return ['data' => $decoded, 'error' => null];
                    } catch (RuntimeException $e) {
                        $fallbackError = $e->getMessage();
                    }
                } else {
                    $detail = trim($hostRun['stderr'] !== '' ? $hostRun['stderr'] : $hostRun['stdout']);
                    $fallbackError = "cv scr /tmp/ck-surface-dump.php exited {$hostRun['status']}" . ($detail !== '' ? ": {$detail}" : '');
                }
            }
        }

        $error = "{$primaryError}; fallback failed ({$fallbackError})";
        fwrite(STDERR, "ck ab: surface capture failed on {$side}: {$error}\n");
        return ['data' => null, 'error' => $error];
    }

    /**
     * @param array<string, array{data: ?array<string, mixed>, error: ?string}> $surfaces
     * @return array<string, mixed>
     */
    private function summarizeSurface(array $surfaces, string $out): array
    {
        $dataA = $surfaces['A']['data'] ?? null;
        $dataB = $surfaces['B']['data'] ?? null;
        if ($dataA !== null && $dataB !== null) {
            $diff = SurfaceDiff::compare($dataA, $dataB);
            $diffArray = $diff->toArray();
            $this->writeJson("{$out}/surface-diff.json", $diffArray);
            echo $diff->formatSummary();
            return $diffArray;
        }

        $errors = [];
        foreach (['A', 'B'] as $side) {
            $sideError = $surfaces[$side]['error'] ?? null;
            if ($sideError !== null) {
                $errors[] = "{$side}: {$sideError}";
            }
        }
        $message = $errors !== [] ? implode('; ', $errors) : 'surface data missing';
        $errorPayload = ['error' => $message];
        $this->writeJson("{$out}/surface-diff.json", $errorPayload);
        echo "ck ab: surface comparison failed: {$message}\n";
        return $errorPayload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $directory = dirname($path);
        if ($directory !== '' && !is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("cannot create directory for {$path}");
        }
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($path, $encoded) === false) {
            throw new RuntimeException("cannot write {$path}");
        }
    }

    /** Copies the side's JUnit report out of its container; null when the command wrote none. */
    private function fetchReport(string $file, string $target): ?string
    {
        if (is_file($target)) {
            unlink($target);
        }
        $copy = $this->runner->capture(['docker', 'compose', '-f', $file, 'cp', 'app:' . self::JUNIT, $target]);
        return $copy['status'] === 0 && is_file($target) ? $target : null;
    }

    /**
     * @param list<string> $arguments
     * @return array{
     *   images: array{A: string, B: string},
     *   mounts: array{A: list<string>, B: list<string>},
     *   locales: ?string,
     *   name: ?string,
     *   out: ?string,
     *   json: ?string,
     *   surface: bool,
     *   keep: bool,
     *   pages: list<string>,
     *   anon_pages: list<string>,
     *   a11y: bool,
     *   browser: bool,
     *   expose: bool,
     *   port_a: ?int,
     *   port_b: ?int,
     *   command: list<string>
     * }
     */
    private function parse(array $arguments): array
    {
        $separator = array_search('--', $arguments, true);
        $command = $separator === false ? [] : array_slice($arguments, $separator + 1);
        if ($separator !== false && $command === []) {
            throw new RuntimeException("which check? Give the command after --\n" . self::USAGE);
        }
        $arguments = $separator === false ? $arguments : array_slice($arguments, 0, (int) $separator);
        $images = [];
        $mounts = ['A' => [], 'B' => []];
        $locales = null;
        $name = null;
        $out = null;
        $json = null;
        $surface = false;
        $keep = false;
        $pages = [];
        $anonPages = [];
        $a11y = true;
        $browser = false;
        $expose = false;
        $portA = null;
        $portB = null;
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if ($argument === '--keep') {
                $keep = true;
                continue;
            }
            if ($argument === '--surface') {
                $surface = true;
                continue;
            }
            if ($argument === '--browser') {
                $browser = true;
                continue;
            }
            if ($argument === '--no-a11y') {
                $a11y = false;
                continue;
            }
            if ($argument === '--expose') {
                $expose = true;
                continue;
            }
            if (!str_starts_with($argument, '--')) {
                $images[] = StandaloneStack::image($argument);
                continue;
            }
            $value = array_shift($arguments);
            if ($value === null) {
                throw new RuntimeException("{$argument} needs a value");
            }
            if ($argument === '--mount') {
                $mounts['A'][] = $mounts['B'][] = StandaloneStack::mount($value);
            } elseif ($argument === '--mount-a' || $argument === '--mount-b') {
                $mounts[strtoupper(substr($argument, -1))][] = StandaloneStack::mount($value);
            } elseif ($argument === '--locales') {
                $locales = StandaloneStack::locales($value);
            } elseif ($argument === '--name') {
                if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $value) !== 1) {
                    throw new RuntimeException("--name takes lowercase letters, digits, - and _, not {$value}");
                }
                $name = $value;
            } elseif ($argument === '--out') {
                $out = $value;
            } elseif ($argument === '--json') {
                $json = $value;
            } elseif ($argument === '--page') {
                $pages[] = self::civicrmRoute('--page', $value);
            } elseif ($argument === '--anon-page') {
                $anonPages[] = self::civicrmRoute('--anon-page', $value);
            } elseif ($argument === '--port-a') {
                $portA = self::parsePort('--port-a', $value);
            } elseif ($argument === '--port-b') {
                $portB = self::parsePort('--port-b', $value);
            } else {
                throw new RuntimeException("unknown option {$argument}\n" . self::USAGE);
            }
        }
        if ($command === [] && $pages === [] && $anonPages === []) {
            throw new RuntimeException("which check? Give the command after --\n" . self::USAGE);
        }
        if (count($images) !== 2) {
            throw new RuntimeException('ab compares two images, got ' . count($images) . "\n" . self::USAGE);
        }
        if ($images[0] === $images[1] && $mounts['A'] === $mounts['B']) {
            throw new RuntimeException("both sides are {$images[0]} with the same mounts; nothing to compare");
        }
        // The browser shares the app's network, where the site answers on port 80, not on a published port.
        if ($browser && ($pages !== [] || $anonPages !== [] || $expose || $portA !== null || $portB !== null)) {
            throw new RuntimeException('--browser runs browser tests against port 80 inside the stack; it cannot be combined with --page, --anon-page, --expose or --port-a/--port-b');
        }
        if ($portA !== null && $portB !== null && $portA === $portB) {
            throw new RuntimeException('--port-a and --port-b must be different ports');
        }
        return [
            'images' => ['A' => $images[0], 'B' => $images[1]],
            'mounts' => $mounts,
            'locales' => $locales,
            'name' => $name,
            'out' => $out,
            'json' => $json,
            'surface' => $surface,
            'keep' => $keep,
            'pages' => $pages,
            'anon_pages' => $anonPages,
            'a11y' => $a11y,
            'browser' => $browser,
            'expose' => $expose,
            'port_a' => $portA,
            'port_b' => $portB,
            'command' => $command,
        ];
    }

    private static function civicrmRoute(string $option, string $value): string
    {
        $route = ltrim($value, '/');
        if (!str_starts_with($route, 'civicrm/')) {
            throw new RuntimeException("{$option} takes a CiviCRM route such as civicrm/admin/search, not {$value}");
        }
        return $route;
    }

    private static function parsePort(string $option, string $value): int
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new RuntimeException("{$option} takes a TCP port 1..65535, not {$value}");
        }
        $port = (int) $value;
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException("{$option} takes a TCP port 1..65535, not {$value}");
        }
        return $port;
    }
}
