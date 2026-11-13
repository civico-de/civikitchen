<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Ab\JUnit;
use CiviKitchen\Toolbelt\Process\Runner;
use RuntimeException;

/** Runs one check on two Standalone images and names the tests that pass on the first and fail on the second. Runs on the host. */
final class AbCommand implements Command
{
    public const JUNIT = '/tmp/ck-ab.xml';

    private const USAGE = <<<'TXT'
ck ab — run the same check on two CiviCRM versions and compare the results

  ck ab <image A> <image B> [options] -- <command> [arguments ...]

  <image>              a Standalone image, or a civikitchen tag such as standalone-6.16,
                       standalone, standalone-beta or standalone-master
  --mount <src>:<dst>  mount a host path into both app containers (repeatable),
                       e.g. an extension: --mount "$PWD:/var/www/html/ext/myext"
  --locales <list>     install these core language files in both, e.g. it_IT,fr_FR
                       (sets CIVIKITCHEN_LOCALES; default: en_US only)
  --out <dir>          for the compose files and JUnit reports (default: ./ck-ab-<A>-vs-<B>)
  --keep               leave both stacks running

The command runs in each app container as www-data, from /var/www/html. When it
writes a JUnit report to /tmp/ck-ab.xml, the results are compared per test:

  ck ab standalone standalone-beta -- ckcoretest --log-junit /tmp/ck-ab.xml tests/phpunit/api/v4/Query

Exit status 1: a test passes on A and fails on B, or without reports, the
command passes on A and fails on B. Runs on the host through scaffold/ck.
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
        if ($this->runner->which('docker') === null) {
            throw new RuntimeException('ab runs on the host and needs docker on PATH');
        }
        $label = StandaloneStack::label($options['images']['A']) . '-vs-' . StandaloneStack::label($options['images']['B']);
        $out = $options['out'] ?? getcwd() . "/ck-ab-{$label}";
        if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
            throw new RuntimeException("cannot create {$out}");
        }
        $out = (string) realpath($out);

        $files = [];
        $results = [];
        try {
            foreach ($options['images'] as $side => $image) {
                $files[$side] = $this->stack->prepare("ck-ab-{$label}-" . strtolower($side), $image, $options['mounts'], $options['locales'], "{$out}/{$side}.compose.json");
            }
            foreach ($files as $file) {
                $this->stack->up($file, false);
            }
            foreach ($files as $side => $file) {
                $this->stack->up($file, true);
                $results[$side] = ['image' => $options['images'][$side], 'core' => $this->stack->core($file)];
            }
            foreach ($files as $side => $file) {
                echo "ck ab: {$side} ({$results[$side]['core']}): " . implode(' ', $options['command']) . "\n";
                $results[$side]['status'] = $this->runner->passthrough([...StandaloneStack::exec($file), ...$options['command']]);
                $results[$side]['junit'] = $this->fetchReport($file, "{$out}/{$side}.junit.xml");
            }
        } finally {
            foreach ($files as $side => $file) {
                if ($options['keep']) {
                    echo "ck ab: kept {$side}: docker compose -f {$file} exec -u www-data app bash\n";
                    continue;
                }
                if (!$this->stack->down($file)) {
                    fwrite(STDERR, "ck ab: could not remove the stack of {$file}\n");
                    continue;
                }
                unlink($file);
            }
        }
        return $this->report($results);
    }

    /**
     * @param array<string, array{image: string, core: string, status: int, junit: ?string}> $results
     */
    private function report(array $results): int
    {
        foreach ($results as $side => $result) {
            echo "ck ab: {$side} {$result['image']} ({$result['core']}): exit {$result['status']}\n";
        }
        ['A' => $a, 'B' => $b] = $results;
        if ($a['junit'] === null && $b['junit'] === null) {
            echo 'ck ab: no JUnit report at ' . self::JUNIT . "; comparing exit status only\n";
            if ($a['status'] === 0 && $b['status'] !== 0) {
                echo "ck ab: the command passes on A and fails on B\n";
                return 1;
            }
            echo $a['status'] === $b['status'] ? "ck ab: same exit status on both\n" : "ck ab: the command fails on A and passes on B\n";
            return 0;
        }
        if ($a['junit'] === null || $b['junit'] === null) {
            throw new RuntimeException('only ' . ($a['junit'] === null ? 'B' : 'A') . ' wrote ' . self::JUNIT . '; the other side stopped before its tests ran');
        }
        $onA = JUnit::outcomes($a['junit']);
        $onB = JUnit::outcomes($b['junit']);
        if ($onA === [] || $onB === []) {
            throw new RuntimeException('a JUnit report holds no test case; nothing was compared');
        }
        $groups = ['regression' => [], 'fixed' => [], 'both' => [], 'gone' => []];
        foreach ($onA as $test => $outcome) {
            $other = $onB[$test] ?? JUnit::SKIPPED;
            if ($outcome === JUnit::SKIPPED) {
                continue;
            }
            if ($other === JUnit::SKIPPED) {
                $groups['gone'][] = $test;
            } elseif ($outcome !== $other) {
                $groups[$outcome === JUnit::PASSED ? 'regression' : 'fixed'][] = $test;
            } elseif ($outcome === JUnit::FAILED) {
                $groups['both'][] = $test;
            }
        }
        echo 'ck ab: ' . count($onA) . ' tests on A, ' . count($onB) . ' on B, ' . count(array_diff_key($onB, $onA)) . " only on B\n";
        $titles = [
            'regression' => 'pass on A, fail on B',
            'fixed' => 'fail on A, pass on B',
            'both' => 'fail on both',
            'gone' => 'ran on A, skipped or missing on B',
        ];
        foreach ($titles as $group => $title) {
            echo "ck ab: {$title}: " . count($groups[$group]) . "\n";
            foreach ($groups[$group] as $test) {
                echo "  {$test}\n";
            }
        }
        return $groups['regression'] === [] ? 0 : 1;
    }

    /** Copies the side's JUnit report out of its container; null when the command wrote none. */
    private function fetchReport(string $file, string $target): ?string
    {
        if (is_file($target)) {
            unlink($target);
        }
        $copy = $this->runner->capture(['docker', 'compose', '-f', $file, 'cp', 'app:' . self::JUNIT, $target]);
        return $copy['status'] === 0 && is_file($target) ? $target : null;
    }

    /**
     * @param list<string> $arguments
     * @return array{images: array{A: string, B: string}, mounts: list<string>, locales: ?string, out: ?string, keep: bool, command: non-empty-list<string>}
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
        $mounts = [];
        $locales = null;
        $out = null;
        $keep = false;
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if ($argument === '--keep') {
                $keep = true;
                continue;
            }
            if (!str_starts_with($argument, '--')) {
                $images[] = StandaloneStack::image($argument);
                continue;
            }
            $value = array_shift($arguments);
            if ($value === null) {
                throw new RuntimeException("{$argument} needs a value");
            }
            if ($argument === '--mount') {
                $mounts[] = StandaloneStack::mount($value);
            } elseif ($argument === '--locales') {
                $locales = StandaloneStack::locales($value);
            } elseif ($argument === '--out') {
                $out = $value;
            } else {
                throw new RuntimeException("unknown option {$argument}\n" . self::USAGE);
            }
        }
        if (count($images) !== 2) {
            throw new RuntimeException('ab compares two images, got ' . count($images) . "\n" . self::USAGE);
        }
        if ($images[0] === $images[1]) {
            throw new RuntimeException("both sides are {$images[0]}; nothing to compare");
        }
        return ['images' => ['A' => $images[0], 'B' => $images[1]], 'mounts' => $mounts, 'locales' => $locales, 'out' => $out, 'keep' => $keep, 'command' => $command];
    }
}
