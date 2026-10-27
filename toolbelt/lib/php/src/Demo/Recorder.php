<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Demo;

use CiviKitchen\Toolbelt\Process\Runner;
use RuntimeException;

/**
 * Runs toolbelt/demo/record.mts in the checkout's Playwright image on the host network,
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
            '-v', $this->checkoutRoot . '/toolbelt/demo/record.mts:/ck-demo/record.mts:ro',
            '-v', $outDirectory . ':/out',
        ];
        $script['out'] = "/out/{$name}.webm";
        if ($steps !== null) {
            $mounted = '/ck-demo/steps.' . self::STEPS_EXTENSIONS[pathinfo($steps, PATHINFO_EXTENSION)];
            array_push($command, '-v', "{$steps}:{$mounted}:ro");
            $script['steps'] = $mounted;
        }
        array_push($command, $image, 'node', '/ck-demo/record.mts', (string) json_encode($script, JSON_UNESCAPED_SLASHES));
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
