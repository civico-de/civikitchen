<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Browser\BrowserDiff;
use CiviKitchen\Toolbelt\Browser\BrowserProbe;
use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use PHPUnit\Framework\TestCase;

final class BrowserDiffTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = Directory::temporary('civikitchen-browser-diff');
    }

    protected function tearDown(): void
    {
        Directory::remove($this->tempDir);
    }

    public function testIdenticalRunsAndNormalizedPortsProduceNoRegressions(): void
    {
        $before = [
            'prefix' => 'A',
            'routes' => [
                [
                    'route' => 'civicrm/dashboard?reset=1',
                    'mode' => 'auth',
                    'hasCrmContainer' => true,
                    'redirectedToLogin' => false,
                    'consoleErrors' => ['Failed to load http://localhost:8400/ext/foo/bar.js'],
                    'pageErrors' => ['Warning at http://127.0.0.1:8400/asset.js:10'],
                    'networkFailures' => [['status' => 404, 'method' => 'GET', 'urlPath' => '/favicon.ico']],
                    'a11yViolations' => [
                        ['id' => 'color-contrast', 'impact' => 'serious', 'description' => 'Low contrast', 'nodes' => 1, 'targets' => ['#main']],
                    ],
                    'screenshot' => 'screenshots/A-civicrm-dashboard-reset-1.png',
                ],
                [
                    'route' => 'civicrm/event/info?id=1',
                    'mode' => 'anon',
                    'hasCrmContainer' => true,
                    'redirectedToLogin' => false,
                    'consoleErrors' => [],
                    'pageErrors' => [],
                    'networkFailures' => [],
                    'a11yViolations' => [],
                    'screenshot' => 'A-anon-civicrm-event-info-id-1.png',
                ],
            ],
        ];
        $after = [
            'prefix' => 'B',
            'routes' => [
                [
                    'route' => 'civicrm/dashboard?reset=1',
                    'mode' => 'auth',
                    'hasCrmContainer' => true,
                    'redirectedToLogin' => false,
                    'consoleErrors' => ['Failed to load http://localhost:8401/ext/foo/bar.js'],
                    'pageErrors' => ['Warning at http://127.0.0.1:8401/asset.js:10'],
                    'networkFailures' => [['status' => 404, 'method' => 'GET', 'urlPath' => '/favicon.ico']],
                    'a11yViolations' => [
                        ['id' => 'color-contrast', 'impact' => 'moderate', 'description' => 'Low contrast', 'nodes' => 1, 'targets' => ['#main']],
                    ],
                    'screenshot' => 'screenshots/B-civicrm-dashboard-reset-1.png',
                ],
                [
                    'route' => 'civicrm/event/info?id=1',
                    'mode' => 'anon',
                    'hasCrmContainer' => true,
                    'redirectedToLogin' => false,
                    'consoleErrors' => [],
                    'pageErrors' => [],
                    'networkFailures' => [],
                    'a11yViolations' => [],
                    'screenshot' => 'B-anon-civicrm-event-info-id-1.png',
                ],
            ],
        ];

        $diff = BrowserDiff::compare($before, $after);

        self::assertFalse($diff->hasRegressions());
        self::assertSame([], $diff->riskFlags());
        self::assertSame("Browser diff: no regressions (2 routes)\n", $diff->formatSummary());

        $data = $diff->toArray();
        self::assertSame(2, $data['counts']['routes']);
        self::assertSame(0, $data['counts']['routes_with_regressions']);
        self::assertSame(0, $data['counts']['risk_flags']);
        self::assertSame([
            [
                'route' => 'civicrm/dashboard?reset=1',
                'mode' => 'auth',
                'before' => 'A-civicrm-dashboard-reset-1.png',
                'after' => 'B-civicrm-dashboard-reset-1.png',
            ],
            [
                'route' => 'civicrm/event/info?id=1',
                'mode' => 'anon',
                'before' => 'A-anon-civicrm-event-info-id-1.png',
                'after' => 'B-anon-civicrm-event-info-id-1.png',
            ],
        ], $data['screenshots']);
    }

    public function testDetectsAllRegressionCategoriesAndFormatsSummary(): void
    {
        $before = [
            'prefix' => 'A',
            'routes' => [
                [
                    'route' => 'civicrm/contact/search?reset=1',
                    'mode' => 'auth',
                    'hasCrmContainer' => true,
                    'redirectedToLogin' => false,
                    'consoleErrors' => [],
                    'pageErrors' => [],
                    'networkFailures' => [],
                    'a11yViolations' => [
                        ['id' => 'label', 'impact' => 'moderate', 'description' => 'Missing label', 'nodes' => 1, 'targets' => ['#q']],
                    ],
                    'screenshot' => 'A-civicrm-contact-search-reset-1.png',
                ],
                [
                    'route' => 'civicrm/contribute/transact?id=1',
                    'mode' => 'anon',
                    'hasCrmContainer' => true,
                    'redirectedToLogin' => false,
                    'consoleErrors' => [],
                    'pageErrors' => [],
                    'networkFailures' => [],
                    'a11yViolations' => [],
                    'screenshot' => 'A-anon-civicrm-contribute-transact-id-1.png',
                ],
                [
                    'route' => 'civicrm/admin/restricted',
                    'mode' => 'anon',
                    'hasCrmContainer' => false,
                    'redirectedToLogin' => true,
                    'consoleErrors' => [],
                    'pageErrors' => [],
                    'networkFailures' => [],
                    'a11yViolations' => [],
                    'screenshot' => 'A-anon-civicrm-admin-restricted.png',
                ],
            ],
        ];
        $after = [
            'prefix' => 'B',
            'routes' => [
                [
                    'route' => 'civicrm/contact/search?reset=1',
                    'mode' => 'auth',
                    'hasCrmContainer' => false,
                    'redirectedToLogin' => false,
                    'consoleErrors' => ['Refused script at http://localhost:8401/bad.js'],
                    'pageErrors' => ['Uncaught TypeError: CRM.foo is not a function at http://[::1]:8401/app.js:12'],
                    'networkFailures' => [['status' => 500, 'method' => 'post', 'urlPath' => '/civicrm/ajax/api4']],
                    'a11yViolations' => [
                        ['id' => 'label', 'impact' => 'critical', 'description' => 'Missing label', 'nodes' => 2, 'targets' => ['#q', '#r']],
                        ['id' => 'button-name', 'impact' => 'minor', 'description' => 'Empty button', 'nodes' => 1, 'targets' => ['button.go']],
                    ],
                    'screenshot' => 'B-civicrm-contact-search-reset-1.png',
                ],
                [
                    'route' => 'civicrm/contribute/transact?id=1',
                    'mode' => 'anon',
                    'hasCrmContainer' => false,
                    'redirectedToLogin' => true,
                    'consoleErrors' => [],
                    'pageErrors' => [],
                    'networkFailures' => [],
                    'a11yViolations' => [],
                    'screenshot' => 'B-anon-civicrm-contribute-transact-id-1.png',
                ],
                [
                    // Already redirected to login on A, so NOT a regression.
                    'route' => 'civicrm/admin/restricted',
                    'mode' => 'anon',
                    'hasCrmContainer' => false,
                    'redirectedToLogin' => true,
                    'consoleErrors' => [],
                    'pageErrors' => [],
                    'networkFailures' => [],
                    'a11yViolations' => [],
                    'screenshot' => 'B-anon-civicrm-admin-restricted.png',
                ],
            ],
        ];

        $fileA = "{$this->tempDir}/A.browser.json";
        $fileB = "{$this->tempDir}/B.browser.json";
        file_put_contents($fileA, json_encode($before));
        file_put_contents($fileB, json_encode($after));

        $diff = BrowserDiff::fromFiles($fileA, $fileB);
        self::assertTrue($diff->hasRegressions());
        $data = $diff->toArray();

        self::assertSame(3, $data['counts']['routes']);
        self::assertSame(2, $data['counts']['routes_with_regressions']);
        self::assertSame(1, $data['counts']['new_console_errors']);
        self::assertSame(1, $data['counts']['new_page_errors']);
        self::assertSame(1, $data['counts']['new_network_failures']);
        self::assertSame(2, $data['counts']['new_a11y_violations']);
        self::assertSame(3, $data['counts']['render_regressions']);
        self::assertSame(8, $data['counts']['risk_flags']);

        self::assertSame('Refused script at http://localhost/bad.js', $data['new_console_errors'][0]['message']);
        self::assertSame('Uncaught TypeError: CRM.foo is not a function at http://[::1]/app.js:12', $data['new_page_errors'][0]['message']);
        self::assertSame('POST', $data['new_network_failures'][0]['method']);
        self::assertSame(500, $data['new_network_failures'][0]['status']);
        self::assertSame('impact-increased', $data['new_a11y_violations'][0]['change']);
        self::assertSame('moderate', $data['new_a11y_violations'][0]['previous_impact']);
        self::assertSame('new', $data['new_a11y_violations'][1]['change']);

        $codes = array_column($diff->riskFlags(), 'code');
        self::assertContains('browser-page-error', $codes);
        self::assertContains('browser-console-error', $codes);
        self::assertContains('browser-network-failure', $codes);
        self::assertContains('browser-render-regression', $codes);
        self::assertContains('browser-a11y-regression', $codes);

        $summary = $diff->formatSummary();
        self::assertStringContainsString('Browser diff:', $summary);
        self::assertStringContainsString('routes: 3 (2 with regressions)', $summary);
        self::assertStringContainsString('Risk flags (8):', $summary);
        self::assertStringContainsString('browser-page-error', $summary);
        self::assertStringContainsString('browser-render-regression', $summary);
        self::assertStringContainsString('browser-a11y-regression', $summary);
    }

    public function testRejectsInvalidBrowserReports(): void
    {
        try {
            BrowserDiff::decodeFile($this->tempDir . '/missing.browser.json');
            self::fail('expected missing file exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('does not exist', $e->getMessage());
        }

        foreach (['not json', '{}', '{"routes":"nope"}'] as $bad) {
            try {
                BrowserDiff::decodeJson($bad, 'fixture');
                self::fail("expected exception for: {$bad}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString('fixture', $e->getMessage());
            }
        }
    }

    public function testBrowserProbeRunsContainerAndReadsReportFile(): void
    {
        $runner = new ProbeRunner();
        $probe = new BrowserProbe(dirname(__DIR__, 2), $runner);

        $report = $probe->probe('http://localhost:8400', $this->tempDir, 'A', ['civicrm/dashboard'], ['civicrm/event/info'], true);

        self::assertSame('A', $report['prefix']);
        self::assertCount(1, $runner->dockerRuns);
        $command = $runner->dockerRuns[0];
        self::assertContains('--network', $command);
        self::assertContains('host', $command);
        self::assertContains('/ck-probe/probe.mts', $command);
        self::assertContains($this->tempDir . ':/out', $command);
        self::assertSame([], glob($this->tempDir . '/.ck-probe-*'));

        // Failure when docker exits non-zero
        $failRunner = new ProbeRunner();
        $failRunner->probeStatus = 1;
        $failProbe = new BrowserProbe(dirname(__DIR__, 2), $failRunner);
        try {
            $failProbe->probe('http://localhost:8400', $this->tempDir, 'B', ['civicrm/dashboard']);
            self::fail('expected probe failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('browser probe B failed (exit 1)', $e->getMessage());
        }

        // Failure when report file is missing on host (Docker volume not shared)
        $noFileRunner = new ProbeRunner();
        $noFileRunner->writeReport = false;
        $noFileProbe = new BrowserProbe(dirname(__DIR__, 2), $noFileRunner);
        $this->expectExceptionMessage('Docker must share');
        $noFileProbe->probe('http://localhost:8400', $this->tempDir, 'B', ['civicrm/dashboard']);
    }
}

final class ProbeRunner extends Runner
{
    /** @var list<list<string>> */
    public array $dockerRuns = [];
    public int $probeStatus = 0;
    public bool $writeReport = true;

    public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        return ['status' => 0, 'output' => ''];
    }

    public function captureStdout(array $command, ?string $input = null, ?string $workingDirectory = null): array
    {
        $this->dockerRuns[] = $command;
        $spec = json_decode((string) end($command), true);
        $mount = (string) current(array_filter($command, static fn(string $part): bool => str_ends_with($part, ':/out')));
        $hostOut = substr($mount, 0, -strlen(':/out'));
        $prefix = (string) ($spec['prefix'] ?? 'A');
        $payload = [
            'prefix' => $prefix,
            'site' => $spec['site'] ?? '',
            'runA11y' => $spec['runA11y'] ?? true,
            'routes' => [],
        ];
        if ($this->writeReport) {
            file_put_contents("{$hostOut}/{$prefix}.browser.json", json_encode($payload));
        }
        return ['status' => $this->probeStatus, 'output' => json_encode($payload) . "\n"];
    }
}
