<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\CiviCore;
use CiviKitchen\Toolbelt\Repository\PhpFloor;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CompatibilityCommand implements Command
{
    /** The PHP range the pinned mago can check; raise the top with a mago that knows the next PHP. */
    private const MAGO_OLDEST = '8.0';
    private const MAGO_NEWEST = '8.5';

    /** Analyzer codes for symbols missing at the floor or deprecated in any PHP mago knows. */
    private const ANALYZER_CODES = [
        'unavailable-class-constant', 'unavailable-class-like', 'unavailable-constant', 'unavailable-enum-case',
        'unavailable-function', 'unavailable-method', 'unavailable-property',
        'deprecated-class', 'deprecated-constant', 'deprecated-function', 'deprecated-method', 'deprecated-trait',
        'civikitchen/unavailable-access',
    ];

    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
        private readonly string $imageCore = '/var/www/html/core',
    ) {
    }

    public function run(array $arguments): int
    {
        $testVersion = '';
        $paths = [];
        $literal = false;
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if (!$literal && $argument === '--') {
                $literal = true;
                continue;
            }
            if (!$literal && in_array($argument, ['-h', '--help'], true)) {
                echo $this->usage();
                return 0;
            }
            if (!$literal && preg_match('/^(--php|--range)(?:=(.*))?$/s', $argument, $option) === 1) {
                if (!isset($option[2]) && $arguments === []) {
                    return $this->error("{$option[1]} needs a value");
                }
                $value = $option[2] ?? (string) array_shift($arguments);
                if (($option[1] === '--range') !== str_contains($value, '-')) {
                    return $this->error($option[1] === '--php' ? "--php takes one version, got {$value}" : "--range takes FLOOR-TOP, got {$value}");
                }
                $testVersion = $option[1] === '--php' ? "{$value}-" : $value;
                continue;
            }
            if (!$literal && str_starts_with($argument, '-')) {
                return $this->error("unknown option: {$argument}");
            } else {
                $paths[] = $argument;
            }
        }
        if ($testVersion === '') {
            $floor = PhpFloor::ofComposerJson('.');
            if ($floor === null) {
                return $this->error('no PHP floor in composer.json require.php - declare one, or pass --php');
            }
            $testVersion = $floor . '-';
        }
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                return $this->error("no such file or directory: {$path}");
            }
        }
        if ($paths === []) {
            foreach (['Civi', 'CRM', 'api', 'managed'] as $directory) {
                if (is_dir($directory)) {
                    $paths[] = $directory;
                }
            }
            foreach (glob('*.php') ?: [] as $file) {
                if (is_file($file)) {
                    $paths[] = $file;
                }
            }
            if ($paths === []) {
                $paths[] = '.';
            }
        }
        [$floor, $newest] = array_pad(explode('-', $testVersion, 2), 2, '');
        $newest = $newest === '' ? self::MAGO_NEWEST : $newest;
        foreach ([$floor, $newest] as $version) {
            if (preg_match('/^\d+\.\d+$/', $version) !== 1 || version_compare($version, self::MAGO_OLDEST, '<') || version_compare($version, self::MAGO_NEWEST, '>')) {
                return $this->error("PHP {$version}: mago checks MAJOR.MINOR from " . self::MAGO_OLDEST . ' to ' . self::MAGO_NEWEST);
            }
        }
        if (version_compare($floor, $newest, '>')) {
            return $this->error("the range {$floor}-{$newest} ends below its floor");
        }
        $files = $this->phpFiles($paths);
        if ($files === []) {
            echo "ckcompat: no PHP files to check.\n";
            return 0;
        }
        $core = CiviCore::locate($this->runner, $this->imageCore);
        if ($core === null) {
            return $this->error(CiviCore::MISSING . ' Its symfony polyfills decide which newer functions an extension may call.');
        }
        echo "ckcompat: PHP {$floor} floor, deprecations up to {$newest} (from composer.json unless overridden)\n";

        // mago picks the config format by extension, so the file needs its .toml.
        $directory = Directory::temporary('ckcompat');
        $config = $directory . '/mago.toml';
        $list = static fn(array $items): string => '[' . implode(', ', array_map(static fn(string $item): string => (string) json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $items)) . ']';
        $vendor = CiviCore::vendor($core);
        $polyfills = $vendor === null ? [] : (glob($vendor . '/symfony/polyfill-*', GLOB_ONLYDIR) ?: []);
        $worker = ['php', $this->checkoutRoot . '/toolbelt/mago-ext/worker.php', "--compatibility={$newest}"];
        file_put_contents($config, "[source]\npaths = {$list($files)}\nincludes = {$list($polyfills)}\n\n"
            . "[linter]\nminimum-fail-level = \"note\"\n\n[analyzer]\nminimum-fail-level = \"note\"\n\n"
            . "[extension-hosts.civikitchen]\ncommand = {$list($worker)}\n");
        $mago = ['mago', '--workspace', '.', '--config', $config, '--php-version'];
        $environment = [...(getenv() ?: []), 'CK_MAGO_WORKSPACE' => (string) getcwd()];
        try {
            $rules = $this->deprecationRules([...$mago, $newest]);
            if ($rules === null) {
                return $this->error('mago lint --list-rules returned no Deprecation rules');
            }
            // Every lint run also reports the syntax its PHP version cannot parse.
            $statuses = [
                $this->runner->passthrough([...$mago, $floor, 'lint', '--only', 'civikitchen/php-compatibility'], $environment),
                $this->runner->passthrough([...$mago, $newest, 'lint', '--only', implode(',', [...$rules, 'civikitchen/php-deprecation'])], $environment),
                $this->runner->passthrough([...$mago, $floor, 'analyze', ...array_merge(...array_map(static fn(string $code): array => ['--retain-code', $code], self::ANALYZER_CODES))], $environment),
            ];
        } finally {
            Directory::remove($directory);
        }
        return max($statuses) > 1 ? 2 : max($statuses);
    }

    /**
     * mago's own Deprecation category at the newest PHP, so a mago bump brings its new rules along.
     *
     * @param list<string> $mago
     * @return non-empty-list<string>|null
     */
    private function deprecationRules(array $mago): ?array
    {
        $result = $this->runner->captureSeparate([...$mago, 'lint', '--list-rules', '--json']);
        if ($result['status'] !== 0) {
            fwrite(STDERR, $result['stderr']);
        }
        $rules = $result['status'] === 0 ? json_decode($result['stdout'], true) : null;
        $codes = [];
        foreach (is_array($rules) ? $rules : [] as $rule) {
            if (is_array($rule) && ($rule['category'] ?? null) === 'Deprecation' && is_string($rule['code'] ?? null)) {
                $codes[] = $rule['code'];
            }
        }
        return $codes === [] ? null : $codes;
    }

    /** @param list<string> $paths @return list<string> */
    private function phpFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path) && str_ends_with($path, '.php')) {
                $files[] = $path;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $name = $file->getPathname();
                if ($file->isFile() && str_ends_with($name, '.php')
                    && preg_match('#(^|/)(vendor|node_modules|\.civikitchen-siblings)/|\.civix\.php$|(^|/)CRM/[^/]+/DAO/#', $name) !== 1) {
                    $files[] = $name;
                }
            }
        }
        $files = array_values(array_unique($files));
        sort($files);
        return $files;
    }

    private function error(string $message): int
    {
        fwrite(STDERR, "ckcompat: {$message}\n");
        return 2;
    }

    private function usage(): string
    {
        return <<<'TXT'
ckcompat - check PHP cross-version compatibility against the declared floor.

  ckcompat                 check composer.json require.php floor
  ckcompat path ...        check the given files/directories
  ckcompat --php 8.1       check against this floor
  ckcompat --range 8.1-8.4 check a closed range

mago at the floor: syntax and semantics, the civikitchen/php-compatibility
rule, and analyze for functions, classes, methods, constants and enum cases
the floor lacks, also through a static call or constant fetch. CiviCRM
core's symfony polyfills count as available. Deprecations: mago's Deprecation
rules, civikitchen/php-deprecation and the built-in symbols PHP deprecates
up to the top of the range (else 8.5). A file that guards a newer symbol with
function_exists(), class_exists(), method_exists() or defined() is not reported
for it, nor for anything a PHP_VERSION_ID or version_compare(PHP_VERSION, ...)
check above the floor covers. A newer constant of an older class goes
unchecked: mago has no version for it.

TXT;
    }
}
