<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Cli\SurfaceCommand;
use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Surface\SurfaceDiff;
use PHPUnit\Framework\TestCase;

final class SurfaceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = Directory::temporary('civikitchen-surface');
    }

    protected function tearDown(): void
    {
        Directory::remove($this->tempDir);
    }

    public function testIdenticalSurfacesProduceEmptyDiffEvenIfUnsorted(): void
    {
        $a = [
            'api4' => [
                ['entity' => 'Contact', 'action' => 'get', 'permissions' => ['access CiviCRM']],
                ['entity' => 'Activity', 'action' => 'get', 'permissions' => ['access CiviCRM']],
            ],
            'api3' => [
                ['entity' => 'Contact', 'action' => 'get', 'permissions' => ['access CiviCRM']],
            ],
            'routes' => [
                ['path' => 'civicrm/z', 'access' => [['access CiviCRM'], 'and'], 'callback' => 'CRM_Z'],
                ['path' => 'civicrm/a', 'access' => [['administer CiviCRM'], 'and'], 'callback' => 'CRM_A'],
            ],
            'afform' => [
                ['name' => 'afWidget', 'server_route' => 'civicrm/widget', 'permission' => 'access CiviCRM', 'is_public' => false],
            ],
        ];
        $b = [
            'api4' => array_reverse($a['api4']),
            'api3' => $a['api3'],
            'routes' => array_reverse($a['routes']),
            'afform' => $a['afform'],
        ];

        $diff = SurfaceDiff::compare($a, $b);

        self::assertFalse($diff->hasChanges());
        self::assertFalse($diff->hasRiskFlags());
        self::assertSame("Surface diff: no changes\n", $diff->formatSummary());
        $data = $diff->toArray();
        self::assertSame([], $data['risk_flags']);
        self::assertSame(0, $data['counts']['risk_flags']);
    }

    public function testDetectsAdditionsRemovalsChangesAndRiskFlagsAcrossCategories(): void
    {
        $before = [
            'api4' => [
                ['entity' => 'Contact', 'action' => 'get', 'permissions' => ['access CiviCRM']],
                ['entity' => 'Contact', 'action' => 'delete', 'permissions' => ['delete contacts']],
                ['entity' => 'Setting', 'action' => 'get', 'permissions' => ['administer CiviCRM']],
                ['entity' => 'Legacy', 'action' => 'run', 'permissions' => ['access CiviCRM']],
            ],
            'api3' => [
                ['entity' => 'Widget', 'action' => 'create', 'permissions' => ['administer CiviCRM']],
                ['entity' => 'Widget', 'action' => 'old', 'permissions' => ['access CiviCRM']],
            ],
            'routes' => [
                ['path' => 'civicrm/admin/widget', 'access' => [['administer CiviCRM'], 'and'], 'callback' => 'CRM_Widget_Admin'],
                ['path' => 'civicrm/widget/view', 'access' => [['access CiviCRM'], 'and'], 'callback' => 'CRM_Widget_View'],
                ['path' => 'civicrm/widget/legacy', 'access' => [['access CiviCRM'], 'and'], 'callback' => 'CRM_Widget_Legacy'],
            ],
            'afform' => [
                ['name' => 'afWidgetInternal', 'server_route' => 'civicrm/af-internal', 'permission' => 'access CiviCRM', 'is_public' => false],
                ['name' => 'afWidgetPublicOld', 'server_route' => 'civicrm/af-public-old', 'permission' => null, 'is_public' => true],
                ['name' => 'afWidgetRemoved', 'server_route' => null, 'permission' => 'access CiviCRM', 'is_public' => false],
            ],
        ];

        $after = [
            'api4' => [
                // Tightened permission -> rg-perm
                ['entity' => 'Contact', 'action' => 'get', 'permissions' => ['view all contacts']],
                // Weakened to null -> api-perm-weakened
                ['entity' => 'Contact', 'action' => 'delete', 'permissions' => null],
                // Weakened to baseline access CiviCRM -> api-perm-weakened
                ['entity' => 'Setting', 'action' => 'get', 'permissions' => ['access CiviCRM']],
                // Added without permissions -> api-no-permissions
                ['entity' => 'Greeter', 'action' => 'ping', 'permissions' => []],
            ],
            'api3' => [
                // Weakened via OR -> api-perm-weakened
                ['entity' => 'Widget', 'action' => 'create', 'permissions' => ['or' => [['administer CiviCRM', 'edit all contacts']]]],
                // Added with permission -> no risk flag
                ['entity' => 'Widget', 'action' => 'get', 'permissions' => ['access CiviCRM']],
            ],
            'routes' => [
                // Widened to access CiviCRM -> route-perm-weakened
                ['path' => 'civicrm/admin/widget', 'access' => [['access CiviCRM'], 'and'], 'callback' => 'CRM_Widget_Admin'],
                // Tightened -> rg-perm
                ['path' => 'civicrm/widget/view', 'access' => [['view all contacts'], 'and'], 'callback' => 'CRM_Widget_View'],
                // Added without access_arguments -> route-no-access
                ['path' => 'civicrm/widget/open', 'access' => null, 'callback' => 'CRM_Widget_Open'],
                // Added with access_arguments -> no risk flag
                ['path' => 'civicrm/widget/safe', 'access' => [['access CiviCRM'], 'and'], 'callback' => 'CRM_Widget_Safe'],
            ],
            'afform' => [
                // Made public -> afform-made-public
                ['name' => 'afWidgetInternal', 'server_route' => 'civicrm/af-internal', 'permission' => null, 'is_public' => true],
                // Public restricted -> rg-perm
                ['name' => 'afWidgetPublicOld', 'server_route' => 'civicrm/af-public-old', 'permission' => 'access CiviCRM', 'is_public' => false],
                // Added public Afform -> public-afform-added
                ['name' => 'afWidgetPublicNew', 'server_route' => 'civicrm/af-public-new', 'permission' => null, 'is_public' => true],
                // Added non-public with route and no permission -> afform-no-permission
                ['name' => 'afWidgetUnguarded', 'server_route' => 'civicrm/af-unguarded', 'permission' => null, 'is_public' => false],
            ],
        ];

        $diff = SurfaceDiff::compare($before, $after);
        self::assertTrue($diff->hasChanges());
        self::assertTrue($diff->hasRiskFlags());

        $array = $diff->toArray();
        self::assertSame(1, $array['counts']['api4']['added']);
        self::assertSame(1, $array['counts']['api4']['removed']);
        self::assertSame(3, $array['counts']['api4']['changed']);
        self::assertSame(1, $array['counts']['api3']['added']);
        self::assertSame(1, $array['counts']['api3']['removed']);
        self::assertSame(1, $array['counts']['api3']['changed']);
        self::assertSame(2, $array['counts']['routes']['added']);
        self::assertSame(1, $array['counts']['routes']['removed']);
        self::assertSame(2, $array['counts']['routes']['changed']);
        self::assertSame(2, $array['counts']['afform']['added']);
        self::assertSame(1, $array['counts']['afform']['removed']);
        self::assertSame(2, $array['counts']['afform']['changed']);

        $flagsByTarget = [];
        foreach ($diff->riskFlags() as $flag) {
            $flagsByTarget[$flag['category'] . ':' . $flag['target']][] = $flag['code'];
        }

        self::assertSame(['rg-perm'], $flagsByTarget['api4:Contact.get']);
        self::assertSame(['api-perm-weakened'], $flagsByTarget['api4:Contact.delete']);
        self::assertSame(['api-perm-weakened'], $flagsByTarget['api4:Setting.get']);
        self::assertSame(['api-no-permissions'], $flagsByTarget['api4:Greeter.ping']);
        self::assertSame(['api-perm-weakened'], $flagsByTarget['api3:Widget.create']);
        self::assertSame(['route-perm-weakened'], $flagsByTarget['routes:civicrm/admin/widget']);
        self::assertSame(['rg-perm'], $flagsByTarget['routes:civicrm/widget/view']);
        self::assertSame(['route-no-access'], $flagsByTarget['routes:civicrm/widget/open']);
        self::assertSame(['afform-made-public', 'afform-perm-weakened'], $flagsByTarget['afform:afWidgetInternal']);
        self::assertSame(['rg-perm', 'rg-perm'], $flagsByTarget['afform:afWidgetPublicOld']);
        self::assertSame(['public-afform-added'], $flagsByTarget['afform:afWidgetPublicNew']);
        self::assertSame(['afform-no-permission'], $flagsByTarget['afform:afWidgetUnguarded']);

        $summary = $diff->formatSummary();
        self::assertStringContainsString('api4: +1 -1 ~3', $summary);
        self::assertStringContainsString('Risk flags (', $summary);
        self::assertStringContainsString('public-afform-added', $summary);
        self::assertStringContainsString('rg-perm', $summary);
    }

    public function testIgnoresReorderedPermissionsAndCallbackOnlyRouteChangeInRiskFlags(): void
    {
        $before = [
            'api4' => [
                ['entity' => 'Contact', 'action' => 'get', 'permissions' => ['access CiviCRM', 'view all contacts']],
            ],
            'routes' => [
                ['path' => 'civicrm/widget', 'access' => [['access CiviCRM'], 'and'], 'callback' => 'CRM_Old'],
            ],
        ];
        $after = [
            'api4' => [
                ['entity' => 'Contact', 'action' => 'get', 'permissions' => ['view all contacts', 'access CiviCRM']],
            ],
            'routes' => [
                ['path' => 'civicrm/widget', 'access' => [['access CiviCRM'], 'and'], 'callback' => 'CRM_New'],
            ],
        ];

        $diff = SurfaceDiff::compare($before, $after);
        self::assertTrue($diff->hasChanges());
        self::assertFalse($diff->hasRiskFlags());
    }

    public function testSubsetPermissionWeakeningAndTightening(): void
    {
        $before = [
            'api4' => [
                ['entity' => 'A', 'action' => 'weak', 'permissions' => ['access CiviCRM', 'edit all contacts']],
                ['entity' => 'A', 'action' => 'tight', 'permissions' => ['access CiviCRM', 'edit all contacts']],
                ['entity' => 'A', 'action' => 'open', 'permissions' => []],
            ],
        ];
        $after = [
            'api4' => [
                ['entity' => 'A', 'action' => 'weak', 'permissions' => ['edit all contacts']],
                ['entity' => 'A', 'action' => 'tight', 'permissions' => ['access CiviCRM', 'edit all contacts', 'administer CiviCRM']],
                ['entity' => 'A', 'action' => 'open', 'permissions' => ['access CiviCRM']],
            ],
        ];

        $diff = SurfaceDiff::compare($before, $after);
        $codes = array_column($diff->riskFlags(), 'code', 'target');
        self::assertSame('api-perm-weakened', $codes['A.weak']);
        self::assertSame('rg-perm', $codes['A.tight']);
        self::assertSame('rg-perm', $codes['A.open']);
    }

    public function testRejectsInvalidSurfacePayloads(): void
    {
        try {
            SurfaceDiff::fromFiles($this->tempDir . '/missing.json', $this->tempDir . '/b.json');
            self::fail('expected missing file exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('does not exist', $e->getMessage());
        }

        foreach (['not json', '{}', '{"other":[]}', '{"api4":"bad"}'] as $bad) {
            try {
                SurfaceDiff::decodeJson($bad, 'fixture');
                self::fail("expected exception for: {$bad}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString('fixture', $e->getMessage());
            }
        }
    }

    public function testFormatSummaryTruncatesLongLists(): void
    {
        $items = [];
        for ($i = 1; $i <= 12; $i++) {
            $items[] = ['entity' => 'Item', 'action' => "act{$i}", 'permissions' => ['access CiviCRM']];
        }
        $diff = SurfaceDiff::compare(['api4' => []], ['api4' => $items]);
        self::assertStringContainsString('... (+2 more)', $diff->formatSummary());
    }

    public function testSurfaceCommandDiffModesAndFlags(): void
    {
        $fileA = $this->tempDir . '/A.json';
        $fileB = $this->tempDir . '/B.json';
        $fileRiskB = $this->tempDir . '/B-risk.json';
        $outFile = $this->tempDir . '/nested/diff.json';

        $base = ['api4' => [['entity' => 'Contact', 'action' => 'get', 'permissions' => ['access CiviCRM']]], 'api3' => [], 'routes' => [], 'afform' => []];
        $safeB = ['api4' => [
            ['entity' => 'Contact', 'action' => 'get', 'permissions' => ['access CiviCRM']],
            ['entity' => 'Widget', 'action' => 'get', 'permissions' => ['access CiviCRM']],
        ], 'api3' => [], 'routes' => [], 'afform' => []];
        $riskB = ['api4' => [
            ['entity' => 'Contact', 'action' => 'get', 'permissions' => null],
        ], 'api3' => [], 'routes' => [], 'afform' => []];

        file_put_contents($fileA, json_encode($base));
        file_put_contents($fileB, json_encode($safeB));
        file_put_contents($fileRiskB, json_encode($riskB));

        $runner = new SurfaceRunner();

        // --help
        [$status, $output] = $this->surface($runner, ['--help']);
        self::assertSame(0, $status);
        self::assertStringContainsString('ck surface --diff', $output);

        // Clean / non-risk diff with --fail-on-risk still exits 0
        [$status, $output] = $this->surface($runner, ['--diff', $fileA, $fileB, '--fail-on-risk']);
        self::assertSame(0, $status);
        self::assertStringContainsString('api4: +1 -0 ~0', $output);

        // Risk diff without --fail-on-risk exits 0
        [$status, $output] = $this->surface($runner, ['--diff', $fileA, $fileRiskB]);
        self::assertSame(0, $status);
        self::assertStringContainsString('api-perm-weakened', $output);

        // Risk diff with --fail-on-risk and --json exits 1 and outputs valid JSON
        [$status, $output] = $this->surface($runner, ['--diff', $fileA, $fileRiskB, '--json', '--fail-on-risk']);
        self::assertSame(1, $status);
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $decoded['counts']['risk_flags']);
        self::assertSame('api-perm-weakened', $decoded['risk_flags'][0]['code']);

        // --out writes to file
        [$status, $output] = $this->surface($runner, ['--diff', $fileA, $fileB, '--json', '--out', $outFile]);
        self::assertSame(0, $status);
        self::assertSame('', $output);
        self::assertFileExists($outFile);
    }

    public function testSurfaceCommandContainerDumpAndValidation(): void
    {
        $payload = json_encode(['api4' => [], 'api3' => [], 'routes' => [], 'afform' => []]);
        $runner = new SurfaceRunner(stdout: (string) $payload);

        [$status, $output] = $this->surface($runner, []);
        self::assertSame(0, $status);
        self::assertSame($payload . "\n", $output);
        self::assertSame(['cv', 'scr', dirname(__DIR__, 2) . '/toolbelt/lib/surface-dump.php'], $runner->ran);

        $outFile = $this->tempDir . '/dump.json';
        [$status, $output] = $this->surface($runner, ['--out', $outFile]);
        self::assertSame(0, $status);
        self::assertSame('', $output);
        self::assertSame($payload . "\n", file_get_contents($outFile));

        $cases = [
            [['--unknown'], 'unknown option --unknown'],
            [['--diff', 'only-one.json'], '--diff requires two surface JSON files'],
            [['extra.json'], 'unexpected argument: extra.json'],
            [['--out'], '--out needs a file path'],
            [['--fail-on-risk'], '--fail-on-risk is only valid with --diff'],
        ];
        foreach ($cases as [$args, $message]) {
            try {
                $this->surface($runner, $args);
                self::fail("expected: {$message}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }

        // Missing cv
        $noCv = new SurfaceRunner(hasCv: false);
        try {
            $this->surface($noCv, []);
            self::fail('expected missing cv failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('requires cv', $e->getMessage());
        }

        // cv exits 0 but CiviCRM failed to boot (empty stdout)
        $emptyBoot = new SurfaceRunner(stdout: '');
        try {
            $this->surface($emptyBoot, []);
            self::fail('expected empty boot failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('surface dump', $e->getMessage());
        }

        // cv exits non-zero
        $failingCv = new SurfaceRunner(status: 1, stderr: 'boot error');
        try {
            $this->surface($failingCv, []);
            self::fail('expected cv failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('boot error', $e->getMessage());
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function surface(SurfaceRunner $runner, array $arguments): array
    {
        ob_start();
        try {
            $status = (new SurfaceCommand(dirname(__DIR__, 2), $runner))->run($arguments);
            return [$status, (string) ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }
}

final class SurfaceRunner extends Runner
{
    /** @var list<string> */
    public array $ran = [];

    public function __construct(
        private readonly bool $hasCv = true,
        private readonly int $status = 0,
        private readonly string $stdout = '',
        private readonly string $stderr = '',
    ) {
    }

    public function which(string $program): ?string
    {
        return $program === 'cv' && !$this->hasCv ? null : "/fake/{$program}";
    }

    public function captureSeparate(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        $this->ran = $command;
        return ['status' => $this->status, 'stdout' => $this->stdout, 'stderr' => $this->stderr];
    }
}
