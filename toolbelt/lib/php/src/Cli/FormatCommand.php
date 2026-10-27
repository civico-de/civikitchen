<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Files;

final class FormatCommand implements Command
{
    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    public function run(array $arguments): int
    {
        $check = false;
        $paths = [];
        $literal = false;
        foreach ($arguments as $argument) {
            if (!$literal && $argument === '--') {
                $literal = true;
            } elseif (!$literal && in_array($argument, ['-h', '--help'], true)) {
                echo $this->usage();
                return 0;
            } elseif (!$literal && $argument === '--check') {
                $check = true;
            } elseif (!$literal && str_starts_with($argument, '-')) {
                return $this->error("unknown option: {$argument}");
            } else {
                $paths[] = $argument;
            }
        }
        if (!is_file('info.xml')) {
            return $this->error('no info.xml here - run from the extension root.');
        }
        $repository = new Files($this->checkoutRoot, $this->runner);
        if (!$repository->isGitCheckout()) {
            return $this->error('not a git checkout - the file list comes from git ls-files.');
        }
        $mago = $this->runner->which('mago');
        if ($mago === null) {
            return $this->error('no mago on PATH - is this a civikitchen image?');
        }
        $magoConfig = $this->checkoutRoot . '/toolbelt/mago/mago.toml';
        $oxfmt = $this->checkoutRoot . '/toolbelt/oxfmt/node_modules/.bin/oxfmt';
        $failed = false;

        // Untracked files included: a new file is exactly the one nobody has
        // formatted yet, and --check is the gate that has to catch it.
        $phpFiles = $repository->source(['php'], $paths, true, true);
        if ($phpFiles === []) {
            echo "ckfmt: no PHP files to format.\n";
        } else {
            $base = [$mago];
            if (is_file('mago.toml')) {
                echo "ckfmt: using this repo's own mago.toml (the CiviKitchen baseline does not apply).\n";
            } else {
                $base = [...$base, '--config', $magoConfig];
            }
            if ($check) {
                $failed = $this->runner->passthrough([...$base, 'fmt', '--check', ...$phpFiles]) !== 0;
            } else {
                for ($pass = 0; $pass < 3; $pass++) {
                    if ($this->runner->passthrough([...$base, 'fmt', ...$phpFiles]) !== 0) {
                        $failed = true;
                        break;
                    }
                    if ($this->runner->capture([...$base, 'fmt', '--check', ...$phpFiles])['status'] === 0) {
                        break;
                    }
                    if ($pass === 2) {
                        fwrite(STDERR, "ckfmt: mago output did not stabilise after 3 passes - likely a mago formatter bug.\n");
                        $failed = true;
                    }
                }
            }
        }

        $jsFiles = $repository->source(['js', 'mjs', 'cjs', 'ts', 'tsx'], $paths, true, true);
        if ($jsFiles === []) {
            echo "ckfmt: no JavaScript or TypeScript to format.\n";
        } elseif (!is_executable($oxfmt)) {
            return $this->error("no oxfmt toolchain at {$oxfmt} - is this a civikitchen image?");
        } else {
            $oxArguments = [$oxfmt, $check ? '--check' : '--write'];
            if (glob('.oxfmtrc.*')) {
                echo "ckfmt: using this repo's own .oxfmtrc.* (the CiviKitchen baseline does not apply).\n";
            } else {
                $oxArguments = [...$oxArguments, '-c', dirname($oxfmt, 3) . '/oxfmtrc.json'];
            }
            if ($this->runner->passthrough([...$oxArguments, ...$jsFiles]) !== 0) {
                $failed = true;
            }
        }
        if ($check && $failed) {
            fwrite(STDERR, "ckfmt: unformatted files found - run ckfmt (no flags) and commit the result.\n");
        }
        return $failed ? 1 : 0;
    }

    private function error(string $message): int
    {
        fwrite(STDERR, "ckfmt: {$message}\n");
        return 2;
    }

    private function usage(): string
    {
        return <<<'TXT'
ckfmt - format a CiviCRM extension (mago for PHP, oxfmt for JS/TS).

  ckfmt              format in place
  ckfmt --check      report unformatted files
  ckfmt path ...     limit to given files/directories
TXT;
    }
}
