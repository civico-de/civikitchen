<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Surface\SurfaceDiff;
use RuntimeException;

/** Dumps the CiviCRM API/route/Afform surface inside a container, or diffs two surface JSON files. */
final class SurfaceCommand implements Command
{
    private const USAGE = <<<'TXT'
ck surface — dump or diff CiviCRM API, route, and Afform surface

  ck surface [--out <file>]
  ck surface --diff <A.json> <B.json> [--json] [--fail-on-risk] [--out <file>]

  --out <file>         write output to <file> instead of stdout
  --diff <A> <B>       compare two surface JSON files (runs anywhere, no CiviCRM needed)
  --json               with --diff, output structured JSON instead of a text summary
  --fail-on-risk       with --diff, exit 1 when any risk flag is detected
TXT;

    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    public function run(array $arguments): int
    {
        if (in_array('-h', $arguments, true) || in_array('--help', $arguments, true)) {
            echo self::USAGE, "\n";
            return 0;
        }

        $options = $this->parse($arguments);
        if ($options['diff']) {
            return $this->runDiff($options['files'][0], $options['files'][1], $options['json'], $options['fail_on_risk'], $options['out']);
        }
        return $this->runDump($options['out']);
    }

    private function runDump(?string $outFile): int
    {
        if ($this->runner->which('cv') === null) {
            throw new RuntimeException('ck surface requires cv inside a CiviCRM container (or pass --diff <A.json> <B.json>)');
        }
        $payload = $this->checkoutRoot . '/toolbelt/lib/surface-dump.php';
        if (!is_file($payload)) {
            throw new RuntimeException("missing surface dump script: {$payload}");
        }
        $result = $this->runner->captureSeparate(['cv', 'scr', $payload]);
        if ($result['status'] !== 0) {
            $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            throw new RuntimeException("cv scr surface-dump.php failed (exit {$result['status']})" . ($detail !== '' ? ": {$detail}" : ''));
        }
        // cv can exit 0 when CiviCRM fails to boot; decoding proves the payload ran.
        SurfaceDiff::decodeJson($result['stdout'], 'surface dump');
        $output = rtrim($result['stdout']) . "\n";
        $this->emit($output, $outFile);
        return 0;
    }

    private function runDiff(string $fileA, string $fileB, bool $json, bool $failOnRisk, ?string $outFile): int
    {
        $diff = SurfaceDiff::fromFiles($fileA, $fileB);
        $output = $json
            ? json_encode($diff->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
            : $diff->formatSummary();
        $this->emit($output, $outFile);
        return $failOnRisk && $diff->hasRiskFlags() ? 1 : 0;
    }

    private function emit(string $content, ?string $outFile): void
    {
        if ($outFile === null) {
            echo $content;
            return;
        }
        $directory = dirname($outFile);
        if ($directory !== '' && !is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("cannot create directory for {$outFile}");
        }
        if (file_put_contents($outFile, $content) === false) {
            throw new RuntimeException("cannot write {$outFile}");
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{diff: bool, files: list<string>, json: bool, fail_on_risk: bool, out: ?string}
     */
    private function parse(array $arguments): array
    {
        $diff = false;
        $json = false;
        $failOnRisk = false;
        $out = null;
        $files = [];

        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if ($argument === '--diff') {
                $diff = true;
                continue;
            }
            if ($argument === '--json') {
                $json = true;
                continue;
            }
            if ($argument === '--fail-on-risk') {
                $failOnRisk = true;
                continue;
            }
            if ($argument === '--out') {
                $value = array_shift($arguments);
                if ($value === null || $value === '' || str_starts_with($value, '--')) {
                    throw new RuntimeException('--out needs a file path');
                }
                $out = $value;
                continue;
            }
            if (str_starts_with($argument, '--')) {
                throw new RuntimeException("unknown option {$argument}\n" . self::USAGE);
            }
            $files[] = $argument;
        }

        if ($diff) {
            if (count($files) !== 2) {
                throw new RuntimeException("--diff requires two surface JSON files: <A.json> <B.json>\n" . self::USAGE);
            }
        } else {
            if ($files !== []) {
                throw new RuntimeException("unexpected argument: {$files[0]}\n" . self::USAGE);
            }
            if ($failOnRisk) {
                throw new RuntimeException('--fail-on-risk is only valid with --diff');
            }
        }

        return [
            'diff' => $diff,
            'files' => $files,
            'json' => $json,
            'fail_on_risk' => $failOnRisk,
            'out' => $out,
        ];
    }
}
