<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use SimpleXMLElement;

final class CivixCommand implements Command
{
    public function __construct(private readonly Runner $runner = new Runner())
    {
    }

    public function run(array $arguments): int
    {
        $mode = 'report';
        foreach ($arguments as $argument) {
            if (in_array($argument, ['-h', '--help'], true)) {
                echo $this->usage();
                return 0;
            }
            if ($argument === '--check') {
                $mode = 'check';
            } elseif ($argument === '--update') {
                $mode = 'update';
            } else {
                return $this->error("unknown argument: {$argument}");
            }
        }

        if (!is_file('info.xml')) {
            return $this->error('no info.xml here - run from the extension root.');
        }
        $civix = $this->findExecutable('civix');
        if ($civix === null) {
            return $this->error('civix not found - this needs the civikitchen image.');
        }

        try {
            $declared = $this->declaredFormat('info.xml');
            $current = $this->currentFormat($civix);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage());
        }
        $scaffolds = glob('*.civix.php') ?: [];
        $scaffold = $scaffolds[0] ?? '';

        if ($mode === 'update') {
            if ($declared === '') {
                return $this->error('no <civix><format> in info.xml - this extension has no scaffold to upgrade.');
            }
            return $this->runner->passthrough([$civix, 'upgrade', '-n']);
        }
        if ($declared === '' && $scaffold === '') {
            echo "civix: no scaffold (no <civix><format>, no *.civix.php); current format is {$current}\n";
            return $mode === 'check' ? 1 : 0;
        }
        if ($declared === '' || $scaffold === '') {
            echo "civix: INCONSISTENT - info.xml format='" . ($declared ?: 'none')
                . "' but scaffold file '" . ($scaffold ?: 'none') . "'\n";
            return 1;
        }
        if ($declared === $current) {
            echo "civix: format {$declared} (current)\n";
            return 0;
        }
        if (version_compare($declared, $current, '>')) {
            echo "civix: format {$declared} is AHEAD of this image's civix ({$current}) - update the image, do not run --update\n";
            return 1;
        }
        echo "civix: format {$declared} is behind {$current} - run 'ckcivix --update' (or 'civix upgrade')\n";
        return $mode === 'check' ? 1 : 0;
    }

    private function declaredFormat(string $file): string
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($file);
        if (!$xml instanceof SimpleXMLElement) {
            throw new \RuntimeException('cannot parse info.xml');
        }
        return trim((string) $xml->civix->format);
    }

    private function currentFormat(string $civix): string
    {
        $source = file_get_contents($civix);
        if ($source === false || preg_match_all('#upgrades/([0-9]+\.[0-9]+\.[0-9]+)\.up\.php#', $source, $matches) < 1) {
            throw new \RuntimeException('no upgrade scripts found in the civix phar');
        }
        $versions = array_values(array_unique($matches[1]));
        usort($versions, 'version_compare');
        return (string) end($versions);
    }

    private function findExecutable(string $name): ?string
    {
        $result = $this->runner->capture(['sh', '-c', 'command -v "$1"', 'sh', $name]);
        $path = trim($result['output']);
        return $result['status'] === 0 && $path !== '' ? $path : null;
    }

    private function error(string $message): int
    {
        fwrite(STDERR, "ckcivix: {$message}\n");
        return 2;
    }

    private function usage(): string
    {
        return <<<'TXT'
ckcivix - report or fix drift between the civix scaffold and the current format.

  ckcivix            report the declared format, the current one, and the verdict
  ckcivix --check    exit 1 when the scaffold is behind or missing
  ckcivix --update   run `civix upgrade` to bring the scaffold forward

Run from the extension root. An extension with no scaffold at all is reported,
not failed, unless --check is given.

--check compares <civix><format> with civix's newest upgrade step. `civix
upgrade` also runs a general pass every time (*.civix.php, empty hook stubs,
mixins), so --update can still change files when --check passes.
TXT;
    }
}
