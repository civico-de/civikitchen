<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Cli\Application;
use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Runtime\ExtensionArchiveInstaller;
use CiviKitchen\Toolbelt\Runtime\ExtensionProvisioner;
use CiviKitchen\Toolbelt\Runtime\ProfileProvisioner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ProvisionSite.php';

/**
 * A profile's dependencies against a fake site: real git on fixture repositories, cv and
 * composer faked, the extension map stale until refreshed.
 */
final class ProfileProvisionerTest extends TestCase
{
    private const DEMO = 'org.example.demo';
    private const DIGEST_URL = 'https://example.org/dep-1.0.zip';

    private string $temporary;
    private string $ext;
    private string $state;
    private ProvisionSite $site;
    private string $stdout = '';
    private string|false $composerHome;

    /** @var resource */
    private $stderr;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/civikitchen-profile-' . bin2hex(random_bytes(6));
        $this->ext = $this->temporary . '/ext';
        $this->state = "{$this->ext}/.ck-profile-example.replaced";
        self::assertTrue(mkdir($this->ext, 0700, true));
        $this->site = new ProvisionSite($this->ext);
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stderr);
        $this->stderr = $stderr;
        $this->composerHome = getenv('COMPOSER_HOME');
        putenv('COMPOSER_HOME');
    }

    protected function tearDown(): void
    {
        ExtensionArchiveInstaller::removeTree($this->temporary);
        fclose($this->stderr);
        putenv($this->composerHome === false ? 'COMPOSER_HOME' : "COMPOSER_HOME={$this->composerHome}");
    }

    public function testRepoDependencyIsClonedAtItsPinThenTheExtensionMapRefreshed(): void
    {
        [$repo, $commit] = $this->repository();

        self::assertNull($this->attempt($this->profile([self::repo($repo, $commit)])));

        self::assertSame($commit, $this->head("{$this->ext}/" . self::DEMO));
        self::assertSame("==> [example] cloning extensions into {$this->ext}\n  cloning org.example.demo @ {$commit}\n"
            . "==> [example] resolving composer dependencies of git-sourced extensions\n"
            . "==> [example] downloading registry extensions\n==> [example] enabling extensions\n", $this->stdout);
        self::assertSame(['refresh'], $this->site->log);
        self::assertSame([self::DEMO], $this->entries());
        self::assertCount(1, $this->site->moves);
        self::assertSame("{$this->ext}/" . self::DEMO, $this->site->moves[0][1]);
    }

    public function testFailedCheckoutLeavesNoDirectoryBehind(): void
    {
        [$repo] = $this->repository();

        self::assertSame('could not clone org.example.demo at requested ref does-not-exist', $this->attempt($this->profile([self::repo($repo, 'does-not-exist')])));
        self::assertSame([], $this->entries());
        self::assertSame([], $this->site->log);
    }

    public function testRepositoryDeclaringAnotherKeyLeavesNoDirectoryBehind(): void
    {
        [$repo, $commit] = $this->repository();

        self::assertSame('cloned repository does not declare extension key org.example.wrong', $this->attempt($this->profile([self::repo($repo, $commit, 'org.example.wrong')])));
        self::assertSame([], $this->entries());
    }

    public function testCheckoutAtItsPinIsAVerifiedNoOp(): void
    {
        [$repo, $commit] = $this->repository();
        $profile = $this->profile([self::repo($repo, $commit)]);
        self::assertNull($this->attempt($profile));
        $this->stdout = '';
        $this->site->log = [];

        self::assertNull($this->attempt($profile));

        self::assertStringContainsString("  org.example.demo already present at {$commit}\n", $this->stdout);
        self::assertSame([], $this->site->log);
    }

    public function testCheckoutAtAnotherCommitIsRefused(): void
    {
        [$repo, $first] = $this->repository();
        $known = $this->commit($repo);
        self::assertNull($this->attempt($this->profile([self::repo($repo, $first)])));
        $later = $this->commit($repo);

        self::assertSame("{$this->ext}/org.example.demo exists at {$first}, not requested ref {$known}", $this->attempt($this->profile([self::repo($repo, $known)])));
        self::assertSame("{$this->ext}/org.example.demo exists at {$first}, not requested ref {$later}", $this->attempt($this->profile([self::repo($repo, $later)])));
        self::assertSame("{$this->ext}/org.example.demo exists at {$first}, not requested ref missing", $this->attempt($this->profile([self::repo($repo, 'missing')])));
        self::assertSame($first, $this->head("{$this->ext}/" . self::DEMO));
    }

    public function testUninstalledPackagedCopyIsSwappedForThePinAndTheSwapAppended(): void
    {
        [$repo, $commit] = $this->repository();
        $target = $this->packaged($this->ext);
        $this->site->site[self::DEMO] = 'uninstalled';
        file_put_contents($this->state, "/earlier\t/earlier-backup\n");

        self::assertNull($this->attempt($this->profile([self::repo($repo, $commit)])));

        $backup = "{$this->ext}/.org.example.demo.packaged." . getmypid();
        self::assertSame($commit, $this->head($target));
        self::assertSame('packaged', file_get_contents("{$backup}/demo.php"));
        self::assertSame("/earlier\t/earlier-backup\n{$target}\t{$backup}\n", file_get_contents($this->state));
        self::assertStringContainsString("  replacing packaged org.example.demo with pinned commit {$commit}\n", $this->stdout);
        self::assertSame(['refresh'], $this->site->log);
        // mv copies where rename() fails, as from an overlay's lower layer.
        self::assertSame([$target, $backup], $this->site->moves[0]);
        self::assertStringStartsWith("{$this->ext}/.org.example.demo.clone.", $this->site->moves[1][0]);
        self::assertSame($target, $this->site->moves[1][1]);
    }

    public function testFailedSwapMovesThePackagedCopyBack(): void
    {
        [$repo, $commit] = $this->repository();
        $target = $this->packaged($this->ext);
        $this->site->site[self::DEMO] = 'uninstalled';
        $this->site->failingMoves = '/\.clone\./';

        self::assertSame('could not install pinned org.example.demo', $this->attempt($this->profile([self::repo($repo, $commit)])));

        $backup = "{$this->ext}/.org.example.demo.packaged." . getmypid();
        self::assertSame([$backup, $target], $this->site->moves[2]);
        self::assertSame('packaged', file_get_contents("{$target}/demo.php"));
        self::assertFileDoesNotExist($backup);
        self::assertSame([self::DEMO], $this->entries());
    }

    public function testPackagedCopyThatCannotBeMovedAsideStaysWithItsBackupRecordedButAbsent(): void
    {
        [$repo, $commit] = $this->repository();
        $target = $this->packaged($this->ext);
        $this->site->site[self::DEMO] = 'uninstalled';
        $this->site->failingMoves = '/\/org\.example\.demo$/';

        self::assertSame('could not install pinned org.example.demo', $this->attempt($this->profile([self::repo($repo, $commit)])));

        $backup = "{$this->ext}/.org.example.demo.packaged." . getmypid();
        self::assertCount(1, $this->site->moves);
        self::assertSame("{$target}\t{$backup}\n", file_get_contents($this->state));
        self::assertFileDoesNotExist($backup);
        self::assertSame('packaged', file_get_contents("{$target}/demo.php"));
        self::assertSame([self::DEMO], $this->entries());
    }

    public function testCloneThatCannotBeMovedInLeavesNoDirectoryBehind(): void
    {
        [$repo, $commit] = $this->repository();
        $this->site->failingMoves = '/\.clone\./';

        self::assertSame('could not install pinned org.example.demo', $this->attempt($this->profile([self::repo($repo, $commit)])));

        self::assertSame([], $this->entries());
        self::assertFileDoesNotExist($this->state);
    }

    public function testSwapNeverMovesIntoAnExistingBackup(): void
    {
        [$repo, $commit] = $this->repository();
        $target = $this->packaged($this->ext);
        $this->site->site[self::DEMO] = 'uninstalled';
        $backup = "{$this->ext}/.org.example.demo.packaged." . getmypid();
        mkdir($backup);

        self::assertSame("{$backup} already exists", $this->attempt($this->profile([self::repo($repo, $commit)])));

        self::assertSame([], $this->site->moves);
        self::assertFileDoesNotExist($this->state);
        self::assertSame('packaged', file_get_contents("{$target}/demo.php"));
        self::assertSame([self::DEMO], $this->entries());
    }

    public function testSwapThatCannotBeRecordedLeavesThePackagedCopyInPlace(): void
    {
        [$repo, $commit] = $this->repository();
        $target = $this->packaged($this->ext);
        $this->site->site[self::DEMO] = 'uninstalled';
        mkdir($this->state);

        self::assertSame("could not record the replacement of org.example.demo in {$this->state}", $this->attempt($this->profile([self::repo($repo, $commit)])));

        self::assertSame('packaged', file_get_contents("{$target}/demo.php"));
        self::assertSame([self::DEMO], $this->entries());
    }

    public function testPackagedCopyOnAnotherScannedPathIsConvergedWhereItIs(): void
    {
        [$repo, $commit] = $this->repository();
        $target = $this->packaged("{$this->temporary}/core-extensions");
        $this->site->site[self::DEMO] = 'uninstalled';
        $this->site->paths[self::DEMO] = $target;

        self::assertNull($this->attempt($this->profile([self::repo($repo, $commit)])));

        self::assertSame($commit, $this->head($target));
        self::assertDirectoryExists("{$this->temporary}/core-extensions/.org.example.demo.packaged." . getmypid());
        self::assertSame([], $this->entries());
    }

    public function testKeyTheSiteHasWithoutAPathIsNotClonedAgain(): void
    {
        [$repo, $commit] = $this->repository();
        $this->site->site[self::DEMO] = 'installed';
        $this->site->paths[self::DEMO] = '';

        self::assertNull($this->attempt($this->profile([self::repo($repo, $commit)])));

        self::assertStringContainsString("  org.example.demo already present\n", $this->stdout);
        self::assertSame([], $this->entries());
        self::assertSame([], $this->site->log);
    }

    /** @return array<string, array{string, string}> */
    public static function lifecycleRefusals(): array
    {
        return [
            'installed' => ['installed', 'refusing to replace database-installed packaged extension org.example.demo; its lifecycle cannot be rolled back safely'],
            'disabled' => ['disabled', 'refusing to replace database-installed packaged extension org.example.demo; its lifecycle cannot be rolled back safely'],
            'neither installed nor uninstalled' => ['unknown', 'refusing to replace org.example.demo with unknown extension lifecycle status'],
        ];
    }

    /** @dataProvider lifecycleRefusals */
    public function testPackagedCopyTheDatabaseMayHaveUpgradedIsNeverReplaced(string $status, string $error): void
    {
        [$repo, $commit] = $this->repository();
        $target = $this->packaged($this->ext);
        $this->site->site[self::DEMO] = $status;

        self::assertSame($error, $this->attempt($this->profile([self::repo($repo, $commit)])));

        self::assertSame('packaged', file_get_contents("{$target}/demo.php"));
        self::assertSame([self::DEMO], $this->entries());
    }

    /** @return array<string, array{string, string}> */
    public static function statusQueryFailures(): array
    {
        return [
            'the installed query' => ['statuses installed', 'could not determine database lifecycle status for org.example.demo'],
            'the uninstalled query' => ['statuses uninstalled', 'could not determine uninstalled extension status for org.example.demo'],
        ];
    }

    /** @dataProvider statusQueryFailures */
    public function testFailedLifecycleQueryNeverReplaces(string $step, string $error): void
    {
        [$repo, $commit] = $this->repository();
        $target = $this->packaged($this->ext);
        $this->site->site[self::DEMO] = 'uninstalled';
        $this->site->failures[$step] = 1;

        self::assertSame($error, $this->attempt($this->profile([self::repo($repo, $commit)])));

        self::assertSame('packaged', file_get_contents("{$target}/demo.php"));
        self::assertSame([self::DEMO], $this->entries());
    }

    public function testDirectoryCiviCrmDoesNotKnowUnderTheKeyIsNeverReplaced(): void
    {
        [$repo, $commit] = $this->repository();
        $target = $this->packaged($this->ext);
        $error = "{$target} already exists but is not a verifiable git checkout";

        self::assertSame($error, $this->attempt($this->profile([self::repo($repo, $commit)])));

        $this->site->site[self::DEMO] = 'uninstalled';
        file_put_contents("{$target}/info.xml", '<extension key="org.example.other" type="module"/>');
        self::assertSame($error, $this->attempt($this->profile([self::repo($repo, $commit)])));
        self::assertSame('packaged', file_get_contents("{$target}/demo.php"));
    }

    public function testComposerUpdatesACheckoutWithoutALockFileUnderTheDefaultHome(): void
    {
        [$repo, $commit] = $this->repository(['composer.json' => '{"require":{"example/lib":"^1"}}']);

        self::assertNull($this->attempt($this->profile([self::repo($repo, $commit) + ['enable' => true]])));

        self::assertSame([[['update', '--no-dev', '--no-interaction', '--no-progress', "--working-dir={$this->ext}/org.example.demo"], '/tmp/composer']], $this->site->composerRuns);
        self::assertSame(['refresh', 'composer update', 'enable org.example.demo'], $this->site->log);
        self::assertStringContainsString("==> [example] resolving composer dependencies of git-sourced extensions\n  composer update for org.example.demo\n", $this->stdout);
    }

    public function testComposerInstallsFromALockFileUnderTheGivenHome(): void
    {
        [$repo, $commit] = $this->repository(['composer.json' => '{}', 'composer.lock' => '{}']);
        putenv('COMPOSER_HOME=/srv/composer');

        self::assertNull($this->attempt($this->profile([self::repo($repo, $commit)])));

        self::assertSame([[['install', '--no-dev', '--no-interaction', '--no-progress', "--working-dir={$this->ext}/org.example.demo"], '/srv/composer']], $this->site->composerRuns);
    }

    public function testExistingVendorDirectoryIsNotResolvedAgain(): void
    {
        [$repo, $commit] = $this->repository(['composer.json' => '{}']);
        $profile = $this->profile([self::repo($repo, $commit)]);
        self::assertNull($this->attempt($profile));
        $this->site->composer = false;

        self::assertNull($this->attempt($profile));

        self::assertCount(1, $this->site->composerRuns);
        self::assertStringContainsString("  org.example.demo composer dependencies already present\n", $this->stdout);
    }

    public function testCheckoutWithoutComposerJsonNeedsNoComposer(): void
    {
        [$repo, $commit] = $this->repository();
        $this->site->composer = false;

        self::assertNull($this->attempt($this->profile([self::repo($repo, $commit)])));
        self::assertSame([], $this->site->composerRuns);
    }

    public function testMissingComposerStopsBeforeAnythingIsEnabled(): void
    {
        [$repo, $commit] = $this->repository(['composer.json' => '{}']);
        $this->site->composer = false;

        self::assertSame('composer is required to resolve org.example.demo but is not on PATH', $this->attempt($this->profile([self::repo($repo, $commit) + ['enable' => true]])));
        self::assertSame(['refresh'], $this->site->log);
    }

    public function testFailedComposerShowsItsOutputAndStopsBeforeAnythingIsEnabled(): void
    {
        [$repo, $commit] = $this->repository(['composer.json' => '{}']);
        $this->site->failures['composer'] = 1;

        self::assertSame('composer update failed for org.example.demo', $this->attempt($this->profile([self::repo($repo, $commit) + ['enable' => true]])));
        self::assertSame("fixture: could not resolve dependencies\n", $this->errors());
        self::assertSame(['refresh', 'composer update'], $this->site->log);
    }

    public function testRegistryDependencyFindsTheCheckoutItRequiresFromTheSameRun(): void
    {
        [$repo, $commit] = $this->repository();
        $this->feed('org.example.registry', [self::DEMO]);

        self::assertNull($this->attempt($this->profile([self::repo($repo, $commit), ['name' => 'org.example.registry', 'registry' => true]])));

        self::assertSame(['refresh', 'ext:list', 'enable org.example.demo', 'download org.example.registry', 'refresh'], $this->site->log);
        self::assertStringContainsString("==> [example] downloading registry extensions\n  downloading org.example.registry\n", $this->stdout);
    }

    public function testRegistryDependencysRequirementTakesAMountedPin(): void
    {
        $this->feed('org.example.registry', ['org.example.dep']);
        $this->site->archives[self::DIGEST_URL] = $this->archive('org.example.dep');
        $digest = (string) hash_file('sha256', $this->site->archives[self::DIGEST_URL]);
        $mounted = "{$this->temporary}/mounted";
        mkdir($mounted);
        file_put_contents("{$mounted}/civikitchen.yaml", "version: 1\npolicy:\n  extension_sources:\n    - key: org.example.dep\n      version: '^1.0'\n"
            . '      url: ' . self::DIGEST_URL . "\n      sha256: {$digest}\n      reason: not in the feed\n");

        self::assertNull($this->attempt($this->profile([['name' => 'org.example.registry', 'registry' => true]]), [$mounted]));

        self::assertSame(['https://feed.example.org/org.example.registry.zip', self::DIGEST_URL], array_map(static fn (array $curl): string => $curl[count($curl) - 3], $this->site->curls));
        self::assertFileExists("{$this->ext}/org.example.dep/info.xml");
        self::assertSame(['ext:list', 'refresh', 'enable org.example.dep', 'enable org.example.dep', 'download org.example.registry', 'refresh'], $this->site->log);
    }

    public function testRegistryDependencyTheSiteHasIsNotFetched(): void
    {
        $this->site->site['org.example.known'] = 'installed';
        mkdir("{$this->ext}/org.example.unscanned");

        self::assertNull($this->attempt($this->profile([['name' => 'org.example.known', 'registry' => true], ['name' => 'org.example.unscanned', 'registry' => true]])));

        self::assertStringContainsString("  org.example.known already present\n  org.example.unscanned already present\n", $this->stdout);
        self::assertSame([], $this->site->log);
    }

    public function testEnabledOneAtATimeInProfileOrder(): void
    {
        $this->site->site += ['org.example.b' => 'uninstalled', 'org.example.a' => 'uninstalled', 'org.example.fetched' => 'uninstalled'];

        self::assertNull($this->attempt($this->profile([
            ['name' => 'org.example.b', 'enable' => true],
            ['name' => 'org.example.fetched', 'enable' => false],
            ['name' => 'org.example.a', 'enable' => true],
        ])));

        self::assertSame(['enable org.example.b', 'enable org.example.a'], $this->site->log);
    }

    public function testFailedEnableStopsTheOnesAfterIt(): void
    {
        $this->site->site += ['org.example.b' => 'uninstalled', 'org.example.a' => 'uninstalled'];
        $this->site->failures['enable'] = 1;

        self::assertSame('cv ext:enable org.example.b failed', $this->attempt($this->profile([['name' => 'org.example.b', 'enable' => true], ['name' => 'org.example.a', 'enable' => true]])));
        self::assertSame(['enable org.example.b'], $this->site->log);
    }

    public function testDependencySkippedOnThisFrameworkIsLeftAlone(): void
    {
        [$repo, $commit] = $this->repository();
        $profile = $this->profile([self::repo($repo, $commit) + ['enable' => true, 'skipUf' => ['Standalone']]]);

        self::assertNull($this->attempt($profile));
        self::assertSame([], $this->entries());

        self::assertNull($this->attempt($profile, [], 'WordPress'));
        self::assertSame(['refresh', 'enable org.example.demo'], $this->site->log);
    }

    public function testUnlistableSiteStopsBeforeAnyClone(): void
    {
        [$repo, $commit] = $this->repository();
        $this->site->failures['discover'] = 1;

        self::assertSame('could not list the extensions the site can see', $this->attempt($this->profile([self::repo($repo, $commit)])));
        self::assertSame([], $this->entries());
    }

    public function testCliProvisionsAProfileAndFailsAFailedOne(): void
    {
        [$repo, $commit] = $this->repository();
        $application = new Application(self::root() . '/toolbelt/bin', self::root(), $this->site);
        $run = function (string $version) use ($application, $repo): int {
            $this->writeProfile([self::repo($repo, $version) + ['enable' => true]]);
            ob_start();
            try {
                return $application->run(['internal', 'provision-profile', '--ext-dir', $this->ext, '--archive-dir', '',
                    '--replaced-state', $this->state, "{$this->temporary}/example/profile.json", 'Standalone'], 'ck');
            } finally {
                ob_end_clean();
            }
        };

        self::assertSame(1, $run('does-not-exist'));
        self::assertSame(0, $run($commit));
        self::assertSame(['refresh', 'enable org.example.demo'], $this->site->log);
    }

    public function testCliReportsAnUnexpectedErrorAsAFailedProvisioning(): void
    {
        file_put_contents("{$this->temporary}/profile.json", '{"description": "fixture", "dependencies": "none"}');

        $php = PHP_SAPI === 'phpdbg' ? dirname(PHP_BINARY) . '/php' : PHP_BINARY;
        $result = (new Runner())->captureSeparate([$php, self::root() . '/toolbelt/bin/ck', 'internal', 'provision-profile',
            '--ext-dir', $this->ext, '--replaced-state', $this->state, "{$this->temporary}/profile.json", 'Standalone']);

        self::assertSame(1, $result['status'], $result['stderr']);
        self::assertStringStartsWith('  ERROR: ', $result['stderr']);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The step attempt() runs: DEPENDENCIES provisioned as the profile "example".
     *
     * @param list<array<string, mixed>> $dependencies
     */
    private function profile(array $dependencies): Closure
    {
        $profile = $this->writeProfile($dependencies);
        return fn (array $mounted = [], string $uf = 'Standalone') => $this->provisioner($mounted)->provision($profile, $uf);
    }

    /** @param list<array<string, mixed>> $dependencies */
    private function writeProfile(array $dependencies): string
    {
        $directory = "{$this->temporary}/example";
        is_dir($directory) || mkdir($directory);
        file_put_contents("{$directory}/profile.json", json_encode(['description' => 'fixture', 'dependencies' => $dependencies], JSON_THROW_ON_ERROR));
        return "{$directory}/profile.json";
    }

    /** @param list<string> $mounted */
    private function provisioner(array $mounted): ProfileProvisioner
    {
        $extensions = new ExtensionProvisioner(self::root(), $this->ext, '', $mounted, $this->site, static function (): void {
        }, $this->stderr);
        return new ProfileProvisioner(self::root(), $this->ext, $this->state, $extensions, $this->site, $this->stderr);
    }

    /**
     * STEP's failure message, or null when it succeeded; what it printed lands in $this->stdout.
     *
     * @param list<string> $mounted
     */
    private function attempt(Closure $step, array $mounted = [], string $uf = 'Standalone'): ?string
    {
        ob_start();
        try {
            $step($mounted, $uf);
            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        } finally {
            $this->stdout .= (string) ob_get_clean();
        }
    }

    private function errors(): string
    {
        rewind($this->stderr);
        return (string) stream_get_contents($this->stderr);
    }

    /** @return list<string> what the ext dir holds besides the replaced-state file and backups */
    private function entries(): array
    {
        $entries = array_diff(scandir($this->ext) ?: [], ['.', '..', basename($this->state)]);
        return array_values(array_filter($entries, static fn (string $entry): bool => !str_contains($entry, '.packaged.')));
    }

    /**
     * @param array<string, string> $files
     * @return array{string, string} a repository declaring org.example.demo, and its commit
     */
    private function repository(array $files = []): array
    {
        $repo = "{$this->temporary}/repo";
        mkdir($repo);
        file_put_contents("{$repo}/info.xml", '<extension key="org.example.demo" type="module"><file>demo</file></extension>');
        file_put_contents("{$repo}/demo.php", '<?php');
        foreach ($files as $name => $content) {
            file_put_contents("{$repo}/{$name}", $content);
        }
        $this->git($repo, 'init', '-q');
        return [$repo, $this->commit($repo)];
    }

    private function commit(string $repo): string
    {
        file_put_contents("{$repo}/commits", 'x', FILE_APPEND);
        $this->git($repo, 'add', '-A');
        $this->git($repo, '-c', 'user.name=CiviKitchen', '-c', 'user.email=ci@example.org', 'commit', '-qm', 'fixture');
        return $this->git($repo, 'rev-parse', 'HEAD');
    }

    private function head(string $checkout): string
    {
        return $this->git($checkout, 'rev-parse', 'HEAD');
    }

    private function git(string $directory, string ...$arguments): string
    {
        $result = $this->site->captureSeparate(['git', '-C', $directory, ...$arguments]);
        self::assertSame(0, $result['status'], $result['stderr']);
        return trim($result['stdout']);
    }

    /** A packaged org.example.demo below PARENT, without Git metadata. */
    private function packaged(string $parent): string
    {
        $target = "{$parent}/" . self::DEMO;
        mkdir($target, 0777, true);
        file_put_contents("{$target}/info.xml", '<extension key="org.example.demo" type="module"><file>demo</file></extension>');
        file_put_contents("{$target}/demo.php", 'packaged');
        return $target;
    }

    /** @return array<string, mixed> */
    private static function repo(string $repo, string $version, string $key = self::DEMO): array
    {
        return ['name' => $key, 'repo' => $repo, 'version' => $version];
    }

    /** @param list<string> $requires */
    private function feed(string $key, array $requires): void
    {
        $url = "https://feed.example.org/{$key}.zip";
        $this->site->feed[$key] = $url;
        $this->site->archives[$url] = $this->archive($key, $requires);
    }

    /** @param list<string> $requires */
    private function archive(string $key, array $requires = []): string
    {
        $directory = "{$this->temporary}/archives";
        is_dir($directory) || mkdir($directory);
        $path = "{$directory}/{$key}.zip";
        $ext = implode('', array_map(static fn (string $required): string => "<ext>{$required}</ext>", $requires));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE));
        $zip->addEmptyDir($key);
        $zip->addFromString("{$key}/info.xml", "<extension key=\"{$key}\" type=\"module\"><file>x</file><version>1.0.0</version><requires>{$ext}</requires></extension>");
        self::assertTrue($zip->close());
        return $path;
    }
}
