<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Policy;
use SimpleXMLElement;

final class CoverageCommand implements Command
{
    private readonly Policy $policy;

    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
        $this->policy = new Policy($checkoutRoot, $runner);
    }

    public function run(array $arguments): int
    {
        if (in_array($arguments[0] ?? '', ['-h', '--help'], true)) {
            echo $this->usage();
            return 0;
        }
        if (!is_file('info.xml')) {
            return $this->error('no info.xml here - run from the extension root.');
        }
        // policy.tests always carries a reason, so the value is
        // 'optional -- <reason>', never the bare word (TestSuiteRequiredCheck
        // reads the same key the same way).
        $testsOptional = preg_match('/^optional\s+--\s+\S/', $this->policy->value('tests')) === 1;
        $config = is_file('phpunit.xml') ? 'phpunit.xml' : (is_file('phpunit.xml.dist') ? 'phpunit.xml.dist' : null);
        if ($config === null) {
            if ($testsOptional) {
                echo "ckcoverage: no phpunit config, and policy.tests declares it optional - nothing to measure.\n";
                return 0;
            }
            return $this->error('no phpunit config.');
        }
        libxml_use_internal_errors(true);
        $parsed = simplexml_load_file($config);
        if ($parsed === false) {
            return $this->error("{$config} is not well-formed XML.");
        }
        // PHPUnit 9 reads the include list from <coverage> or <filter><whitelist>, never <source>.
        if (($parsed->xpath('/*/coverage | /*/filter/whitelist') ?: []) === []) {
            if ($testsOptional) {
                echo "ckcoverage: phpunit config declares no <coverage> section, and policy.tests declares tests optional - nothing to measure.\n";
                return 0;
            }
            $hint = ($parsed->xpath('/*/source') ?: []) !== [] ? ' (PHPUnit 9 ignores <source>: list the sources in <coverage><include>)' : '';
            return $this->error("phpunit config declares no <coverage> section{$hint} - nothing to measure.");
        }

        $work = Directory::temporary('ckcoverage');
        register_shutdown_function(Directory::remove(...), $work);
        $clover = "{$work}/clover.xml";
        $runLog = "{$work}/run.log";
        // A --log-junit the caller passed comes after ours and wins in phpunit,
        // so read that file instead of adding a second flag - and leave it behind.
        $callerJunit = $this->junitArgument($arguments);
        $junit = $callerJunit ?? "{$work}/junit.xml";
        // pcov instruments only pcov.directory, which it guesses as ./src, ./lib or
        // ./app when one exists - in an extension with a JS src/ that is no PHP at all.
        $iniDir = "{$work}/ini.d";
        $iniFile = $iniDir . '/zz-ckcoverage.ini';
        $cwd = getcwd();
        if ($cwd === false || strpbrk($cwd, '"$') !== false) {
            return $this->error('the extension path must not contain " or $ - pcov.directory cannot be set to it.');
        }
        if (!mkdir($iniDir, 0700) || file_put_contents($iniFile, 'pcov.directory="' . $cwd . "\"\n") === false) {
            return $this->error('could not create temporary files.');
        }

        echo "ckcoverage: running the suite with coverage (this is slower than a plain run) ...\n";
        $runner = $this->runner->which('ckphpunit') ?? 'phpunit';
        $environment = getenv();
        $environment['CIVICRM_UF'] = $environment['CIVICRM_UF'] ?? 'UnitTests';
        // An empty segment keeps PHP's own scan directory, as an unset variable does;
        // a variable set to '' scans nothing, so it gets no such segment.
        $scanDirs = $environment['PHP_INI_SCAN_DIR'] ?? null;
        $environment['PHP_INI_SCAN_DIR'] = $scanDirs === '' ? $iniDir : $scanDirs . PATH_SEPARATOR . $iniDir;
        $command = [$runner, '--coverage-clover', $clover];
        if ($callerJunit === null) {
            $command[] = '--log-junit';
            $command[] = $junit;
        }
        $status = $this->runner->redirect([...$command, ...$arguments], $runLog, $environment);
        if ($status !== 0) {
            fwrite(STDERR, "ckcoverage: the test suite itself failed - coverage is meaningless until it passes.\n");
            $lines = file($runLog, FILE_IGNORE_NEW_LINES) ?: [];
            fwrite(STDERR, implode("\n", array_slice($lines, -25)) . "\n");
            return $status;
        }
        // "No tests executed!" is an exit-0 phpunit run, and so is a suite that
        // skips everything; the junit log is the only structured count.
        $counts = $this->junitCounts($junit);
        if ($counts === null) {
            return $this->error('phpunit wrote no readable junit log - cannot tell whether any test ran.');
        }
        if ($counts['cases'] === 0) {
            if ($testsOptional) {
                echo "ckcoverage: no test was executed, and policy.tests declares tests optional - nothing to measure.\n";
                return 0;
            }
            return $this->error('no test was executed - an empty suite is not a passing suite.');
        }
        if ($counts['ran'] === 0) {
            if ($testsOptional) {
                echo "ckcoverage: every test was skipped, and policy.tests declares tests optional - nothing to measure.\n";
                return 0;
            }
            return $this->error('every test was skipped - a skipped suite is not a passing suite.');
        }
        if (!is_file($clover) || filesize($clover) === 0) {
            $log = (string) file_get_contents($runLog);
            if (str_contains($log, 'No filter is configured')) {
                return $this->error('phpunit saw no coverage filter - list the sources in <coverage><include>.');
            }
            if (str_contains($log, 'Incorrect filter configuration')) {
                return $this->error('the <coverage> include list matches no file - check its paths.');
            }
            return $this->error('phpunit produced no clover report (is pcov/xdebug loaded?)');
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($clover);
        $metrics = $xml instanceof SimpleXMLElement ? $xml->project->metrics : null;
        if (!$metrics instanceof SimpleXMLElement) {
            return $this->error('no project metrics in clover');
        }
        $covered = (int) $metrics['coveredstatements'];
        $total = (int) $metrics['statements'];
        if ($total === 0) {
            return $this->error('zero executable statements measured - check the <coverage> include paths.');
        }
        $percentage = $covered * 100 / $total;
        $formatted = number_format($percentage, 2, '.', '');
        echo "ckcoverage: {$formatted}% line coverage ({$covered}/{$total} statements)\n";

        $floor = $this->policy->value('min_coverage');
        if ($floor === '') {
            echo "ckcoverage: no policy.coverage.minimum in civikitchen.yaml - reporting only.\n";
            return 0;
        }
        if (preg_match('/^\d+$/', $floor) !== 1) {
            return $this->error("policy.coverage.minimum is not a whole percentage: '{$floor}'");
        }
        if ($percentage >= (int) $floor) {
            echo "ckcoverage: floor {$floor}% met.\n";
            return 0;
        }
        fwrite(STDERR, "ckcoverage: FAIL - {$formatted}% is below policy.coverage.minimum ({$floor}%).\n");
        return 1;
    }

    /**
     * Listed and actually run test cases; null when the log is missing or
     * unreadable, which is not the same as an empty suite.
     *
     * @return array{cases:int,ran:int}|null
     */
    private function junitCounts(string $junit): ?array
    {
        if (!is_file($junit) || filesize($junit) === 0) {
            return null;
        }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($junit);
        if (!$xml instanceof SimpleXMLElement) {
            return null;
        }
        $cases = $xml->xpath('//testcase');
        if (!is_array($cases)) {
            return null;
        }
        // A skipped or incomplete test is listed but never ran; junit marks
        // both with a <skipped/> child. A failure or error did run.
        $skipped = $xml->xpath('//testcase[skipped]');
        return ['cases' => count($cases), 'ran' => count($cases) - (is_array($skipped) ? count($skipped) : 0)];
    }

    /** @param list<string> $arguments */
    private function junitArgument(array $arguments): ?string
    {
        foreach ($arguments as $index => $argument) {
            if (str_starts_with($argument, '--log-junit=')) {
                return substr($argument, strlen('--log-junit='));
            }
            if ($argument === '--log-junit') {
                return $arguments[$index + 1] ?? null;
            }
        }
        return null;
    }

    private function error(string $message): int
    {
        fwrite(STDERR, "ckcoverage: {$message}\n");
        return 2;
    }

    private function usage(): string
    {
        return <<<'TXT'
ckcoverage - run the PHPUnit suite with coverage and enforce the floor.

  ckcoverage [phpunit args]

The floor is policy.coverage.minimum in civikitchen.yaml. Arguments go to
phpunit; a --log-junit given there is read instead of a temporary log. Run from
the extension root.

TXT;
    }
}
