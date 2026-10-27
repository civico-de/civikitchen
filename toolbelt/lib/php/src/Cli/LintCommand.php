<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Files;
use CiviKitchen\Toolbelt\Repository\PhpFloor;

final class LintCommand implements Command
{
    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    public function run(array $arguments): int
    {
        $fix = false;
        $all = false;
        $paths = [];
        $literal = false;
        foreach ($arguments as $argument) {
            if (!$literal && in_array($argument, ['-h', '--help'], true)) {
                echo $this->usage();
                return 0;
            }
            if (!$literal && $argument === '--') {
                $literal = true;
            } elseif (!$literal && $argument === '--fix') {
                $fix = true;
            } elseif (!$literal && $argument === '--all') {
                $all = true;
            } elseif (!$literal && str_starts_with($argument, '-')) {
                return $this->error("unknown option: {$argument}");
            } else {
                $paths[] = $argument;
            }
        }
        $repository = new Files($this->checkoutRoot, $this->runner);
        if (!$repository->isGitCheckout()) {
            return $this->error('not a git checkout - the file list comes from git ls-files.');
        }
        if ($paths === [] && !$all) {
            $paths = $repository->changedPhp();
            if ($paths === []) {
                echo "cklint: no changed PHP files to lint.\n";
                return 0;
            }
        }
        if (!$all) {
            foreach ($paths as $path) {
                if (is_file($path) && str_ends_with($path, '.php')
                    && $this->runner->passthrough([PHP_BINARY, '-l', $path]) !== 0) {
                    return 1;
                }
            }
        }
        $failed = false;

        $mago = $this->runner->which('mago');
        if ($mago === null) {
            return $this->error('no mago on PATH - is this a civikitchen image?');
        }
        $scope = $all ? [] : $paths;
        $files = $repository->source(['php'], $scope, true, true);
        $files = array_values(array_filter($files, static fn(string $file): bool => $file !== 'tests/phpunit/bootstrap.php'));
        if ($files === []) {
            echo "cklint: no PHP files for the mago lint stage.\n";
        } else {
            $magoArguments = [$mago];
            if (is_file('mago.toml')) {
                echo "cklint: using this repo's own mago.toml (the CiviKitchen lint baseline applies only through its extends).\n";
            } else {
                $magoArguments = [...$magoArguments, '--config', $this->checkoutRoot . '/toolbelt/mago/mago.toml'];
                // The baseline's 8.1 is the fleet floor; a repo declaring a higher one may use its features.
                $floor = PhpFloor::ofComposerJson('.');
                if ($floor !== null) {
                    $magoArguments = [...$magoArguments, '--php-version', $floor];
                }
            }
            $strictTypes = $fix ? $this->magoRuleEnabled($magoArguments, 'strict-types') : false;
            if ($strictTypes === null) {
                return $this->error('could not read the effective mago linter config (mago config --show linter)');
            }
            $magoArguments[] = 'lint';
            // The extension rules resolve info.xml and fixtures from the repo root, not mago's config directory.
            $environment = [...(getenv() ?: []), 'CK_MAGO_WORKSPACE' => (string) getcwd()];
            // mago rates inserting the declare potentially unsafe; that semantic change is the rule's point.
            // `--only` would re-enable a rule the repo switched off, hence the config check.
            if ($strictTypes && $this->runner->passthrough([...$magoArguments, '--only', 'strict-types', '--fix', '--potentially-unsafe', '--format-after-fix', ...$files], $environment) !== 0) {
                $failed = true;
            }
            if ($fix) {
                $magoArguments = [...$magoArguments, '--fix', '--format-after-fix'];
            }
            if ($this->runner->passthrough([...$magoArguments, ...$files], $environment) !== 0) {
                $failed = true;
            }
        }
        return $failed ? 1 : 0;
    }

    /**
     * @param list<string> $magoArguments mago and its global options
     */
    private function magoRuleEnabled(array $magoArguments, string $rule): ?bool
    {
        // stdout only: mago logs `--php-version` overrides to stderr.
        $result = $this->runner->captureSeparate([...$magoArguments, 'config', '--show', 'linter', '--no-extensions']);
        $config = json_decode($result['stdout'], true);
        $enabled = is_array($config) ? ($config['rules'][$rule]['enabled'] ?? null) : null;

        return $result['status'] === 0 && is_bool($enabled) ? $enabled : null;
    }

    private function error(string $message): int
    {
        fwrite(STDERR, "cklint: {$message}\n");
        return 2;
    }

    private function usage(): string
    {
        return <<<'TXT'
cklint - PHP linting for CiviCRM extensions (php -l + mago lint).

  cklint                  lint PHP files with uncommitted git changes
  cklint --all            lint the whole project
  cklint path ...         lint given files/directories
  cklint --fix [path...]  auto-fix findings
TXT;
    }
}
