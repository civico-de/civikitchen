<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Cli\Application;
use CiviKitchen\Toolbelt\Runtime\ExtensionArchiveInstaller;
use CiviKitchen\Toolbelt\Runtime\ExtensionProvisioner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ProvisionSite.php';

/**
 * The extension provisioner against a fake site: cv refuses what core refuses, and a real
 * ckconform reads every civikitchen.yaml.
 */
final class ExtensionProvisionerTest extends TestCase
{
    private const DIGEST_URL = 'https://example.org/dep-1.0.zip';
    private const ZERO = '0000000000000000000000000000000000000000000000000000000000000000';

    private string $temporary;
    private string $ext;
    private ProvisionSite $site;
    private string $stdout = '';

    /** @var resource */
    private $stderr;

    /** @var list<int> */
    private array $pauses = [];

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/civikitchen-provision-' . bin2hex(random_bytes(6));
        $this->ext = $this->temporary . '/ext';
        self::assertTrue(mkdir($this->ext, 0700, true));
        $this->site = new ProvisionSite($this->ext);
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stderr);
        $this->stderr = $stderr;
    }

    protected function tearDown(): void
    {
        ExtensionArchiveInstaller::removeTree($this->temporary);
        fclose($this->stderr);
    }

    public function testPinnedRequirementIsVerifiedInstalledAndEnabledFirst(): void
    {
        $this->fixtureRequiringDep(self::sourcePin('org.example.dep', self::DIGEST_URL, $this->digestArchive('1.2.3'), '^1.2'));

        self::assertNull($this->attempt(fn () => $this->provisioner()->enable('fixture')));

        self::assertSame(['refresh', 'enable org.example.dep', 'enable fixture'], $this->site->log);
        self::assertFileExists("{$this->ext}/org.example.dep/info.xml");
        // At most three redirects, and timed out so three attempts bound the job.
        self::assertSame([
            'curl', '--fail', '--silent', '--show-error', '--location', '--proto', '=https', '--proto-redir', '=https', '--max-redirs', '3',
            '--max-filesize', '134217728', '--connect-timeout', '20', '--max-time', '300', self::DIGEST_URL, '-o',
        ], array_slice($this->site->curls[0], 0, -1));
        self::assertStringContainsString("[civikitchen] Enabling extensions: fixture\n", $this->stdout);
        self::assertStringContainsString("[civikitchen]   fixture requires org.example.dep — installing the configured digest-pinned source\n", $this->stdout);
    }

    public function testRequirementKnownOnlyToTheDatabaseIsInstalledAgain(): void
    {
        $this->fixtureRequiringDep(self::sourcePin('org.example.dep', self::DIGEST_URL, $this->digestArchive('1.2.3'), '^1.2'));
        $this->site->site = ['org.example.dep' => 'installed-missing'];

        self::assertNull($this->attempt(fn () => $this->provisioner()->enable('fixture')));

        self::assertSame(['refresh', 'enable org.example.dep', 'enable fixture'], $this->site->log);
        self::assertFileExists("{$this->ext}/org.example.dep/info.xml");
    }

    public function testWrongDigestFailsBeforeCvSeesTheArchiveAndIsNotRetried(): void
    {
        $this->digestArchive('1.2.3');
        $this->fixtureRequiringDep(self::sourcePin('org.example.dep', self::DIGEST_URL, self::ZERO, '^1.2'));

        self::assertSame('checksum mismatch for org.example.dep', $this->attempt(fn () => $this->provisioner()->enable('fixture')));

        self::assertSame([], $this->site->log);
        self::assertCount(1, $this->site->curls);
        self::assertSame([], $this->pauses);
        self::assertDirectoryDoesNotExist("{$this->ext}/org.example.dep");
    }

    public function testMalformedConfigFailsInsteadOfFallingBackToTheRegistry(): void
    {
        $this->feed('org.example.dep');
        $fixture = $this->fixtureRequiringDep("version: 1\npolicy: [\n");

        $error = "could not read {$fixture}/civikitchen.yaml";
        self::assertSame($error, $this->attempt(fn () => $this->provisioner()->enable('fixture')));
        self::assertSame([], $this->site->log);
        self::assertSame([], $this->site->curls);

        // Read before presence is asked: a present requirement does not hide it.
        $this->site->site = ['org.example.dep' => 'installed'];
        self::assertSame($error, $this->attempt(fn () => $this->provisioner()->enable('fixture')));
    }

    public function testPresentRequirementIsOnlyCheckedAgainstItsConstraint(): void
    {
        $this->fixtureRequiringDep(self::sourcePin('org.example.dep', self::DIGEST_URL, self::ZERO, '^1.2'));
        $this->extension('org.example.dep', 'org.example.dep', [], '', '1.3.0');
        $this->site->site = ['org.example.dep' => 'uninstalled'];

        self::assertNull($this->attempt(fn () => $this->provisioner()->enable('fixture')));

        self::assertSame(['enable fixture'], $this->site->log);
        self::assertSame([], $this->site->curls);
    }

    public function testPresentRequirementOutsideItsConstraintFails(): void
    {
        $this->fixtureRequiringDep(self::sourcePin('org.example.dep', self::DIGEST_URL, self::ZERO, '^2.0'));
        $this->extension('org.example.dep', 'org.example.dep', [], '', '1.3.0');
        $this->site->site = ['org.example.dep' => 'uninstalled'];

        self::assertSame('extension version 1.3.0 does not satisfy ^2.0', $this->attempt(fn () => $this->provisioner()->enable('fixture')));
        self::assertSame([], $this->site->log);
    }

    public function testUnpinnedRequirementComesFromTheRegistryAfterItsRequiresWereRead(): void
    {
        $this->feed('org.example.dep');
        $this->fixtureRequiringDep();

        self::assertNull($this->attempt(fn () => $this->provisioner()->enable('fixture')));

        self::assertSame(['ext:list', 'download org.example.dep', 'refresh', 'enable org.example.dep', 'enable fixture'], $this->site->log);
        // Over whichever protocol cv itself downloads it.
        self::assertSame(['--proto', '=http,https', '--proto-redir', '=http,https'], array_slice($this->site->curls[0], 5, 4));
        self::assertContains('https://feed.example.org/org.example.dep.zip', $this->site->curls[0]);
        self::assertStringContainsString("[civikitchen]   fixture requires org.example.dep — installing it from the registry\n", $this->stdout);
    }

    public function testUnreadableRequiresFailInsteadOfReadingAsNone(): void
    {
        $this->feed('org.example.dep');
        $this->fixtureRequiringDep();
        $this->site->failures['requires'] = 1;
        self::assertSame('could not read the <requires> of fixture', $this->attempt(fn () => $this->provisioner()->enable('fixture')));
        self::assertSame([], $this->site->log);

        $this->site->failures['requires'] = 1;
        self::assertSame('could not read the <requires> of org.example.dep', $this->attempt(fn () => $this->provisioner()->extras('org.example.dep')));
        self::assertSame(['ext:list'], $this->site->log);
    }

    public function testPresentButUninstalledRequirementIsEnabledBeforeTheDownload(): void
    {
        // Core refuses the download otherwise; an empty <ext/> names no extension.
        $this->feed('org.example.dep', ['', 'org.example.bundled']);
        $this->site->site = ['org.example.bundled' => 'uninstalled'];
        $this->fixtureRequiringDep();

        self::assertNull($this->attempt(fn () => $this->provisioner()->enable('fixture')));

        self::assertSame(['ext:list', 'enable org.example.bundled', 'download org.example.dep', 'refresh', 'enable org.example.dep', 'enable fixture'], $this->site->log);
    }

    public function testAbsentRequirementOfARegistryDependencyIsDownloadedFirst(): void
    {
        $this->feed('org.example.dep', ['org.example.sub']);
        $this->feed('org.example.sub');
        $this->fixtureRequiringDep();

        self::assertNull($this->attempt(fn () => $this->provisioner()->enable('fixture')));

        self::assertSame([
            'ext:list', 'ext:list', 'download org.example.sub', 'refresh', 'enable org.example.sub', 'enable org.example.sub',
            'download org.example.dep', 'refresh', 'enable org.example.dep', 'enable fixture',
        ], $this->site->log);
    }

    public function testPresentRequirementsOwnRequiresAreInstalledBeforeItIsEnabled(): void
    {
        $this->feed('org.example.e', ['org.example.m']);
        $this->feed('org.example.d');
        $this->extension('org.example.m', 'org.example.m', ['org.example.d']);
        $this->site->site = ['org.example.m' => 'uninstalled'];

        self::assertNull($this->attempt(fn () => $this->provisioner()->extras('org.example.e')));

        self::assertSame([
            'ext:list', 'ext:list', 'download org.example.d', 'refresh', 'enable org.example.d', 'enable org.example.m',
            'download org.example.e', 'refresh', 'enable org.example.e',
        ], $this->site->log);
        self::assertStringContainsString("[civikitchen]   org.example.m requires org.example.d — installing it from the registry\n", $this->stdout);
    }

    public function testRequiresCycleIsNamedInsteadOfRecursingForever(): void
    {
        $this->feed('org.example.dep', ['org.example.sub']);
        $this->feed('org.example.sub', ['org.example.dep']);
        $this->fixtureRequiringDep();

        self::assertSame('<requires> cycle: org.example.dep org.example.sub org.example.dep', $this->attempt(fn () => $this->provisioner()->enable('fixture')));
        self::assertNotContains('download org.example.dep', $this->site->log);
        self::assertNotContains('download org.example.sub', $this->site->log);
    }

    public function testKeyMissingFromTheFeedIsNamedAtOnceAndNeverHandedToCv(): void
    {
        $this->extension('fixture', 'fixture', ['org.example.unlisted']);

        self::assertSame('org.example.unlisted is not in the extension feed', $this->attempt(fn () => $this->provisioner()->enable('fixture')));

        self::assertSame(['ext:list'], $this->site->log);
        self::assertSame([], $this->pauses);
        self::assertSame([], $this->site->curls);
    }

    public function testShortNameFailsAtOnceNamingItsKey(): void
    {
        $this->feed('org.example.widget');
        $this->site->names['org.example.widget'] = 'widget';

        self::assertSame('widget is not an extension key; use org.example.widget', $this->attempt(fn () => $this->provisioner()->extras('widget')));

        self::assertSame(['ext:list'], $this->site->log);
        self::assertSame([], $this->pauses);
        self::assertSame([], $this->site->curls);
    }

    public function testRequirementsCoreDeclaresObsoleteAreLeftOut(): void
    {
        $this->site->obsolete = ['org.example.obsolete'];
        $this->extension('fixture', 'fixture', ['org.example.obsolete']);
        self::assertNull($this->attempt(fn () => $this->provisioner()->enable('fixture')));
        self::assertSame(['enable fixture'], $this->site->log);

        $this->site->log = [];
        $this->feed('org.example.dep', ['org.example.obsolete']);
        $this->fixtureRequiringDep();
        self::assertNull($this->attempt(fn () => $this->provisioner()->enable('fixture')));
        self::assertSame(['ext:list', 'download org.example.dep', 'refresh', 'enable org.example.dep', 'enable fixture'], $this->site->log);
    }

    public function testDownloadedRequirementsOwnConfigNeverPinsAnything(): void
    {
        // An extra has no pin directory: its requirements take the mounted extensions' pins only.
        $mounted = $this->extension('alpha', 'alpha', [], self::config(self::sourcePin('org.example.other', self::DIGEST_URL, self::ZERO, '^1.0')));
        $this->feed('org.example.dep', ['org.example.sub']);
        $this->site->archives['https://feed.example.org/org.example.sub.zip'] = $this->archive('org.example.sub', ['org.example.bundled'], '1.0.0', ['civikitchen.yaml' => "version: 1\npolicy: [\n"]);
        $this->site->feed['org.example.sub'] = 'https://feed.example.org/org.example.sub.zip';
        $this->site->site = ['org.example.bundled' => 'uninstalled'];

        self::assertNull($this->attempt(fn () => $this->provisioner([$mounted])->extras('org.example.dep')));

        self::assertSame([
            'ext:list', 'ext:list', 'enable org.example.bundled', 'download org.example.sub', 'refresh', 'enable org.example.sub', 'enable org.example.sub',
            'download org.example.dep', 'refresh', 'enable org.example.dep',
        ], $this->site->log);
        self::assertSame([$mounted], array_values(array_unique($this->site->policyReads)));
    }

    public function testKeyAtUrlExtraReadsItsRequiresFromThatUrl(): void
    {
        $this->site->archives['https://example.org/own/dep.zip'] = $this->archive('org.example.dep', ['org.example.bundled']);
        $this->site->site = ['org.example.bundled' => 'uninstalled'];

        self::assertNull($this->attempt(fn () => $this->provisioner()->extras('org.example.dep@https://example.org/own/dep.zip')));

        self::assertSame(['enable org.example.bundled', 'download org.example.dep@https://example.org/own/dep.zip', 'refresh', 'enable org.example.dep'], $this->site->log);
        self::assertContains('https://example.org/own/dep.zip', $this->site->curls[0]);
    }

    public function testFailingDownloadIsRetriedThenStopsBeforeTheDependentIsEnabled(): void
    {
        $this->feed('org.example.dep');
        $this->fixtureRequiringDep();
        $this->site->failures['download'] = 99;

        self::assertSame('download of org.example.dep failed after 3 attempts', $this->attempt(fn () => $this->provisioner()->enable('fixture')));

        self::assertSame(['ext:list', 'download org.example.dep', 'download org.example.dep', 'download org.example.dep'], $this->site->log);
        self::assertSame([5, 5], $this->pauses);
        self::assertSame(
            "[civikitchen] WARN: download of org.example.dep failed (attempt 1/3); retrying in 5s...\n"
            . "[civikitchen] WARN: download of org.example.dep failed (attempt 2/3); retrying in 5s...\n",
            $this->errors(),
        );
    }

    public function testFailingExtensionQueryStopsInsteadOfDownloading(): void
    {
        $this->feed('org.example.dep');
        $this->fixtureRequiringDep();
        $this->site->site = ['org.example.dep' => 'installed'];
        $this->site->failures['api4'] = 1;

        self::assertSame('could not query the extension list for org.example.dep', $this->attempt(fn () => $this->provisioner()->enable('fixture')));
        self::assertSame([], $this->site->log);

        $this->site->extensionList = 'Fatal error';
        self::assertStringStartsWith('the extension list is not JSON', (string) $this->attempt(fn () => $this->provisioner()->enable('fixture')));
        self::assertSame([], $this->site->log);
    }

    public function testStagedReleaseIsInstalledWithoutAnyNetworkCall(): void
    {
        $staged = $this->staged('dep-1.2.3.zip', $this->archive('org.example.dep', [], '1.2.3'));
        $this->fixtureRequiringDep(self::releasePin('dep-1.2.3.zip', (string) hash_file('sha256', $staged), '^1.2'));

        self::assertNull($this->attempt(fn () => $this->provisioner([], dirname($staged))->enable('fixture')));

        self::assertSame(['refresh', 'enable org.example.dep', 'enable fixture'], $this->site->log);
        self::assertFileExists("{$this->ext}/org.example.dep/info.xml");
        self::assertSame([], $this->site->curls);
        self::assertStringContainsString("[civikitchen]   fixture requires org.example.dep — installing the staged example-org/dep release v1.2.3\n", $this->stdout);
    }

    public function testStagedRequirementsOwnRequiresResolveFirstPinnedByTheDependent(): void
    {
        $staged = $this->staged('dep-nested.zip', $this->archive('org.example.dep', ['org.example.sub'], '1.2.3'));
        $this->feed('org.example.sub');
        $this->fixtureRequiringDep(self::releasePin('dep-nested.zip', (string) hash_file('sha256', $staged), '^1.2'));
        $this->site->site = ['fixture' => 'uninstalled'];

        self::assertNull($this->attempt(fn () => $this->provisioner([], dirname($staged))->enable('fixture')));

        self::assertSame(['refresh', 'ext:list', 'download org.example.sub', 'refresh', 'enable org.example.sub', 'enable org.example.dep', 'enable fixture'], $this->site->log);
    }

    public function testRequirementBackOnTheMountedExtensionIsACycle(): void
    {
        $staged = $this->staged('dep-cycle.zip', $this->archive('org.example.dep', ['fixture'], '1.2.3'));
        $this->fixtureRequiringDep(self::releasePin('dep-cycle.zip', (string) hash_file('sha256', $staged), '^1.2'));
        $this->site->site = ['fixture' => 'uninstalled'];

        self::assertSame('<requires> cycle: fixture org.example.dep fixture', $this->attempt(fn () => $this->provisioner([], dirname($staged))->enable('fixture')));
        self::assertSame(['refresh'], $this->site->log);
    }

    public function testStagedRequirementBackOnARegistryDependencyIsACycle(): void
    {
        $staged = $this->staged('dep-cycle.zip', $this->archive('org.example.dep', ['org.example.sub'], '1.2.3'));
        $this->feed('org.example.sub', ['org.example.dep']);
        $this->fixtureRequiringDep(self::releasePin('dep-cycle.zip', (string) hash_file('sha256', $staged), '^1.2'));

        self::assertSame('<requires> cycle: org.example.dep org.example.sub org.example.dep', $this->attempt(fn () => $this->provisioner([], dirname($staged))->enable('fixture')));
        self::assertSame(['refresh', 'ext:list'], $this->site->log);
    }

    public function testStagedBytesAreCheckedAgainstThePin(): void
    {
        $staged = $this->staged('dep-1.2.3.zip', $this->archive('org.example.dep', [], '1.2.3'));
        $this->feed('org.example.dep');
        $this->fixtureRequiringDep(self::releasePin('dep-1.2.3.zip', self::ZERO, '^1.2'));

        self::assertSame('checksum mismatch for org.example.dep', $this->attempt(fn () => $this->provisioner([], dirname($staged))->enable('fixture')));

        self::assertSame([], $this->site->log);
        self::assertDirectoryDoesNotExist("{$this->ext}/org.example.dep");
    }

    public function testReleasePinWithoutItsStagedArchiveNamesTheFile(): void
    {
        $staged = $this->staged('dep-1.2.3.zip', $this->archive('org.example.dep', [], '1.2.3'));
        $this->feed('org.example.dep');
        $this->fixtureRequiringDep(self::releasePin('absent-1.2.3.zip', (string) hash_file('sha256', $staged), '^1.2'));

        $error = $this->attempt(fn () => $this->provisioner([], dirname($staged))->enable('fixture'));

        self::assertSame('org.example.dep is pinned to release v1.2.3, but ' . dirname($staged) . '/absent-1.2.3.zip is not there', $error);
        self::assertSame([], $this->site->log);
    }

    public function testReleasePinWithoutAnArchiveDirSaysWhatIsMissing(): void
    {
        $this->feed('org.example.dep');
        $this->fixtureRequiringDep(self::releasePin('dep-1.2.3.zip', self::ZERO, '^1.2'));

        $error = $this->attempt(fn () => $this->provisioner()->enable('fixture'));

        self::assertSame('org.example.dep is pinned to release v1.2.3, which has to be staged — set CK_DEP_ARCHIVE_DIR, or mount org.example.dep into the ext dir', $error);
        self::assertSame([], $this->site->log);
    }

    public function testExtensionWithoutRequiresIsJustEnabled(): void
    {
        $this->extension('fixture', 'fixture');
        self::assertNull($this->attempt(fn () => $this->provisioner()->enable('fixture')));
        self::assertSame(['enable fixture'], $this->site->log);
    }

    public function testDigestPinnedInstallRefusesATraversalKey(): void
    {
        $digest = $this->digestArchive('1.2.3');

        $error = $this->attempt(fn () => $this->provisioner()->extras('../escaped@' . self::DIGEST_URL . "#sha256={$digest}"));

        self::assertSame('digest-pinned download of ../escaped failed', $error);
        self::assertFileDoesNotExist("{$this->temporary}/escaped");
        self::assertStringContainsString('unsafe extension key', $this->errors());
    }

    public function testFailedRefreshRollsTheInstallBackAndTheRetryInstallsAgain(): void
    {
        $spec = 'org.example.dep@' . self::DIGEST_URL . '#sha256=' . $this->digestArchive('1.2.3');
        $this->site->failures['refresh'] = 1;

        self::assertNull($this->attempt(fn () => $this->provisioner()->extras($spec)));

        self::assertSame(['refresh', 'refresh', 'enable org.example.dep'], $this->site->log);
        self::assertCount(2, $this->site->curls);
        self::assertSame([5], $this->pauses);
        self::assertFileExists("{$this->ext}/org.example.dep/info.xml");

        ExtensionArchiveInstaller::removeTree("{$this->ext}/org.example.dep");
        $this->site->failures['refresh'] = 99;
        self::assertSame('digest-pinned download of org.example.dep failed', $this->attempt(fn () => $this->provisioner()->extras($spec)));
        self::assertDirectoryDoesNotExist("{$this->ext}/org.example.dep");
    }

    public function testDigestPinnedFetchIsRetriedThenNamed(): void
    {
        $spec = 'org.example.dep@' . self::DIGEST_URL . '#sha256=' . $this->digestArchive('1.2.3');
        $this->site->failures['curl'] = 2;
        self::assertNull($this->attempt(fn () => $this->provisioner()->extras($spec)));
        self::assertSame([5, 5], $this->pauses);
        self::assertCount(3, $this->site->curls);

        ExtensionArchiveInstaller::removeTree("{$this->ext}/org.example.dep");
        $this->site->failures['curl'] = 3;
        self::assertSame('digest-pinned download of org.example.dep failed', $this->attempt(fn () => $this->provisioner()->extras($spec)));
        self::assertCount(6, $this->site->curls);
    }

    public function testUnanswerablePathStopsProvisioning(): void
    {
        $spec = 'org.example.dep@' . self::DIGEST_URL . '#sha256=' . $this->digestArchive('1.2.3');
        $this->site->failures['path'] = 1;
        self::assertSame('cv path -x org.example.dep failed', $this->attempt(fn () => $this->provisioner()->extras($spec)));
        self::assertNotContains('enable org.example.dep', $this->site->log);
    }

    public function testVersionOutsideThePinnedConstraintLeavesNothingBehind(): void
    {
        $mounted = $this->extension('alpha', 'alpha', [], self::config(self::sourcePin('org.example.dep', self::DIGEST_URL, $this->digestArchive('1.2.3'), '^2.0')));

        $error = $this->attempt(fn () => $this->provisioner([$mounted])->extras('org.example.dep'));

        self::assertSame('digest-pinned download of org.example.dep failed', $error);
        self::assertStringContainsString('extension version 1.2.3 does not satisfy ^2.0', $this->errors());
        self::assertDirectoryDoesNotExist("{$this->ext}/org.example.dep");
    }

    public function testInstalledFilesAreReadableByTheWebServerWhateverTheUmask(): void
    {
        $spec = 'org.example.dep@' . self::DIGEST_URL . '#sha256=' . $this->digestArchive('1.2.3', ['lib/code.php' => '<?php']);
        $umask = umask(0077);
        try {
            self::assertNull($this->attempt(fn () => $this->provisioner()->extras($spec)));
        } finally {
            umask($umask);
        }
        clearstatcache();
        self::assertSame(0755, fileperms("{$this->ext}/org.example.dep") & 0777);
        self::assertSame(0755, fileperms("{$this->ext}/org.example.dep/lib") & 0777);
        self::assertSame(0644, fileperms("{$this->ext}/org.example.dep/lib/code.php") & 0777);
        self::assertSame(0644, fileperms("{$this->ext}/org.example.dep/info.xml") & 0777);
    }

    public function testMountedExtensionsAreEnabledByTheirKeyInTheGivenOrder(): void
    {
        $mounted = [
            $this->extension('alpha', 'alpha'),
            $this->extension('beta-dir', 'org.example.beta'),
            $this->extension('no key', ''),
            $this->temporary . '/ext/workspace',
            '',
        ];
        $this->extension('downloaded', 'downloaded');
        $this->extension('workspace/mine', 'mine');
        file_put_contents("{$this->ext}/no key/info.xml", '<extension');
        $this->site->site = ['org.example.beta' => 'uninstalled'];

        self::assertNull($this->attempt(fn () => $this->provisioner($mounted)->enable('')));

        self::assertSame(['enable alpha', 'enable org.example.beta'], $this->site->log);
        self::assertSame("[civikitchen] Enabling extensions: alpha,org.example.beta\n", $this->stdout);
        // A folder of extensions is no extension and no mistake.
        self::assertSame("[civikitchen] WARN: {$this->ext}/no key is mounted into the ext dir but has no readable info.xml key — not enabled\n", $this->errors());
    }

    public function testListedKeysGoFirstInTheirOrderAndAreNotRepeated(): void
    {
        $mounted = [$this->extension('alpha', 'alpha'), $this->extension('beta-dir', 'org.example.beta')];
        $this->extension('downloaded', 'downloaded');
        $this->site->site = ['org.example.beta' => 'uninstalled'];

        self::assertNull($this->attempt(fn () => $this->provisioner($mounted)->enable("org.example.beta, downloaded,\t,")));

        self::assertSame(['enable org.example.beta', 'enable downloaded', 'enable alpha'], $this->site->log);
        self::assertSame("[civikitchen] Enabling extensions: org.example.beta,downloaded,alpha\n", $this->stdout);
    }

    public function testNothingListedOrMountedDoesNothing(): void
    {
        self::assertNull($this->attempt(fn () => $this->provisioner()->enable(' , ')));
        self::assertNull($this->attempt(fn () => $this->provisioner()->extras('')));
        self::assertSame([], $this->site->log);
        self::assertSame('', $this->stdout);
    }

    public function testBareExtraTakesAMountedPinAndKeyAtUrlPassesThrough(): void
    {
        $digest = $this->digestArchive('2.0.1', [], 'de.example.opt');
        $mounted = $this->extension('alpha', 'alpha', [], self::config(self::sourcePin('de.example.opt', self::DIGEST_URL, $digest, '^2.0')));
        $this->feed('de.example.bare');
        $this->site->archives['https://example.org/own.zip'] = $this->archive('de.example.opt', [], '2.0.2');

        self::assertNull($this->attempt(fn () => $this->provisioner([$mounted])->extras(' de.example.opt,de.example.bare,,de.example.opt@https://example.org/own.zip')));

        self::assertSame([
            'refresh', 'enable de.example.opt',
            'ext:list', 'download de.example.bare', 'refresh', 'enable de.example.bare',
            'download de.example.opt@https://example.org/own.zip', 'refresh', 'enable de.example.opt',
        ], $this->site->log);
        self::assertSame(
            "[civikitchen] Installing configured extra extensions\n"
            . "[civikitchen]   - de.example.opt (digest-pinned)\n"
            . "[civikitchen]   - de.example.bare\n"
            . "[civikitchen]   - de.example.opt\n",
            $this->stdout,
        );
    }

    public function testRequirementOfAnExtraTakesAMountedPin(): void
    {
        $digest = $this->digestArchive('2.0.1', [], 'de.example.opt');
        $mounted = $this->extension('alpha', 'alpha', [], self::config(self::sourcePin('de.example.opt', self::DIGEST_URL, $digest, '^2.0')));
        $this->feed('de.example.bare', ['de.example.opt']);

        self::assertNull($this->attempt(fn () => $this->provisioner([$mounted])->extras('de.example.bare')));

        self::assertSame(['ext:list', 'refresh', 'enable de.example.opt', 'enable de.example.opt', 'download de.example.bare', 'refresh', 'enable de.example.bare'], $this->site->log);
        self::assertFileExists("{$this->ext}/de.example.opt/info.xml");
    }

    public function testFirstMountedExtensionPinningAKeySuppliesItsWholePin(): void
    {
        $digest = $this->digestArchive('1.2.3');
        $source = $this->extension('alpha', 'alpha', [], self::config(self::sourcePin('org.example.dep', self::DIGEST_URL, $digest, '^1.2')));
        $release = $this->extension('beta', 'beta', [], self::config(self::releasePin('dep-1.2.3.zip', self::ZERO, '^9.0')));

        self::assertNull($this->attempt(fn () => $this->provisioner([$source, $release])->extras('org.example.dep')));
        self::assertCount(1, $this->site->curls);
        self::assertStringContainsString('  - org.example.dep (digest-pinned)', $this->stdout);

        ExtensionArchiveInstaller::removeTree("{$this->ext}/org.example.dep");
        $error = $this->attempt(fn () => $this->provisioner([$release, $source])->extras('org.example.dep'));
        self::assertStringStartsWith('org.example.dep is pinned to release v1.2.3, which has to be staged', (string) $error);
    }

    public function testDigestPinnedExtraGetsItsOwnRequiresResolved(): void
    {
        $spec = 'org.example.dep@' . self::DIGEST_URL . '#sha256=' . $this->digestArchive('1.2.3', [], 'org.example.dep', ['org.example.sub']);
        $this->feed('org.example.sub');

        self::assertNull($this->attempt(fn () => $this->provisioner()->extras($spec)));

        self::assertSame(['refresh', 'ext:list', 'download org.example.sub', 'refresh', 'enable org.example.sub', 'enable org.example.dep'], $this->site->log);
        self::assertStringContainsString("[civikitchen]   org.example.dep requires org.example.sub — installing it from the registry\n", $this->stdout);
    }

    public function testStagedExtraGetsItsOwnRequiresResolved(): void
    {
        $staged = $this->staged('dep-1.2.3.zip', $this->archive('org.example.dep', ['org.example.sub'], '1.2.3'));
        $mounted = $this->extension('alpha', 'alpha', [], self::config(self::releasePin('dep-1.2.3.zip', (string) hash_file('sha256', $staged), '^1.2')));
        $this->feed('org.example.sub');

        self::assertNull($this->attempt(fn () => $this->provisioner([$mounted], dirname($staged))->extras('org.example.dep')));

        self::assertSame(['refresh', 'ext:list', 'download org.example.sub', 'refresh', 'enable org.example.sub', 'enable org.example.dep'], $this->site->log);
        self::assertStringContainsString("[civikitchen]   - org.example.dep (staged example-org/dep release v1.2.3)\n", $this->stdout);
    }

    public function testEveryDownloadRunsUnderARaisedHttpTimeoutThatIsAlwaysReset(): void
    {
        $this->feed('org.example.dep', ['org.example.sub']);
        $this->feed('org.example.sub');

        self::assertNull($this->attempt(fn () => $this->provisioner()->fetch('org.example.dep')));

        self::assertSame([
            'vset http_timeout=120', 'ext:download -n --no-install org.example.sub', 'vdel http_timeout',
            'vset http_timeout=120', 'ext:download -n --no-install org.example.dep', 'vdel http_timeout',
        ], $this->downloads());
        // Fetched, not enabled: a profile enables its dependencies in its own order.
        self::assertSame(['ext:list', 'ext:list', 'download org.example.sub', 'refresh', 'enable org.example.sub', 'enable org.example.sub', 'download org.example.dep', 'refresh'], $this->site->log);

        $this->site->raw = [];
        $this->site->failures['download'] = 99;
        self::assertNotNull($this->attempt(fn () => $this->provisioner()->fetch('org.example.dep')));
        self::assertSame('vdel http_timeout', $this->downloads()[count($this->downloads()) - 1]);
    }

    public function testFailedResetAfterAFailedDownloadReportsTheDownload(): void
    {
        $this->feed('org.example.dep');
        $this->site->failures = ['download' => 99, 'vdel' => 1];

        self::assertSame('download of org.example.dep failed after 3 attempts', $this->attempt(fn () => $this->provisioner()->fetch('org.example.dep')));
        self::assertStringEndsWith("[civikitchen] WARN: cv vdel http_timeout failed\n", $this->errors());
    }

    public function testFailedResetAfterADownloadFailsTheDownload(): void
    {
        $this->feed('org.example.dep');
        $this->site->failures['vdel'] = 1;

        self::assertSame('cv vdel http_timeout failed', $this->attempt(fn () => $this->provisioner()->fetch('org.example.dep')));
        self::assertSame(['ext:list', 'download org.example.dep'], $this->site->log);
        self::assertSame('', $this->errors());
    }

    public function testTransientFetchFailuresAreRetried(): void
    {
        $this->feed('org.example.dep');
        $this->site->failures = ['ext:list' => 1, 'curl' => 1];

        self::assertNull($this->attempt(fn () => $this->provisioner()->fetch('org.example.dep')));

        self::assertSame([5, 5], $this->pauses);
        self::assertSame(['ext:list', 'ext:list', 'ext:list', 'download org.example.dep', 'refresh'], $this->site->log);

        $this->site->log = [];
        $this->site->feedList = '<html>';
        self::assertSame('could not fetch org.example.dep to read its <requires>', $this->attempt(fn () => $this->provisioner()->fetch('org.example.dep')));
        self::assertStringContainsString('[civikitchen] WARN: the extension feed is not JSON', $this->errors());
        self::assertSame(['ext:list', 'ext:list', 'ext:list'], $this->site->log);
    }

    public function testRefusedEnableStopsProvisioning(): void
    {
        $this->feed('org.example.dep');
        $this->site->failures['enable'] = 1;

        self::assertSame('cv ext:enable org.example.dep failed', $this->attempt(fn () => $this->provisioner()->extras('org.example.dep,org.example.next')));
        self::assertSame(['ext:list', 'download org.example.dep', 'refresh', 'enable org.example.dep'], $this->site->log);
    }

    public function testTemporaryArchivesAreRemovedOnSuccessAndFailure(): void
    {
        $before = glob(sys_get_temp_dir() . '/civikitchen-extension.*');
        $this->feed('org.example.dep');
        $spec = 'org.example.pinned@' . self::DIGEST_URL . '#sha256=' . $this->digestArchive('1.2.3', [], 'org.example.pinned');
        $staged = $this->staged('dep-1.2.3.zip', $this->archive('org.example.dep', [], '1.2.3'));
        $mounted = $this->extension('alpha', 'alpha', [], self::config(self::releasePin('dep-1.2.3.zip', self::ZERO, '^1.2')));

        self::assertNull($this->attempt(fn () => $this->provisioner()->fetch('org.example.dep')));
        self::assertNull($this->attempt(fn () => $this->provisioner()->extras($spec)));
        self::assertNotNull($this->attempt(fn () => $this->provisioner()->extras('org.example.pinned@' . self::DIGEST_URL . '#sha256=' . self::ZERO)));
        self::assertNotNull($this->attempt(fn () => $this->provisioner([$mounted], dirname($staged))->extras('org.example.dep')));

        self::assertSame($before, glob(sys_get_temp_dir() . '/civikitchen-extension.*'));
    }

    public function testEmptyPathAnswerFallsBackToTheExtDir(): void
    {
        $this->feed('org.example.dep', ['org.example.sub']);
        $this->feed('org.example.sub');
        $this->site->pathOutput = '';

        self::assertNull($this->attempt(fn () => $this->provisioner()->extras('org.example.dep')));

        // The installed copies' requires are read from <ext-dir>/<key>: sub's twice, once as present.
        self::assertSame(['ext:list', 'ext:list', 'download org.example.sub', 'refresh', 'enable org.example.sub', 'enable org.example.sub', 'download org.example.dep', 'refresh', 'enable org.example.dep'], $this->site->log);
        self::assertSame(5, $this->site->requiresReads);
    }

    public function testCliResolvesRequiresFromTheGivenDirectoryWithItsStagedArchives(): void
    {
        $staged = $this->staged('dep-1.2.3.zip', $this->archive('org.example.dep', [], '1.2.3'));
        $this->fixtureRequiringDep(self::releasePin('dep-1.2.3.zip', (string) hash_file('sha256', $staged), '^1.2'));
        $application = new Application(self::root() . '/toolbelt/bin', self::root(), $this->site);

        ob_start();
        $status = $application->run(['internal', 'provision-requires', '--ext-dir', $this->ext, '--archive-dir', dirname($staged), 'fixture'], 'ck');
        ob_end_clean();

        self::assertSame(0, $status);
        self::assertSame(['refresh', 'enable org.example.dep'], $this->site->log);
        self::assertFileExists("{$this->ext}/org.example.dep/info.xml");
    }

    public function testCliEnablesInTheOrderOfItsMountedOptions(): void
    {
        $alpha = $this->extension('alpha', 'alpha');
        $beta = $this->extension('beta', 'org.example.beta');
        $this->site->site = ['org.example.beta' => 'uninstalled'];
        $application = new Application(self::root() . '/toolbelt/bin', self::root(), $this->site);

        ob_start();
        $status = $application->run(['internal', 'provision-enable', '--ext-dir', $this->ext, '--archive-dir', '', '--mounted', $beta, '--mounted', $alpha, ''], 'ck');
        ob_end_clean();

        self::assertSame(0, $status);
        self::assertSame(['enable org.example.beta', 'enable alpha'], $this->site->log);
    }

    public function testCliInstallsExtras(): void
    {
        $this->feed('org.example.dep');
        $application = new Application(self::root() . '/toolbelt/bin', self::root(), $this->site);

        ob_start();
        $status = $application->run(['internal', 'provision-extras', '--ext-dir', $this->ext, '--archive-dir', '', 'org.example.dep'], 'ck');
        ob_end_clean();

        self::assertSame(0, $status);
        self::assertSame(['ext:list', 'download org.example.dep', 'refresh', 'enable org.example.dep'], $this->site->log);
    }

    /** @return array<string, array{list<string>}> */
    public static function invalidProvisionCalls(): array
    {
        return [
            'no ext dir' => [['provision-enable', '']],
            'an empty ext dir' => [['provision-enable', '--ext-dir', '', '']],
            'no list' => [['provision-extras', '--ext-dir', '/ext']],
            'a second list' => [['provision-extras', '--ext-dir', '/ext', 'a', 'b']],
            'a third requires operand' => [['provision-requires', '--ext-dir', '/ext', 'key', '/dir', 'more']],
            'an unknown option' => [['provision-requires', '--ext-dir', '/ext', '--verbose', 'yes', 'key']],
            'an option without its value' => [['provision-requires', '--ext-dir']],
            'two archive dirs' => [['provision-requires', '--ext-dir', '/ext', '--archive-dir', '/a', '--archive-dir', '/b', 'key']],
            'a profile without its replaced state' => [['provision-profile', '--ext-dir', '/ext', '/p/profile.json', 'Standalone']],
            'an empty replaced state' => [['provision-profile', '--ext-dir', '/ext', '--replaced-state', '', '/p/profile.json', 'Standalone']],
            'two replaced states' => [['provision-profile', '--ext-dir', '/ext', '--replaced-state', '/a', '--replaced-state', '/b', '/p/profile.json', 'Standalone']],
            'a profile without its UF' => [['provision-profile', '--ext-dir', '/ext', '--replaced-state', '/s', '/p/profile.json']],
            'a replaced state for another operation' => [['provision-requires', '--ext-dir', '/ext', '--replaced-state', '/s', 'key']],
        ];
    }

    /**
     * @param list<string> $arguments
     * @dataProvider invalidProvisionCalls
     */
    public function testCliRefusesAnInvalidProvisionCall(array $arguments): void
    {
        $application = new Application(self::root() . '/toolbelt/bin', self::root(), $this->site);
        self::assertSame(2, $application->run(['internal', ...$arguments], 'ck'));
        self::assertSame([], $this->site->raw);
    }

    public function testCliFailsAFailedProvisioning(): void
    {
        $this->extension('fixture', 'fixture', ['org.example.dep']);
        $this->site->failures['api4'] = 1;
        $application = new Application(self::root() . '/toolbelt/bin', self::root(), $this->site);

        ob_start();
        $status = $application->run(['internal', 'provision-requires', '--ext-dir', $this->ext, 'fixture', "{$this->ext}/fixture"], 'ck');
        ob_end_clean();

        self::assertSame(1, $status);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @param list<string> $mounted */
    private function provisioner(array $mounted = [], string $archiveDir = ''): ExtensionProvisioner
    {
        $pause = function (int $seconds): void {
            $this->pauses[] = $seconds;
        };
        return new ExtensionProvisioner(self::root(), $this->ext, $archiveDir, $mounted, $this->site, $pause, $this->stderr);
    }

    /** The failure message of STEP, or null when it succeeded; what it printed lands in $this->stdout. */
    private function attempt(Closure $step): ?string
    {
        ob_start();
        try {
            $step();
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

    /** @return list<string> the raw cv calls around the downloads */
    private function downloads(): array
    {
        return array_values(array_filter($this->site->raw, static fn (string $call): bool => preg_match('/^(vset|vdel|ext:download) /', $call) === 1));
    }

    /** The mounted extension "fixture" in the ext dir, requiring org.example.dep. */
    private function fixtureRequiringDep(string $config = ''): string
    {
        return $this->extension('fixture', 'fixture', ['org.example.dep'], $config === '' || str_starts_with($config, 'version:') ? $config : self::config($config));
    }

    /** @param list<string> $requires */
    private function extension(string $name, string $key, array $requires = [], string $config = '', string $version = '1.0.0'): string
    {
        $directory = "{$this->ext}/{$name}";
        is_dir($directory) || mkdir($directory, 0777, true);
        file_put_contents("{$directory}/info.xml", self::info($key, $requires, $version));
        if ($config !== '') {
            file_put_contents("{$directory}/civikitchen.yaml", $config);
        }
        return $directory;
    }

    /** @param list<string> $requires */
    private function feed(string $key, array $requires = []): void
    {
        $url = "https://feed.example.org/{$key}.zip";
        $this->site->feed[$key] = $url;
        $this->site->archives[$url] = $this->archive($key, $requires);
    }

    /**
     * The archive behind DIGEST_URL; its sha256.
     *
     * @param array<string, string> $files
     * @param list<string> $requires
     */
    private function digestArchive(string $version, array $files = [], string $key = 'org.example.dep', array $requires = []): string
    {
        $this->site->archives[self::DIGEST_URL] = $this->archive($key, $requires, $version, $files);
        return (string) hash_file('sha256', $this->site->archives[self::DIGEST_URL]);
    }

    private function staged(string $asset, string $archive): string
    {
        $directory = "{$this->temporary}/staged";
        is_dir($directory) || mkdir($directory);
        self::assertTrue(copy($archive, "{$directory}/{$asset}"));
        return "{$directory}/{$asset}";
    }

    /**
     * @param list<string> $requires
     * @param array<string, string> $files
     */
    private function archive(string $key, array $requires = [], string $version = '1.0.0', array $files = []): string
    {
        $directory = "{$this->temporary}/archives";
        is_dir($directory) || mkdir($directory);
        $path = $directory . '/' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE));
        $zip->addEmptyDir($key);
        $zip->addFromString("{$key}/info.xml", self::info($key, $requires, $version));
        foreach ($files as $name => $content) {
            $zip->addFromString("{$key}/{$name}", $content);
        }
        self::assertTrue($zip->close());
        return $path;
    }

    /** @param list<string> $requires */
    private static function info(string $key, array $requires, string $version): string
    {
        $ext = implode('', array_map(static fn (string $required): string => "<ext>{$required}</ext>", $requires));
        return "<?xml version=\"1.0\"?>\n<extension key=\"{$key}\" type=\"module\"><file>x</file><version>{$version}</version><requires>{$ext}</requires></extension>\n";
    }

    private static function config(string ...$pins): string
    {
        return "version: 1\npolicy:\n  extension_sources:\n" . implode('', $pins);
    }

    private static function sourcePin(string $key, string $url, string $digest, string $version): string
    {
        return "    - key: {$key}\n      version: '{$version}'\n      url: {$url}\n      sha256: {$digest}\n      reason: not in the feed\n";
    }

    private static function releasePin(string $asset, string $digest, string $version): string
    {
        return "    - key: org.example.dep\n      version: '{$version}'\n      release:\n        repository: example-org/dep\n        tag: v1.2.3\n"
            . "        asset: {$asset}\n      sha256: {$digest}\n      reason: private repository, no registry serves it\n";
    }
}
