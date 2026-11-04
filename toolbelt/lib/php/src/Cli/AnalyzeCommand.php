<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\CiviCore;
use CiviKitchen\Toolbelt\Repository\Files;
use CiviKitchen\Toolbelt\Repository\PhpFloor;
use CiviKitchen\Toolbelt\Runtime\ExtensionInspector;

final class AnalyzeCommand implements Command
{
    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
        private readonly string $imageCore = '/var/www/html/core',
        private readonly string $imageExtDir = '/var/www/html/ext',
    ) {
    }

    public function run(array $arguments): int
    {
        foreach ($arguments as $argument) {
            if (in_array($argument, ['-h', '--help'], true)) {
                echo $this->usage();
                return 0;
            }
            return $this->error("unknown argument: {$argument}");
        }
        $repository = new Files($this->checkoutRoot, $this->runner);
        if (!$repository->isGitCheckout()) {
            return $this->error('not a git checkout - the file list comes from git ls-files.');
        }
        $core = CiviCore::locate($this->runner, $this->imageCore);
        if ($core === null) {
            return $this->error(CiviCore::MISSING);
        }
        // phpstan's main pass leaves the suite to phpstan-tests; the same scope keeps the two comparable.
        $isTest = static fn(string $file): bool => preg_match('#(^|/)tests/#', $file) === 1 || $file === 'phpstanBootstrap.php';
        $paths = array_values(array_filter($repository->source(['php'], [], true, true), static fn(string $file): bool => !$isTest($file)));
        try {
            $scope = $this->phpstanScope();
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        $symbolsOnly = [];
        if ($scope !== null) {
            // Files phpstan does not analyse still lend their symbols, as phpstan's autoloader would.
            $root = getcwd() . '/';
            $scanned = array_values(array_filter($paths, static fn(string $file): bool => !self::matches($root . $file, $scope['analyseAndScan'])));
            $analysed = static fn(string $file): bool => self::matches($root . $file, $scope['paths']) && !self::matches($root . $file, $scope['analyse']);
            $paths = array_values(array_filter($scanned, $analysed));
            $symbolsOnly = array_values(array_diff($scanned, $paths));
        }
        if ($paths === []) {
            echo "ckanalyze: no PHP files to analyze.\n";
            return 0;
        }
        $generated = array_values(array_diff($repository->source(['php'], [], false, true), $repository->source(['php'], [], true, true)));
        $coreVendor = CiviCore::vendor($core);
        // Each stub stands in for core's file at the same path; stub-parity.php keeps their members in step.
        $stubs = $this->checkoutRoot . '/toolbelt/mago/stubs';
        $replaced = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stubs, \FilesystemIterator::SKIP_DOTS)) as $stub) {
            $replaced[] = $core . substr((string) $stub, strlen($stubs));
        }
        sort($replaced);
        $includes = [$core, ...($coreVendor === null || str_starts_with($coreVendor, $core . '/') ? [] : [$coreVendor]), $stubs, ...$generated, ...$symbolsOnly];
        foreach (['vendor', '.civikitchen-siblings', ...$repository->vendoredPrefixes()] as $symbolsOnly) {
            if (file_exists($symbolsOnly)) {
                $includes[] = $symbolsOnly;
            }
        }
        // Required extensions resolve as phpstanBootstrap.php resolves them; core's own sit inside $core.
        $extDir = getenv('CK_EXT_DIR') ?: $this->imageExtDir;
        $inspector = new ExtensionInspector('');
        $coreKeys = array_map($inspector->key(...), [...glob("{$core}/ext/*/info.xml") ?: [], ...glob("{$core}/ext/*/*/info.xml") ?: []]);
        foreach (array_diff($inspector->requirements('info.xml'), $coreKeys) as $key) {
            if (!is_dir("{$extDir}/{$key}")) {
                fwrite(STDERR, "ckanalyze: required extension {$key} is not under {$extDir}; its classes stay unresolved\n");
                continue;
            }
            $includes[] = "{$extDir}/{$key}";
        }

        $own = is_file('mago.toml');
        if ($own) {
            echo "ckanalyze: using this repo's own mago.toml (the CiviKitchen plugins load only through its extends).\n";
        }
        $base = $own ? (string) realpath('mago.toml') : $this->checkoutRoot . '/toolbelt/mago/mago.toml';
        // mago picks the config format by extension, so the file needs its .toml.
        $directory = Directory::temporary('ckanalyze');
        $config = $directory . '/mago.toml';
        $list = static fn(array $items): string => '[' . implode(', ', array_map(static fn(string $item): string => (string) json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $items)) . ']';
        // mago's analyzer fails on errors only; a shadow run has to count every finding.
        file_put_contents($config, "extends = {$list([$base])}\n\n[source]\npaths = {$list($paths)}\nincludes = {$list($includes)}\nexcludes = {$list($replaced)}\npatches = {$list([$this->checkoutRoot . '/toolbelt/mago/patches'])}\n\n[analyzer]\nminimum-fail-level = \"note\"\n");
        $command = ['mago', '--workspace', '.', '--config', $config];
        $floor = $own ? null : PhpFloor::ofComposerJson('.');
        if ($floor !== null) {
            $command = [...$command, '--php-version', $floor];
        }
        // The extension's plugins resolve info.xml and fixtures from the repo root, not mago's config directory.
        $environment = [...(getenv() ?: []), 'CK_MAGO_WORKSPACE' => (string) getcwd()];
        try {
            return $this->runner->passthrough([...$command, 'analyze'], $environment);
        } finally {
            Directory::remove($directory);
        }
    }

    /**
     * The files phpstan's default config analyses, as phpstan itself resolves
     * them (absolute paths and globs); null without such a config.
     *
     * @return array{paths: list<string>, analyse: list<string>, analyseAndScan: list<string>}|null
     */
    private function phpstanScope(): ?array
    {
        if (array_filter(['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'], is_file(...)) === []) {
            return null;
        }
        $dump = $this->runner->captureSeparate(['phpstan', 'dump-parameters', '--json']);
        $parameters = json_decode($dump['stdout'], true);
        if ($dump['status'] !== 0 || !is_array($parameters) || !is_array($parameters['paths'] ?? null)) {
            throw new \RuntimeException("phpstan dump-parameters failed, so the files phpstan analyses are unknown:\n" . $dump['stderr']);
        }
        $strings = static fn(mixed $list): array => array_values(array_map(strval(...), is_array($list) ? $list : []));
        $excludes = is_array($parameters['excludePaths'] ?? null) ? $parameters['excludePaths'] : [];
        return [
            'paths' => $strings($parameters['paths']),
            'analyse' => $strings($excludes['analyse'] ?? []),
            'analyseAndScan' => $strings($excludes['analyseAndScan'] ?? []),
        ];
    }

    /**
     * phpstan's path semantics on an absolute path: the file itself, a directory
     * above it, or an fnmatch() glob.
     *
     * @param list<string> $patterns
     */
    private static function matches(string $file, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($file === $pattern || str_starts_with($file, rtrim($pattern, '/') . '/') || fnmatch($pattern, $file)) {
                return true;
            }
        }
        return false;
    }

    private function error(string $message): int
    {
        fwrite(STDERR, "ckanalyze: {$message}\n");
        return 2;
    }

    private function usage(): string
    {
        return <<<'TXT'
ckanalyze - static analysis with mago analyze and the CiviKitchen plugins.

  ckanalyze               analyze the extension's PHP outside tests/, limited
                          to phpstan's paths and excludePaths when the repo
                          has a phpstan.neon(.dist)

Run from the extension root inside the image; untracked files count. CiviCRM
core (CIVICRM_CORE_DIR, else /var/www/html/core, else cv's [civicrm.root]),
civix-generated files,
vendor/, .civikitchen-siblings/, policy.vendored_paths and the extensions
named in info.xml <requires> (under CK_EXT_DIR, else /var/www/html/ext) are
read for their symbols only. Core files with a typed stub in toolbelt/mago/stubs/ are read
from the stub instead; toolbelt/mago/patches/ adds types to core's own
declarations. Uses this
repo's mago.toml when it has one (its [source] lists add to the scope
above), else the CiviKitchen baseline at the composer.json PHP floor. Every
finding fails, notes included. `ck ci` runs it as a shadow gate beside phpstan:
findings are reported but do not fail the run.

TXT;
    }
}
