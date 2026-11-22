<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Ab\JUnit;
use CiviKitchen\Toolbelt\Browser\BrowserProbe;
use CiviKitchen\Toolbelt\Cli\AbCommand;
use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use PHPUnit\Framework\TestCase;

final class AbTest extends TestCase
{
    private const IMAGE = 'ghcr.io/civico-de/civikitchen:';
    private const CHECK = ['--', 'ckcoretest', '--log-junit', AbCommand::JUNIT, 'tests/phpunit/api/v4/Query'];

    private string $out;

    protected function setUp(): void
    {
        $this->out = Directory::temporary('civikitchen-ab') . '/out';
    }

    protected function tearDown(): void
    {
        Directory::remove(dirname($this->out));
    }

    public function testNamesTheTestsThatPassOnAAndFailOnB(): void
    {
        $runner = new AbRunner();
        $runner->reports = [
            'a' => ['QueryTest::testJoin' => 'passed', 'QueryTest::testOld' => 'failed', 'QueryTest::testBroken' => 'failed',
                'QueryTest::testDropped' => 'passed', 'QueryTest::testSame with data set #1' => 'passed', 'QueryTest::testNever' => 'skipped'],
            'b' => ['QueryTest::testJoin' => 'failed', 'QueryTest::testOld' => 'passed', 'QueryTest::testBroken' => 'error',
                'QueryTest::testSame with data set #1' => 'passed', 'QueryTest::testNever' => 'failed', 'QueryTest::testNew' => 'passed'],
        ];
        $runner->status = ['a' => 1, 'b' => 1];

        [$status, $output] = $this->ab($runner, ['standalone-6.16', 'standalone-beta', '--mount', '/src/myext:/var/www/html/ext/myext', '--locales', 'it_IT', '--out', $this->out, ...self::CHECK]);

        self::assertSame(1, $status);
        self::assertStringContainsString("pass on A, fail on B: 1\n  QueryTest::testJoin\n", $output);
        self::assertStringContainsString("fail on A, pass on B: 1\n  QueryTest::testOld\n", $output);
        self::assertStringContainsString("fail on both: 1\n  QueryTest::testBroken\n", $output);
        self::assertStringContainsString("ran on A, skipped or missing on B: 1\n  QueryTest::testDropped\n", $output);
        self::assertStringContainsString('6 tests on A, 6 on B, 1 only on B', $output);
        self::assertStringContainsString('A ' . self::IMAGE . 'standalone-6.16 (CiviCRM 6.16.2): exit 1', $output);
        self::assertStringContainsString('(CiviCRM 6.17.beta1, civicrm-core 6.17 abc1234)', $output);

        self::assertSame(self::IMAGE . 'standalone-6.16', $runner->configs['ck-ab-standalone-6-16-vs-standalone-beta-a']['services']['app']['image']);
        $b = $runner->configs['ck-ab-standalone-6-16-vs-standalone-beta-b']['services']['app'];
        self::assertSame(self::IMAGE . 'standalone-beta', $b['image']);
        self::assertSame(['/src/myext:/var/www/html/ext/myext'], $b['volumes']);
        self::assertSame('it_IT', $b['environment']['CIVIKITCHEN_LOCALES']);
        self::assertSame($runner->configs['ck-ab-standalone-6-16-vs-standalone-beta-a']['services']['db'], $runner->configs['ck-ab-standalone-6-16-vs-standalone-beta-b']['services']['db']);
        // Both stacks are gone, the reports stay.
        self::assertSame(['a', 'b'], $runner->removed);
        self::assertFileDoesNotExist("{$this->out}/A.compose.json");
        self::assertFileExists("{$this->out}/B.junit.xml");
    }

    public function testIdenticalResultsExitZero(): void
    {
        $runner = new AbRunner();
        $runner->reports = ['a' => ['T::testOne' => 'passed', 'T::testTwo' => 'failed'], 'b' => ['T::testOne' => 'passed', 'T::testTwo' => 'failed']];
        $runner->status = ['a' => 1, 'b' => 1];

        [$status, $output] = $this->ab($runner, ['standalone', 'example.org/civi:next', '--out', $this->out, ...self::CHECK]);

        self::assertSame(0, $status);
        self::assertStringContainsString('pass on A, fail on B: 0', $output);
        self::assertSame('example.org/civi:next', $runner->configs['ck-ab-standalone-vs-next-b']['services']['app']['image']);
    }

    public function testWithoutReportsTheExitStatusDecides(): void
    {
        foreach ([[['a' => 0, 'b' => 3], 1, 'passes on A and fails on B'], [['a' => 2, 'b' => 2], 0, 'same exit status'], [['a' => 1, 'b' => 0], 0, 'fails on A and passes on B']] as [$exits, $expected, $line]) {
            $runner = new AbRunner();
            $runner->status = $exits;
            [$status, $output] = $this->ab($runner, ['standalone', 'standalone-master', '--out', $this->out, '--', 'cv', 'api4', 'Contact.get']);
            self::assertSame($expected, $status, $line);
            self::assertStringContainsString($line, $output);
            self::assertStringContainsString('comparing exit status only', $output);
        }
    }

    public function testAReportOnOneSideOnlyIsAnError(): void
    {
        $runner = new AbRunner();
        $runner->reports = ['a' => ['T::testOne' => 'passed']];

        $this->expectExceptionMessage('only A wrote /tmp/ck-ab.xml');
        try {
            $this->ab($runner, ['standalone', 'standalone-master', '--out', $this->out, ...self::CHECK]);
        } finally {
            self::assertSame(['a', 'b'], $runner->removed);
        }
    }

    public function testAnEmptyReportIsAnError(): void
    {
        $runner = new AbRunner();
        $runner->reports = ['a' => [], 'b' => ['T::testOne' => 'passed']];

        $this->expectExceptionMessage('holds no test case');
        $this->ab($runner, ['standalone', 'standalone-master', '--out', $this->out, ...self::CHECK]);
    }

    public function testAStackThatDoesNotBootStopsTheRunWithItsLog(): void
    {
        $runner = new AbRunner();
        $runner->bootStatus = 1;

        try {
            $this->ab($runner, ['standalone', 'standalone-master', '--out', $this->out, ...self::CHECK]);
            self::fail('expected the boot failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('did not boot', $e->getMessage());
            self::assertStringContainsString('provisioning failed', $e->getMessage());
        }
        self::assertSame(['a', 'b'], $runner->removed);
        self::assertSame([], $runner->ran);
    }

    public function testKeepLeavesBothStacks(): void
    {
        $runner = new AbRunner();
        [$status, $output] = $this->ab($runner, ['standalone', 'standalone-master', '--keep', '--out', $this->out, '--', 'true']);

        self::assertSame(0, $status);
        self::assertSame([], $runner->removed);
        self::assertFileExists("{$this->out}/A.compose.json");
        self::assertStringContainsString('kept B: docker compose -f ' . realpath($this->out) . '/B.compose.json', $output);
    }

    public function testAnExistingStackOfThatNameIsNotReused(): void
    {
        $runner = new AbRunner();
        $runner->existing = true;

        $this->expectExceptionMessage('a stack named ck-ab-standalone-vs-standalone-master-a exists');
        $this->ab($runner, ['standalone', 'standalone-master', '--out', $this->out, '--', 'true']);
    }

    public function testMountsPerSideCompareTwoTreesOnOneImage(): void
    {
        $runner = new AbRunner();
        $runner->status = ['a' => 0, 'b' => 1];

        [$status] = $this->ab($runner, ['standalone', 'standalone', '--mount', '/src/tests:/var/www/html/core/tests:ro',
            '--mount-a', '/src/base/CRM:/var/www/html/core/CRM:ro', '--mount-b', '/src/fix/CRM:/var/www/html/core/CRM:ro', '--out', $this->out, '--', 'true']);

        self::assertSame(1, $status);
        self::assertSame(['/src/tests:/var/www/html/core/tests:ro', '/src/base/CRM:/var/www/html/core/CRM:ro'],
            $runner->configs['ck-ab-standalone-vs-standalone-a']['services']['app']['volumes']);
        self::assertSame(['/src/tests:/var/www/html/core/tests:ro', '/src/fix/CRM:/var/www/html/core/CRM:ro'],
            $runner->configs['ck-ab-standalone-vs-standalone-b']['services']['app']['volumes']);
    }

    public function testRejectsWhatItCannotCompare(): void
    {
        $cases = [
            [['standalone', 'standalone-beta'], 'which check?'],
            [['standalone', '--', 'true'], 'two images, got 1'],
            [['standalone', 'standalone', '--', 'true'], 'nothing to compare'],
            [['standalone', 'standalone', '--mount-a', '/a:/b', '--mount-b', '/a:/b', '--', 'true'], 'same mounts; nothing to compare'],
            [['standalone', 'standalone-beta', '--mount', '/a:/b:rw', '--', 'true'], '--mount takes'],
            [['standalone', 'standalone-beta', '--mount', 'ext:/var/www', '--', 'true'], '--mount takes'],
            [['standalone', 'standalone-beta', '--locales', 'it', '--', 'true'], '--locales takes'],
            [['standalone', 'standalone-beta', '--cms', 'all', '--', 'true'], 'unknown option --cms'],
            [['standalone', 'standalone-beta', '--out', '--', 'true'], '--out needs a value'],
            [['standalone', 'standalone-beta', '--json', '--', 'true'], '--json needs a value'],
            [['standalone', 'standalone-beta', '--name', 'Bug 1', '--', 'true'], '--name takes'],
            [['standalone', 'standalone-beta', '--name', '-bad', '--', 'true'], '--name takes'],
            [['standalone', 'standalone-beta', '--name', '--', 'true'], '--name needs a value'],
        ];
        foreach ($cases as [$arguments, $message]) {
            try {
                $this->ab(new AbRunner(), $arguments);
                self::fail("expected: {$message}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        $runner = new AbRunner();
        $runner->docker = false;
        $this->expectExceptionMessage('needs docker');
        $this->ab($runner, ['standalone', 'standalone-beta', '--', 'true']);
    }

    public function testHelp(): void
    {
        self::assertSame(0, $this->ab(new AbRunner(), ['--help'])[0]);
        [$status, $output] = $this->ab(new AbRunner(), []);
        self::assertSame(2, $status);
        self::assertStringContainsString('ck ab <image A> <image B>', $output);
    }

    public function testJUnitRejectsAFileThatIsNoReport(): void
    {
        mkdir($this->out, 0777, true);
        file_put_contents("{$this->out}/broken.xml", 'PHP Fatal error');

        $this->expectExceptionMessage('is not a JUnit report');
        JUnit::outcomes("{$this->out}/broken.xml");
    }

    public function testJsonWritesStructuredReportInJunitAndExitStatusModes(): void
    {
        $runner = new AbRunner();
        $runner->reports = [
            'a' => ['T::testPass' => 'passed', 'T::testRegress' => 'passed', 'T::testFix' => 'failed', 'T::testBoth' => 'failed', 'T::testGone' => 'passed'],
            'b' => ['T::testPass' => 'passed', 'T::testRegress' => 'failed', 'T::testFix' => 'passed', 'T::testBoth' => 'error', 'T::testOnlyB' => 'passed'],
        ];
        $runner->status = ['a' => 1, 'b' => 1];
        $jsonPath = $this->out . '/nested/ab-report.json';

        [$status, $output] = $this->ab($runner, ['standalone-6.16', 'standalone-beta', '--out', $this->out, '--json', $jsonPath, ...self::CHECK]);

        self::assertSame(1, $status);
        self::assertStringNotContainsString('T::testPass', $output);
        self::assertFileExists($jsonPath);
        $report = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('junit', $report['mode']);
        self::assertSame(1, $report['exit_code']);
        self::assertSame(self::IMAGE . 'standalone-6.16', $report['A']['image']);
        self::assertSame('CiviCRM 6.16.2', $report['A']['core']);
        self::assertSame(['T::testRegress'], $report['groups']['regression']);
        self::assertSame(['T::testFix'], $report['groups']['fixed']);
        self::assertSame(['T::testBoth'], $report['groups']['both']);
        self::assertSame(['T::testGone'], $report['groups']['gone']);
        self::assertSame(['T::testOnlyB'], $report['groups']['only_on_b']);
        self::assertSame(['T::testPass'], $report['groups']['passed_both']);
        self::assertSame(5, $report['counts']['a']);
        self::assertSame(5, $report['counts']['b']);
        self::assertSame(1, $report['counts']['only_on_b']);
        self::assertSame(1, $report['counts']['passed_both']);
        self::assertArrayNotHasKey('surface_diff', $report);

        // Exit-status mode
        $exitRunner = new AbRunner();
        $exitRunner->status = ['a' => 0, 'b' => 0];
        $exitJson = $this->out . '/exit.json';
        [$exitStatus] = $this->ab($exitRunner, ['standalone', 'standalone-master', '--out', $this->out, '--json', $exitJson, '--', 'true']);
        self::assertSame(0, $exitStatus);
        $exitReport = json_decode((string) file_get_contents($exitJson), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('exit-status', $exitReport['mode']);
        self::assertSame(0, $exitReport['exit_code']);
        self::assertSame(0, $exitReport['counts']['a']);
        self::assertSame([], $exitReport['groups']['passed_both']);
        self::assertSame(0, $exitReport['counts']['passed_both']);
    }

    public function testNameSetsComposeProjectPrefixForBothStacks(): void
    {
        $runner = new AbRunner();
        $runner->reports = [
            'a' => ['T::testOne' => 'passed'],
            'b' => ['T::testOne' => 'passed'],
        ];

        [$status] = $this->ab($runner, ['standalone-6.16', 'standalone-master', '--name', 'review-pr_42', '--out', $this->out, ...self::CHECK]);

        self::assertSame(0, $status);
        self::assertArrayHasKey('review-pr_42-a', $runner->configs);
        self::assertArrayHasKey('review-pr_42-b', $runner->configs);
        self::assertArrayNotHasKey('ck-ab-standalone-6-16-vs-standalone-master-a', $runner->configs);
        self::assertSame(['a', 'b'], $runner->removed);
    }

    public function testSurfaceCapturesDumpsComputesDiffAndIncludesInJson(): void
    {
        $runner = new AbRunner();
        $runner->surfaces = [
            'a' => ['status' => 0, 'stdout' => (string) json_encode([
                'api4' => [['entity' => 'Contact', 'action' => 'get', 'permissions' => ['access CiviCRM']]],
                'api3' => [],
                'routes' => [],
                'afform' => [],
            ]), 'stderr' => ''],
            'b' => ['status' => 0, 'stdout' => (string) json_encode([
                'api4' => [['entity' => 'Contact', 'action' => 'get', 'permissions' => ['administer CiviCRM']]],
                'api3' => [],
                'routes' => [['path' => 'civicrm/open', 'access' => null, 'callback' => 'CRM_Open']],
                'afform' => [],
            ]), 'stderr' => ''],
        ];
        $jsonPath = $this->out . '/ab.json';

        [$status, $output] = $this->ab($runner, ['standalone-6.16', 'standalone-master', '--out', $this->out, '--surface', '--json', $jsonPath, '--', 'true']);

        self::assertSame(0, $status);
        self::assertFileExists("{$this->out}/A.surface.json");
        self::assertFileExists("{$this->out}/B.surface.json");
        self::assertFileExists("{$this->out}/surface-diff.json");
        self::assertStringContainsString('Surface diff:', $output);
        self::assertStringContainsString('route-no-access', $output);
        $report = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $report['surface_diff']['counts']['risk_flags']);
    }

    public function testSurfaceFallsBackToCvScrAndRecordsErrorWhenBothFail(): void
    {
        $surfaceA = (string) json_encode(['api4' => [], 'api3' => [], 'routes' => [], 'afform' => []]);
        $surfaceB = (string) json_encode([
            'api4' => [['entity' => 'Widget', 'action' => 'get', 'permissions' => ['access CiviCRM']]],
            'api3' => [],
            'routes' => [],
            'afform' => [],
        ]);

        // Side A lacks `ck surface` (exits 2) and falls back to `cv scr /opt/civikitchen/toolbelt/lib/surface-dump.php`
        $runner = new AbRunner();
        $runner->surfaces = [
            'a' => ['status' => 2, 'stdout' => '', 'stderr' => 'ck: unknown command: surface'],
            'b' => ['status' => 0, 'stdout' => $surfaceB, 'stderr' => ''],
        ];
        $runner->surfaceFallback = [
            'a' => ['status' => 0, 'stdout' => $surfaceA, 'stderr' => ''],
        ];

        [$status, $output] = $this->ab($runner, ['standalone-6.16', 'standalone-master', '--out', $this->out, '--surface', '--', 'true']);
        self::assertSame(0, $status);
        self::assertFileExists("{$this->out}/A.surface.json");
        self::assertStringContainsString('api4: +1 -0 ~0', $output);
        self::assertContains('a:cv-scr-surface', $runner->surfaceCalls);

        // Both primary and fallback fail on side A -> explicit error recorded, run completes
        $failRunner = new AbRunner();
        $failRunner->surfaces = [
            'a' => ['status' => 2, 'stdout' => '', 'stderr' => 'ck: unknown command: surface'],
            'b' => ['status' => 0, 'stdout' => $surfaceB, 'stderr' => ''],
        ];
        $failRunner->surfaceFallback = [
            'a' => ['status' => 1, 'stdout' => '', 'stderr' => 'No such file or directory'],
        ];
        $jsonPath = $this->out . '/fail-surface.json';

        [$status, $output] = $this->ab($failRunner, ['standalone-6.16', 'standalone-master', '--out', $this->out, '--surface', '--json', $jsonPath, '--', 'true']);
        self::assertSame(0, $status);
        self::assertStringContainsString('surface comparison failed:', $output);
        $diffFile = json_decode((string) file_get_contents("{$this->out}/surface-diff.json"), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $diffFile);
        self::assertStringContainsString('fallback failed', $diffFile['error']);
        $report = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($diffFile, $report['surface_diff']);
    }

    public function testExposeAndPortOverridesPublishPortsAndPrintUrls(): void
    {
        $runner = new AbRunner();
        $jsonPath = $this->out . '/expose.json';

        [$status, $output] = $this->ab(
            $runner,
            ['standalone-6.16', 'standalone-master', '--expose', '--out', $this->out, '--json', $jsonPath, '--', 'true'],
            null,
            static fn(int $port): bool => $port !== 8400,
        );

        self::assertSame(0, $status);
        self::assertStringContainsString("ck ab: A_URL=http://localhost:8401\n", $output);
        self::assertStringContainsString("ck ab: B_URL=http://localhost:8402\n", $output);

        $aApp = $runner->configs['ck-ab-standalone-6-16-vs-standalone-master-a']['services']['app'];
        $bApp = $runner->configs['ck-ab-standalone-6-16-vs-standalone-master-b']['services']['app'];
        self::assertSame(['127.0.0.1:8401:80'], $aApp['ports']);
        self::assertSame('http://localhost:8401', $aApp['environment']['CIVIKITCHEN_SITE_URL']);
        self::assertSame(['127.0.0.1:8402:80'], $bApp['ports']);
        self::assertSame('http://localhost:8402', $bApp['environment']['CIVIKITCHEN_SITE_URL']);

        $report = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['A' => 'http://localhost:8401', 'B' => 'http://localhost:8402'], $report['urls']);
        self::assertArrayNotHasKey('browser_diff', $report);

        // Explicit --port-a / --port-b overrides
        $portRunner = new AbRunner();
        [$portStatus, $portOutput] = $this->ab(
            $portRunner,
            ['standalone-6.16', 'standalone-master', '--port-a', '8450', '--port-b', '8455', '--out', $this->out, '--', 'true'],
        );
        self::assertSame(0, $portStatus);
        self::assertStringContainsString("ck ab: A_URL=http://localhost:8450\n", $portOutput);
        self::assertStringContainsString("ck ab: B_URL=http://localhost:8455\n", $portOutput);
    }

    public function testBrowserProbePagesAnonPagesNoA11yAndJsonOutput(): void
    {
        $runner = new AbRunner();
        $runner->reports = [
            'a' => ['T::testPass' => 'passed'],
            'b' => ['T::testPass' => 'passed'],
        ];
        $calls = [];
        $probe = new BrowserProbe(
            dirname(__DIR__, 2),
            $runner,
            static function (
                string $siteUrl,
                string $outDir,
                string $prefix,
                array $pages,
                array $anonymousPages,
                bool $runA11y,
                array $size,
            ) use (&$calls): array {
                $calls[] = compact('siteUrl', 'outDir', 'prefix', 'pages', 'anonymousPages', 'runA11y', 'size');
                if ($prefix === 'A') {
                    return [
                        'prefix' => 'A',
                        'site' => $siteUrl,
                        'runA11y' => $runA11y,
                        'routes' => [
                            [
                                'route' => 'civicrm/dashboard?reset=1',
                                'mode' => 'auth',
                                'hasCrmContainer' => true,
                                'redirectedToLogin' => false,
                                'consoleErrors' => [],
                                'pageErrors' => [],
                                'networkFailures' => [],
                                'a11yViolations' => [],
                                'screenshot' => 'A-civicrm-dashboard-reset-1.png',
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
                }
                return [
                    'prefix' => 'B',
                    'site' => $siteUrl,
                    'runA11y' => $runA11y,
                    'routes' => [
                        [
                            'route' => 'civicrm/dashboard?reset=1',
                            'mode' => 'auth',
                            'hasCrmContainer' => true,
                            'redirectedToLogin' => false,
                            'consoleErrors' => ['TypeError at http://localhost:8401/dash.js'],
                            'pageErrors' => [],
                            'networkFailures' => [],
                            'a11yViolations' => [],
                            'screenshot' => 'B-civicrm-dashboard-reset-1.png',
                        ],
                        [
                            'route' => 'civicrm/event/info?id=1',
                            'mode' => 'anon',
                            'hasCrmContainer' => false,
                            'redirectedToLogin' => true,
                            'consoleErrors' => [],
                            'pageErrors' => [],
                            'networkFailures' => [],
                            'a11yViolations' => [],
                            'screenshot' => 'B-anon-civicrm-event-info-id-1.png',
                        ],
                    ],
                ];
            },
        );

        $jsonPath = $this->out . '/browser-ab.json';
        [$status, $output] = $this->ab(
            $runner,
            [
                'standalone-6.16',
                'standalone-master',
                '--page',
                '/civicrm/dashboard?reset=1',
                '--anon-page',
                'civicrm/event/info?id=1',
                '--no-a11y',
                '--out',
                $this->out,
                '--json',
                $jsonPath,
                ...self::CHECK,
            ],
            $probe,
            static fn(int $port): bool => true,
        );

        self::assertSame(0, $status);
        self::assertCount(2, $calls);
        self::assertSame('http://localhost:8400', $calls[0]['siteUrl']);
        self::assertSame('http://localhost:8401', $calls[1]['siteUrl']);
        self::assertSame(['civicrm/dashboard?reset=1'], $calls[0]['pages']);
        self::assertSame(['civicrm/event/info?id=1'], $calls[0]['anonymousPages']);
        self::assertFalse($calls[0]['runA11y']);

        self::assertFileExists("{$this->out}/A.browser.json");
        self::assertFileExists("{$this->out}/B.browser.json");
        self::assertFileExists("{$this->out}/browser-diff.json");
        self::assertStringContainsString('Browser diff:', $output);
        self::assertStringContainsString('browser-console-error', $output);
        self::assertStringContainsString('browser-render-regression', $output);

        $report = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['A' => 'http://localhost:8400', 'B' => 'http://localhost:8401'], $report['urls']);
        self::assertSame(1, $report['browser_diff']['counts']['new_console_errors']);
        self::assertSame(2, $report['browser_diff']['counts']['render_regressions']);
        self::assertSame('TypeError at http://localhost/dash.js', $report['browser_diff']['new_console_errors'][0]['message']);
    }

    public function testBrowserProbeFailureRecordsErrorWithoutLosingReport(): void
    {
        $runner = new AbRunner();
        $runner->reports = [
            'a' => ['T::testPass' => 'passed'],
            'b' => ['T::testPass' => 'failed'],
        ];
        $runner->status = ['a' => 0, 'b' => 1];
        $failingProbe = new BrowserProbe(
            dirname(__DIR__, 2),
            $runner,
            static function (): array {
                throw new RuntimeException('playwright image build failed');
            },
        );
        $jsonPath = $this->out . '/probe-fail.json';

        [$status, $output] = $this->ab(
            $runner,
            ['standalone-6.16', 'standalone-master', '--page', 'civicrm/dashboard', '--out', $this->out, '--json', $jsonPath, ...self::CHECK],
            $failingProbe,
            static fn(int $port): bool => true,
        );

        self::assertSame(1, $status);
        self::assertStringContainsString("pass on A, fail on B: 1\n  T::testPass\n", $output);
        self::assertStringContainsString('browser comparison failed:', $output);
        $report = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['T::testPass'], $report['groups']['regression']);
        self::assertArrayHasKey('error', $report['browser_diff']);
        self::assertStringContainsString('playwright image build failed', $report['browser_diff']['error']);
    }

    public function testBrowserModeWithoutCommandAndInvalidBrowserOptions(): void
    {
        $runner = new AbRunner();
        $probe = new BrowserProbe(
            dirname(__DIR__, 2),
            $runner,
            static fn(string $siteUrl, string $outDir, string $prefix): array => [
                'prefix' => $prefix,
                'site' => $siteUrl,
                'runA11y' => true,
                'routes' => [[
                    'route' => 'civicrm/dashboard',
                    'mode' => 'auth',
                    'hasCrmContainer' => true,
                    'redirectedToLogin' => false,
                    'consoleErrors' => [],
                    'pageErrors' => [],
                    'networkFailures' => [],
                    'a11yViolations' => [],
                    'screenshot' => "{$prefix}-civicrm-dashboard.png",
                ]],
            ],
        );

        [$status, $output] = $this->ab(
            $runner,
            ['standalone-6.16', 'standalone-master', '--page', 'civicrm/dashboard', '--out', $this->out],
            $probe,
            static fn(int $port): bool => true,
        );
        self::assertSame(0, $status);
        self::assertStringContainsString('Browser diff: no regressions (1 routes)', $output);

        $invalidCases = [
            [['standalone', 'standalone-beta', '--page', 'admin/search', '--', 'true'], '--page takes a CiviCRM route'],
            [['standalone', 'standalone-beta', '--anon-page', 'user/login', '--', 'true'], '--anon-page takes a CiviCRM route'],
            [['standalone', 'standalone-beta', '--port-a', 'abc', '--', 'true'], '--port-a takes a TCP port'],
            [['standalone', 'standalone-beta', '--port-b', '70000', '--', 'true'], '--port-b takes a TCP port'],
            [['standalone', 'standalone-beta', '--port-a', '8400', '--port-b', '8400', '--', 'true'], 'must be different ports'],
        ];
        foreach ($invalidCases as [$args, $message]) {
            try {
                $this->ab(new AbRunner(), $args);
                self::fail("expected: {$message}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    /**
     * @param list<string> $arguments
     * @param (Closure(int): bool)|null $portFree
     * @return array{int, string}
     */
    private function ab(AbRunner $runner, array $arguments, ?BrowserProbe $probe = null, ?Closure $portFree = null): array
    {
        ob_start();
        try {
            $status = (new AbCommand($runner, dirname(__DIR__, 2), $probe, $portFree))->run($arguments);
            return [$status, (string) ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }
}

/** Docker as `ck ab` drives it: side a is the first image, side b the second. */
final class AbRunner extends Runner
{
    /** @var array<string, array<string, string>> per side, the outcome of each test; no entry means no report */
    public array $reports = [];

    /** @var array<string, int> exit status of the check per side */
    public array $status = ['a' => 0, 'b' => 0];

    /** @var array<string, array<string, mixed>> compose config by project */
    public array $configs = [];

    /** @var list<string> */
    public array $removed = [];

    /** @var list<string> sides the check ran on */
    public array $ran = [];

    public bool $docker = true;
    public bool $existing = false;
    public int $bootStatus = 0;

    /** @var array<string, array{status: int, stdout: string, stderr: string}> */
    public array $surfaces = [];

    /** @var array<string, array{status: int, stdout: string, stderr: string}> */
    public array $surfaceFallback = [];

    /** @var list<string> */
    public array $surfaceCalls = [];

    public function which(string $program): ?string
    {
        return $program === 'docker' && !$this->docker ? null : "/fake/{$program}";
    }

    public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
    {
        $side = $this->side($command[3]);
        if ($command[4] === 'up') {
            return in_array('--wait', $command, true) ? $this->bootStatus : 0;
        }
        if ($command[4] === 'down') {
            $this->removed[] = $side;
            return 0;
        }
        $this->ran[] = $side;
        return $this->status[$side];
    }

    public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        if ($command[1] === 'ps') {
            return ['status' => 0, 'output' => $this->existing ? "abc123\n" : ''];
        }
        if ($command[4] === 'logs') {
            return ['status' => 0, 'output' => "[civikitchen] ERROR: provisioning failed\n"];
        }
        $side = $this->side($command[3]);
        if (!isset($this->reports[$side])) {
            return ['status' => 1, 'output' => 'no such file'];
        }
        $xml = '<testsuites><testsuite name="suite">';
        foreach ($this->reports[$side] as $test => $outcome) {
            [$class, $name] = explode('::', $test);
            $child = ['passed' => '', 'failed' => '<failure>no</failure>', 'error' => '<error>boom</error>', 'skipped' => '<skipped/>'][$outcome];
            $xml .= "<testcase name=\"{$name}\" class=\"{$class}\">{$child}</testcase>";
        }
        file_put_contents((string) end($command), $xml . '</testsuite></testsuites>');
        return ['status' => 0, 'output' => ''];
    }

    public function captureSeparate(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        $side = $this->side($command[3]);
        if (array_slice($command, -2) === ['ck', 'surface']) {
            $this->surfaceCalls[] = "{$side}:ck-surface";
            return $this->surfaces[$side] ?? ['status' => 0, 'stdout' => '{"api4":[],"api3":[],"routes":[],"afform":[]}', 'stderr' => ''];
        }
        if (in_array('/opt/civikitchen/toolbelt/lib/surface-dump.php', $command, true)) {
            $this->surfaceCalls[] = "{$side}:cv-scr-surface";
            return $this->surfaceFallback[$side] ?? ['status' => 1, 'stdout' => '', 'stderr' => 'no surface-dump.php'];
        }
        if (in_array('cv', $command, true)) {
            return ['status' => 0, 'stdout' => $side === 'a' ? "6.16.2\n" : "6.17.beta1\n", 'stderr' => ''];
        }
        return $side === 'a' ? ['status' => 1, 'stdout' => '', 'stderr' => 'cat: no such file'] : ['status' => 0, 'stdout' => "civicrm-core 6.17 abc1234\n", 'stderr' => ''];
    }

    private function side(string $composeFile): string
    {
        $config = json_decode((string) file_get_contents($composeFile), true);
        $this->configs[$config['name']] = $config;
        return substr($config['name'], -1);
    }
}
