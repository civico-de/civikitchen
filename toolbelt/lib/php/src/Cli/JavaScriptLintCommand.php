<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\JavaScript\Api4CatalogExport;
use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Files;

final class JavaScriptLintCommand implements Command
{
    private const IGNORED_PATTERNS = ['**/node_modules/**', '**/vendor/**', '**/dist/**', '**/build/**', '**/.civikitchen-siblings/**',
        '**/bower_components/**', '**/packages/**', '**/*.min.js', '**/*.bundle.js'];

    /** Path segments of core's vendored and test trees, skipped by --core. */
    private const CORE_SKIPPED_DIRECTORIES = ['node_modules', 'vendor', 'bower_components', 'packages', 'dist', 'build', 'extern', 'tests'];

    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    public function run(array $arguments): int
    {
        $fix = false;
        $core = false;
        $format = '';
        $paths = [];
        $literal = false;
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if (!$literal && in_array($argument, ['-h', '--help'], true)) {
                echo $this->usage();
                return 0;
            }
            if (!$literal && $argument === '--') {
                $literal = true;
            } elseif (!$literal && $argument === '--fix') {
                $fix = true;
            } elseif (!$literal && $argument === '--core') {
                $core = true;
            } elseif (!$literal && $argument === '--format') {
                $format = (string) (array_shift($arguments) ?? '');
            } elseif (!$literal && str_starts_with($argument, '--format=')) {
                $format = substr($argument, 9);
            } elseif (!$literal && str_starts_with($argument, '-')) {
                return $this->error("unknown option: {$argument}");
            } else {
                $paths[] = $argument;
            }
        }
        $toolchain = $this->checkoutRoot . '/toolbelt/oxlint';
        $oxlint = $toolchain . '/node_modules/.bin/oxlint';
        if (!is_executable($oxlint)) {
            return $this->error("no oxlint toolchain at {$toolchain} - is this a civikitchen image?");
        }
        $catalog = $this->checkoutRoot . '/toolbelt/phpstan/src/Api4Catalog.php';
        $report = $format !== '' ? [$oxlint, "--format={$format}"] : [$oxlint];
        $command = $fix ? [...$report, '--fix'] : $report;
        if ($core) {
            if ($fix || count($paths) > 1) {
                return $this->error('--core takes at most one argument, the core directory, and no --fix.');
            }
            return $this->runCore($toolchain, $command, $catalog, $paths[0] ?? '');
        }
        if (!is_file('info.xml')) {
            return $this->error('no info.xml here - run from the extension root.');
        }
        $repository = new Files($this->checkoutRoot, $this->runner);
        $sourceFiles = $repository->source(['js', 'mjs', 'cjs', 'ts', 'tsx']);
        if ($paths === []) {
            if ($sourceFiles === []) {
                echo "ckeslint: no JavaScript or TypeScript in this repo - nothing to lint.\n";
                return 0;
            }
            echo 'ckeslint: ', count($sourceFiles), " JS/TS file(s) to lint.\n";
            $paths[] = '.';
        }
        $typeCheck = $this->policyFlag('javascript_type_check');
        if ($typeCheck === null) {
            return 2;
        }
        $copy = false;
        if (is_file('.oxlintrc.json')) {
            if ($typeCheck) {
                return $this->error("policy.javascript.type_check applies to the CiviKitchen baseline; with its own .oxlintrc.json this repo sets \"options\": {\"typeCheck\": true} there.");
            }
            echo "ckeslint: using this repo's own .oxlintrc.json (the CiviKitchen baseline does not apply; any jsPlugins it names must be installed in this repo's node_modules).\n";
        } elseif (glob('eslint.config.*')) {
            return $this->error('this repo ships an eslint.config.* but the image gate is oxlint now - add an .oxlintrc.json or remove the custom config.');
        } else {
            if ($typeCheck && is_file('tsconfig.json')) {
                echo "ckeslint: type check (policy.javascript.type_check) through this repo's tsconfig.json.\n";
                $command = [...$command, '--type-aware', '--type-check'];
            }
            $copy = $typeCheck && !is_file('tsconfig.json');
            $command = [...$command, '-c', $toolchain . (is_file('tsconfig.json') ? '/.oxlintrc.json' : '/.oxlintrc-no-type-aware.json')];
            foreach (self::IGNORED_PATTERNS as $pattern) {
                $command = [...$command, '--ignore-pattern', $pattern];
            }
        }

        $extensionDirs = ['.', ...(glob('.civikitchen-siblings/*', GLOB_ONLYDIR) ?: [])];
        $catalogJson = $this->writeCatalog($catalog, $extensionDirs);
        $environment = $this->environment($toolchain, $catalogJson);
        $overlay = '';
        $nodeTypes = $toolchain . '/node_modules/@types/node';
        if (is_file('tsconfig.json') && !file_exists('node_modules/@types/node') && is_dir($nodeTypes)) {
            if ((is_dir('node_modules/@types') || @mkdir('node_modules/@types', 0777, true)) && @symlink($nodeTypes, 'node_modules/@types/node')) {
                $overlay = (string) getcwd() . '/node_modules/@types/node';
            }
            $config = json_decode((string) file_get_contents('tsconfig.json'), true);
            $hasTypes = is_array($config) && isset($config['compilerOptions']['types']);
            if ($overlay !== '' && !$hasTypes && $this->usesNodeGlobals($sourceFiles)) {
                echo "ckeslint: this repo uses Node globals but its tsconfig.json has no \"types\" - add \"types\": [\"node\"], or the type-aware rules see an untyped `process`.\n";
            }
        }
        try {
            $status = $this->runner->passthrough([...$command, ...$paths], $environment);
            return $copy ? max($status, $this->runTypeCheckCopy($toolchain, $report, $sourceFiles, $paths, $environment)) : $status;
        } finally {
            @unlink($catalogJson);
            if ($overlay !== '') {
                @unlink($overlay);
                @rmdir(dirname($overlay));
                @rmdir(dirname($overlay, 2));
            }
        }
    }

    /**
     * Type-check and lint core's own JavaScript: a copy of it beside a
     * tsconfig (allowJs + checkJs) and a declaration file for its globals.
     *
     * @param non-empty-list<string> $command
     */
    private function runCore(string $toolchain, array $command, string $catalog, string $coreDir): int
    {
        $coreDir = $coreDir !== '' ? $coreDir : ((string) getenv('CIVICRM_CORE_DIR') ?: '/var/www/html/core');
        $coreDir = rtrim($coreDir, '/');
        if (!is_file($coreDir . '/Civi.php')) {
            return $this->error("not a CiviCRM core directory: {$coreDir} has no Civi.php");
        }
        $files = $this->coreJavaScript($coreDir);
        if ($files === []) {
            return $this->error("no JavaScript found under {$coreDir}");
        }
        $work = Directory::temporary('ckeslint-core');
        $catalogJson = $this->writeCatalog($catalog, []);
        try {
            if (!$this->stage($toolchain, $coreDir, $files, $work)) {
                return 2;
            }
            $api = json_decode((string) file_get_contents($catalogJson), true);
            echo 'ckeslint --core: ', count($files), " JS file(s) from {$coreDir}, APIv4 catalog of CiviCRM ", $api['version'], ".\n";
            return $this->runner->passthrough(
                [...$command, '--type-aware', '--type-check', '-c', $toolchain . '/.oxlintrc-core.json', '.'],
                $this->environment($toolchain, $catalogJson),
                $work,
            );
        } finally {
            @unlink($catalogJson);
            Directory::remove($work);
        }
    }

    /**
     * The type check for a repo without a tsconfig.json, as a second pass that
     * reports TypeScript's diagnostics only: tsgolint checks just the files
     * under a tsconfig in their own tree, so it runs over a copy of the
     * tracked source beside the CiviKitchen tsconfig.
     *
     * @param non-empty-list<string> $report
     * @param list<string> $sourceFiles
     * @param list<string> $paths
     * @param array<string, string> $environment
     */
    private function runTypeCheckCopy(string $toolchain, array $report, array $sourceFiles, array $paths, array $environment): int
    {
        $root = (string) realpath('.');
        $checked = [];
        $skipped = [];
        foreach ($paths as $path) {
            $real = realpath($path);
            $relative = $real === $root ? '.' : ($real !== false && str_starts_with($real, $root . '/') ? substr($real, strlen($root) + 1) : null);
            $inCopy = $relative !== null && ($relative === '.' || array_filter($sourceFiles, static fn(string $file): bool => $file === $relative || str_starts_with($file, $relative . '/')) !== []);
            if ($inCopy) {
                $checked[] = $relative;
            } else {
                $skipped[] = $path;
            }
        }
        if ($skipped !== []) {
            echo 'ckeslint: not type-checked, no tracked JS/TS of this repo: ', implode(', ', $skipped), "\n";
        }
        if ($checked === []) {
            return 0;
        }
        echo "ckeslint: type check (policy.javascript.type_check) over a copy of the tracked JS/TS beside a CiviKitchen tsconfig.\n";
        $work = Directory::temporary('ckeslint-typecheck');
        try {
            if (!$this->stage($toolchain, '.', $sourceFiles, $work)) {
                return 2;
            }
            if (is_dir('node_modules') && !symlink((string) realpath('node_modules'), $work . '/node_modules')) {
                return $this->error("could not link this repo's node_modules into {$work}");
            }
            return $this->runner->passthrough([...$report, '--type-aware', '--type-check', '-A', 'all', ...$checked], $environment, $work);
        } finally {
            Directory::remove($work);
        }
    }

    /** @param list<string> $files relative to $from */
    private function stage(string $toolchain, string $from, array $files, string $work): bool
    {
        foreach ($files as $file) {
            $target = $work . '/' . $file;
            if ((!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) || !copy($from . '/' . $file, $target)) {
                $this->error("could not copy {$file} to {$work}");
                return false;
            }
        }
        foreach (['tsconfig.json', 'civicrm-globals.d.ts'] as $name) {
            if (!copy($toolchain . '/typecheck/' . $name, $work . '/' . $name)) {
                $this->error("could not copy {$toolchain}/typecheck/{$name}");
                return false;
            }
        }
        return true;
    }

    /** A boolean policy key; null after reporting an unreadable civikitchen.yaml. */
    private function policyFlag(string $key): ?bool
    {
        $result = $this->runner->capture([$this->checkoutRoot . '/toolbelt/bin/ckconform', '--policy', $key]);
        if ($result['status'] !== 0) {
            $this->error('cannot read civikitchen.yaml: ' . trim($result['output']));
            return null;
        }
        return trim($result['output']) === 'true';
    }

    /** @return list<string> core-relative paths of core's first-party .js */
    private function coreJavaScript(string $coreDir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($coreDir, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $entry): bool => $entry->isDir()
                ? !in_array($entry->getFilename(), self::CORE_SKIPPED_DIRECTORIES, true)
                : preg_match('/(?<!\.min|\.bundle)\.js$/', $entry->getFilename()) === 1,
        ));
        foreach ($iterator as $entry) {
            $files[] = substr($entry->getPathname(), strlen($coreDir) + 1);
        }
        sort($files);
        return $files;
    }

    /** @param list<string> $extensionDirs */
    private function writeCatalog(string $catalog, array $extensionDirs): string
    {
        $file = sys_get_temp_dir() . '/ckeslint-api4-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($file, json_encode(Api4CatalogExport::build($catalog, $extensionDirs), JSON_THROW_ON_ERROR));
        return $file;
    }

    /** @return array<string, string> */
    private function environment(string $toolchain, string $catalogJson): array
    {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment['CIVIKITCHEN_API4_CATALOG'] = $catalogJson;
        if (($environment['OXLINT_TSGOLINT_PATH'] ?? '') === '') {
            foreach (glob($toolchain . '/node_modules/@oxlint-tsgolint/*/tsgolint') ?: [] as $candidate) {
                if (is_executable($candidate)) {
                    $environment['OXLINT_TSGOLINT_PATH'] = $candidate;
                    break;
                }
            }
        }
        return $environment;
    }

    /** @param list<string> $files */
    private function usesNodeGlobals(array $files): bool
    {
        foreach ($files as $file) {
            $source = @file_get_contents($file);
            if (is_string($source) && preg_match('/process\.|__dirname|__filename/', $source) === 1) {
                return true;
            }
        }
        return false;
    }

    private function error(string $message): int
    {
        fwrite(STDERR, "ckeslint: {$message}\n");
        return 2;
    }

    private function usage(): string
    {
        return <<<'TXT'
ckeslint - JS/TS lint gate for CiviCRM extensions (CiviKitchen baseline, oxlint).

  ckeslint              lint project JS/TS
  ckeslint --fix        apply available fixes
  ckeslint path ...     lint given files/directories
  ckeslint --format=X   select oxlint reporter
  ckeslint --core [dir] type-check and lint CiviCRM core's own JavaScript
                        (dir defaults to $CIVICRM_CORE_DIR, then /var/www/html/core)
TXT;
    }
}
