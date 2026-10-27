<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Playwright;

use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use RuntimeException;

/**
 * A project's image from toolbelt/playwright/Dockerfile: the browsers of the
 * Playwright version its lockfile resolves, its dependency tree, and Bun if it uses Bun.
 */
final class Image
{
    /** Install settings the package managers read next to package.json. */
    private const INSTALL_CONFIG = ['.npmrc', 'bunfig.toml'];

    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner,
    ) {
    }

    /** The image's tag; built once per Dockerfile, package.json, lockfile, install settings and requested platform. */
    public function for(string $projectDirectory): string
    {
        [$lockFile, $arguments] = $this->packageManager($projectDirectory);
        $files = [$this->checkoutRoot . '/toolbelt/playwright/Dockerfile', $projectDirectory . '/package.json', $lockFile];
        foreach (self::INSTALL_CONFIG as $config) {
            if (is_file("{$projectDirectory}/{$config}")) {
                $files[] = "{$projectDirectory}/{$config}";
            }
        }
        $contents = implode("\0", [(string) getenv('DOCKER_DEFAULT_PLATFORM'), ...array_map(static fn(string $file): string => (string) file_get_contents($file), $files)]);
        $tag = 'civikitchen-playwright:' . substr(hash('sha256', $contents), 0, 12);
        if ($this->runner->capture(['docker', 'image', 'inspect', $tag])['status'] === 0) {
            return $tag;
        }
        // The legacy builder has neither --output nor stage pruning.
        if ($this->runner->capture(['docker', 'buildx', 'version'])['status'] !== 0) {
            throw new RuntimeException("building {$tag} needs BuildKit: install the docker CLI's buildx plugin");
        }
        $context = Directory::temporary('ck-playwright');
        $export = Directory::temporary('ck-playwright');
        try {
            foreach ($files as $file) {
                copy($file, $context . '/' . basename($file));
            }
            // The installed tree names the version, so neither lockfile format needs a parser here.
            if ($this->runner->passthrough(['docker', 'build', ...$arguments, '--target', 'playwright-core', '--output', "type=local,dest={$export}", $context]) !== 0) {
                throw new RuntimeException("could not install the dependencies of {$projectDirectory}");
            }
            $core = is_file("{$export}/package.json") ? json_decode((string) file_get_contents("{$export}/package.json"), true) : null;
            $version = is_array($core) ? ($core['version'] ?? null) : null;
            if (!is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
                throw new RuntimeException("the dependencies of {$projectDirectory} hold no playwright-core release");
            }
            if ($this->runner->passthrough(['docker', 'build', ...$arguments, '--build-arg', "PLAYWRIGHT_VERSION={$version}", '-t', $tag, $context]) !== 0) {
                throw new RuntimeException("could not build the Playwright image {$tag}");
            }
        } finally {
            Directory::remove($context);
            Directory::remove($export);
        }
        return $tag;
    }

    /** @return array{string, list<string>} the lockfile and the build arguments that select its package manager */
    private function packageManager(string $projectDirectory): array
    {
        $package = is_file("{$projectDirectory}/package.json") ? json_decode((string) file_get_contents("{$projectDirectory}/package.json"), true) : null;
        if (!is_array($package)) {
            throw new RuntimeException("{$projectDirectory}/package.json is missing or not JSON");
        }
        $npm = is_file("{$projectDirectory}/package-lock.json");
        if ($npm === is_file("{$projectDirectory}/bun.lock")) {
            throw new RuntimeException("{$projectDirectory} needs exactly one lockfile, package-lock.json or bun.lock");
        }
        if ($npm) {
            return ["{$projectDirectory}/package-lock.json", ['--build-arg', 'PACKAGE_MANAGER=npm']];
        }
        $pin = $package['packageManager'] ?? null;
        if (!is_string($pin) || preg_match('/^bun@(\d+\.\d+\.\d+)(?:\+\S+)?$/', $pin, $match) !== 1) {
            throw new RuntimeException("{$projectDirectory}/package.json pins no Bun; bun.lock needs \"packageManager\": \"bun@x.y.z\"");
        }
        return ["{$projectDirectory}/bun.lock", ['--build-arg', 'PACKAGE_MANAGER=bun', '--build-arg', "BUN_VERSION={$match[1]}"]];
    }
}
