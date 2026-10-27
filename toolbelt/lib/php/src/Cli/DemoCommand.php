<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Demo\Cms;
use CiviKitchen\Toolbelt\Demo\ComparisonPage;
use CiviKitchen\Toolbelt\Demo\CoreDiff;
use CiviKitchen\Toolbelt\Demo\Recorder;
use CiviKitchen\Toolbelt\Demo\Stack;
use CiviKitchen\Toolbelt\Process\Runner;
use Closure;
use RuntimeException;

/** Records a civicrm-core change before and after on fresh sites, one stack per CMS. Runs on the host. */
final class DemoCommand implements Command
{
    private const FIRST_PORT = 8300;

    private const USAGE = <<<'TXT'
ck demo — record a civicrm-core change before and after, on one or more CMS

  ck demo <PR number|PR URL|diff file> --page <route> [--page <route> ...] [options]

  --page <route>       a CiviCRM route to show, e.g. 'civicrm/admin/search' (repeatable)
  --steps <file.ts>    a self-contained ES module whose default export drives the page
                       after the routes:
                       export default async ({ page, url, cms, phase }) => { ... }
  --cms <list>         comma-separated standalone, wordpress, drupal10, drupal11,
                       joomla, or all (default: standalone)
  --image <cms>=<ref>  boot that CMS from another image (repeatable)
  --cv '<arguments>'   run `cv <arguments>` on every site before recording (repeatable)
  --out <dir>          for the videos and index.html (default: ./ck-demo-<PR or file>)
  --size <WxH>         viewport and video size (default: 1280x800)
  --dwell <seconds>    how long each route stays on screen (default: 2.5)
  --keep               leave the stacks running, with the change applied

Runs on the host and needs Docker and PHP. Every CMS boots in a stack of its own;
the change is checked against all of them before anything is recorded.
TXT;

    /** @param (Closure(int): bool)|null $portFree */
    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
        private readonly ?Closure $portFree = null,
    ) {
    }

    public function run(array $arguments): int
    {
        if ($arguments === [] || array_intersect($arguments, ['-h', '--help']) !== []) {
            echo self::USAGE, "\n";
            return $arguments === [] ? 2 : 0;
        }
        $options = $this->parse($arguments);
        if ($this->runner->which('docker') === null) {
            throw new RuntimeException('demo runs on the host and needs docker on PATH');
        }
        $diff = CoreDiff::load($options['source'], $this->runner);
        echo "ck demo: {$diff->title} changes " . count($diff->files) . " files\n";
        $out = $options['out'] ?? getcwd() . '/ck-demo-' . $diff->label;
        if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
            throw new RuntimeException("cannot create {$out}");
        }
        $out = (string) realpath($out);
        // A page from an earlier run must not pass for this one if it fails.
        if (is_file("{$out}/index.html")) {
            unlink("{$out}/index.html");
        }

        $stacks = [];
        try {
            foreach ($options['cms'] as $name) {
                $file = "{$out}/stacks/{$name}.json";
                $port = is_file($file) ? 0 : $this->freePort(array_map(static fn(Stack $stack): int => $stack->port, $stacks));
                $cms = Cms::named($name, $options['images'][$name] ?? null);
                $stacks[] = Stack::prepare($cms, "ck-demo-{$diff->label}-{$name}", $file, $port, $this->runner);
            }
            $this->record($diff, $stacks, $options, $out);
        } finally {
            $this->finish($stacks, $options['keep'], $out);
        }
        return 0;
    }

    /**
     * @param list<Stack> $stacks
     * @param array{pages: list<string>, steps: ?string, cv: list<string>, size: array{width: int, height: int}, dwell: float} $options
     */
    private function record(CoreDiff $diff, array $stacks, array $options, string $out): void
    {
        $recorder = new Recorder($this->checkoutRoot, $this->runner);
        $image = $recorder->image();
        foreach ($stacks as $stack) {
            echo "ck demo: starting {$stack->cms->label} at {$stack->siteUrl()}\n";
            $stack->up(false);
        }
        $failures = [];
        foreach ($stacks as $stack) {
            $stack->up(true);
            $stack->load($diff);
            try {
                if ($stack->state() === 'applied') {
                    echo "ck demo: {$stack->cms->label} already holds the change; reverting it for the before run\n";
                    $stack->revert();
                }
            } catch (RuntimeException $e) {
                $failures[] = $e->getMessage();
            }
        }
        if ($failures !== []) {
            throw new RuntimeException(implode("\n", $failures) . "\nNothing was recorded.");
        }

        $sections = [];
        foreach ($stacks as $stack) {
            foreach ($options['cv'] as $arguments) {
                $stack->cv($arguments);
            }
            $stack->flush();
            $script = [
                'cms' => $stack->cms->name,
                'site' => $stack->siteUrl(),
                'routePrefix' => $stack->cms->routePrefix,
                'login' => $stack->cms->login,
                'pages' => $options['pages'],
                'size' => $options['size'],
                'dwellMs' => (int) round($options['dwell'] * 1000),
            ];
            $runs = [];
            foreach (['before', 'after'] as $phase) {
                if ($phase === 'after') {
                    $stack->apply();
                }
                echo "ck demo: recording {$stack->cms->label} {$phase}\n";
                $name = "{$stack->cms->name}-{$phase}";
                $errors = $recorder->record($image, $out, $name, $script + ['phase' => $phase], $options['steps']);
                $this->reportErrors("{$stack->cms->label} {$phase}", $errors);
                $runs[$phase] = ['video' => "{$out}/{$name}.webm", 'errors' => $errors];
            }
            $sections[] = ['label' => $stack->cms->label, 'version' => $stack->version(), 'before' => $runs['before'], 'after' => $runs['after']];
        }
        $size = "{$options['size']['width']}×{$options['size']['height']}";
        file_put_contents("{$out}/index.html", ComparisonPage::render($diff, $options['pages'], $size, $sections));
        echo "ck demo: {$out}/index.html\n";
    }

    /** @param list<Stack> $stacks */
    private function finish(array $stacks, bool $keep, string $out): void
    {
        foreach ($stacks as $stack) {
            if ($keep) {
                echo "ck demo: kept {$stack->cms->label} at {$stack->siteUrl()} (admin / admin)\n";
                continue;
            }
            try {
                $stack->down();
            } catch (RuntimeException $e) {
                fwrite(STDERR, "ck demo: {$e->getMessage()}\n");
            }
        }
        if (!$keep && is_dir("{$out}/stacks") && (scandir("{$out}/stacks") ?: []) === ['.', '..']) {
            rmdir("{$out}/stacks");
        }
    }

    /** @param list<string> $errors */
    private function reportErrors(string $run, array $errors): void
    {
        if ($errors === []) {
            return;
        }
        echo "ck demo: {$run}: " . count($errors) . " script errors\n";
        foreach ($errors as $error) {
            echo "  {$error}\n";
        }
    }

    /** @param list<int> $taken ports this run assigned but has not bound yet */
    private function freePort(array $taken): int
    {
        $free = $this->portFree ?? static function (int $port): bool {
            $server = @stream_socket_server("tcp://127.0.0.1:{$port}");
            if ($server === false) {
                return false;
            }
            fclose($server);
            return true;
        };
        for ($port = self::FIRST_PORT; $port <= 65535; $port++) {
            if (!in_array($port, $taken, true) && $free($port)) {
                return $port;
            }
        }
        throw new RuntimeException('no free port for a stack');
    }

    /**
     * @param list<string> $arguments
     * @return array{source: string, pages: list<string>, steps: ?string, cms: list<string>, images: array<string, string>, cv: list<string>, out: ?string, size: array{width: int, height: int}, dwell: float, keep: bool}
     */
    private function parse(array $arguments): array
    {
        $options = ['source' => null, 'pages' => [], 'steps' => null, 'cms' => ['standalone'], 'images' => [], 'cv' => [],
            'out' => null, 'size' => ['width' => 1280, 'height' => 800], 'dwell' => 2.5, 'keep' => false];
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if ($argument === '--keep') {
                $options['keep'] = true;
                continue;
            }
            if (!str_starts_with($argument, '--')) {
                if ($options['source'] !== null) {
                    throw new RuntimeException("one change at a time: {$options['source']} and {$argument}");
                }
                $options['source'] = $argument;
                continue;
            }
            $value = array_shift($arguments);
            if ($value === null) {
                throw new RuntimeException("{$argument} needs a value");
            }
            switch ($argument) {
                case '--page':
                    $route = ltrim($value, '/');
                    if (!str_starts_with($route, 'civicrm/')) {
                        throw new RuntimeException("--page takes a CiviCRM route such as civicrm/admin/search, not {$value}");
                    }
                    $options['pages'][] = $route;
                    break;
                case '--steps':
                    $steps = realpath($value);
                    if ($steps === false || !is_file($steps)) {
                        throw new RuntimeException("no steps module at {$value}");
                    }
                    if (!isset(Recorder::STEPS_EXTENSIONS[pathinfo($steps, PATHINFO_EXTENSION)])) {
                        throw new RuntimeException('--steps takes a .' . implode(', .', array_keys(Recorder::STEPS_EXTENSIONS)) . " module, not {$value}");
                    }
                    $options['steps'] = $steps;
                    break;
                case '--cms':
                    $names = $value === 'all' ? Cms::NAMES : array_values(array_unique(array_map('trim', explode(',', $value))));
                    array_map(static fn(string $name): Cms => Cms::named($name), $names);
                    $options['cms'] = $names;
                    break;
                case '--image':
                    [$name, $image] = array_pad(explode('=', $value, 2), 2, '');
                    if (!in_array($name, Cms::NAMES, true) || $image === '') {
                        throw new RuntimeException("--image takes <cms>=<image>, not {$value}");
                    }
                    $options['images'][$name] = $image;
                    break;
                case '--cv':
                    $options['cv'][] = $value;
                    break;
                case '--out':
                    $options['out'] = $value;
                    break;
                case '--size':
                    if (preg_match('/^(\d{3,4})x(\d{3,4})$/', $value, $match) !== 1) {
                        throw new RuntimeException("--size takes <width>x<height>, not {$value}");
                    }
                    $options['size'] = ['width' => (int) $match[1], 'height' => (int) $match[2]];
                    break;
                case '--dwell':
                    if (!is_numeric($value) || (float) $value < 0) {
                        throw new RuntimeException("--dwell takes seconds, not {$value}");
                    }
                    $options['dwell'] = (float) $value;
                    break;
                default:
                    throw new RuntimeException("unknown option {$argument}\n" . self::USAGE);
            }
        }
        if ($options['source'] === null) {
            throw new RuntimeException("which change? Name a PR number, PR URL or diff file\n" . self::USAGE);
        }
        if ($options['pages'] === [] && $options['steps'] === null) {
            throw new RuntimeException('nothing to record: give --page or --steps');
        }
        foreach (array_keys($options['images']) as $name) {
            if (!in_array($name, $options['cms'], true)) {
                throw new RuntimeException("--image names {$name}, which --cms does not record");
            }
        }
        return $options;
    }
}
