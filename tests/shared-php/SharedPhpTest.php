<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Cli\AnalyzeCommand;
use CiviKitchen\Toolbelt\Cli\Application;
use CiviKitchen\Toolbelt\Cli\CompatibilityCommand;
use CiviKitchen\Toolbelt\Cli\CoverageCommand;
use CiviKitchen\Toolbelt\Cli\FormatCommand;
use CiviKitchen\Toolbelt\Cli\InternalRuntimeCommand;
use CiviKitchen\Toolbelt\Cli\LintCommand;
use CiviKitchen\Toolbelt\Cli\MigrateCommand;
use CiviKitchen\Toolbelt\Cli\ReleaseCommand;
use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\JavaScript\Api4CatalogExport;
use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\CiviCore;
use CiviKitchen\Toolbelt\Repository\Files;
use CiviKitchen\Toolbelt\Repository\PhpFloor;
use CiviKitchen\Toolbelt\Runtime\ExtensionInspector;
use CiviKitchen\Toolbelt\Runtime\ExtensionArchiveInstaller;
use CiviKitchen\Toolbelt\Runtime\ProfileData;
use CiviKitchen\Toolbelt\Runtime\VendorInstaller;
use CiviKitchen\Toolbelt\Scaffold\ExtensionEditor;
use PHPUnit\Framework\TestCase;

final class SharedPhpTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = Directory::temporary('civikitchen-shared-php');
    }

    protected function tearDown(): void
    {
        Directory::remove($this->temporary);
    }

    public function testApplicationRoutesHelpForEveryCommandThatOffersIt(): void
    {
        $application = new Application(dirname(__DIR__, 2) . '/toolbelt/bin', dirname(__DIR__, 2));
        foreach (['ci', 'civix', 'compatibility', 'coverage', 'demo', 'dependencies', 'format', 'javascript', 'lifecycle',
            'lint', 'mutate', 'playwright', 'profile', 'release', 'schema', 'smarty', 'test'] as $command) {
            ob_start();
            $status = $application->run([$command, '--help'], 'ck');
            $output = (string) ob_get_clean();
            self::assertSame(0, $status, $command);
            self::assertNotSame('', $output, $command);
        }
        ob_start();
        self::assertSame(0, $application->run([], 'ck'));
        self::assertStringContainsString('CiviKitchen', (string) ob_get_clean());

        foreach (['coverage', 'mutate', 'test'] as $command) {
            self::assertSame(2, $application->run([$command], 'ck'), $command);
        }
        self::assertSame(2, $application->run(['internal', 'unknown-operation'], 'ck'));
        self::assertSame(2, $application->run(['definitely-unknown'], 'ck'));
    }

    public function testSentinelProbeTellsAbsentFromUnanswerable(): void
    {
        // Only "no such database" and "no such table" mean the sentinel was never written.
        self::assertSame(InternalRuntimeCommand::SENTINEL_ABSENT, InternalRuntimeCommand::sentinelStatus(false, 1049));
        self::assertSame(InternalRuntimeCommand::SENTINEL_ABSENT, InternalRuntimeCommand::sentinelStatus(false, 1146));
        // Access denied, lost connection, or no errno at all: the question is open.
        self::assertSame(InternalRuntimeCommand::SENTINEL_UNKNOWN, InternalRuntimeCommand::sentinelStatus(false, 1142));
        self::assertSame(InternalRuntimeCommand::SENTINEL_UNKNOWN, InternalRuntimeCommand::sentinelStatus(false, 2013));
        self::assertSame(InternalRuntimeCommand::SENTINEL_UNKNOWN, InternalRuntimeCommand::sentinelStatus(false, 0));
        self::assertNotSame(InternalRuntimeCommand::SENTINEL_ABSENT, InternalRuntimeCommand::SENTINEL_UNKNOWN);
    }

    public function testRunnerCapturesRedirectsAndPassesThroughProcesses(): void
    {
        $runner = new Runner();
        self::assertSame(['status' => 0, 'output' => "hello\n"], $runner->capture(['printf', "hello\n"]));
        self::assertSame(7, $runner->capture(['sh', '-c', 'printf failed; exit 7'])['status']);
        $output = $this->temporary . '/output';
        self::assertSame(0, $runner->redirect(['printf', 'redirected'], $output));
        self::assertSame('redirected', file_get_contents($output));
        self::assertSame(0, $runner->passthrough(['true']));
    }

    /** proc_open searches the parent's PATH (posix_spawnp/execvpe), never the one passed to the child. */
    public function testRunnerResolvesTheProgramAgainstTheParentPath(): void
    {
        $bin = $this->temporary . '/bin';
        mkdir($bin);
        file_put_contents($bin . '/ck-env-probe', "#!/bin/sh\nprintf probe\n");
        chmod($bin . '/ck-env-probe', 0755);
        $runner = new Runner();
        $onlyInChild = $runner->capture(['ck-env-probe'], ['PATH' => $bin]);
        self::assertSame(2, $onlyInChild['status']);
        self::assertSame('ck: ck-env-probe was not found on PATH (' . getenv('PATH') . ")\n", $onlyInChild['output']);
        self::assertSame(['status' => 0, 'output' => ''], $runner->capture(['true'], ['PATH' => $bin]));
        self::assertSame(['status' => 0, 'output' => ''], $runner->capture(['true'], ['HOME' => $this->temporary]));
    }

    public function testRunnerCapturesStdoutAloneWithTheGivenInputAndDirectory(): void
    {
        $runner = new Runner();
        self::assertSame(['status' => 0, 'output' => 'fed'], $runner->captureStdout(['cat'], 'fed'));
        self::assertSame(['status' => 0, 'output' => ''], $runner->captureStdout(['cat']));
        self::assertSame(['status' => 3, 'output' => 'out'], $runner->captureStdout(['sh', '-c', 'printf out; echo "captureStdout passes stderr through" >&2; exit 3']));
        self::assertSame(realpath($this->temporary), realpath(trim($runner->captureStdout(['pwd'], null, $this->temporary)['output'])));
        self::assertSame(['status' => 2, 'output' => ''], $runner->captureStdout([$this->temporary . '/missing']));
    }

    public function testApi4CatalogExportAddsTheExtensionsEntitiesAndActionClasses(): void
    {
        $catalog = dirname(__DIR__, 2) . '/toolbelt/phpstan/src/Api4Catalog.php';
        foreach (['own' => ['Widget.php', 'Action/Contact/Greet.php'], 'sibling' => ['Action/Activity/Archive.php', 'Widget.php']] as $dir => $files) {
            foreach ($files as $file) {
                $parent = dirname($this->temporary . "/{$dir}/Civi/Api4/{$file}");
                is_dir($parent) || mkdir($parent, 0777, true);
                touch($this->temporary . "/{$dir}/Civi/Api4/{$file}");
            }
        }

        $export = Api4CatalogExport::build($catalog, [$this->temporary . '/own', $this->temporary . '/sibling']);

        self::assertSame(\CiviKitchen\PHPStan\Api4Catalog::CORE_VERSION, $export['version']);
        self::assertSame('Case', $export['aliases']['CiviCase']);
        self::assertTrue($export['entities']['Contact']['c']);
        self::assertSame(['Widget'], $export['extensionEntities']);
        self::assertSame(['Activity\\Archive', 'Contact\\Greet'], $export['extensionActions']);
        self::assertSame([], Api4CatalogExport::build($catalog, [])['extensionEntities']);
    }

    /** `ck … > file`: what a command echoes before a passthrough child must survive it. */
    public function testRunnerPassthroughKeepsEarlierOutputInARedirectedFile(): void
    {
        $php = PHP_SAPI === 'phpdbg' ? dirname(PHP_BINARY) . '/php' : PHP_BINARY;
        $output = $this->temporary . '/passthrough';
        $script = sprintf(
            'require %s; echo "before\n"; (new CiviKitchen\Toolbelt\Process\Runner())->passthrough(["printf", "child\n"]);',
            var_export(dirname(__DIR__, 2) . '/toolbelt/lib/php/bootstrap.php', true),
        );
        self::assertSame(0, (new Runner())->redirect([$php, '-r', $script], $output));
        self::assertSame("before\nchild\n", file_get_contents($output));
    }

    public function testRunnerDrainsLargeStdoutAndStderrWithoutDeadlock(): void
    {
        $php = PHP_SAPI === 'phpdbg' ? dirname(PHP_BINARY) . '/php' : PHP_BINARY;
        $result = (new Runner())->captureSeparate([
            $php,
            '-r',
            'fwrite(STDERR, str_repeat("e", 200000)); fwrite(STDOUT, str_repeat("o", 200000));',
        ]);
        self::assertSame(0, $result['status']);
        self::assertSame(200000, strlen($result['stdout']));
        self::assertSame(200000, strlen($result['stderr']));
    }

    public function testProfileDataReadsFiltersAndMergesProfiles(): void
    {
        $first = $this->profile('first.json', [
            'cms' => 'standalone',
            'authx' => ['header_cred' => ['api_key', 'jwt']],
            'dependencies' => [
                ['name' => 'kept'],
                ['name' => 'skipped', 'skipUf' => ['Standalone'], 'skipUfReason' => 'not supported'],
                'ignored',
            ],
            'apiUsers' => [['username' => 'api', 'role' => 'editor', 'permissions' => ['view contacts']]],
        ]);
        $second = $this->profile('second.json', [
            'apiUsers' => [['username' => 'other', 'role' => 'editor', 'permissions' => ['edit contacts']]],
        ]);
        $data = new ProfileData();
        self::assertTrue($data->hasApiUsers($first, true));
        self::assertSame('api_key,jwt', $data->authxPolicy($first, true));
        self::assertSame('standalone', $data->cms($first));
        self::assertSame(['  SKIP skipped on Standalone: not supported'], $data->skipped($first, 'Standalone'));
        self::assertSame('kept', $data->dependencies($first, 'Standalone')[0]['name']);
        $merged = $this->temporary . '/merged.json';
        $data->merge($merged, 'jwt,api_key', [$first, $second]);
        $result = $data->load($merged);
        self::assertCount(2, $result['apiUsers']);
        self::assertSame(['edit contacts', 'view contacts'], $result['apiUsers'][0]['permissions']);
        self::assertSame(['jwt', 'api_key'], $result['authx']['header_cred']);
    }

    public function testProfileDataRejectsConflictingRoles(): void
    {
        $first = $this->profile('first.json', ['apiUsers' => [['username' => 'same', 'role' => 'a']]]);
        $second = $this->profile('second.json', ['apiUsers' => [['username' => 'same', 'role' => 'b']]]);
        $this->expectException(RuntimeException::class);
        (new ProfileData())->merge($this->temporary . '/merged.json', '', [$first, $second]);
    }

    public function testPhpFloorIsTheLowestVersionTheConstraintAdmits(): void
    {
        $cases = [
            '>=8.3' => '8.3', '8.3.2' => '8.3', '^8.1' => '8.1', '~8.2.1' => '8.2', '8.*' => '8.0', '^8' => '8.0',
            '^8.2 || ^8.1' => '8.1', '^8.2 | ^8.1' => '8.1', '>=8.1 <8.5' => '8.1', '>=8.1,<8.5' => '8.1',
            '>=8.1 >=8.2' => '8.2', '8.1 - 8.3' => '8.1', '>=7.4' => '7.4', '>=8.1 !=8.2.0' => '8.1',
            '>= 8.1 < 8.4' => '8.1', '>=8.1 != 8.2' => '8.1', '^ 8.2 || ~ 8.1' => '8.1',
            '*' => null, '<8.4' => null, '^8.2 || *' => null, '' => null,
        ];
        foreach ($cases as $constraint => $floor) {
            self::assertSame($floor, PhpFloor::of((string) $constraint), "require.php \"{$constraint}\"");
        }
        file_put_contents($this->temporary . '/composer.json', '{"require": {"php": "^8.3 || ^8.2"}}');
        self::assertSame('8.2', PhpFloor::ofComposerJson($this->temporary));
        self::assertNull(PhpFloor::ofComposerJson($this->temporary . '/missing'));
    }

    public function testExtensionInspectorReadsSafeMetadata(): void
    {
        $file = $this->temporary . '/info.xml';
        file_put_contents($file, '<extension key="org.example.test"><version>1.2.3</version><requires>'
            . '<ext>org.example.one</ext><ext> org.example.two </ext></requires></extension>');
        $inspector = new ExtensionInspector($this->temporary . '/missing-autoload.php');
        self::assertSame('org.example.test', $inspector->key($file));
        self::assertSame(['org.example.one', 'org.example.two'], $inspector->requirements($file));
        self::assertSame('1.2.3', (string) $inspector->load($file, 'org.example.test')->version);
        $inspector->assertVersion($inspector->load($file, 'org.example.test'), '');
        self::assertSame('', $inspector->key($this->temporary . '/missing.xml'));
        self::assertSame([], $inspector->requirements($this->temporary . '/missing.xml'));
    }

    public function testExtensionInspectorRejectsWrongKey(): void
    {
        $file = $this->temporary . '/info.xml';
        file_put_contents($file, '<extension key="wrong"/>');
        $this->expectException(RuntimeException::class);
        (new ExtensionInspector('missing'))->load($file, 'expected');
    }

    public function testArchiveInstallerRejectsUnsafeExtensionKeyBeforeExtraction(): void
    {
        $installer = new ExtensionArchiveInstaller(new ExtensionInspector('missing'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsafe extension key');
        $installer->install('missing.zip', '../unsafe', $this->temporary . '/target', '');
    }

    public function testArchiveInstallerValidatesAndAtomicallyExtractsZip(): void
    {
        $archive = $this->temporary . '/extension.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE));
        $zip->addFromString('package/info.xml', '<extension key="org.example.safe"><version>1.0.0</version></extension>');
        $zip->addFromString('package/subdirectory/file.txt', 'contents');
        $zip->close();
        $target = $this->temporary . '/installed';
        (new ExtensionArchiveInstaller(new ExtensionInspector('missing')))
            ->install($archive, 'org.example.safe', $target, '');
        self::assertSame('contents', file_get_contents($target . '/subdirectory/file.txt'));

        $badArchive = $this->temporary . '/multiple-roots.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($badArchive, ZipArchive::CREATE));
        $zip->addFromString('one/info.xml', '<extension/>');
        $zip->addFromString('two/file.txt', 'contents');
        $zip->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('one root directory');
        (new ExtensionArchiveInstaller(new ExtensionInspector('missing')))
            ->install($badArchive, 'org.example.safe', $this->temporary . '/bad-target', '');
    }

    /**
     * @return array<string, array{list<string>, string, bool}>
     */
    public static function infoArchives(): array
    {
        return [
            'the only root, any name' => [['package/'], 'package/info.xml', true],
            'the root named after the key among others' => [['__MACOSX/', 'org.example.safe/'], 'org.example.safe/info.xml', true],
            'the only root besides a ./ entry' => [['./', 'package/'], 'package/info.xml', true],
            'two roots, neither the key' => [['one/', 'two/'], 'one/info.xml', false],
            'no directory entries' => [[], 'package/info.xml', false],
        ];
    }

    /**
     * Core's download picks the root the same way; an archive it accepts must be readable here.
     *
     * @param list<string> $directories
     * @dataProvider infoArchives
     */
    public function testArchiveInstallerReadsInfoFromTheRootCoreWouldInstall(array $directories, string $infoPath, bool $readable): void
    {
        $archive = $this->temporary . '/extension.zip';
        $info = '<extension key="org.example.safe"><requires><ext>org.example.one</ext></requires></extension>';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($directories as $directory) {
            $zip->addEmptyDir(rtrim($directory, '/'));
        }
        $zip->addFromString($infoPath, $info);
        $zip->close();
        if (!$readable) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('no root directory for org.example.safe');
        }
        self::assertSame($info, (new ExtensionArchiveInstaller(new ExtensionInspector('missing')))->info($archive, 'org.example.safe'));
    }

    public function testArchiveInstallerRefusesTheInfoOfAnotherKey(): void
    {
        $archive = $this->temporary . '/extension.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE));
        $zip->addEmptyDir('package');
        $zip->addFromString('package/info.xml', '<extension key="org.example.safe"/>');
        $zip->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('key does not match org.example.other');
        (new ExtensionArchiveInstaller(new ExtensionInspector('missing')))->info($archive, 'org.example.other');
    }

    public function testArchiveInstallerMaterialisesSymlinkToAFileInTheArchive(): void
    {
        $archive = $this->symlinkArchive('docs/index.md', '../README.md');
        $target = $this->temporary . '/installed';
        (new ExtensionArchiveInstaller(new ExtensionInspector('missing')))
            ->install($archive, 'org.example.safe', $target, '');
        self::assertFalse(is_link($target . '/docs/index.md'));
        self::assertSame('readme', file_get_contents($target . '/docs/index.md'));
    }

    public function testArchiveInstallerCountsSymlinkCopiesTowardTheExtractionLimit(): void
    {
        $archive = $this->temporary . '/large.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE));
        $zip->addFromString('package/info.xml', '<extension key="org.example.safe"><version>1.0.0</version></extension>');
        $large = fopen($this->temporary . '/large.bin', 'wb');
        self::assertTrue($large !== false && ftruncate($large, 130 << 20) && fclose($large));
        $zip->addFile($this->temporary . '/large.bin', 'package/large.bin');
        $zip->addFromString('package/copy.bin', 'large.bin');
        $zip->setExternalAttributesName('package/copy.bin', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds extraction limits');
        (new ExtensionArchiveInstaller(new ExtensionInspector('missing')))
            ->install($archive, 'org.example.safe', $this->temporary . '/installed', '');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unsafeSymlinks(): array
    {
        return [
            'leaves the root' => ['docs/index.md', '../../README.md'],
            'absolute' => ['docs/index.md', '/etc/passwd'],
            'missing file' => ['docs/index.md', 'missing.md'],
            'directory' => ['link', 'docs'],
            'another symlink' => ['other.md', 'docs/index.md'],
        ];
    }

    /**
     * @dataProvider unsafeSymlinks
     */
    public function testArchiveInstallerRejectsSymlinkThatDoesNotPointToAFileInTheArchive(string $link, string $to): void
    {
        $archive = $this->symlinkArchive($link, $to);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symlink that does not point to a file in it');
        (new ExtensionArchiveInstaller(new ExtensionInspector('missing')))
            ->install($archive, 'org.example.safe', $this->temporary . '/installed', '');
    }

    private function symlinkArchive(string $link, string $to): string
    {
        $archive = $this->temporary . '/symlink.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('package/info.xml', '<extension key="org.example.safe"><version>1.0.0</version></extension>');
        $zip->addFromString('package/README.md', 'readme');
        $zip->addFromString('package/docs/guide.md', 'guide');
        $entries = $link === 'other.md' ? ['docs/index.md' => '../README.md', $link => $to] : [$link => $to];
        foreach ($entries as $name => $target) {
            $zip->addFromString('package/' . $name, $target);
            $zip->setExternalAttributesName('package/' . $name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }
        $zip->close();
        return $archive;
    }

    public function testLifecycleRejectsUnsafeKeyBeforeStartingCv(): void
    {
        file_put_contents($this->temporary . '/info.xml', '<extension key="org.example.safe"><file>safe</file></extension>');
        $before = getcwd();
        chdir($this->temporary);
        try {
            $runner = new RecordingRunner();
            $application = new Application(dirname(__DIR__, 2) . '/toolbelt/bin', dirname(__DIR__, 2), $runner);
            self::assertSame(2, $application->run(['lifecycle', '--key', "x');phpinfo();//"], 'ck'));
            self::assertSame([], $runner->commands);
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testSmartyFailsWhenThePayloadNeverRan(): void
    {
        mkdir($this->temporary . '/ext');
        file_put_contents($this->temporary . '/ext/info.xml', '<extension key="demo"><file>demo</file></extension>');
        $runner = new class () extends Runner {
            public bool $payloadRuns = false;

            public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
            {
                if ($this->payloadRuns) {
                    touch((string) ($environment['CK_SMARTY_DONE'] ?? ''));
                }
                return 0;
            }

            public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
            {
                return ['status' => 0, 'output' => ''];
            }
        };
        $previous = (string) getcwd();
        chdir($this->temporary . '/ext');
        try {
            self::assertSame(2, (new Application('/nonexistent', dirname(__DIR__, 2), $runner))->run([], 'cksmarty'));
            $runner->payloadRuns = true;
            self::assertSame(0, (new Application('/nonexistent', dirname(__DIR__, 2), $runner))->run([], 'cksmarty'));
        } finally {
            chdir($previous);
        }
    }

    public function testCiRunsEveryGateInItsDirectoryAndAggregatesFailures(): void
    {
        mkdir($this->temporary . '/ext');
        mkdir($this->temporary . '/repo');
        file_put_contents($this->temporary . '/ext/info.xml', '<extension key="demo"><file>demo</file></extension>');
        touch($this->temporary . '/ext/phpstan-tests.neon');
        putenv('CK_EXT_PATH=' . $this->temporary . '/ext');
        putenv('CK_TOOL_PATH=' . $this->temporary . '/repo');
        putenv('GITHUB_ACTIONS=true');
        putenv('GITHUB_STEP_SUMMARY=' . $this->temporary . '/summary.md');
        $runner = new class () extends Runner {
            /** @var list<string> */
            public array $calls = [];

            public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
            {
                $this->calls[] = basename((string) $workingDirectory) . ': ' . implode(' ', $command);
                return $command[0] === 'ckdeps' ? 1 : 0;
            }
        };
        try {
            ob_start();
            $status = (new Application('/nonexistent', dirname(__DIR__, 2), $runner))
                ->run(['ci', '--skip', 'cksmarty', '--extra-phpunit-config=unit.xml'], 'ck');
            $output = (string) ob_get_clean();
            self::assertSame(2, (new Application('/nonexistent', dirname(__DIR__, 2), $runner))->run(['ci', '--only', 'lint'], 'ck'));
        } finally {
            foreach (['CK_EXT_PATH', 'CK_TOOL_PATH', 'GITHUB_ACTIONS', 'GITHUB_STEP_SUMMARY'] as $name) {
                putenv($name);
            }
        }
        self::assertSame(1, $status);
        self::assertSame([
            'repo: cklint --all', 'repo: ckconform', 'repo: ckcivix --check', 'repo: ckfmt --check',
            'ext: ckcoverage tests/phpunit', 'ext: phpunit -c unit.xml', 'ext: phpstan analyse --no-progress',
            'ext: phpstan analyse -c phpstan-tests.neon --no-progress', 'repo: ckanalyze', 'repo: ckcompat', 'ext: ckdeps',
            'repo: cktaint', 'repo: ckeslint',
        ], $runner->calls);
        self::assertStringContainsString("::error title=ck ci::ckdeps failed (exit 1)\n", $output);
        $summary = (string) file_get_contents($this->temporary . '/summary.md');
        self::assertStringContainsString('| `ckdeps` | fail (exit 1) |', $summary);
        self::assertStringContainsString('### Taint analysis: no blocking findings', $summary);
    }

    public function testCiReportsAShadowGateWithoutFailingTheRun(): void
    {
        mkdir($this->temporary . '/ext');
        file_put_contents($this->temporary . '/ext/info.xml', '<extension key="demo"><file>demo</file></extension>');
        putenv('CK_EXT_PATH=' . $this->temporary . '/ext');
        putenv('GITHUB_ACTIONS=true');
        putenv('GITHUB_STEP_SUMMARY=' . $this->temporary . '/summary.md');
        $runner = new RecordingRunner();
        $runner->exitCodes['ckanalyze'] = 1;
        try {
            ob_start();
            $status = (new Application('/nonexistent', dirname(__DIR__, 2), $runner))->run(['ci', '--only', 'ckanalyze,ckconform'], 'ck');
            $output = (string) ob_get_clean();
            $runner->exitCodes['ckconform'] = 1;
            ob_start();
            $failing = (new Application('/nonexistent', dirname(__DIR__, 2), $runner))->run(['ci', '--only', 'ckanalyze,ckconform'], 'ck');
            ob_end_clean();
            // A crash is no finding: exit 2 fails the run like any gate.
            $runner->exitCodes = ['ckanalyze' => 2];
            ob_start();
            $crashed = (new Application('/nonexistent', dirname(__DIR__, 2), $runner))->run(['ci', '--only', 'ckanalyze'], 'ck');
            ob_end_clean();
        } finally {
            foreach (['CK_EXT_PATH', 'GITHUB_ACTIONS', 'GITHUB_STEP_SUMMARY'] as $name) {
                putenv($name);
            }
        }
        self::assertSame(0, $status);
        self::assertSame(1, $failing);
        self::assertSame(1, $crashed);
        self::assertStringContainsString("::warning title=ck ci::ckanalyze (shadow, not gating) exited 1\n", $output);
        self::assertStringNotContainsString('::error', $output);
        self::assertStringContainsString('| `ckanalyze` | shadow (exit 1, not gating) |', (string) file_get_contents($this->temporary . '/summary.md'));
    }

    public function testAnalyzeReadsCoreAndGeneratedCodeOnlyForTheirSymbols(): void
    {
        $core = $this->temporary . '/core';
        mkdir($core);
        touch($core . '/Civi.php');
        file_put_contents($this->temporary . '/composer.json', '{"require":{"php":">=8.2"}}');
        mkdir($this->temporary . '/lib');
        putenv('CIVICRM_CORE_DIR=' . $core);
        $before = getcwd();
        chdir($this->temporary);
        try {
            $runner = new AnalyzeRunner();
            self::assertSame(0, (new AnalyzeCommand(dirname(__DIR__, 2), $runner))->run([]));
            self::assertSame(['mago', '--workspace', '.', '--config', $runner->configPath, '--php-version', '8.2', 'analyze'], $runner->command);
            self::assertSame(
                'extends = ["' . dirname(__DIR__, 2) . "/toolbelt/mago/mago.toml\"]\n\n[source]\npaths = [\"Civi/Widget.php\"]\n"
                . 'includes = ["' . $core . '", "' . dirname(__DIR__, 2) . "/toolbelt/mago/stubs\", \"CRM/Widget/DAO/Thing.php\", \"widget.civix.php\", \"lib\"]\n"
                . 'excludes = ["' . $core . "/Civi/Api4/Generic/Result.php\"]\n"
                . 'patches = ["' . dirname(__DIR__, 2) . "/toolbelt/mago/patches\"]\n\n[analyzer]\nminimum-fail-level = \"note\"\n",
                $runner->config,
            );
            self::assertSame(getcwd(), $runner->environment['CK_MAGO_WORKSPACE'] ?? null);
            self::assertSame('mago.toml', basename($runner->configPath));
            self::assertDirectoryDoesNotExist(dirname($runner->configPath));

            // A repo's own mago.toml is the base, and its php-version stands.
            touch('mago.toml');
            $own = new AnalyzeRunner();
            ob_start();
            self::assertSame(1, (new AnalyzeCommand(dirname(__DIR__, 2), $own))->run([]));
            self::assertStringContainsString("using this repo's own mago.toml", (string) ob_get_clean());
            self::assertStringStartsWith('extends = ["' . realpath('mago.toml') . '"]', $own->config);
            self::assertNotContains('--php-version', $own->command);

            putenv('CIVICRM_CORE_DIR=' . $this->temporary . '/nowhere');
            $missing = new AnalyzeRunner();
            self::assertSame(2, (new AnalyzeCommand(dirname(__DIR__, 2), $missing))->run([]));
            self::assertSame([], $missing->command);

            // Without CIVICRM_CORE_DIR, a buildkit image's core comes from cv.
            putenv('CIVICRM_CORE_DIR');
            $buildkit = new AnalyzeRunner($core . "/\n");
            ob_start();
            (new AnalyzeCommand(dirname(__DIR__, 2), $buildkit, $this->temporary . '/no-image-core'))->run([]);
            ob_end_clean();
            self::assertStringContainsString('includes = ["' . $core . '",', $buildkit->config);
        } finally {
            putenv('CIVICRM_CORE_DIR');
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testAnalyzeReadsRequiredExtensionsForTheirSymbols(): void
    {
        $core = $this->temporary . '/core';
        mkdir($core . '/ext/search_kit', 0777, true);
        file_put_contents($core . '/ext/search_kit/info.xml', '<extension key="org.civicrm.search_kit"/>');
        touch($core . '/Civi.php');
        mkdir($this->temporary . '/ext/org.example.rules', 0777, true);
        file_put_contents(
            $this->temporary . '/info.xml',
            '<extension key="widget"><requires><ext>org.example.rules</ext><ext>org.civicrm.search_kit</ext><ext>org.example.absent</ext></requires></extension>',
        );
        putenv('CIVICRM_CORE_DIR=' . $core);
        putenv('CK_EXT_DIR=' . $this->temporary . '/ext');
        $before = getcwd();
        chdir($this->temporary);
        try {
            $runner = new AnalyzeRunner();
            self::assertSame(0, (new AnalyzeCommand(dirname(__DIR__, 2), $runner))->run([]));
            self::assertStringContainsString('"' . $this->temporary . '/ext/org.example.rules"]' . "\nexcludes", $runner->config);
            self::assertStringNotContainsString('search_kit', $runner->config);
            self::assertStringNotContainsString('org.example.absent', $runner->config);
        } finally {
            putenv('CIVICRM_CORE_DIR');
            putenv('CK_EXT_DIR');
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testMigrateTurnsPhpcsCommentsIntoMagoPragmas(): void
    {
        $source = <<<'PHP'
            <?php
            // phpcs:disable PSR1.Files.SideEffects
            require __DIR__ . '/x.php';
            // phpcs:enable

            class Widget {
              // phpcs:ignore Drupal.Scope.MethodScope -- legacy hook signature
              function legacy(): void {
                $x = unserialize($s); // phpcs:ignore CiviKitchen.Security.NoUnsafeUnserialize.Unsafe -- trusted cache
                civicrm_api3('Job', 'x'); // phpcs:ignore CiviKitchen.Legacy.NoLegacyCall -- no v4
                $url = 'http://example.org'; // phpcs:ignore
                // phpcs:ignore CiviKitchen.I18n.UseExtensionTs.BareTs, Drupal.Semantics.FunctionAlias
                echo ts(sizeof([]));
                /* phpcs:disable CiviKitchen.Files.MaxFileLength */
                /**
                 * phpcs:ignore Example.Sniff
                 */
              }
            }
            /* phpcs:ignore Drupal.Scope.MethodScope */ function a(): void {}
            function/* phpcs:ignore Example.Sniff */b(): void {}
            function c(): bool { return /* phpcs:ignore Example.Sniff */true; }
            function d(): void {
              /* phpcs:ignore Example.Sniff */ c();
            }
            PHP;
        $expected = <<<'PHP'
            <?php
            require __DIR__ . '/x.php';

            class Widget {
              // @mago-expect lint:civikitchen/explicit-visibility -- legacy hook signature
              function legacy(): void {
                $x = unserialize($s); // @mago-expect lint:civikitchen/no-unsafe-unserialize -- trusted cache
                civicrm_api3('Job', 'x');
                $url = 'http://example.org';
                // @mago-expect lint:civikitchen/use-extension-ts,lint:no-alias-function
                echo ts(sizeof([]));
                /* phpcs:disable CiviKitchen.Files.MaxFileLength */
                /**
                 * phpcs:ignore Example.Sniff
                 */
              }
            }
            /* @mago-expect lint:civikitchen/explicit-visibility */ function a(): void {}
            function b(): void {}
            function c(): bool { return true; }
            function d(): void {
              c();
            }
            PHP;

        // A block disable of a ported sniff and a docblock stay for a person.
        self::assertSame([$expected, [14, 15]], (new MigrateCommand(dirname(__DIR__, 2)))->migrateComments($source));
    }

    public function testMigrateDropsCommentsWithoutChangingOutputOrLeavingBlankLines(): void
    {
        $migrate = new MigrateCommand(dirname(__DIR__, 2));
        $cases = [
            'inline HTML keeps the open tag whole' => ["<p><?php // phpcs:ignore X.Y.Z ?></p>\n", "<p><?php ?></p>\n"],
            'consecutive comments go without a trace' => [
                "<?php\nfunction f() {\n    // phpcs:disable A\n    // phpcs:disable B\n    # phpcs:disable C\n    return 1;\n}\n",
                "<?php\nfunction f() {\n    return 1;\n}\n",
            ],
            'no doubled blank line' => ["<?php\n\$a = 1;\n\n// phpcs:enable X\n\n\$b = 2;\n", "<?php\n\$a = 1;\n\n\$b = 2;\n"],
            'no blank line opening a block' => [
                "<?php\nfunction f() {\n  // phpcs:ignore X.Y.Z -- why\n  // phpcs:disable Y\n\n  return 1;\n}\n",
                "<?php\nfunction f() {\n  return 1;\n}\n",
            ],
            'a blank line between statements stays' => ["<?php\n\$a = 1;\n// phpcs:enable X\n\n\$b = 2;\n", "<?php\n\$a = 1;\n\n\$b = 2;\n"],
            'CRLF lines go whole' => ["<?php\r\n    // phpcs:disable X\r\n    \$a = 1;\r\n", "<?php\r\n    \$a = 1;\r\n"],
            'no space where no token could merge' => ["<?php\nfoo(/* phpcs:ignore X.Y.Z */\$b);\n", "<?php\nfoo(\$b);\n"],
            'a rule off in the baseline gets no pragma' => ["<?php\nf(TRUE); // phpcs:ignore CiviKitchen.Modern.NameBooleanArguments.UnnamedBoolean -- magic setter\n", "<?php\nf(TRUE);\n"],
            'a comment as the last token' => ["<?php\n\$a = 1;\n// phpcs:enable", "<?php\n\$a = 1;\n"],
            'the close tag keeps its space' => ["<?php echo f(); // phpcs:ignore X.Y.Z ?>\n", "<?php echo f(); ?>\n"],
            'and a block before it too' => ["<p><?= f() /* phpcs:ignore X.Y.Z */ ?></p>", "<p><?= f() ?></p>"],
        ];
        foreach ($cases as $name => [$source, $expected]) {
            self::assertSame([$expected, []], $migrate->migrateComments($source), $name);
        }
    }

    public function testMigrateFindsThePhpcsConfigAnywhereInTemplateCustom(): void
    {
        $before = getcwd();
        chdir($this->temporary);
        try {
            mkdir('Civi');
            file_put_contents('Civi/Widget.php', "<?php\n");
            foreach (['mago.toml,phpcs.xml.dist -- local config' => 1, 'phpcs.xml.dist.bak -- a backup' => 0] as $value => $status) {
                ob_start();
                self::assertSame($status, (new MigrateCommand(dirname(__DIR__, 2), new MigrateRunner($value)))->run([]), $value);
                $output = (string) ob_get_clean();
                self::assertSame($status === 1, str_contains($output, 'drop the phpcs.xml.dist entry'), $value);
            }
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testMigrateDeletesThePhpcsConfigAndListsWhatNeedsAPerson(): void
    {
        $before = getcwd();
        chdir($this->temporary);
        try {
            mkdir('Civi');
            file_put_contents('Civi/Widget.php', "<?php\n\$a = 1; // phpcs:ignore\n");
            file_put_contents('phpcs.xml.dist', <<<'XML'
                <?xml version="1.0"?>
                <ruleset name="widget">
                  <rule ref="CiviKitchen"><exclude name="CiviKitchen.I18n.UseExtensionTs.BareTs"/><exclude name="CiviKitchen.Modern.NameBooleanArguments"/></rule>
                  <exclude-pattern>*/frontend/*</exclude-pattern>
                  <rule ref="CiviKitchen.Files.MaxFileLength"><exclude-pattern>*/tests/*</exclude-pattern></rule>
                  <rule ref="Drupal.Files.TxtFileLineLength"><exclude-pattern>*.md</exclude-pattern></rule>
                </ruleset>
                XML);
            ob_start();
            $status = (new MigrateCommand(dirname(__DIR__, 2), new MigrateRunner('phpcs.xml.dist -- local sniffs')))->run([]);
            $output = (string) ob_get_clean();
            self::assertSame(1, $status);
            self::assertFileDoesNotExist('phpcs.xml.dist');
            self::assertSame("<?php\n\$a = 1;\n", file_get_contents('Civi/Widget.php'));
            self::assertStringContainsString('turned off CiviKitchen.I18n.UseExtensionTs.BareTs: disable civikitchen/use-extension-ts', $output);
            // Off in the baseline already.
            self::assertStringNotContainsString('NameBooleanArguments', $output);
            self::assertStringContainsString('configured CiviKitchen.Files.MaxFileLength (now civikitchen/max-file-length): <exclude-pattern>*/tests/*</exclude-pattern>', $output);
            self::assertStringContainsString('drop the phpcs.xml.dist entry from policy.template_custom', $output);
            // Ruleset-wide path excludes and sniffs without a mago rule are no work for a person.
            self::assertStringNotContainsString('frontend', $output);
            self::assertStringNotContainsString('TxtFileLineLength', $output);

            // A migrated repository is a no-op.
            ob_start();
            $again = (new MigrateCommand(dirname(__DIR__, 2), new MigrateRunner('')))->run([]);
            self::assertStringContainsString('0 file(s) rewritten', (string) ob_get_clean());
            self::assertSame(0, $again);
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testCoverageTreatsOptionalTestsPolicyWithItsMandatoryReasonAsOptional(): void
    {
        file_put_contents($this->temporary . '/info.xml', '<extension key="org.example.safe"><file>safe</file></extension>');
        $before = getcwd();
        chdir($this->temporary);
        try {
            // ckconform --policy prints the whole value, and policy.tests
            // requires a reason, so the value is never the bare word.
            ob_start();
            $status = (new CoverageCommand(dirname(__DIR__, 2), new PolicyRunner('optional -- no PHP in this repo')))->run([]);
            $output = (string) ob_get_clean();
            self::assertSame(0, $status);
            self::assertStringContainsString('declares it optional', $output);

            ob_start();
            $required = (new CoverageCommand(dirname(__DIR__, 2), new PolicyRunner('')))->run([]);
            ob_end_clean();
            self::assertSame(2, $required);
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testLiteralOptionTerminatorPreservesDashPrefixedPaths(): void
    {
        file_put_contents($this->temporary . '/info.xml', '<extension key="org.example.safe"><file>safe</file></extension>');
        file_put_contents($this->temporary . '/composer.json', '{"require":{"php":">=8.1"}}');
        file_put_contents($this->temporary . '/-odd.php', '<?php');
        $before = getcwd();
        chdir($this->temporary);
        try {
            $formatRunner = new RecordingRunner();
            self::assertSame(0, (new FormatCommand(dirname(__DIR__, 2), $formatRunner))->run(['--check', '--', '-odd.php']));
            self::assertTrue($formatRunner->passedArgument('-odd.php'));
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testCoreVendorIsItsOwnOrTheProjectsWhenCoreIsAPackage(): void
    {
        $own = $this->temporary . '/standalone/core';
        mkdir($own . '/vendor', 0700, true);
        self::assertSame($own . '/vendor', CiviCore::vendor($own));
        $package = $this->temporary . '/drupal/vendor/civicrm/civicrm-core';
        mkdir($package, 0700, true);
        self::assertSame($this->temporary . '/drupal/vendor', CiviCore::vendor($package));
        symlink($package, $this->temporary . '/linked-core');
        self::assertSame(realpath($this->temporary . '/drupal/vendor'), CiviCore::vendor($this->temporary . '/linked-core'));
        mkdir($this->temporary . '/bare');
        self::assertNull(CiviCore::vendor($this->temporary . '/bare'));
    }

    public function testCompatibilityRunsMagoAtTheFloorWithCorePolyfillsAsSymbols(): void
    {
        $core = $this->temporary . '/core';
        mkdir($core . '/vendor/symfony/polyfill-php84', 0777, true);
        mkdir($core . '/vendor/symfony/console', 0777, true);
        touch($core . '/Civi.php');
        file_put_contents($this->temporary . '/composer.json', '{"require":{"php":">=8.1"}}');
        file_put_contents($this->temporary . '/-odd.php', '<?php');
        putenv('CIVICRM_CORE_DIR=' . $core);
        $before = getcwd();
        chdir($this->temporary);
        try {
            $runner = new CompatibilityRunner();
            ob_start();
            self::assertSame(0, (new CompatibilityCommand(dirname(__DIR__, 2), $runner))->run(['--', '-odd.php']));
            ob_end_clean();
            $config = $runner->configs[0];
            self::assertStringContainsString("paths = [\"-odd.php\"]\nincludes = [\"{$core}/vendor/symfony/polyfill-php84\"]\n", $config);
            self::assertStringContainsString('"' . dirname(__DIR__, 2) . '/toolbelt/mago-ext/worker.php", "--compatibility=8.5"]', $config);
            self::assertSame([
                ['8.5', 'lint', '--list-rules', '--json'],
                ['8.1', 'lint', '--only', 'civikitchen/php-compatibility'],
                ['8.5', 'lint', '--only', 'explicit-nullable-param,deprecated-cast,civikitchen/php-deprecation'],
            ], array_map(static fn(array $command): array => array_slice($command, 6), array_slice($runner->commands, 0, 3)));
            self::assertSame(['8.1', 'analyze', '--retain-code', 'unavailable-class-constant'], array_slice($runner->commands[3], 6, 4));
            self::assertContains('deprecated-function', $runner->commands[3]);
            self::assertDirectoryDoesNotExist(dirname($runner->configPaths[0]));

            // The top of a range is where the deprecation rules run; one failing stage fails the run.
            $ranged = new CompatibilityRunner(['analyze' => 1]);
            ob_start();
            self::assertSame(1, (new CompatibilityCommand(dirname(__DIR__, 2), $ranged))->run(['--range', '8.1-8.3']));
            ob_end_clean();
            self::assertSame('8.3', $ranged->commands[2][6]);
            self::assertStringContainsString('"--compatibility=8.3"]', $ranged->configs[0]);
            $crashed = new CompatibilityRunner(['analyze' => 1, 'civikitchen/php-compatibility' => 2]);
            ob_start();
            self::assertSame(2, (new CompatibilityCommand(dirname(__DIR__, 2), $crashed))->run([]));
            ob_end_clean();

            $typo = new CompatibilityRunner();
            self::assertSame(2, (new CompatibilityCommand(dirname(__DIR__, 2), $typo))->run(['-odd.php', 'Civi/Missing.php']));
            self::assertSame([], $typo->commands);

            // A floor mago cannot check, a reversed range and a malformed version are usage errors.
            foreach ([['--php', '7.4'], ['--range', '8.4-8.1'], ['--range=8.1'], ['--php=8.1-8.3']] as $arguments) {
                $invalid = new CompatibilityRunner();
                self::assertSame(2, (new CompatibilityCommand(dirname(__DIR__, 2), $invalid))->run($arguments), implode(' ', $arguments));
                self::assertSame([], $invalid->commands);
            }

            putenv('CIVICRM_CORE_DIR=' . $this->temporary . '/nowhere');
            $missing = new CompatibilityRunner();
            self::assertSame(2, (new CompatibilityCommand(dirname(__DIR__, 2), $missing))->run([]));
            self::assertSame([], $missing->commands);
        } finally {
            putenv('CIVICRM_CORE_DIR');
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testReleaseVersionStripsExactlyOneVPrefix(): void
    {
        file_put_contents($this->temporary . '/info.xml', '<extension key="org.example.safe"><file>safe</file><version>1.2.3</version></extension>');
        $before = getcwd();
        chdir($this->temporary);
        try {
            self::assertSame(0, (new ReleaseCommand(dirname(__DIR__, 2), new RecordingRunner()))
                ->run(['check', '--version', 'v1.2.3']));
            self::assertSame(1, (new ReleaseCommand(dirname(__DIR__, 2), new RecordingRunner()))
                ->run(['check', '--version', 'vv1.2.3']));
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testReleaseAcceptsDashPrefixedArchiveAfterOptionTerminator(): void
    {
        file_put_contents($this->temporary . '/info.xml', '<extension key="org.example.safe"><file>safe</file><version>1.2.3</version></extension>');
        $archive = $this->temporary . '/-archive.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE));
        $zip->addFromString('org.example.safe/info.xml', '<extension key="org.example.safe"><version>1.2.3</version></extension>');
        $zip->close();
        $before = getcwd();
        chdir($this->temporary);
        try {
            self::assertSame(0, (new ReleaseCommand(dirname(__DIR__, 2), new RecordingRunner()))
                ->run(['verify', '--', '-archive.zip']));
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testReleaseStagesTheDeclaredBuildOutputAndNothingElse(): void
    {
        $repository = $this->stagedRepository(['ang/app.bundle.js', 'dist/lib']);
        $this->write($repository, 'ang/app.bundle.js', 'built();');
        $this->write($repository, 'ang/unlisted.bundle.js', 'undeclared();');
        $this->write($repository, 'dist/lib/lib.min.js', 'lib();');
        $this->write($repository, 'dist/lib/licenses/LICENSE-dependency', 'license');
        $this->write($repository, 'notes.txt', 'untracked and not ignored');

        [$status, $entries] = $this->releaseDist($repository);

        self::assertSame(0, $status);
        foreach (['staged.php', 'ang/app.bundle.js', 'dist/lib/lib.min.js', 'dist/lib/licenses/LICENSE-dependency'] as $path) {
            self::assertContains("org.example.staged/{$path}", $entries);
        }
        foreach ($entries as $entry) {
            self::assertDoesNotMatchRegularExpression('#unlisted|notes\.txt|/tests/|civikitchen\.yaml|bun\.lock#', $entry);
        }
        // The checkout's own index still matches HEAD.
        self::assertSame('', $this->git($repository, 'diff', '--cached', '--name-only'));
    }

    public function testReleaseRefusesDeclaredBuildOutputTheBuildDidNotWrite(): void
    {
        $repository = $this->stagedRepository(['ang/app.bundle.js', 'dist/lib']);
        $this->write($repository, 'ang/app.bundle.js', 'built();');
        self::assertTrue(mkdir($repository . '/dist/lib', 0777, true));

        [$status, $entries] = $this->releaseDist($repository);

        self::assertSame(1, $status);
        self::assertSame([], $entries);
        $cli = (new Runner())->captureSeparate(
            ['php', dirname(__DIR__, 2) . '/toolbelt/bin/ckrelease', 'dist', '--output', $this->temporary . '/cli'],
            null,
            $repository,
        );
        self::assertSame(1, $cli['status']);
        self::assertStringContainsString("policy.dist.build output missing:\n  dist/lib\n", $cli['stderr']);
        self::assertStringContainsString('Run bun install --frozen-lockfile && bun run build first', $cli['stderr']);
        self::assertFileDoesNotExist($this->temporary . '/cli/org.example.staged-1.0.0.zip');
    }

    public function testReleaseRefusesDeclaredBuildOutputThatGitTracks(): void
    {
        $repository = $this->stagedRepository(['staged.php']);

        [$status, $entries] = $this->releaseDist($repository);

        self::assertSame(1, $status);
        self::assertSame([], $entries);
        $cli = (new Runner())->captureSeparate(
            ['php', dirname(__DIR__, 2) . '/toolbelt/bin/ckrelease', 'dist', '--output', $this->temporary . '/cli'],
            null,
            $repository,
        );
        self::assertSame(1, $cli['status']);
        self::assertStringContainsString("policy.dist.build output is tracked at HEAD:\n  staged.php\n", $cli['stderr']);
    }

    public function testReleaseRefusesAnArchiveHoldingGitLfsPointers(): void
    {
        $repository = $this->stagedRepository(['ang/app.bundle.js']);
        $this->write($repository, 'handbook/images/shot.png', "version https://git-lfs.github.com/spec/v1\noid sha256:4d7a\nsize 12345\n");
        $this->git($repository, 'add', 'handbook');
        $this->git($repository, '-c', 'user.name=ck', '-c', 'user.email=ck@example.org', 'commit', '-q', '-m', 'pointer');
        $this->write($repository, 'ang/app.bundle.js', 'built();');

        $cli = (new Runner())->captureSeparate(
            ['php', dirname(__DIR__, 2) . '/toolbelt/bin/ckrelease', 'dist', '--output', $this->temporary . '/cli'],
            null,
            $repository,
        );
        self::assertSame(1, $cli['status']);
        self::assertStringContainsString("Git LFS pointers instead of files:\n  org.example.staged/handbook/images/shot.png\n", $cli['stderr']);
    }

    public function testReleaseStagesOntoATreeThatAlreadyBundlesVendor(): void
    {
        $repository = $this->stagedRepository(['ang/app.bundle.js']);
        $this->write($repository, 'ang/app.bundle.js', 'built();');
        $this->write($repository, 'vendor/autoload.php', '<?php');
        $this->git($repository, 'add', '--force', 'vendor');

        [$status, $entries] = $this->releaseDist($repository, $this->git($repository, 'write-tree'));

        self::assertSame(0, $status);
        self::assertContains('org.example.staged/vendor/autoload.php', $entries);
        self::assertContains('org.example.staged/ang/app.bundle.js', $entries);
    }

    public function testReleaseFromAnExtensionDirectoryArchivesThatExtensionAlone(): void
    {
        $repository = $this->temporary . '/monorepo';
        $this->write($repository, 'base/info.xml', '<extension key="org.example.base"><file>base</file><version>1.0.0</version></extension>');
        $this->write($repository, 'base/base.php', '<?php');
        $this->write($repository, 'base/tests/BaseTest.php', '<?php');
        $this->write($repository, 'base/.gitignore', "/vendor/\n");
        $this->write($repository, 'addon/info.xml', '<extension key="org.example.addon"><file>addon</file><version>1.0.0</version></extension>');
        $this->write($repository, 'addon/addon.php', '<?php');
        $this->git($repository, 'init', '-q');
        $this->git($repository, 'add', '-A');
        $this->git($repository, '-c', 'user.name=ck', '-c', 'user.email=ck@example.org', 'commit', '-q', '-m', 'fixture');
        // The composer_install path: vendor/ added to the index, archived from the written tree.
        $this->write($repository, 'base/vendor/autoload.php', '<?php');
        $this->git($repository . '/base', 'add', '--force', 'vendor');
        $tree = $this->git($repository . '/base', 'write-tree');

        foreach (['HEAD' => false, $tree => true] as $ref => $vendored) {
            $output = $this->temporary . '/dist-' . ($vendored ? 'tree' : 'head');
            $status = $this->inRepository($repository . '/base', static fn (): int => (new ReleaseCommand(dirname(__DIR__, 2)))
                ->run(['dist', '--version', 'v1.0.0', '--ref', (string) $ref, '--output', $output]));
            self::assertSame(0, $status, (string) $ref);
            $entries = [];
            $zip = new ZipArchive();
            self::assertTrue($zip->open($output . '/org.example.base-1.0.0.zip'));
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entries[] = (string) $zip->getNameIndex($index);
            }
            $zip->close();
            self::assertContains('org.example.base/info.xml', $entries);
            self::assertContains('org.example.base/base.php', $entries);
            self::assertSame($vendored, in_array('org.example.base/vendor/autoload.php', $entries, true));
            foreach ($entries as $entry) {
                self::assertDoesNotMatchRegularExpression('#addon|/base/|/tests/#', $entry);
            }
        }
    }

    public function testVerifyRequiresEveryDeclaredBuildOutputInTheArchive(): void
    {
        $repository = $this->stagedRepository(['ang/app.bundle.js']);
        $archive = $this->temporary . '/archive.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE));
        $zip->addFromString('org.example.staged/info.xml', '<extension key="org.example.staged"><version>1.0.0</version></extension>');
        $zip->close();
        $verify = static fn (): int => (new ReleaseCommand(dirname(__DIR__, 2)))->run(['verify', $archive]);

        self::assertSame(1, $this->inRepository($repository, $verify));
        self::assertTrue($zip->open($archive));
        $zip->addFromString('org.example.staged/ang/app.bundle.js', 'built();');
        $zip->close();
        self::assertSame(0, $this->inRepository($repository, $verify));
    }

    public function testExtensionEditorUpdatesOpenAndProprietaryMetadata(): void
    {
        $directory = $this->temporary . '/extension';
        mkdir($directory);
        file_put_contents($directory . '/info.xml', '<extension><license>AGPL-3.0</license><urls>'
            . '<url desc="Licensing">https://example.test</url></urls><php_compatibility>'
            . '<ver>8.1</ver><ver>8.2</ver><ver>8.3</ver></php_compatibility></extension>');
        file_put_contents($directory . '/README.md', 'Example, licensed under [AGPL](LICENSE.txt).');
        file_put_contents($directory . '/LICENSE.txt', "Copyright old\nBody\n");
        file_put_contents($directory . '/composer.json', '{"license":"AGPL-3.0","require":{}}');
        file_put_contents($directory . '/phpstan.neon.dist', "parameters:\n  phpVersion: 80100\n");
        $editor = new ExtensionEditor();
        $editor->updateComposer($directory . '/composer.json', 'Proprietary', '8.2');
        $composer = json_decode((string) file_get_contents($directory . '/composer.json'), true);
        self::assertSame('proprietary', $composer['license']);
        self::assertTrue($composer['private']);
        self::assertSame('>=8.2', $composer['require']['php']);
        $editor->rewriteLicense($directory, 'Proprietary', 'Example Ltd');
        self::assertStringContainsString('proprietary software', (string) file_get_contents($directory . '/README.md'));
        self::assertStringNotContainsString('Licensing', (string) file_get_contents($directory . '/info.xml'));
        $editor->alignPhpFloor($directory . '/composer.json', $directory . '/info.xml', $directory . '/phpstan.neon.dist');
        self::assertStringNotContainsString('<ver>8.1</ver>', (string) file_get_contents($directory . '/info.xml'));
        self::assertStringContainsString('phpVersion: 80200', (string) file_get_contents($directory . '/phpstan.neon.dist'));
    }

    public function testExtensionEditorSetsTheInitialVersion(): void
    {
        $info = $this->temporary . '/info.xml';
        file_put_contents($info, '<extension><version>1.0</version></extension>');
        (new ExtensionEditor())->setInitialVersion($info);
        self::assertStringContainsString('<version>0.1.0</version>', (string) file_get_contents($info));
        file_put_contents($info, '<extension></extension>');
        $this->expectExceptionMessage('info.xml needs one <version>');
        (new ExtensionEditor())->setInitialVersion($info);
    }

    public function testRepositoryFilesUsesGitAndFiltersGeneratedAndVendoredPaths(): void
    {
        $root = $this->temporary . '/repo';
        mkdir($root);
        mkdir($root . '/toolbelt/bin', 0700, true);
        file_put_contents($root . '/toolbelt/bin/ckconform', "#!/bin/sh\nexit 0\n");
        chmod($root . '/toolbelt/bin/ckconform', 0700);
        (new Runner())->capture(['git', 'init', '-q'], null, $root);
        foreach (['keep.php', 'generated.civix.php', 'DAO/Generated.php', 'vendor/ignored.php'] as $file) {
            $path = $root . '/' . $file;
            is_dir(dirname($path)) || mkdir(dirname($path), 0700, true);
            file_put_contents($path, '<?php');
        }
        (new Runner())->capture(['git', 'add', '.'], null, $root);
        (new Runner())->capture(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-qm', 'fixture'], null, $root);
        file_put_contents($root . '/keep.php', "<?php\n// changed\n");
        $files = new Files($root);
        $before = getcwd();
        chdir($root);
        try {
            self::assertTrue($files->isGitCheckout());
            self::assertSame(['keep.php'], $files->source(['php']));
            self::assertSame(['keep.php'], $files->changedPhp());
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testRepositoryFilesScopesChangedFilesToTheCurrentExtensionDirectory(): void
    {
        $root = $this->temporary . '/multi';
        mkdir($root . '/base/CRM', 0700, true);
        mkdir($root . '/addon/CRM', 0700, true);
        mkdir($root . '/toolbelt/bin', 0700, true);
        file_put_contents($root . '/toolbelt/bin/ckconform', "#!/bin/sh\nexit 0\n");
        chmod($root . '/toolbelt/bin/ckconform', 0700);
        (new Runner())->capture(['git', 'init', '-q'], null, $root);
        foreach (['base/CRM/A.php', 'addon/CRM/B.php'] as $file) {
            file_put_contents($root . '/' . $file, '<?php');
        }
        (new Runner())->capture(['git', 'add', '.'], null, $root);
        (new Runner())->capture(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-qm', 'fixture'], null, $root);
        file_put_contents($root . '/base/CRM/A.php', "<?php\n// changed\n");
        file_put_contents($root . '/addon/CRM/B.php', "<?php\n// changed\n");
        file_put_contents($root . '/addon/CRM/C.php', '<?php');
        $files = new Files($root);
        $before = getcwd();
        chdir($root . '/addon');
        try {
            // Paths relative to the extension, and the neighbour left out.
            self::assertSame(['CRM/B.php', 'CRM/C.php'], $files->changedPhp());
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testRepositoryFilesTrustsTheWorktreeRootRatherThanTheCurrentDirectory(): void
    {
        $root = $this->temporary . '/guard';
        mkdir($root . '/addon', 0700, true);
        mkdir($root . '/.git', 0700, true);
        $recorder = new class () extends Runner {
            /** @var list<string> */
            public array $commands = [];

            /** @param non-empty-list<string> $command @return array{status: int, output: string} */
            public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
            {
                $this->commands[] = implode(' ', $command);
                return ['status' => 1, 'output' => ''];
            }
        };
        $files = new Files($root, $recorder);
        $before = getcwd();
        chdir($root . '/addon');
        try {
            self::assertFalse($files->isGitCheckout());
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
        self::assertSame(
            'git -c safe.directory=' . (string) realpath($root) . ' rev-parse --is-inside-work-tree',
            $recorder->commands[0],
        );
    }

    public function testReleaseRoutesGitThroughTheGuardedEntryPoint(): void
    {
        $root = $this->temporary . '/release';
        mkdir($root . '/.git', 0700, true);
        file_put_contents($root . '/info.xml', '<extension key="org.example.safe"><file>safe</file><version>1.2.3</version></extension>');
        // Fails the ref check, so the run stops right after the guarded rev-parses.
        $runner = new class () extends Runner {
            /** @var list<list<string>> */
            public array $commands = [];

            public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
            {
                $this->commands[] = $command;
                if (str_ends_with($command[0], 'ckconform')) {
                    return ['status' => 0, 'output' => "dir tests\n"];
                }
                return ['status' => in_array('--verify', $command, true) ? 1 : 0, 'output' => "true\n"];
            }
        };
        $before = getcwd();
        chdir($root);
        ob_start();
        try {
            self::assertNotSame(0, (new ReleaseCommand(dirname(__DIR__, 2), $runner))
                ->run(['dist', '--version', '1.2.3', '--output', $root . '/out']));
        } finally {
            ob_end_clean();
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
        $guard = 'safe.directory=' . (string) realpath($root);
        $calls = array_values(array_filter($runner->commands, static fn(array $command): bool => $command[0] === 'git'));
        self::assertCount(2, $calls);
        foreach ($calls as $command) {
            self::assertSame(['git', '-c', $guard], array_slice($command, 0, 3), implode(' ', $command));
        }
    }

    public function testNoCommandBuildsAGitCommandLineOfItsOwn(): void
    {
        // The safe.directory guard exists once, in Files::gitCommand(); a literal
        // git command line anywhere else is a call that would run unguarded.
        $source = dirname(__DIR__, 2) . '/toolbelt/lib/php/src';
        $offenders = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php'
                || $file->getPathname() === $source . '/Repository/Files.php') {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), "['git',")) {
                $offenders[] = $file->getFilename();
            }
        }
        self::assertSame([], $offenders);
    }

    public function testDirectoryRemovesATreeButOnlyUnlinksALinkInsideIt(): void
    {
        $first = Directory::temporary('ck-directory');
        $second = Directory::temporary('ck-directory');
        self::assertNotSame($first, $second);
        self::assertSame(0700, fileperms($first) & 0777);
        mkdir($this->temporary . '/outside');
        touch($this->temporary . '/outside/kept');
        mkdir($first . '/a/b', 0700, true);
        touch($first . '/a/b/file');
        symlink($this->temporary . '/outside', $first . '/a/link');
        Directory::remove($first);
        Directory::remove($second);
        Directory::remove($first);
        self::assertDirectoryDoesNotExist($first);
        self::assertDirectoryDoesNotExist($second);
        self::assertFileExists($this->temporary . '/outside/kept');
    }

    public function testRunnerNamesAProgramThatIsNotOnPathInsteadOfSpawningIt(): void
    {
        $runner = new Runner();
        $result = $runner->capture(['ck-no-such-tool'], ['PATH' => $this->temporary]);
        self::assertSame(2, $result['status']);
        self::assertSame('ck: ck-no-such-tool was not found on PATH (' . getenv('PATH') . ")\n", $result['output']);

        // An absolute path is not looked up, so it gets its own message.
        $result = $runner->capture([$this->temporary . '/nope']);
        self::assertSame(2, $result['status']);
        self::assertSame("ck: {$this->temporary}/nope is not an executable file\n", $result['output']);

        // A program that IS there still runs.
        $result = $runner->capture(['env'], ['PATH' => '/usr/bin:/bin']);
        self::assertSame(0, $result['status']);
    }

    public function testLintIgnoresALeftoverPhpcsConfig(): void
    {
        file_put_contents($this->temporary . '/phpcs.xml.dist', '<?xml version="1.0"?><ruleset name="p"><file>.</file><rule ref="CiviKitchen"/></ruleset>');
        $before = getcwd();
        chdir($this->temporary);
        ob_start();
        try {
            $runner = new RecordingRunner();
            self::assertSame(0, (new LintCommand(dirname(__DIR__, 2), $runner))->run(['--all']));
            $tools = array_map(static fn (array $command): string => basename($command[0]), $runner->commands);
            self::assertSame([], array_intersect($tools, ['phpcs', 'phpcbf']));
        } finally {
            ob_end_clean();
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testLintRunsMagoWithTheBaselineAndTheRepoAsExtensionWorkspace(): void
    {
        $before = getcwd();
        chdir($this->temporary);
        ob_start();
        try {
            $runner = new RecordingRunner();
            self::assertSame(0, (new LintCommand(dirname(__DIR__, 2), $runner))->run(['--all']));
            self::assertContains(dirname(__DIR__, 2) . '/toolbelt/mago/mago.toml', $this->firstCommand($runner, '/fake/mago'));
            self::assertSame(getcwd(), $runner->environments['/fake/mago']['CK_MAGO_WORKSPACE'] ?? null);
        } finally {
            ob_end_clean();
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testLintFixInsertsTheStrictTypesDeclareBeforeTheSafeFixes(): void
    {
        $before = getcwd();
        chdir($this->temporary);
        ob_start();
        try {
            $runner = new RecordingRunner();
            self::assertSame(0, (new LintCommand(dirname(__DIR__, 2), $runner))->run(['--fix', '--all']));
            $mago = array_values(array_filter($runner->commands, static fn (array $command): bool => $command[0] === '/fake/mago' && !in_array('config', $command, true)));
            self::assertCount(2, $mago);
            self::assertContains('--potentially-unsafe', $mago[0]);
            self::assertSame('strict-types', $mago[0][array_search('--only', $mago[0], true) + 1]);
            self::assertNotContains('--potentially-unsafe', $mago[1]);
        } finally {
            ob_end_clean();
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testLintFixReadsTheLinterConfigPastThePhpVersionNotice(): void
    {
        $before = getcwd();
        chdir($this->temporary);
        file_put_contents($this->temporary . '/composer.json', '{"require":{"php":">=8.1"}}');
        ob_start();
        try {
            $runner = new RecordingRunner();
            self::assertSame(0, (new LintCommand(dirname(__DIR__, 2), $runner))->run(['--fix', '--all']));
            self::assertTrue($runner->passedArgument('--php-version'));
            self::assertTrue($runner->passedArgument('--potentially-unsafe'));
        } finally {
            ob_end_clean();
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testLintFixLeavesAStrictTypesOptOutAlone(): void
    {
        $before = getcwd();
        chdir($this->temporary);
        ob_start();
        try {
            foreach (['{"rules": {"strict-types": {"enabled": false}}}' => 1, 'not json' => 2] as $config => $commands) {
                $runner = new RecordingRunner();
                $runner->magoLinterConfig = $config;
                $status = (new LintCommand(dirname(__DIR__, 2), $runner))->run(['--fix', '--all']);
                $lint = array_filter($runner->commands, static fn (array $command): bool => $command[0] === '/fake/mago' && !in_array('config', $command, true));
                self::assertNotContains('--potentially-unsafe', array_merge(...array_values($lint) ?: [[]]), $config);
                self::assertSame($commands === 1 ? 0 : 2, $status, $config);
                self::assertCount($commands === 1 ? 1 : 0, $lint, $config);
            }
        } finally {
            ob_end_clean();
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testLintFailsExactlyWhenMagoReportsFindings(): void
    {
        $before = getcwd();
        chdir($this->temporary);
        ob_start();
        try {
            foreach ([[['--all'], 0, 0], [['--all'], 1, 1], [['--fix', '--all'], 0, 0], [['--fix', '--all'], 1, 1]] as [$arguments, $exit, $expected]) {
                $runner = new RecordingRunner();
                $runner->exitCodes['/fake/mago'] = $exit;
                self::assertSame($expected, (new LintCommand(dirname(__DIR__, 2), $runner))->run($arguments), implode(' ', $arguments) . " mago exit {$exit}");
            }
        } finally {
            ob_end_clean();
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testLintRefusesOutsideAGitCheckoutInsteadOfPassingUnchecked(): void
    {
        file_put_contents($this->temporary . '/Probe.php', "<?php\nreturn @unlink('/tmp/nope');\n");
        // Real git, faked toolchain: every mode must stop before a linter runs.
        $runner = new class () extends Runner {
            /** @var list<list<string>> */
            public array $commands = [];

            public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
            {
                $this->commands[] = $command;
                if ($command[0] === 'git') {
                    return parent::capture($command, $environment, $workingDirectory);
                }
                return ['status' => 0, 'output' => ''];
            }

            public function which(string $program): ?string
            {
                return $program === 'mago' ? '/fake/mago' : parent::which($program);
            }

            public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
            {
                $this->commands[] = $command;
                return 0;
            }
        };
        $before = getcwd();
        chdir($this->temporary);
        try {
            foreach ([[], ['--all'], ['Probe.php']] as $arguments) {
                self::assertSame(2, (new LintCommand(dirname(__DIR__, 2), $runner))->run($arguments), implode(' ', $arguments));
            }
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
        self::assertSame([], array_values(array_filter($runner->commands, static fn(array $command): bool => $command[0] !== 'git')));
    }

    public function testFormatChecksUntrackedFilesToo(): void
    {
        $root = $this->temporary . '/fmt';
        mkdir($root . '/Civi', 0700, true);
        file_put_contents($root . '/info.xml', '<extension key="org.example.safe"><file>safe</file></extension>');
        file_put_contents($root . '/Civi/Tracked.php', "<?php\n");
        (new Runner())->capture(['git', 'init', '-q'], null, $root);
        (new Runner())->capture(['git', 'add', '.'], null, $root);
        (new Runner())->capture(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-qm', 'fixture'], null, $root);
        file_put_contents($root . '/Civi/Untracked.php', "<?php  class  Untracked {}\n");
        // Real git, faked toolchain: the file list is what is under test.
        $runner = new class () extends Runner {
            /** @var list<list<string>> */
            public array $commands = [];

            public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
            {
                $this->commands[] = $command;
                if ($command[0] === 'git') {
                    return parent::capture($command, $environment, $workingDirectory);
                }
                return ['status' => 0, 'output' => ''];
            }

            public function which(string $program): ?string
            {
                return $program === 'mago' ? '/fake/mago' : parent::which($program);
            }

            public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
            {
                $this->commands[] = $command;
                return 0;
            }
        };
        $before = getcwd();
        chdir($root);
        ob_start();
        try {
            self::assertSame(0, (new FormatCommand(dirname(__DIR__, 2), $runner))->run(['--check']));
        } finally {
            ob_end_clean();
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
        $formatted = array_merge(...array_values(array_filter($runner->commands, static fn(array $command): bool => $command[0] === '/fake/mago')));
        self::assertContains('Civi/Untracked.php', $formatted);
        self::assertContains('Civi/Tracked.php', $formatted);
    }

    /**
     * The first recorded command whose program is $program.
     *
     * @return list<string>
     */
    public function testVendorInstallerInstallsOnlyCheckoutsThatNeedAndMayWriteVendor(): void
    {
        $lock = static fn(string $name): string => json_encode(['packages' => [['name' => $name]], 'packages-dev' => []], JSON_THROW_ON_ERROR);
        $names = ['needs', 'fails', 'vendored', 'half', 'plain', 'core', 'devcore', 'badlock', 'readonly', 'readonlyneeds'];
        foreach ($names as $name) {
            mkdir($this->temporary . '/' . $name);
        }
        foreach (array_diff($names, ['plain']) as $name) {
            file_put_contents($this->temporary . "/{$name}/composer.json", '{"require":{"php":">=8.1","ext-json":"*"}}');
        }
        file_put_contents($this->temporary . '/readonlyneeds/composer.json', '{"require":{"php":">=8.1","guzzlehttp/guzzle":"^7"}}');
        // Core as a dev requirement without a lock still resolves into vendor/.
        file_put_contents($this->temporary . '/devcore/composer.json', '{"require-dev":{"civicrm/civicrm-core":"^6"}}');
        mkdir($this->temporary . '/vendored/vendor');
        touch($this->temporary . '/vendored/vendor/autoload.php');
        // An interrupted install leaves vendor/ without its autoloader.
        mkdir($this->temporary . '/half/vendor');
        file_put_contents($this->temporary . '/needs/composer.lock', $lock('guzzlehttp/guzzle'));
        file_put_contents($this->temporary . '/core/composer.lock', $lock('civicrm/civicrm-core'));
        file_put_contents($this->temporary . '/badlock/composer.lock', '{"packages": [');
        chmod($this->temporary . '/readonly', 0555);
        chmod($this->temporary . '/readonlyneeds', 0555);
        $runner = new class () extends Runner {
            /** @var list<string> */
            public array $calls = [];

            public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
            {
                $this->calls[] = basename((string) $workingDirectory) . ': ' . implode(' ', $command);
                return basename((string) $workingDirectory) === 'fails' ? 1 : 0;
            }
        };
        try {
            ob_start();
            $status = (new VendorInstaller($runner))->install(array_map(
                fn(string $name): string => $this->temporary . '/' . $name . '/',
                [...$names, 'missing'],
            ));
            ob_end_clean();
        } finally {
            chmod($this->temporary . '/readonly', 0755);
            chmod($this->temporary . '/readonlyneeds', 0755);
        }
        self::assertSame(1, $status);
        self::assertSame([
            'needs: composer install --no-interaction --no-progress --prefer-dist',
            'fails: composer install --no-interaction --no-progress --prefer-dist',
            'half: composer install --no-interaction --no-progress --prefer-dist',
        ], $runner->calls);
        self::assertSame(0, (new VendorInstaller($runner))->install([]));
        // Read-only mounts are the caller's choice: a note, not a failure.
        chmod($this->temporary . '/readonlyneeds', 0555);
        try {
            self::assertSame(0, (new VendorInstaller($runner))->install([
                $this->temporary . '/readonly', $this->temporary . '/readonlyneeds', $this->temporary . '/vendored',
                $this->temporary . '/plain', $this->temporary . '/devcore',
            ]));
        } finally {
            chmod($this->temporary . '/readonlyneeds', 0755);
        }
        self::assertSame(1, (new VendorInstaller($runner))->install([$this->temporary . '/badlock']));
    }

    private function firstCommand(RecordingRunner $runner, string $program): array
    {
        foreach ($runner->commands as $command) {
            if ($command[0] === $program) {
                return $command;
            }
        }
        self::fail("no {$program} command was recorded");
    }

    /** @param array<string, mixed> $contents */
    private function profile(string $name, array $contents): string
    {
        $file = $this->temporary . '/' . $name;
        file_put_contents($file, json_encode($contents, JSON_THROW_ON_ERROR));
        return $file;
    }

    /**
     * A committed extension repository whose civikitchen.yaml declares these build outputs.
     *
     * @param list<string> $outputs
     */
    private function stagedRepository(array $outputs): string
    {
        $repository = $this->temporary . '/extension';
        $this->write($repository, 'info.xml', '<extension key="org.example.staged"><file>staged</file><version>1.0.0</version></extension>');
        $this->write($repository, 'staged.php', '<?php');
        $this->write($repository, 'tests/StagedTest.php', '<?php');
        $this->write($repository, 'package.json', '{"packageManager": "bun@1.4.0"}');
        $this->write($repository, 'bun.lock', '{}');
        $this->write($repository, '.gitignore', "/ang/\n/dist/\n/vendor/\n");
        $this->write($repository, 'civikitchen.yaml', "version: 1\npolicy:\n  dist:\n    build:\n      tool: bun\n      outputs:\n"
            . implode('', array_map(static fn (string $output): string => "        - {$output}\n", $outputs)));
        $this->git($repository, 'init', '-q');
        $this->git($repository, 'add', '-A');
        $this->git($repository, '-c', 'user.name=ck', '-c', 'user.email=ck@example.org', 'commit', '-q', '-m', 'fixture');
        return $repository;
    }

    private function write(string $repository, string $path, string $contents): void
    {
        $file = $repository . '/' . $path;
        if (!is_dir(dirname($file))) {
            self::assertTrue(mkdir(dirname($file), 0777, true));
        }
        self::assertNotFalse(file_put_contents($file, $contents));
    }

    /** Git in the fixture, insulated from the developer's own configuration. */
    private function git(string $repository, string ...$arguments): string
    {
        $result = (new Runner())->captureSeparate(
            ['git', ...$arguments],
            [...getenv(), 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null'],
            $repository,
        );
        self::assertSame(0, $result['status'], $result['stderr']);
        return trim($result['stdout']);
    }

    /** @return array{0: int, 1: list<string>} the exit status and the entry names of the zip it left */
    private function releaseDist(string $repository, string $ref = 'HEAD'): array
    {
        $output = $this->temporary . '/dist';
        $status = $this->inRepository($repository, static fn (): int => (new ReleaseCommand(dirname(__DIR__, 2)))
            ->run(['dist', '--ref', $ref, '--output', $output]));
        $entries = [];
        $zip = new ZipArchive();
        if (is_file($output . '/org.example.staged-1.0.0.zip') && $zip->open($output . '/org.example.staged-1.0.0.zip') === true) {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entries[] = (string) $zip->getNameIndex($index);
            }
            $zip->close();
        }
        return [$status, $entries];
    }

    /** @param callable(): int $command */
    private function inRepository(string $repository, callable $command): int
    {
        $before = getcwd();
        chdir($repository);
        ob_start();
        try {
            return $command();
        } finally {
            ob_end_clean();
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }
}

final class RecordingRunner extends Runner
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function which(string $program): ?string
    {
        return $program === 'mago' ? '/fake/mago' : parent::which($program);
    }

    public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        $this->commands[] = $command;
        if ($command[0] === 'git') {
            $joined = implode(' ', $command);
            if (str_contains($joined, 'rev-parse')) {
                return ['status' => 0, 'output' => "true\n"];
            }
            return str_contains($joined, '*.php') || in_array('-odd.php', $command, true)
                ? ['status' => 0, 'output' => "-odd.php\n"]
                : ['status' => 0, 'output' => ''];
        }
        if (str_ends_with($command[0], 'ckconform')) {
            return ['status' => 0, 'output' => "dir tests\n"];
        }
        if ($command[0] === '/fake/mago' && in_array('config', $command, true)) {
            $result = $this->captureSeparate($command, $environment, $workingDirectory);
            return ['status' => $result['status'], 'output' => $result['stderr'] . $result['stdout']];
        }
        return ['status' => 0, 'output' => ''];
    }

    public function captureSeparate(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        if ($command[0] !== '/fake/mago' || !in_array('config', $command, true)) {
            return parent::captureSeparate($command, $environment, $workingDirectory);
        }
        $this->commands[] = $command;
        // Like mago: a `--php-version` override is logged to stderr.
        $stderr = in_array('--php-version', $command, true) ? " INFO Overriding PHP version with 8.1.0.\n" : '';
        return ['status' => 0, 'stdout' => $this->magoLinterConfig, 'stderr' => $stderr];
    }

    /** What `mago config --show linter` prints. */
    public string $magoLinterConfig = '{"rules": {"strict-types": {"enabled": true}}}';

    /** @var array<string, int> exit status per program, 0 when absent */
    public array $exitCodes = [];

    /** @var array<string, ?array<string, string>> the environment each program last ran with */
    public array $environments = [];

    public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
    {
        $this->commands[] = $command;
        $this->environments[$command[0]] = $environment;
        return $this->exitCodes[$command[0]] ?? 0;
    }

    public function passedArgument(string $argument): bool
    {
        foreach ($this->commands as $command) {
            if (in_array($argument, $command, true)) {
                return true;
            }
        }
        return false;
    }
}

/** A git checkout with source, generated and test PHP; records the mago run and the config it read. */
final class AnalyzeRunner extends Runner
{
    /** @var list<string> */
    public array $command = [];

    public string $config = '';

    public string $configPath = '';

    /** @var array<string, string> */
    public array $environment = [];

    public function __construct(private readonly string $cvRoot = '')
    {
    }

    public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        $joined = implode(' ', $command);
        if ($command[0] === 'cv') {
            return ['status' => $this->cvRoot === '' ? 1 : 0, 'output' => $this->cvRoot];
        }
        if (str_contains($joined, 'rev-parse')) {
            return ['status' => 0, 'output' => "true\n"];
        }
        if (str_contains($joined, 'ls-files')) {
            return ['status' => 0, 'output' => "CRM/Widget/DAO/Thing.php\nCivi/Widget.php\nlib/upstream.php\nphpstanBootstrap.php\ntests/phpunit/WidgetTest.php\nwidget.civix.php\n"];
        }
        return ['status' => 0, 'output' => ''];
    }

    /** policy.vendored_paths */
    public function captureSeparate(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        return ['status' => 0, 'stdout' => str_ends_with($command[0], 'ckconform') ? "lib -- unmodified upstream\n" : '', 'stderr' => ''];
    }

    public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
    {
        $this->command = $command;
        $this->configPath = $command[4];
        $this->config = (string) file_get_contents($this->configPath);
        $this->environment = $environment ?? [];
        return is_file('mago.toml') ? 1 : 0;
    }
}

/** Records each mago run and the config it read; answers --list-rules with two Deprecation rules. */
final class CompatibilityRunner extends Runner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var list<string> */
    public array $configs = [];

    /** @var list<string> */
    public array $configPaths = [];

    /** @param array<string, int> $exitCodes exit status per mago subcommand or flag */
    public function __construct(private readonly array $exitCodes = [])
    {
    }

    public function captureSeparate(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        $this->commands[] = $command;
        $rules = [['code' => 'explicit-nullable-param', 'category' => 'Deprecation'], ['code' => 'strict-types', 'category' => 'Safety'], ['code' => 'deprecated-cast', 'category' => 'Deprecation']];
        return ['status' => 0, 'stdout' => (string) json_encode($rules), 'stderr' => ''];
    }

    public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
    {
        $this->commands[] = $command;
        $this->configPaths[] = $command[4];
        $this->configs[] = (string) file_get_contents($command[4]);
        $status = 0;
        foreach ($this->exitCodes as $argument => $code) {
            $status = in_array($argument, $command, true) ? max($status, $code) : $status;
        }
        return $status;
    }
}

/** A git checkout holding Civi/Widget.php, with a canned policy.template_custom. */
final class MigrateRunner extends Runner
{
    public function __construct(private readonly string $templateCustom)
    {
    }

    public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        $joined = implode(' ', $command);
        return match (true) {
            str_contains($joined, 'rev-parse') => ['status' => 0, 'output' => "true\n"],
            str_contains($joined, 'ls-files') => ['status' => 0, 'output' => "Civi/Widget.php\n"],
            default => ['status' => 0, 'output' => ''],
        };
    }

    public function captureSeparate(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        return ['status' => 0, 'stdout' => in_array('template_custom', $command, true) ? $this->templateCustom : '', 'stderr' => ''];
    }
}

/** Answers `ckconform --policy <key>` with one canned value. */
final class PolicyRunner extends Runner
{
    public function __construct(private readonly string $value)
    {
    }

    public function captureSeparate(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        return ['status' => 0, 'stdout' => $this->value . "\n", 'stderr' => ''];
    }
}
