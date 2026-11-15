<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Cli\BisectCommand;
use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Files;
use PHPUnit\Framework\TestCase;

/** `ck bisect` over a real git history, with a docker that runs the check in the checkout. */
final class BisectTest extends TestCase
{
    private const DOCKER = <<<'SH'
#!/bin/sh
# Docker as ck bisect drives it: the stack calls succeed, the check is $CK_FAKE_CHECK in the checkout.
echo "$*" >> "$CK_FAKE/calls"
case " $* " in
  *" ck-bisect "*)
    # git bisect run starts the check at the top of the work tree; ck's own runs start elsewhere.
    if [ "$(pwd -P)" = "$CK_FAKE/core" ]; then export IN_BISECT_RUN=1; fi
    cd "$CK_FAKE/core" && sh -c "$CK_FAKE_CHECK"
    exit $? ;;
  " ps "*) echo "$CK_FAKE_EXISTING"; exit 0 ;;
  *" up "*)
    cp "$3" "$CK_FAKE/compose.json"
    git -C "$CK_FAKE/core" rev-parse HEAD > "$CK_FAKE/booted-on"
    exit "${CK_FAKE_BOOT:-0}" ;;
  *" logs "*) echo "[civikitchen] ERROR: provisioning failed"; exit 0 ;;
  *" down "*) exit 0 ;;
  *" cv ev "*) echo 6.21.alpha1; exit 0 ;;
  *" cat "*) echo "git . 4f0c2aa91b6d5e8a7c3b2d1f0e9a8b7c6d5e4f3a"; exit 0 ;;
esac
exit 64
SH;
    private const BROKEN = 'test ! -e CRM/broken';
    private const CHECK = ['--', 'ckcoretest', 'tests/phpunit/api/v4/Query'];

    private string $fake;
    private string $core;
    private string $path;

    protected function setUp(): void
    {
        $this->fake = (string) realpath(Directory::temporary('civikitchen-bisect'));
        $this->core = "{$this->fake}/core";
        mkdir("{$this->fake}/bin");
        mkdir($this->core);
        file_put_contents("{$this->fake}/bin/docker", self::DOCKER);
        chmod("{$this->fake}/bin/docker", 0755);
        $this->path = (string) getenv('PATH');
        putenv("PATH={$this->fake}/bin" . PATH_SEPARATOR . $this->path);
        putenv("CK_FAKE={$this->fake}");
        putenv('CK_FAKE_CHECK=' . self::BROKEN);
        $this->git('init', '--quiet', '--initial-branch=main');
    }

    protected function tearDown(): void
    {
        putenv("PATH={$this->path}");
        foreach (['CK_FAKE', 'CK_FAKE_CHECK', 'CK_FAKE_BOOT', 'CK_FAKE_EXISTING'] as $name) {
            putenv($name);
        }
        Directory::remove($this->fake);
    }

    public function testFindsTheCommitAtWhichTheCheckStartsToFail(): void
    {
        $commits = $this->history();

        [$status, $output] = $this->bisect(['standalone-master', '--core', $this->core, '--good', $commits[1], '--bad', 'main', '--dir', 'CRM', '--mount', "{$this->fake}:/var/www/html/ext/org.example.myext", '--locales', 'it_IT', ...self::CHECK]);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString("ck bisect: first bad commit: {$commits[4]} c4\n", $output);
        self::assertStringContainsString("ck bisect: confirmed: the command passes on {$commits[3]} and fails on {$commits[4]}\n", $output);
        self::assertStringContainsString('standalone-master (CiviCRM 6.21.alpha1, git . 4f0c2aa91b6d5e8a7c3b2d1f0e9a8b7c6d5e4f3a): ckcoretest tests/phpunit/api/v4/Query', $output);
        self::assertStringContainsString('ck bisect: the range also changes composer.lock, xml; those are not mounted', $output);

        $config = json_decode((string) file_get_contents("{$this->fake}/compose.json"), true);
        self::assertSame('ck-bisect-standalone-master', $config['name']);
        self::assertSame('ghcr.io/civico-de/civikitchen:standalone-master', $config['services']['app']['image']);
        self::assertSame(["{$this->core}/CRM:/var/www/html/core/CRM", "{$this->fake}:/var/www/html/ext/org.example.myext"], $config['services']['app']['volumes']);
        self::assertSame('it_IT', $config['services']['app']['environment']['CIVIKITCHEN_LOCALES']);
        // The site installs on the bad end, the one the image is picked for.
        self::assertSame("{$commits[6]}\n", file_get_contents("{$this->fake}/booted-on"));
        self::assertStringContainsString('exec -T -u www-data -w /var/www/html app sh -c cv flush >&2 || exit 125; exec "$@" ck-bisect ckcoretest tests/phpunit/api/v4/Query', $this->calls());
        $this->assertNothingIsLeft();
    }

    public function testFindsItBetweenNeighbours(): void
    {
        $commits = $this->history();

        [$status, $output] = $this->bisect(['standalone-master', '--core', $this->core, '--good', $commits[3], '--bad', $commits[4], '--dir', 'CRM', ...self::CHECK]);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString("first bad commit: {$commits[4]} c4\n", $output);
        self::assertStringNotContainsString('the range also changes', $output);
        $this->assertNothingIsLeft();
    }

    public function testGoodOnAnotherBranchStartsFromTheMergeBase(): void
    {
        $commits = $this->history();
        $this->git('checkout', '--quiet', '-b', 'release', $commits[3]);
        $this->commit('r1', ['release-notes.md' => 'r1']);
        $this->git('checkout', '--quiet', 'main');

        [$status, $output] = $this->bisect(['standalone-master', '--core', $this->core, '--good', 'release', '--bad', 'main', '--dir', 'CRM', ...self::CHECK]);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString("confirmed: the command passes on {$commits[3]} and fails on {$commits[4]}\n", $output);
        // What only the release branch changes is not part of the range.
        self::assertStringContainsString('the range also changes composer.lock;', $output);
        $this->assertNothingIsLeft();
    }

    public function testAMergeIsConfirmedAgainstEveryParent(): void
    {
        $base = $this->commit('c1', ['CRM/a.txt' => 'v1']);
        $main = $this->commit('c2', ['CRM/a.txt' => 'v2']);
        $this->git('checkout', '--quiet', '-b', 'side', $base);
        $side = $this->commit('s1', ['CRM/side.txt' => 's1']);
        $this->git('checkout', '--quiet', 'main');
        $this->git('merge', '--quiet', '--no-ff', '--no-commit', 'side');
        $merge = $this->commit('merge side', ['CRM/broken' => '']);

        [$status, $output] = $this->bisect(['standalone-master', '--core', $this->core, '--good', $main, '--bad', 'main', '--dir', 'CRM', ...self::CHECK]);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString("confirmed: the command passes on {$main} and {$side} and fails on {$merge}\n", $output);
    }

    public function testAMountedDirectoryStaysWhereACommitLacksIt(): void
    {
        $this->commit('c1', ['CRM/a.txt' => 'v1']);
        $good = $this->commit('c2', ['CRM/a.txt' => 'v2', 'xml/schema.xml' => '<database/>']);
        // A branch from before xml/ existed, merged later: its commit is in the range.
        $this->git('checkout', '--quiet', '-b', 'old', "{$good}^");
        $old = $this->commit('o1', ['CRM/broken' => '']);
        $this->git('checkout', '--quiet', 'main');
        $this->git('merge', '--quiet', '--no-ff', '--message', 'merge old', 'old');
        putenv('CK_FAKE_CHECK=test -d xml || touch "$CK_FAKE/xml-was-gone"; ' . self::BROKEN);

        [$status, $output] = $this->bisect(['standalone-master', '--core', $this->core, '--good', $good, '--bad', 'main', '--dir', 'CRM', '--dir', 'xml', ...self::CHECK]);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString("ck bisect: first bad commit: {$old} o1\n", $output);
        self::assertFileDoesNotExist("{$this->fake}/xml-was-gone");
        self::assertFileDoesNotExist("{$this->core}/xml/.ck-bisect-keep");
        self::assertFileDoesNotExist("{$this->core}/CRM/.ck-bisect-keep");
        $this->assertNothingIsLeft();
    }

    public function testNamesWhatACommitOfTheRangeTouchesAndAnotherReverts(): void
    {
        $good = $this->commit('c1', ['CRM/a.txt' => 'v1']);
        $this->commit('c2', ['CRM/a.txt' => 'v2', 'js/crm.js' => '']);
        unlink("{$this->core}/js/crm.js");
        $this->commit('c3', ['CRM/broken' => '']);

        [$status, $output] = $this->bisect(['standalone-master', '--core', $this->core, '--good', $good, '--bad', 'main', '--dir', 'CRM', ...self::CHECK]);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('ck bisect: the range also changes js;', $output);
    }

    public function testACommitWithoutAParentIsConfirmedOnItsOwn(): void
    {
        $good = $this->commit('c1', ['CRM/a.txt' => 'v1']);
        $this->git('checkout', '--quiet', '--orphan', 'import');
        unlink("{$this->core}/CRM/a.txt");
        $root = $this->commit('i1', ['CRM/broken' => '']);
        $this->git('checkout', '--quiet', 'main');
        $this->git('merge', '--quiet', '--no-ff', '--allow-unrelated-histories', '--message', 'merge import', 'import');

        [$status, $output] = $this->bisect(['standalone-master', '--core', $this->core, '--good', $good, '--bad', 'main', '--dir', 'CRM', ...self::CHECK]);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString("confirmed: the command fails on {$root}, which has no parent\n", $output);
        $this->assertNothingIsLeft();
    }

    public function testACommitThatCannotBeTestedIsSkipped(): void
    {
        $commits = $this->history();
        putenv('CK_FAKE_CHECK=if grep -q v3 CRM/a.txt; then exit 125; fi; ' . self::BROKEN);

        [$message, $output] = $this->fails(['standalone-master', '--core', $this->core, '--good', $commits[1], '--bad', 'main', '--dir', 'CRM', ...self::CHECK]);

        // git cannot tell c3 from c4 and says so.
        self::assertStringContainsString('git bisect run ended without a result (exit 2)', $message);
        self::assertStringContainsString($commits[3], $output);
        self::assertStringContainsString($commits[4], $output);
        $this->assertNothingIsLeft();
    }

    public function testAStatusAbove127StopsTheBisect(): void
    {
        $commits = $this->history();
        putenv('CK_FAKE_CHECK=if [ -n "$IN_BISECT_RUN" ]; then exit 200; fi; ' . self::BROKEN);

        [$message] = $this->fails(['standalone-master', '--core', $this->core, '--good', $commits[1], '--bad', 'main', '--dir', 'CRM', ...self::CHECK]);

        self::assertStringContainsString('git bisect run ended without a result', $message);
        $this->assertNothingIsLeft();
    }

    public function testTheCheckMustPassOnGoodAndFailOnBad(): void
    {
        $commits = $this->history();
        $cases = [
            'false' => "the command exits 1 on the good commit {$commits[1]}",
            'exit 125' => "the command exits 125 (cv flush failed, or the command skips the commit) on the good commit {$commits[1]}",
            'true' => "the command exits 0 on the bad commit {$commits[6]}",
            'if test -e CRM/broken; then exit 200; fi' => "the command exits 200 on the bad commit {$commits[6]}",
            'if test -e CRM/broken; then exit 125; fi' => 'the command exits 125 (cv flush failed, or the command skips the commit) on the bad commit',
        ];
        foreach ($cases as $check => $expected) {
            putenv("CK_FAKE_CHECK={$check}");
            [$message, $output] = $this->fails(['standalone-master', '--core', $this->core, '--good', $commits[1], '--bad', 'main', '--dir', 'CRM', ...self::CHECK]);
            self::assertStringContainsString($expected, $message);
            self::assertStringNotContainsString('first bad commit', $output);
            $this->assertNothingIsLeft();
        }
    }

    public function testAnUnstableCheckIsNotConfirmed(): void
    {
        $commits = $this->history();
        $inRun = 'if [ -n "$IN_BISECT_RUN" ]; then ' . self::BROKEN . '; exit $?; fi; ';
        $cases = [
            // Outside git bisect run the check passes on c1 only, so the parent fails too.
            'grep -q v1 CRM/a.txt' => "not confirmed: the command exits 1 on its parent {$commits[3]}.",
            // And here on everything but c6, so the commit found passes.
            '! grep -q v6 CRM/a.txt' => 'not confirmed: the command exits 0 on the commit itself.',
        ];
        foreach ($cases as $check => $expected) {
            putenv("CK_FAKE_CHECK={$inRun}{$check}");
            [$status, $output] = $this->bisect(['standalone-master', '--core', $this->core, '--good', $commits[1], '--bad', 'main', '--dir', 'CRM', ...self::CHECK]);
            self::assertSame(1, $status, $output);
            self::assertStringContainsString("first bad commit: {$commits[4]} c4\n", $output);
            self::assertStringContainsString($expected, $output);
            self::assertStringNotContainsString('ck bisect: confirmed', $output);
            $this->assertNothingIsLeft();
        }
    }

    public function testRefusesACheckoutItCannotMove(): void
    {
        $commits = $this->history();
        $arguments = ['standalone-master', '--core', $this->core, '--good', $commits[1], '--bad', 'main', '--dir', 'CRM', ...self::CHECK];

        file_put_contents("{$this->core}/CRM/a.txt", 'edited');
        self::assertStringContainsString('has local changes and a bisect moves its HEAD', $this->fails($arguments)[0]);
        self::assertSame('edited', file_get_contents("{$this->core}/CRM/a.txt"));
        $this->git('checkout', '--quiet', '--', 'CRM/a.txt');

        $this->git('bisect', 'start');
        self::assertStringContainsString('a bisect is in progress', $this->fails($arguments)[0]);
        $this->git('bisect', 'reset');

        $arguments[2] = "{$this->core}/CRM";
        self::assertStringContainsString('--core takes the top of a civicrm-core work tree', $this->fails($arguments)[0]);
        self::assertFileDoesNotExist("{$this->fake}/calls");
    }

    public function testADirectoryMustExistAtBothEnds(): void
    {
        $commits = $this->history();
        $cases = [
            // xml/ arrives with c2.
            'xml' => "--dir xml is no directory in {$commits[1]}",
            'composer.lock' => '--dir composer.lock is no directory in',
        ];
        foreach ($cases as $dir => $expected) {
            [$message] = $this->fails(['standalone-master', '--core', $this->core, '--good', $commits[1], '--bad', 'main', '--dir', 'CRM', '--dir', $dir, ...self::CHECK]);
            self::assertStringContainsString($expected, $message);
        }
        self::assertFileDoesNotExist("{$this->fake}/calls");
    }

    public function testAStackThatDoesNotStartLeavesTheCheckoutWhereItWas(): void
    {
        $commits = $this->history();
        $arguments = ['standalone-master', '--core', $this->core, '--good', $commits[1], '--bad', 'main', '--dir', 'CRM', '--name', 'bug-1234', ...self::CHECK];

        putenv('CK_FAKE_EXISTING=abc123');
        self::assertStringContainsString('a stack named bug-1234 exists', $this->fails($arguments)[0]);
        self::assertSame('main', $this->git('symbolic-ref', '--short', 'HEAD'));

        putenv('CK_FAKE_EXISTING');
        putenv('CK_FAKE_BOOT=1');
        [$message] = $this->fails($arguments);
        self::assertStringContainsString('did not boot', $message);
        self::assertStringContainsString('provisioning failed', $message);
        self::assertStringNotContainsString('ck-bisect', $this->calls());
        $this->assertNothingIsLeft('bug-1234');
    }

    public function testRejectsWhatItCannotRun(): void
    {
        $commits = $this->history();
        $valid = ['standalone-master', '--core', $this->core, '--good', $commits[1], '--bad', 'main', '--dir', 'CRM'];
        $cases = [
            'which check?' => $valid,
            'bisect takes one image, got 2' => [...$valid, 'standalone-beta', ...self::CHECK],
            'bisect takes one image, got 0' => [...array_slice($valid, 1), ...self::CHECK],
            '--core is required' => ['standalone-master', '--good', 'a', '--bad', 'b', '--dir', 'CRM', ...self::CHECK],
            '--good is required' => ['standalone-master', '--core', $this->core, '--bad', 'b', '--dir', 'CRM', ...self::CHECK],
            '--bad is required' => ['standalone-master', '--core', $this->core, '--good', 'a', '--dir', 'CRM', ...self::CHECK],
            '--dir is required' => [...array_slice($valid, 0, 7), ...self::CHECK],
            '--dir takes a directory relative to the checkout' => [...$valid, '--dir', '../elsewhere', ...self::CHECK],
            'not .git' => [...$valid, '--dir', '.git', ...self::CHECK],
            'not /srv/core/CRM' => [...$valid, '--dir', '/srv/core/CRM', ...self::CHECK],
            '--core /nowhere is no directory' => ['standalone-master', '--core', '/nowhere', '--good', 'a', '--bad', 'b', '--dir', 'CRM', ...self::CHECK],
            '--name takes' => [...$valid, '--name', 'Bug 1', ...self::CHECK],
            '--locales takes' => [...$valid, '--locales', 'it_IT, fr_FR', ...self::CHECK],
            '--bad needs a value' => ['standalone-master', '--core', $this->core, '--good', 'a', '--dir', 'CRM', '--bad', ...self::CHECK],
            'unknown option --keep' => [...$valid, '--keep', 'yes', ...self::CHECK],
            '--mount takes <absolute host path>:<container path>' => [...$valid, '--mount', 'tests:/b', ...self::CHECK],
            "--mount {$this->core}/CRM:/b is inside the checkout" => [...$valid, '--mount', "{$this->core}/CRM:/b", ...self::CHECK],
            "--mount {$this->core}:/b is inside the checkout" => [...$valid, '--mount', "{$this->core}:/b", ...self::CHECK],
            "--mount {$this->core}/./CRM:/b is inside the checkout" => [...$valid, '--mount', "{$this->core}/./CRM:/b", ...self::CHECK],
            "--good and --bad are both {$commits[6]}" => ['standalone-master', '--core', $this->core, '--good', 'main', '--bad', $commits[6], '--dir', 'CRM', ...self::CHECK],
            'git rev-parse --verify nowhere^{commit} failed' => ['standalone-master', '--core', $this->core, '--good', 'nowhere', '--bad', 'main', '--dir', 'CRM', ...self::CHECK],
        ];
        foreach ($cases as $expected => $arguments) {
            self::assertStringContainsString($expected, $this->fails($arguments)[0]);
        }
        foreach (['docker', 'git'] as $program) {
            $runner = new BisectRunner();
            $runner->missing = $program;
            self::assertStringContainsString("needs {$program} on PATH", $this->fails([...$valid, ...self::CHECK], $runner)[0]);
        }
        self::assertFileDoesNotExist("{$this->fake}/calls");
    }

    public function testGitIsToldToTrustTheCheckoutItIsGiven(): void
    {
        self::assertSame(
            ['git', '-C', '/src/civicrm-core', '-c', 'safe.directory=/src/civicrm-core', 'bisect', 'log'],
            Files::gitCommandIn('/src/civicrm-core', ['bisect', 'log']),
        );
    }

    public function testHelp(): void
    {
        [$status, $output] = $this->bisect(['--help']);
        self::assertSame(0, $status);
        self::assertStringContainsString('ck bisect <image> --core <checkout> --good <rev> --bad <rev> --dir <name>', $output);
        self::assertSame(2, $this->bisect([])[0]);
    }

    /**
     * Six commits on main; c4 adds CRM/broken, c2 and c5 change files outside CRM.
     *
     * @return array<int, string> the commits by their number
     */
    private function history(): array
    {
        $commits = [];
        foreach (range(1, 6) as $number) {
            $files = ['CRM/a.txt' => "v{$number}"];
            if ($number === 2) {
                $files['xml/schema.xml'] = '<database/>';
            }
            if ($number === 4) {
                $files['CRM/broken'] = '';
            }
            if ($number === 5) {
                $files['composer.lock'] = '{}';
            }
            $commits[$number] = $this->commit("c{$number}", $files);
        }
        return $commits;
    }

    /** @param array<string, string> $files */
    private function commit(string $subject, array $files): string
    {
        foreach ($files as $path => $content) {
            if (!is_dir(dirname("{$this->core}/{$path}"))) {
                mkdir(dirname("{$this->core}/{$path}"));
            }
            file_put_contents("{$this->core}/{$path}", $content);
        }
        $this->git('add', '--all');
        $this->git('commit', '--quiet', '--message', $subject);
        return $this->git('rev-parse', 'HEAD');
    }

    private function git(string ...$arguments): string
    {
        $result = (new Runner())->capture(['git', '-C', $this->core, '-c', 'user.name=Test', '-c', 'user.email=test@example.org', '-c', 'commit.gpgsign=false', ...$arguments]);
        self::assertSame(0, $result['status'], $result['output']);
        return trim($result['output']);
    }

    private function calls(): string
    {
        return (string) file_get_contents("{$this->fake}/calls");
    }

    /** The checkout is back on main without a bisect, and the stack and its compose file are gone. */
    private function assertNothingIsLeft(string $project = 'ck-bisect-standalone-master'): void
    {
        self::assertSame('main', $this->git('symbolic-ref', '--short', 'HEAD'));
        self::assertSame('', $this->git('status', '--porcelain'));
        self::assertNotSame(0, (new Runner())->capture(['git', '-C', $this->core, 'bisect', 'log'])['status']);
        self::assertStringEndsWith("down -v --remove-orphans\n", $this->calls());
        self::assertFileDoesNotExist(sys_get_temp_dir() . "/{$project}.compose.json");
        unlink("{$this->fake}/calls");
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function bisect(array $arguments, BisectRunner $runner = new BisectRunner()): array
    {
        ob_start();
        try {
            $status = (new BisectCommand($runner))->run($arguments);
            return [$status, (string) ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{string, string} the error and what was printed before it
     */
    private function fails(array $arguments, BisectRunner $runner = new BisectRunner()): array
    {
        ob_start();
        try {
            (new BisectCommand($runner))->run($arguments);
            self::fail('expected an error, got: ' . ob_get_contents());
        } catch (RuntimeException $e) {
            return [$e->getMessage(), (string) ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }
}

/** The real runner, with a child's output in PHP's output buffer instead of on the terminal. */
final class BisectRunner extends Runner
{
    public ?string $missing = null;

    public function which(string $program): ?string
    {
        return $program === $this->missing ? null : parent::which($program);
    }

    public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
    {
        $result = $this->capture($command, $environment, $workingDirectory);
        echo $result['output'];
        return $result['status'];
    }
}
