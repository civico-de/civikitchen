<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Files;
use RuntimeException;

/** Finds the civicrm-core commit at which a check turns from passing to failing, in one Standalone stack. Runs on the host. */
final class BisectCommand implements Command
{
    private const CORE = '/var/www/html/core/';
    // The site's caches hold the previous commit's code; a commit it cannot flush on cannot be tested.
    private const CHECK = 'cv flush >&2 || exit 125; exec "$@"';
    // Untracked, so git leaves a mounted directory in place on a commit that lacks it: Docker binds the directory, not its path.
    private const KEEP = '.ck-bisect-keep';

    private const USAGE = <<<'TXT'
ck bisect — find the civicrm-core commit at which a check starts to fail

  ck bisect <image> --core <checkout> --good <rev> --bad <rev> --dir <name> [options] -- <command> [arguments ...]

  <image>              a Standalone image whose core is --bad or close to it; the site
                       installs on that commit, e.g. standalone-master for origin/master
  --core <checkout>    the top of a civicrm-core work tree without local changes; its
                       HEAD moves during the run and returns afterwards
  --good <rev>         a commit on which the command passes
  --bad <rev>          a commit on which it fails
  --dir <name>         a directory of the checkout to mount over the image's copy
                       (repeatable), e.g. --dir CRM --dir Civi --dir ext/afform
  --mount <src>:<dst>[:ro]
                       mount a host path outside the checkout into the app container
                       (repeatable), e.g. the extension whose test is the check
  --locales <list>     install these core language files, e.g. it_IT,fr_FR
  --name <project>     compose project name (default: ck-bisect-<tag>)

The command runs in the app container as www-data, from /var/www/html, after
`cv flush`, on every commit `git bisect run` picks. Its exit status decides:
0 good, 125 cannot be tested (skip), any other up to 127 bad, above that the
bisect stops.

  ck bisect standalone-master --core "$HOME/src/civicrm-core" --good 6.20.0 --bad origin/master --dir CRM --dir Civi --dir tests -- ckcoretest tests/phpunit/api/v4/Query

Before bisecting, the command must pass on --good and fail on --bad;
afterwards it runs again on the commit found and on its parents. Exit status
0: found and confirmed there; 1: found, not confirmed. Runs on the host
through scaffold/ck.
TXT;

    private readonly StandaloneStack $stack;

    public function __construct(private readonly Runner $runner = new Runner())
    {
        $this->stack = new StandaloneStack($runner);
    }

    public function run(array $arguments): int
    {
        if ($arguments === [] || in_array($arguments[0], ['-h', '--help'], true)) {
            echo self::USAGE, "\n";
            return $arguments === [] ? 2 : 0;
        }
        $options = $this->parse($arguments);
        foreach (['docker', 'git'] as $program) {
            if ($this->runner->which($program) === null) {
                throw new RuntimeException("bisect runs on the host and needs {$program} on PATH");
            }
        }
        $core = $options['core'];
        if ($this->git($core, ['rev-parse', '--show-toplevel']) !== $core) {
            throw new RuntimeException("--core takes the top of a civicrm-core work tree, not {$core}");
        }
        $good = $this->git($core, ['rev-parse', '--verify', "{$options['good']}^{commit}"]);
        $bad = $this->git($core, ['rev-parse', '--verify', "{$options['bad']}^{commit}"]);
        if ($good === $bad) {
            throw new RuntimeException("--good and --bad are both {$good}; nothing to bisect");
        }
        if ($this->git($core, ['status', '--porcelain', '--untracked-files=no']) !== '') {
            throw new RuntimeException("{$core} has local changes and a bisect moves its HEAD; give a clean work tree: git -C {$core} worktree add --detach <directory>");
        }
        if ($this->runner->capture(Files::gitCommandIn($core, ['bisect', 'log']))['status'] === 0) {
            throw new RuntimeException("a bisect is in progress in {$core}; end it: git -C {$core} bisect reset");
        }
        $mounts = [];
        foreach ($options['dirs'] as $dir) {
            foreach ([$good, $bad] as $commit) {
                // The stack boots on the copy of --bad, and one that --good lacks is empty there.
                if ($this->runner->capture(Files::gitCommandIn($core, ['cat-file', '-t', "{$commit}:{$dir}"]))['output'] !== "tree\n") {
                    throw new RuntimeException("--dir {$dir} is no directory in {$commit}");
                }
            }
            $mounts[] = StandaloneStack::mount("{$core}/{$dir}:" . self::CORE . $dir);
        }
        foreach ($options['mounts'] as $mount) {
            // Resolved like --core; a source this process does not see is compared as given.
            $source = explode(':', $mount)[0];
            $resolved = realpath($source);
            $source = $resolved === false ? $source : $resolved;
            if ($source === $core || str_starts_with($source, "{$core}/")) {
                throw new RuntimeException("--mount {$mount} is inside the checkout; mount its directories with --dir");
            }
            $mounts[] = $mount;
        }

        $project = $options['name'] ?? 'ck-bisect-' . StandaloneStack::label($options['image']);
        $this->git($core, ['bisect', 'start']);
        try {
            // The site installs on the mounted code, which fails on a mix far from the image's core.
            $this->git($core, ['checkout', '--quiet', '--detach', $bad]);
            foreach ($options['dirs'] as $dir) {
                if (file_put_contents("{$core}/{$dir}/" . self::KEEP, '') === false) {
                    throw new RuntimeException("cannot write into {$core}/{$dir}; the run keeps a placeholder file there");
                }
            }
            $file = $this->stack->prepare($project, $options['image'], $mounts, $options['locales'], sys_get_temp_dir() . "/{$project}.compose.json");
            try {
                $this->stack->up($file, true);
                echo "ck bisect: {$options['image']} ({$this->stack->core($file)}): " . implode(' ', $options['command']) . "\n";
                $this->nameUnmounted($core, $good, $bad, $options['dirs']);
                return $this->search($core, $good, $bad, [...StandaloneStack::exec($file), 'sh', '-c', self::CHECK, 'ck-bisect', ...$options['command']]);
            } finally {
                if (!$this->stack->down($file)) {
                    // By project name: the temporary compose file does not outlive this process.
                    fwrite(STDERR, "ck bisect: could not remove the stack; remove it: docker compose -p {$project} down -v\n");
                }
                unlink($file);
            }
        } finally {
            foreach ($options['dirs'] as $dir) {
                if (is_file("{$core}/{$dir}/" . self::KEEP)) {
                    unlink("{$core}/{$dir}/" . self::KEEP);
                }
            }
            if ($this->runner->capture(Files::gitCommandIn($core, ['bisect', 'reset']))['status'] !== 0) {
                fwrite(STDERR, "ck bisect: could not return {$core} to where it was; do it: git -C {$core} bisect reset\n");
            }
        }
    }

    /**
     * @param non-empty-list<string> $check the command line that runs the check in the stack
     */
    private function search(string $core, string $good, string $bad, array $check): int
    {
        $status = $this->status($core, $check, $good);
        if ($status !== 0) {
            throw new RuntimeException('the command ' . self::exits($status) . " on the good commit {$good}; a bisect needs a check that passes there");
        }
        $status = $this->status($core, $check, $bad);
        if (!self::fails($status)) {
            throw new RuntimeException('the command ' . self::exits($status) . " on the bad commit {$bad}; a bisect needs a check that fails there with a status of 1 to 127, except 125");
        }
        $this->git($core, ['bisect', 'good', $good]);
        $this->git($core, ['bisect', 'bad', $bad]);
        $status = $this->runner->passthrough(Files::gitCommandIn($core, ['bisect', 'run', ...$check]));
        if ($status !== 0) {
            throw new RuntimeException("git bisect run ended without a result (exit {$status}); its reason is above");
        }
        $first = $this->git($core, ['rev-parse', '--verify', 'refs/bisect/bad']);
        echo 'ck bisect: first bad commit: ' . $this->git($core, ['log', '-1', '--format=%H %s', $first]) . "\n";

        $parents = array_slice(explode(' ', $this->git($core, ['rev-list', '--parents', '-n', '1', $first])), 1);
        $doubts = [];
        foreach ($parents as $parent) {
            $status = $this->status($core, $check, $parent);
            if ($status !== 0) {
                $doubts[] = 'the command ' . self::exits($status) . " on its parent {$parent}";
            }
        }
        $status = $this->status($core, $check, $first);
        if (!self::fails($status)) {
            $doubts[] = 'the command ' . self::exits($status) . ' on the commit itself';
        }
        if ($doubts !== []) {
            echo 'ck bisect: not confirmed: ' . implode(', ', $doubts) . ". The check does not turn from passing to failing at this commit:\n"
                . "  it is unstable, or the commit sits on a branch that forked before the check could pass\n";
            return 1;
        }
        echo 'ck bisect: confirmed: the command '
            . ($parents === [] ? "fails on {$first}, which has no parent\n" : 'passes on ' . implode(' and ', $parents) . " and fails on {$first}\n");
        return 0;
    }

    /**
     * Names what the commits of the range touch outside the mounted directories: there every commit runs on the image's copy.
     *
     * @param non-empty-list<string> $dirs
     */
    private function nameUnmounted(string $core, string $good, string $bad, array $dirs): void
    {
        $unmounted = [];
        // From the merge base, which is where git bisect starts when --good is on another branch.
        $base = $this->git($core, ['merge-base', $good, $bad]);
        foreach (explode("\n", $this->git($core, ['log', '--format=', '--name-only', "{$base}..{$bad}"])) as $path) {
            foreach ($dirs as $dir) {
                if (str_starts_with($path, "{$dir}/")) {
                    continue 2;
                }
            }
            $unmounted[explode('/', $path)[0]] = true;
        }
        unset($unmounted['']);
        if ($unmounted !== []) {
            ksort($unmounted);
            echo 'ck bisect: the range also changes ' . implode(', ', array_keys($unmounted)) . "; those are not mounted, every commit runs on the image's copy of them\n";
        }
    }

    /**
     * @param non-empty-list<string> $check
     */
    private function status(string $core, array $check, string $commit): int
    {
        $this->git($core, ['checkout', '--quiet', '--detach', $commit]);
        return $this->runner->passthrough($check);
    }

    /** Whether git bisect run takes $status as a bad commit. */
    private static function fails(int $status): bool
    {
        return $status >= 1 && $status <= 127 && $status !== 125;
    }

    private static function exits(int $status): string
    {
        return "exits {$status}" . ($status === 125 ? ' (cv flush failed, or the command skips the commit)' : '');
    }

    /**
     * Trimmed stdout of a git command that has to succeed.
     *
     * @param non-empty-list<string> $arguments
     */
    private function git(string $core, array $arguments): string
    {
        $result = $this->runner->captureSeparate(Files::gitCommandIn($core, $arguments));
        if ($result['status'] !== 0) {
            throw new RuntimeException('git ' . implode(' ', $arguments) . " failed in {$core}:\n" . trim($result['stderr'] . $result['stdout']));
        }
        return trim($result['stdout']);
    }

    /**
     * @param list<string> $arguments
     * @return array{image: string, core: string, good: string, bad: string, dirs: non-empty-list<string>, mounts: list<string>, locales: ?string, name: ?string, command: non-empty-list<string>}
     */
    private function parse(array $arguments): array
    {
        $separator = array_search('--', $arguments, true);
        $command = $separator === false ? [] : array_slice($arguments, $separator + 1);
        if ($command === []) {
            throw new RuntimeException("which check? Give the command after --\n" . self::USAGE);
        }
        $arguments = array_slice($arguments, 0, (int) $separator);
        $images = [];
        $dirs = [];
        $mounts = [];
        $values = ['--core' => null, '--good' => null, '--bad' => null, '--locales' => null, '--name' => null];
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if (!str_starts_with($argument, '--')) {
                $images[] = StandaloneStack::image($argument);
                continue;
            }
            $value = array_shift($arguments);
            if ($value === null) {
                throw new RuntimeException("{$argument} needs a value");
            }
            if ($argument === '--dir') {
                // Segments start with a letter, a digit or _: no .git, no way out of the checkout.
                if (preg_match('#^\w[\w.-]*(/\w[\w.-]*)*$#', $value) !== 1) {
                    throw new RuntimeException("--dir takes a directory relative to the checkout, e.g. CRM or ext/afform, not {$value}");
                }
                $dirs[] = $value;
            } elseif ($argument === '--mount') {
                $mounts[] = StandaloneStack::mount($value);
            } elseif (array_key_exists($argument, $values)) {
                $values[$argument] = $value;
            } else {
                throw new RuntimeException("unknown option {$argument}\n" . self::USAGE);
            }
        }
        if (count($images) !== 1) {
            throw new RuntimeException('bisect takes one image, got ' . count($images) . "\n" . self::USAGE);
        }
        foreach (['--core', '--good', '--bad'] as $required) {
            if ($values[$required] === null) {
                throw new RuntimeException("{$required} is required\n" . self::USAGE);
            }
        }
        if ($dirs === []) {
            throw new RuntimeException("--dir is required: which directories of the checkout does the stack run?\n" . self::USAGE);
        }
        $core = realpath($values['--core']);
        if ($core === false || !is_dir($core)) {
            throw new RuntimeException("--core {$values['--core']} is no directory here; scaffold/ck sees \$HOME, its own checkout and the current git work tree");
        }
        if ($values['--name'] !== null && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $values['--name']) !== 1) {
            throw new RuntimeException("--name takes lowercase letters, digits, - and _, not {$values['--name']}");
        }
        return [
            'image' => $images[0],
            'core' => $core,
            'good' => $values['--good'],
            'bad' => $values['--bad'],
            'dirs' => $dirs,
            'mounts' => $mounts,
            'locales' => $values['--locales'] === null ? null : StandaloneStack::locales($values['--locales']),
            'name' => $values['--name'],
            'command' => $command,
        ];
    }
}
