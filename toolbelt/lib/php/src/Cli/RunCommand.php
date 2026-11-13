<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use RuntimeException;

/** Boots one throwaway Standalone stack from an image and runs a command in it. Runs on the host. */
final class RunCommand implements Command
{
    private const USAGE = <<<'TXT'
ck run — run a command in a fresh Standalone stack of one CiviCRM version

  ck run <image> [options] -- <command> [arguments ...]

  <image>              a Standalone image, or a civikitchen tag such as standalone-6.16,
                       standalone, standalone-beta or standalone-master
  --mount <src>:<dst>  mount a host path into the app container (repeatable), e.g. a
                       directory of a civicrm-core checkout over /var/www/html/core/<dir>
  --locales <list>     install these core language files, e.g. it_IT,fr_FR
                       (sets CIVIKITCHEN_LOCALES; default: en_US only)
  --name <project>     compose project name (default: ck-run-<tag>)
  --keep               leave the stack running; its compose file is written to the
                       current directory

The command runs in the app container as www-data, from /var/www/html:

  ck run standalone-master --mount "$PWD/tests:/var/www/html/core/tests" -- ckcoretest tests/phpunit/api/v4/Query

Exit status: the command's. The stack and its volumes are removed afterwards
unless --keep is given. Runs on the host through scaffold/ck.
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
            throw new RuntimeException('run runs on the host and needs docker on PATH');
        }
        $project = $options['name'] ?? 'ck-run-' . StandaloneStack::label($options['image']);
        // A kept stack is entered and removed through its compose file, so that one stays with the caller.
        $directory = $options['keep'] ? (string) getcwd() : sys_get_temp_dir();
        $file = $this->stack->prepare($project, $options['image'], $options['mounts'], $options['locales'], "{$directory}/{$project}.compose.json");
        try {
            $this->stack->up($file, true);
            echo "ck run: {$options['image']} ({$this->stack->core($file)}): " . implode(' ', $options['command']) . "\n";
            return $this->runner->passthrough([...StandaloneStack::exec($file), ...$options['command']]);
        } finally {
            if ($options['keep']) {
                echo "ck run: kept: docker compose -f {$file} exec -u www-data app bash\n";
                echo "ck run: remove it: docker compose -f {$file} down -v && rm {$file}\n";
            } else {
                if (!$this->stack->down($file)) {
                    // By project name: the temporary compose file does not outlive this process.
                    fwrite(STDERR, "ck run: could not remove the stack; remove it: docker compose -p {$project} down -v\n");
                }
                unlink($file);
            }
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{image: string, mounts: list<string>, locales: ?string, name: ?string, keep: bool, command: non-empty-list<string>}
     */
    private function parse(array $arguments): array
    {
        $separator = array_search('--', $arguments, true);
        $command = $separator === false ? [] : array_slice($arguments, $separator + 1);
        if ($command === []) {
            throw new RuntimeException("which command? Give it after --\n" . self::USAGE);
        }
        $arguments = array_slice($arguments, 0, (int) $separator);
        $images = [];
        $mounts = [];
        $locales = null;
        $name = null;
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
            } elseif ($argument === '--name') {
                if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $value) !== 1) {
                    throw new RuntimeException("--name takes lowercase letters, digits, - and _, not {$value}");
                }
                $name = $value;
            } else {
                throw new RuntimeException("unknown option {$argument}\n" . self::USAGE);
            }
        }
        if (count($images) !== 1) {
            throw new RuntimeException('run takes one image, got ' . count($images) . "\n" . self::USAGE);
        }
        return ['image' => $images[0], 'mounts' => $mounts, 'locales' => $locales, 'name' => $name, 'keep' => $keep, 'command' => $command];
    }
}
