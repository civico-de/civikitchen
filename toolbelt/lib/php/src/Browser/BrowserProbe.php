<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Browser;

use CiviKitchen\Toolbelt\Playwright\Image;
use CiviKitchen\Toolbelt\Process\Runner;
use Closure;
use RuntimeException;

/**
 * Runs toolbelt/demo/probe.mts in the checkout's Playwright image on the host
 * network against a published Standalone stack URL.
 */
final class BrowserProbe
{
    private ?string $image = null;

    /**
     * @param (Closure(string, string, 'A'|'B', list<string>, list<string>, bool, array{width: int, height: int}): array<string, mixed>)|null $customProbe
     */
    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
        private readonly ?Closure $customProbe = null,
    ) {
    }

    /**
     * Probes one Standalone stack and returns the decoded browser report.
     *
     * @param 'A'|'B' $prefix
     * @param list<string> $pages
     * @param list<string> $anonymousPages
     * @param array{width: int, height: int} $size
     * @return array<string, mixed>
     */
    public function probe(
        string $siteUrl,
        string $outDir,
        string $prefix,
        array $pages,
        array $anonymousPages = [],
        bool $runA11y = true,
        array $size = ['width' => 1280, 'height' => 800],
    ): array {
        if ($this->customProbe !== null) {
            return ($this->customProbe)($siteUrl, $outDir, $prefix, $pages, $anonymousPages, $runA11y, $size);
        }

        $source = $this->checkoutRoot . '/toolbelt/demo/probe.mts';
        if (!is_file($source)) {
            throw new RuntimeException("missing browser probe script: {$source}");
        }

        $image = $this->image ??= (new Image($this->checkoutRoot, $this->runner))->for($this->checkoutRoot);

        // Docker resolves the mount on its own side, which shares $outDir but not necessarily the checkout.
        $probeScript = tempnam($outDir, '.ck-probe-');
        if ($probeScript === false || realpath(dirname($probeScript)) !== realpath($outDir)) {
            throw new RuntimeException("cannot create a file in {$outDir}");
        }
        if (!copy($source, $probeScript)) {
            unlink($probeScript);
            throw new RuntimeException("cannot write {$probeScript}");
        }

        $spec = [
            'site' => $siteUrl,
            'pages' => $pages,
            'anonymousPages' => $anonymousPages,
            'outDir' => '/out',
            'prefix' => $prefix,
            'size' => $size,
            'runA11y' => $runA11y,
        ];
        $command = [
            'docker', 'run', '--rm', '--init', '--network', 'host', '--shm-size', '2g',
            '--user', posix_getuid() . ':' . posix_getgid(), '-e', 'HOME=/tmp',
            '-v', $probeScript . ':/ck-probe/probe.mts:ro',
            '-v', $outDir . ':/out',
            $image,
            'node', '/ck-probe/probe.mts',
            (string) json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];

        $reportFile = "{$outDir}/{$prefix}.browser.json";
        if (is_file($reportFile)) {
            unlink($reportFile);
        }

        try {
            $result = $this->runner->captureStdout($command);
        } finally {
            unlink($probeScript);
        }

        $lines = array_values(array_filter(explode("\n", trim($result['output'])), 'strlen'));
        $stdoutReport = json_decode($lines === [] ? '' : $lines[count($lines) - 1], true);
        if ($result['status'] !== 0 || !is_array($stdoutReport) || !is_array($stdoutReport['routes'] ?? null)) {
            throw new RuntimeException("browser probe {$prefix} failed (exit {$result['status']})");
        }
        if (!is_file($reportFile)) {
            throw new RuntimeException("the browser probe wrote no {$reportFile}: Docker must share {$outDir} (colima and Docker Desktop share \$HOME)");
        }
        return BrowserDiff::decodeFile($reportFile);
    }
}
