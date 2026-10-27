<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Demo;

use CiviKitchen\Toolbelt\Process\Runner;
use RuntimeException;

/**
 * Runs toolbelt/demo/record.mts in Playwright's browser image on the host network,
 * where it opens a stack at the same localhost URL the site was installed under.
 */
final class Recorder
{
    /** A steps module's extension and the ESM name it is mounted under. */
    public const STEPS_EXTENSIONS = ['ts' => 'mts', 'mts' => 'mts', 'js' => 'mjs', 'mjs' => 'mjs'];

    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner,
    ) {
    }

    /** Playwright's image at the locked @playwright/test version, plus that package; built once per lockfile. */
    public function image(): string
    {
        $lockFile = $this->checkoutRoot . '/package-lock.json';
        $dockerfile = $this->checkoutRoot . '/toolbelt/demo/Dockerfile';
        $lock = json_decode((string) file_get_contents($lockFile), true);
        $version = is_array($lock) ? ($lock['packages']['node_modules/@playwright/test']['version'] ?? null) : null;
        if (!is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new RuntimeException("{$lockFile} locks no @playwright/test version");
        }
        $tag = 'civikitchen-demo-recorder:' . substr(hash('sha256', (string) file_get_contents($dockerfile) . file_get_contents($lockFile)), 0, 12);
        if ($this->runner->capture(['docker', 'image', 'inspect', $tag])['status'] === 0) {
            return $tag;
        }
        $context = sys_get_temp_dir() . '/ck-demo-recorder-' . bin2hex(random_bytes(6));
        mkdir($context);
        try {
            foreach ([$dockerfile, $lockFile, $this->checkoutRoot . '/package.json'] as $file) {
                copy($file, $context . '/' . basename($file));
            }
            $status = $this->runner->passthrough(['docker', 'build', '--build-arg', "PLAYWRIGHT_VERSION={$version}", '-t', $tag, $context]);
        } finally {
            array_map('unlink', glob($context . '/*') ?: []);
            rmdir($context);
        }
        if ($status !== 0) {
            throw new RuntimeException("could not build the recorder image {$tag}");
        }
        return $tag;
    }

    /**
     * Records one run into $outDirectory/$name.webm.
     *
     * @param array<string, mixed> $script what record.mts reads, without the output path and steps
     * @return list<string> the page's script errors
     */
    public function record(string $image, string $outDirectory, string $name, array $script, ?string $steps): array
    {
        $command = [
            'docker', 'run', '--rm', '--init', '--network', 'host', '--shm-size', '2g',
            '--user', posix_getuid() . ':' . posix_getgid(), '-e', 'HOME=/tmp',
            '-v', $this->checkoutRoot . '/toolbelt/demo/record.mts:/civikitchen/demo/record.mts:ro',
            '-v', $outDirectory . ':/out',
        ];
        $script['out'] = "/out/{$name}.webm";
        if ($steps !== null) {
            // Next to record.mts, so its imports resolve against the image's node_modules.
            $mounted = '/civikitchen/demo/steps.' . self::STEPS_EXTENSIONS[pathinfo($steps, PATHINFO_EXTENSION)];
            array_push($command, '-v', "{$steps}:{$mounted}:ro");
            $script['steps'] = $mounted;
        }
        array_push($command, $image, 'node', 'demo/record.mts', (string) json_encode($script, JSON_UNESCAPED_SLASHES));
        $video = "{$outDirectory}/{$name}.webm";
        if (is_file($video)) {
            unlink($video);
        }
        $result = $this->runner->captureStdout($command);
        $lines = array_values(array_filter(explode("\n", trim($result['output'])), 'strlen'));
        $report = json_decode($lines === [] ? '' : $lines[count($lines) - 1], true);
        if ($result['status'] !== 0 || !is_array($report) || !is_array($report['errors'] ?? null)) {
            throw new RuntimeException("recording {$name} failed (exit {$result['status']})");
        }
        if (!is_file($video)) {
            throw new RuntimeException("the recorder wrote no {$video}: Docker must share {$outDirectory} (colima and Docker Desktop share \$HOME)");
        }
        return array_values(array_map('strval', $report['errors']));
    }
}
