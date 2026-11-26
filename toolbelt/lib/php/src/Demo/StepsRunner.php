<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Demo;

use CiviKitchen\Toolbelt\Process\Runner;
use RuntimeException;

/**
 * Runs toolbelt/demo/steps.mts in the checkout's Playwright image on the host
 * network: one steps module against one running site.
 */
final class StepsRunner
{
    /** Browser start, login and the report, around the time the steps get; measured 6 to 14 s. */
    private const AROUND_STEPS_SECONDS = 30;

    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner,
    ) {
    }

    /**
     * @param array{width: int, height: int} $size
     * @param ?string $page the route to open before the steps
     * @return array<string, mixed> the report steps.mts prints: ok, error, value, url, title, text, errors
     */
    public function run(string $image, Cms $cms, string $site, string $steps, ?string $page, string $phase, array $size, int $timeoutSeconds): array
    {
        // Docker resolves the mount on its own side, which shares the module's directory but not necessarily this file.
        $directory = dirname($steps);
        // Asked first: tempnam() answers a directory it cannot write to with a file in the system's.
        $script = is_writable($directory) ? tempnam($directory, '.ck-steps-') : false;
        if ($script === false) {
            throw new RuntimeException("cannot create a file in {$directory}");
        }
        if (!copy($this->checkoutRoot . '/toolbelt/demo/steps.mts', $script)) {
            unlink($script);
            throw new RuntimeException("cannot write {$script}");
        }
        $mounted = '/ck-steps/module.' . Recorder::STEPS_EXTENSIONS[pathinfo($steps, PATHINFO_EXTENSION)];
        $spec = [
            'cms' => $cms->name,
            'phase' => $phase,
            'site' => $site,
            'routePrefix' => $cms->routePrefix,
            'login' => $cms->login,
            'steps' => $mounted,
            ...($page === null ? [] : ['page' => $page]),
            'size' => $size,
            'timeoutMs' => $timeoutSeconds * 1000,
        ];
        // Nothing of the host but the two scripts: the module is code its author may not have read.
        $command = [
            'docker', 'run', '--rm', '--init', '--network', 'host', '--shm-size', '2g',
            '--user', posix_getuid() . ':' . posix_getgid(), '-e', 'HOME=/tmp',
            '-v', $script . ':/ck-steps/steps.mts:ro',
            '-v', "{$steps}:{$mounted}:ro",
            // Steps that block the event loop never reach the script's own time limit.
            $image, 'timeout', '-k', '5', (string) ($timeoutSeconds + self::AROUND_STEPS_SECONDS),
            'node', '/ck-steps/steps.mts', (string) json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
        try {
            $result = $this->runner->captureStdout($command);
        } finally {
            unlink($script);
        }
        // What `timeout` exits with after ending the script, by TERM or by KILL; the line is steps.mts'.
        if (in_array($result['status'], [124, 137], true) && str_contains($result['output'], "ck steps: running the steps\n")) {
            return ['ok' => false, 'error' => "the steps did not finish within {$timeoutSeconds} s and held up the browser's script", 'errors' => []];
        }
        $lines = array_values(array_filter(explode("\n", trim($result['output'])), 'strlen'));
        $report = json_decode($lines === [] ? '' : $lines[count($lines) - 1], true);
        if ($result['status'] !== 0 || !is_array($report) || !is_bool($report['ok'] ?? null)) {
            throw new RuntimeException("the browser gave no report on {$site} (exit {$result['status']})");
        }
        return $report;
    }
}
