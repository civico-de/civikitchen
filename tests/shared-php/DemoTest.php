<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Cli\DemoCommand;
use CiviKitchen\Toolbelt\Demo\Cms;
use CiviKitchen\Toolbelt\Demo\ComparisonPage;
use CiviKitchen\Toolbelt\Demo\CoreDiff;
use CiviKitchen\Toolbelt\Demo\Recorder;
use CiviKitchen\Toolbelt\Demo\Stack;
use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use PHPUnit\Framework\TestCase;

final class DemoTest extends TestCase
{
    private const DIFF = <<<'DIFF'
diff --git a/templates/CRM/common/footer.tpl b/templates/CRM/common/footer.tpl
--- a/templates/CRM/common/footer.tpl
+++ b/templates/CRM/common/footer.tpl
@@ -1 +1 @@
-<div id="civicrm-footer">
+<div class="crm-footer-area"><div id="civicrm-footer">
diff --git a/js/footer.js b/js/footer.js
new file mode 100644
--- /dev/null
+++ b/js/footer.js
@@ -0,0 +1 @@
+// footer

DIFF;

    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = Directory::temporary('civikitchen-demo');
    }

    protected function tearDown(): void
    {
        Directory::remove($this->temporary);
    }

    public function testRecordsEveryCmsBeforeAndAfterThenRemovesTheStacks(): void
    {
        $runner = new DemoRunner(self::DIFF);
        $runner->errors['wordpress-after'] = ['Uncaught <TypeError>'];
        $out = $this->temporary . '/out';

        $status = $this->demo($runner, ['37671', '--page', '/civicrm/admin/search', '--cms', 'standalone,wordpress',
            '--cv', 'en -q riverlea', '--out', $out]);

        self::assertSame(0, $status);
        self::assertContains(['curl', '-fsSL', '--max-time', '120', 'https://github.com/civicrm/civicrm-core/pull/37671.diff'], $runner->commands);
        self::assertSame(['standalone-before', 'standalone-after', 'wordpress-before', 'wordpress-after'], array_column($runner->recordings, 'name'));
        // The change lands between the two recordings of a site, and only then.
        $standalone = $runner->siteLog('ck-demo-pr37671-standalone');
        self::assertSame(['sh -c cv en -q riverlea', 'cv flush', 'record before', 'patch apply', 'cv flush', 'record after', 'cv ev echo CRM_Utils_System::version();', 'down'],
            array_slice($standalone, array_search('sh -c cv en -q riverlea', $standalone, true)));
        $wordpress = $runner->recordings[2]['script'];
        self::assertSame('http://localhost:8300', $runner->recordings[0]['script']['site']);
        self::assertSame('http://localhost:8301', $wordpress['site']);
        self::assertSame('/wp-admin/admin.php?page=CiviCRM&q=', $wordpress['routePrefix']);
        self::assertSame(['civicrm/admin/search'], $wordpress['pages']);
        self::assertSame(['width' => 1280, 'height' => 800], $wordpress['size']);
        self::assertSame(2500, $wordpress['dwellMs']);

        $page = (string) file_get_contents("{$out}/index.html");
        self::assertStringContainsString('<a href="https://github.com/civicrm/civicrm-core/pull/37671">civicrm/civicrm-core#37671</a>', $page);
        self::assertStringContainsString('WordPress <span class="version">CiviCRM 6.20.alpha1</span>', $page);
        self::assertSame(4, substr_count($page, 'data:video/webm;base64,' . base64_encode('webm')));
        self::assertStringContainsString('<li>Uncaught &lt;TypeError&gt;</li>', $page);
        self::assertSame(2, $runner->count('down'));
        self::assertDirectoryDoesNotExist("{$out}/stacks");
    }

    public function testAChangeThatDoesNotApplyToOneCmsRecordsNothing(): void
    {
        $runner = new DemoRunner(self::DIFF);
        $runner->states['ck-demo-pr37671-wordpress'] = 'conflict';
        $out = $this->temporary . '/out';

        try {
            $this->demo($runner, ['37671', '--page', 'civicrm/admin/search', '--cms', 'standalone,wordpress', '--out', $out]);
            self::fail('a conflict must stop the run');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('the change does not apply to WordPress 6.20.alpha1', $e->getMessage());
            self::assertStringContainsString('Nothing was recorded.', $e->getMessage());
        }
        self::assertSame([], $runner->recordings);
        self::assertFileDoesNotExist("{$out}/index.html");
        self::assertSame(2, $runner->count('down'));
    }

    public function testAStackThatAlreadyHoldsTheChangeIsRevertedBeforeTheFirstRecording(): void
    {
        $runner = new DemoRunner(self::DIFF);
        $runner->states['ck-demo-pr37671-standalone'] = 'applied';

        $this->demo($runner, ['37671', '--page', 'civicrm/admin/search', '--out', $this->temporary . '/out']);

        $log = $runner->siteLog('ck-demo-pr37671-standalone');
        self::assertLessThan(array_search('record before', $log, true), array_search('patch revert', $log, true));
    }

    public function testKeptStacksStayAndTheNextRunFindsThemOnTheirPort(): void
    {
        $out = $this->temporary . '/out';
        $first = new DemoRunner(self::DIFF);
        $this->demo($first, ['37671', '--page', 'civicrm/admin/search', '--cms', 'drupal10', '--keep', '--out', $out]);
        self::assertSame(0, $first->count('down'));
        $compose = json_decode((string) file_get_contents("{$out}/stacks/drupal10.json"), true);
        self::assertSame(8300, $compose['x-ck-demo']['port']);
        // Drupal serves assets from a published copy, so every patch is followed by a publish.
        self::assertContains('composer civicrm:publish', $first->siteLog('ck-demo-pr37671-drupal10'));

        $second = new DemoRunner(self::DIFF);
        $second->states['ck-demo-pr37671-drupal10'] = 'applied';
        $this->demo($second, ['37671', '--page', 'civicrm/admin/search', '--cms', 'drupal10', '--out', $out], static fn(int $port): bool => false);
        self::assertSame('http://localhost:8300', $second->recordings[0]['script']['site']);
        self::assertNotContains('docker ps', array_map(static fn(array $command): string => implode(' ', array_slice($command, 0, 2)), $second->commands));
        self::assertFileDoesNotExist("{$out}/stacks/drupal10.json");
    }

    public function testAKeptStackOfAnotherChangeIsNotTouched(): void
    {
        $out = $this->temporary . '/out';
        $this->demo(new DemoRunner(self::DIFF), ['37671', '--page', 'civicrm/admin/search', '--keep', '--out', $out]);
        $kept = (string) file_get_contents("{$out}/stacks/standalone.json");

        $runner = new DemoRunner(self::DIFF);
        try {
            $this->demo($runner, ['40000', '--page', 'civicrm/admin/search', '--out', $out]);
            self::fail('another change must not take over a kept stack');
        } catch (RuntimeException $e) {
            self::assertStringContainsString("{$out}/stacks/standalone.json holds a kept stack of another change or image", $e->getMessage());
        }
        self::assertSame($kept, file_get_contents("{$out}/stacks/standalone.json"));
        self::assertSame(0, $runner->count('down'));
    }

    public function testAKeptStackIsNotResumedOnAnotherImage(): void
    {
        $out = $this->temporary . '/out';
        $this->demo(new DemoRunner(self::DIFF), ['37671', '--page', 'civicrm/admin/search', '--keep', '--out', $out,
            '--image', 'standalone=ghcr.io/civico-de/civikitchen:standalone-beta']);
        $kept = (string) file_get_contents("{$out}/stacks/standalone.json");

        $runner = new DemoRunner(self::DIFF);
        $this->expectExceptionMessage('holds a kept stack of another change or image');
        try {
            $this->demo($runner, ['37671', '--page', 'civicrm/admin/search', '--out', $out]);
        } finally {
            self::assertSame($kept, file_get_contents("{$out}/stacks/standalone.json"));
            self::assertSame([], $runner->images);
        }
    }

    public function testAStackOfTheSameNameWithoutItsComposeFileIsNotTouched(): void
    {
        $runner = new DemoRunner(self::DIFF);
        $runner->existingProjects[] = 'ck-demo-pr37671-standalone';

        $this->expectExceptionMessage('a stack named ck-demo-pr37671-standalone exists without');
        $this->demo($runner, ['37671', '--page', 'civicrm/admin/search', '--out', $this->temporary . '/out']);
    }

    public function testStepsAndImagesReachTheRecorderAndTheStack(): void
    {
        $steps = $this->temporary . '/steps.ts';
        file_put_contents($steps, 'export default async () => {};');
        $runner = new DemoRunner(self::DIFF);

        $this->demo($runner, ['https://github.com/civicrm/civicrm-core/pull/37671/files', '--steps', $steps, '--image',
            'standalone=ghcr.io/civico-de/civikitchen:standalone-beta', '--size', '1024x768', '--dwell', '0',
            '--out', $this->temporary . '/out']);

        $recording = $runner->recordings[0];
        self::assertSame('/ck-demo/steps.mts', $recording['script']['steps']);
        self::assertContains(realpath($steps) . ':/ck-demo/steps.mts:ro', $recording['command']);
        self::assertSame(['width' => 1024, 'height' => 768], $recording['script']['size']);
        self::assertSame(0, $recording['script']['dwellMs']);
        self::assertSame('ghcr.io/civico-de/civikitchen:standalone-beta', $runner->images['ck-demo-pr37671-standalone']);
    }

    public function testWithoutDockerNothingStarts(): void
    {
        $runner = new DemoRunner(self::DIFF);
        $runner->docker = false;
        $this->expectExceptionMessage('demo runs on the host and needs docker on PATH');
        $this->demo($runner, ['37671', '--page', 'civicrm/admin/search', '--out', $this->temporary . '/out']);
    }

    public function testAnOutputDirectoryDockerCannotSeeStopsBeforeAnyStack(): void
    {
        $runner = new DemoRunner(self::DIFF);
        $runner->shareStatus = 1;
        $out = $this->temporary . '/out';

        try {
            $this->demo($runner, ['37671', '--page', 'civicrm/admin/search', '--out', $out]);
            self::fail('an output directory Docker cannot see must stop the run');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('Docker does not share ' . realpath($out) . ':', $e->getMessage());
        }
        self::assertSame([], $runner->sites);
        self::assertSame(['.', '..'], scandir($out));
    }

    public function testASiteThatDoesNotBootStopsTheRunAndEveryStackIsRemoved(): void
    {
        $runner = new DemoRunner(self::DIFF);
        $runner->failing[] = 'ck-demo-pr37671-wordpress up --wait';
        $out = $this->temporary . '/out';

        try {
            $this->demo($runner, ['37671', '--page', 'civicrm/admin/search', '--cms', 'standalone,wordpress', '--out', $out]);
            self::fail('a site that does not boot must stop the run');
        } catch (RuntimeException $e) {
            self::assertSame("WordPress did not boot. The end of its log:\n[civikitchen] ERROR: provisioning failed\n", $e->getMessage());
        }
        self::assertSame([], $runner->recordings);
        self::assertSame(2, $runner->count('down'));
        self::assertDirectoryDoesNotExist("{$out}/stacks");
    }

    public function testAChangeThatFailsToApplyAfterTheBeforeRecordingWritesNoPage(): void
    {
        $runner = new DemoRunner(self::DIFF);
        $runner->failing[] = 'ck-demo-pr37671-standalone patch apply';
        $out = $this->temporary . '/out';
        mkdir($out);
        file_put_contents("{$out}/index.html", 'a page from an earlier run');

        try {
            $this->demo($runner, ['37671', '--page', 'civicrm/admin/search', '--out', $out]);
            self::fail('a failed apply must stop the run');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith("could not apply the change on Standalone:\npatch: **** Can't rename file", $e->getMessage());
        }
        self::assertSame(['standalone-before'], array_column($runner->recordings, 'name'));
        self::assertFileDoesNotExist("{$out}/index.html");
        self::assertSame(1, $runner->count('down'));
    }

    public function testAFailingCvCommandNamesItself(): void
    {
        $runner = new DemoRunner(self::DIFF);
        $runner->failing[] = 'ck-demo-pr37671-standalone sh -c cv en -q nope';

        $this->expectExceptionMessage("cv en -q nope failed on Standalone:\nError: no such extension");
        $this->demo($runner, ['37671', '--page', 'civicrm/admin/search', '--cv', 'en -q nope', '--out', $this->temporary . '/out']);
    }

    public function testAStackThatCannotBeRemovedKeepsItsComposeFileAndTheOthersStillGo(): void
    {
        $runner = new DemoRunner(self::DIFF);
        $runner->failing[] = 'ck-demo-pr37671-standalone down';
        $out = $this->temporary . '/out';

        $status = $this->demo($runner, ['37671', '--page', 'civicrm/admin/search', '--cms', 'standalone,wordpress', '--out', $out]);

        self::assertSame(0, $status);
        self::assertSame(2, $runner->count('down'));
        // The next run finds the leftover stack through its compose file.
        self::assertFileExists("{$out}/stacks/standalone.json");
        self::assertFileDoesNotExist("{$out}/stacks/wordpress.json");
        self::assertFileExists("{$out}/index.html");
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidArguments(): iterable
    {
        yield 'no change' => [['--page', 'civicrm/admin/search'], 'which change?'];
        yield 'two changes' => [['1', '2', '--page', 'civicrm/a'], 'one change at a time'];
        yield 'nothing to record' => [['1'], 'nothing to record'];
        yield 'route outside CiviCRM' => [['1', '--page', 'admin/search'], '--page takes a CiviCRM route'];
        yield 'unknown CMS' => [['1', '--page', 'civicrm/a', '--cms', 'typo3'], "unknown CMS 'typo3'"];
        yield 'image for an unrecorded CMS' => [['1', '--page', 'civicrm/a', '--image', 'joomla=x'], '--image names joomla'];
        yield 'malformed image' => [['1', '--page', 'civicrm/a', '--image', 'standalone'], '--image takes <cms>=<image>'];
        yield 'malformed size' => [['1', '--page', 'civicrm/a', '--size', 'big'], '--size takes'];
        yield 'negative dwell' => [['1', '--page', 'civicrm/a', '--dwell', '-1'], '--dwell takes seconds'];
        yield 'missing value' => [['1', '--page'], '--page needs a value'];
        yield 'unknown option' => [['1', '--page', 'civicrm/a', '--fast', 'yes'], 'unknown option --fast'];
        yield 'missing steps' => [['1', '--steps', '/nonexistent/steps.ts'], 'no steps module'];
        yield 'CommonJS steps' => [['1', '--steps', __FILE__], '--steps takes a .ts, .mts, .js, .mjs module'];
        yield 'neither PR nor file' => [['./missing.diff', '--page', 'civicrm/a'], 'neither a civicrm-core PR number or URL nor a diff file'];
    }

    /**
     * @param list<string> $arguments
     * @dataProvider invalidArguments
     */
    public function testInvalidArgumentsStopBeforeDocker(array $arguments, string $message): void
    {
        $runner = new DemoRunner(self::DIFF);
        try {
            $this->demo($runner, $arguments);
            self::fail('expected an error');
        } catch (RuntimeException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame([], array_filter($runner->commands, static fn(array $command): bool => $command[0] === 'docker'));
    }

    public function testHelpAndNoArguments(): void
    {
        ob_start();
        $help = (new DemoCommand(dirname(__DIR__, 2), new DemoRunner(self::DIFF)))->run(['--help']);
        $bare = (new DemoCommand(dirname(__DIR__, 2), new DemoRunner(self::DIFF)))->run([]);
        $output = (string) ob_get_clean();
        self::assertSame([0, 2], [$help, $bare]);
        self::assertStringContainsString('ck demo <PR number|PR URL|diff file>', $output);
    }

    public function testADiffFileNamesTheRunAndAFileWithoutAGitDiffIsRefused(): void
    {
        $file = $this->temporary . '/My Footer Fix.patch';
        file_put_contents($file, self::DIFF);
        $diff = CoreDiff::load($file, new DemoRunner(''));
        self::assertSame(['my-footer-fix', 'My Footer Fix.patch', null], [$diff->label, $diff->title, $diff->url]);
        self::assertSame(['templates/CRM/common/footer.tpl', 'js/footer.js'], $diff->files);

        $this->expectExceptionMessage('holds no git diff');
        CoreDiff::parse('x', 'empty.diff', null, "--- a\n+++ b\n");
    }

    public function testAFailedDownloadIsAnError(): void
    {
        $runner = new DemoRunner(self::DIFF);
        $runner->curlStatus = 22;
        $this->expectExceptionMessage('could not download https://github.com/civicrm/civicrm-core/pull/5.diff (curl exit 22)');
        CoreDiff::load('5', $runner);
    }

    public function testStandaloneComposeConfiguration(): void
    {
        $file = $this->temporary . '/stacks/standalone.json';
        $stack = Stack::prepare(Cms::named('standalone'), 'ck-demo-x-standalone', $file, 8411, new DemoRunner(''));
        $config = json_decode((string) file_get_contents($file), true);
        self::assertSame($stack->config(), $config);
        self::assertSame(['127.0.0.1:8411:80'], $config['services']['app']['ports']);
        self::assertSame('http://localhost:8411', $config['services']['app']['environment']['CIVIKITCHEN_SITE_URL']);
        self::assertSame('ghcr.io/civico-de/civikitchen:standalone-master', $config['services']['app']['image']);
        self::assertSame('civicrm', $config['services']['db']['environment']['MARIADB_DATABASE']);
        self::assertSame(['condition' => 'service_healthy'], $config['services']['app']['depends_on']['db']);
    }

    public function testAComposeFileWithoutAPortIsRefused(): void
    {
        $file = $this->temporary . '/stacks/joomla.json';
        mkdir(dirname($file));
        file_put_contents($file, '{"name": "x"}');
        $this->expectExceptionMessage('names no port');
        Stack::prepare(Cms::named('joomla'), 'x', $file, 8300, new DemoRunner(''));
    }

    public function testARecordingWithoutAVideoOnTheHostIsAnError(): void
    {
        $runner = new DemoRunner('');
        $runner->writeVideos = false;
        $this->expectExceptionMessage('Docker must share');
        (new Recorder(dirname(__DIR__, 2), $runner))->record('img', $this->temporary, 'standalone-before', [], null);
    }

    public function testAFailedRecordingIsAnError(): void
    {
        $runner = new DemoRunner('');
        $runner->recorderStatus = 1;
        $this->expectExceptionMessage('recording standalone-before failed (exit 1)');
        (new Recorder(dirname(__DIR__, 2), $runner))->record('img', $this->temporary, 'standalone-before', [], null);
    }

    public function testThePageWithoutAPullRequestLinkOrPages(): void
    {
        $video = $this->temporary . '/v.webm';
        file_put_contents($video, 'webm');
        $run = ['video' => $video, 'errors' => []];
        $page = ComparisonPage::render(CoreDiff::parse('x', '<fix>.diff', null, self::DIFF), [], '1280×800',
            [['label' => 'Joomla', 'version' => '6.19.2', 'before' => $run, 'after' => $run]]);
        self::assertStringContainsString('<h1>&lt;fix&gt;.diff: before and after</h1>', $page);
        self::assertStringNotContainsString('<p>Pages:', $page);
        self::assertSame(2, substr_count($page, 'No script errors.'));
    }

    /** @param list<string> $arguments */
    private function demo(DemoRunner $runner, array $arguments, ?Closure $portFree = null): int
    {
        ob_start();
        try {
            return (new DemoCommand(dirname(__DIR__, 2), $runner, $portFree ?? static fn(int $port): bool => true))->run($arguments);
        } finally {
            ob_end_clean();
        }
    }
}

/** Docker, curl, cv and patch as `ck demo` drives them, with every site starting from stock. */
final class DemoRunner extends Runner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var array<string, list<string>> what ran on each site, by compose project */
    public array $sites = [];

    /** @var array<string, string> per project: stock, applied or conflict */
    public array $states = [];

    /** @var array<string, string> the app image of each project's compose file */
    public array $images = [];

    /** @var list<string> */
    public array $existingProjects = [];

    /** @var list<array{name: string, script: array<string, mixed>, command: list<string>}> */
    public array $recordings = [];

    /** @var array<string, list<string>> script errors per recording */
    public array $errors = [];

    /** @var list<string> steps that exit 1, as "<project> <entry>" with the entry siteLog() records */
    public array $failing = [];

    public bool $docker = true;
    public bool $writeVideos = true;
    public int $recorderStatus = 0;
    public int $curlStatus = 0;
    public int $shareStatus = 0;

    public function __construct(private readonly string $diff)
    {
    }

    public function which(string $program): ?string
    {
        return $program === 'docker' && !$this->docker ? null : "/fake/{$program}";
    }

    public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
    {
        $this->commands[] = $command;
        if (array_slice($command, 0, 2) === ['docker', 'compose']) {
            $project = $this->project($command);
            $this->images[$project] = json_decode((string) file_get_contents($command[3]), true)['services']['app']['image'];
            $entry = $command[4] === 'up' && in_array('--wait', $command, true) ? 'up --wait' : $command[4];
            if ($entry === 'down') {
                $this->sites[$project][] = 'down';
            }
            return in_array("{$project} {$entry}", $this->failing, true) ? 1 : 0;
        }
        return 0;
    }

    public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        $this->commands[] = $command;
        if (array_slice($command, 0, 2) === ['docker', 'ps']) {
            $project = substr((string) end($command), strlen('label=com.docker.compose.project='));
            return ['status' => 0, 'output' => in_array($project, $this->existingProjects, true) ? "abc123\n" : ''];
        }
        if (in_array('--entrypoint', $command, true) && in_array('test', $command, true)) {
            return ['status' => $this->shareStatus, 'output' => ''];
        }
        if (array_slice($command, 0, 2) === ['docker', 'compose'] && $command[4] === 'logs') {
            return ['status' => 0, 'output' => "[civikitchen] ERROR: provisioning failed\n"];
        }
        return ['status' => 0, 'output' => ''];
    }

    public function captureSeparate(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        $this->commands[] = $command;
        $project = $this->project($command);
        $inner = array_slice($command, array_search('app', $command, true) + 1);
        $state = $this->states[$project] ?? 'stock';
        if ($inner[0] === 'patch') {
            $reverse = in_array('-R', $inner, true);
            if (in_array('--dry-run', $inner, true)) {
                $fits = $state === ($reverse ? 'applied' : 'stock');
                return ['status' => $fits ? 0 : 1, 'stdout' => $fits ? '' : "Hunk #1 FAILED at 1.\n", 'stderr' => ''];
            }
            $entry = $reverse ? 'patch revert' : 'patch apply';
            $this->sites[$project][] = $entry;
            if (in_array("{$project} {$entry}", $this->failing, true)) {
                return ['status' => 1, 'stdout' => "patch: **** Can't rename file\n", 'stderr' => ''];
            }
            $this->states[$project] = $reverse ? 'stock' : 'applied';
            return ['status' => 0, 'stdout' => '', 'stderr' => ''];
        }
        $this->sites[$project][] = implode(' ', $inner);
        if (in_array("{$project} " . implode(' ', $inner), $this->failing, true)) {
            return ['status' => 1, 'stdout' => '', 'stderr' => "Error: no such extension\n"];
        }
        $stdout = match ($inner[1] ?? '') {
            'path' => "/var/www/html/core\n",
            'ev' => "6.20.alpha1\n",
            default => '',
        };
        return ['status' => 0, 'stdout' => $stdout, 'stderr' => ''];
    }

    public function captureStdout(array $command, ?string $input = null, ?string $workingDirectory = null): array
    {
        $this->commands[] = $command;
        if ($command[0] === 'curl') {
            return ['status' => $this->curlStatus, 'output' => $this->curlStatus === 0 ? $this->diff : ''];
        }
        $script = json_decode((string) end($command), true);
        $mount = (string) current(array_filter($command, static fn(string $part): bool => str_ends_with($part, ':/out')));
        $host = substr($mount, 0, -strlen(':/out'));
        $name = basename((string) $script['out'], '.webm');
        $this->recordings[] = ['name' => $name, 'script' => $script, 'command' => $command];
        foreach ($this->sites as $project => $log) {
            if (str_ends_with($project, '-' . $script['cms'])) {
                $this->sites[$project][] = 'record ' . $script['phase'];
            }
        }
        if ($this->writeVideos) {
            file_put_contents("{$host}/{$name}.webm", 'webm');
        }
        return ['status' => $this->recorderStatus, 'output' => "booting\n" . json_encode(['errors' => $this->errors[$name] ?? []]) . "\n"];
    }

    /** @return list<string> */
    public function siteLog(string $project): array
    {
        return $this->sites[$project] ?? [];
    }

    public function count(string $entry): int
    {
        return array_sum(array_map(static fn(array $log): int => count(array_keys($log, $entry, true)), $this->sites));
    }

    /** @param list<string> $command */
    private function project(array $command): string
    {
        $config = json_decode((string) file_get_contents($command[3]), true);
        return (string) $config['name'];
    }
}
