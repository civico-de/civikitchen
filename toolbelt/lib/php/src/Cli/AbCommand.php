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

    private const IMAGE = 'ghcr.io/civico-de/civikitchen:';
    private const SOURCE_LOG = '/usr/local/share/civikitchen/civicrm-source.log';
    // The images' start period plus every retry of their healthcheck.
    private const BOOT_SECONDS = 900;

    private const USAGE = <<<'TXT'
ck ab — run the same check on two CiviCRM versions and compare the results

  ck ab <image A> <image B> [options] -- <command> [arguments ...]

  <image>              a Standalone image, or a civikitchen tag such as standalone-6.16,
                       standalone, standalone-beta or standalone-master
  --mount <src>:<dst>  mount a host path into both app containers (repeatable),
                       e.g. an extension: --mount "$PWD:/var/www/html/ext/myext"
  --out <dir>          for the compose files and JUnit reports (default: ./ck-ab-<A>-vs-<B>)
  --keep               leave both stacks running

The command runs in each app container as www-data, from /var/www/html. When it
writes a JUnit report to /tmp/ck-ab.xml, the results are compared per test:

  ck ab standalone standalone-beta -- ckcoretest --log-junit /tmp/ck-ab.xml tests/phpunit/api/v4/Query

Exit status 1: a test passes on A and fails on B, or without reports, the
command passes on A and fails on B. Runs on the host through scaffold/ck.
TXT;

    public function __construct(private readonly Runner $runner = new Runner())
    {
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
        $label = self::label($options['images']['A']) . '-vs-' . self::label($options['images']['B']);
        $out = $options['out'] ?? getcwd() . "/ck-ab-{$label}";
        if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
            throw new RuntimeException("cannot create {$out}");
        }
        $out = (string) realpath($out);

        $files = [];
        $results = [];
        try {
            foreach ($options['images'] as $side => $image) {
                $files[$side] = $this->prepare("ck-ab-{$label}-" . strtolower($side), $image, $options['mounts'], "{$out}/{$side}.compose.json");
            }
            foreach ($files as $file) {
                $this->up($file, false);
            }
            foreach ($files as $side => $file) {
                $this->up($file, true);
                $results[$side] = ['image' => $options['images'][$side], 'core' => $this->core($file)];
            }
            foreach ($files as $side => $file) {
                echo "ck ab: {$side} ({$results[$side]['core']}): " . implode(' ', $options['command']) . "\n";
                $results[$side]['status'] = $this->runner->passthrough([...self::exec($file), ...$options['command']]);
                $results[$side]['junit'] = $this->fetchReport($file, "{$out}/{$side}.junit.xml");
            }
        } finally {
            foreach ($files as $side => $file) {
                if ($options['keep']) {
                    echo "ck ab: kept {$side}: docker compose -f {$file} exec -u www-data app bash\n";
                    continue;
                }
                if ($this->runner->passthrough(['docker', 'compose', '-f', $file, 'down', '-v', '--remove-orphans']) !== 0) {
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

    /**
     * Writes the compose file of one side; a project of that name must not exist yet.
     *
     * @param list<string> $mounts
     */
    private function prepare(string $project, string $image, array $mounts, string $file): string
    {
        $containers = $this->runner->capture(['docker', 'ps', '-a', '-q', '--filter', "label=com.docker.compose.project={$project}"]);
        if ($containers['status'] !== 0) {
            throw new RuntimeException("docker ps failed:\n" . $containers['output']);
        }
        if (trim($containers['output']) !== '') {
            throw new RuntimeException("a stack named {$project} exists; remove it: docker compose -p {$project} down -v");
        }
        $config = [
            'name' => $project,
            'services' => [
                'app' => [
                    'image' => $image,
                    'pull_policy' => 'missing',
                    'depends_on' => ['db' => ['condition' => 'service_healthy']],
                    'environment' => [
                        'CIVICRM_AUTO_INSTALL' => '1',
                        'CIVICRM_DB_HOST' => 'db',
                        'CIVICRM_DB_NAME' => 'civicrm',
                        'CIVICRM_DB_USER' => 'civicrm',
                        'CIVICRM_DB_PASSWORD' => 'civicrm',
                        // The headless test database is created as database root.
                        'CIVICRM_DB_ROOT_PASSWORD' => 'root',
                    ],
                    'volumes' => $mounts,
                ],
                'db' => [
                    'image' => 'mariadb:11.4',
                    'environment' => [
                        'MARIADB_ROOT_PASSWORD' => 'root',
                        'MARIADB_DATABASE' => 'civicrm',
                        'MARIADB_USER' => 'civicrm',
                        'MARIADB_PASSWORD' => 'civicrm',
                    ],
                    'healthcheck' => [
                        'test' => ['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized'],
                        'interval' => '5s',
                        'timeout' => '5s',
                        'retries' => 30,
                    ],
                ],
            ],
        ];
        file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $file;
    }

    private function up(string $file, bool $wait): void
    {
        $command = ['docker', 'compose', '-f', $file, 'up', '-d'];
        if ($wait) {
            array_push($command, '--wait', '--wait-timeout', (string) self::BOOT_SECONDS);
        }
        if ($this->runner->passthrough($command) !== 0) {
            // The run removes the stack on its way out, so its log goes into the error.
            $log = $this->runner->capture(['docker', 'compose', '-f', $file, 'logs', '--no-color', '--tail', '40', 'app']);
            throw new RuntimeException("the stack of {$file} did not boot. The end of its log:\n" . $log['output']);
        }
    }

    /** The CiviCRM version, plus the commits a git-built image names. */
    private function core(string $file): string
    {
        $version = $this->runner->captureSeparate([...self::exec($file), 'cv', 'ev', 'echo CRM_Utils_System::version();']);
        if ($version['status'] !== 0) {
            throw new RuntimeException("cv ev failed in the stack of {$file}:\n" . $version['stdout'] . $version['stderr']);
        }
        $core = 'CiviCRM ' . trim($version['stdout']);
        $source = $this->runner->captureSeparate([...self::exec($file), 'cat', self::SOURCE_LOG]);
        if ($source['status'] === 0 && trim($source['stdout']) !== '') {
            $core .= ', ' . implode(', ', array_map('trim', explode("\n", trim($source['stdout']))));
        }
        return $core;
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

    /** @return non-empty-list<string> */
    private static function exec(string $file): array
    {
        return ['docker', 'compose', '-f', $file, 'exec', '-T', '-u', 'www-data', '-w', '/var/www/html', 'app'];
    }

    private static function label(string $image): string
    {
        $tag = substr($image, (int) strrpos($image, ':') + 1);
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($tag)), '-');
    }

    /**
     * @param list<string> $arguments
     * @return array{images: array{A: string, B: string}, mounts: list<string>, out: ?string, keep: bool, command: non-empty-list<string>}
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
        $out = null;
        $keep = false;
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if ($argument === '--keep') {
                $keep = true;
                continue;
            }
            if (!str_starts_with($argument, '--')) {
                $images[] = preg_match('#[:/]#', $argument) === 1 ? $argument : self::IMAGE . $argument;
                continue;
            }
            $value = array_shift($arguments);
            if ($value === null) {
                throw new RuntimeException("{$argument} needs a value");
            }
            if ($argument === '--mount') {
                if (preg_match('#^/[^:]*:/[^:]+$#', $value) !== 1) {
                    throw new RuntimeException("--mount takes <absolute host path>:<container path>, not {$value}");
                }
                $mounts[] = $value;
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
        return ['images' => ['A' => $images[0], 'B' => $images[1]], 'mounts' => $mounts, 'out' => $out, 'keep' => $keep, 'command' => $command];
    }
}
