<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Demo\Cms;
use CiviKitchen\Toolbelt\Demo\Recorder;
use CiviKitchen\Toolbelt\Demo\StepsRunner;
use CiviKitchen\Toolbelt\Playwright\Image;
use CiviKitchen\Toolbelt\Process\Runner;
use RuntimeException;

final class StepsCommand
{
    private const USAGE = <<<'TXT'
ck steps — run a steps module in a browser against a running CiviCRM site

  ck steps <site-url> <steps.ts> [options]

  ck steps http://localhost:8300 ./search.ts
  ck steps http://localhost:8301 ./search.ts --cms joomla --page civicrm/admin/search
  ck steps --build-image

Logs in as admin/admin, calls the module's default export and prints one line
of JSON: whether the steps finished (ok), what they threw (error) or returned
(value), and where they ended: url, title, the page as text, and the script
errors the pages logged. The module is the one `ck demo --steps` takes:
export default async ({ page, url, cms, phase }) => { ... }

  --page <route>       open this CiviCRM route before the steps, as `ck demo --page`
                       does, e.g. 'civicrm/admin/search'
  --cms <cms>          the CMS the site runs: standalone, wordpress, drupal10,
                       drupal11, joomla or backdrop (default: standalone)
  --phase <phase>      passed to the module: before or after (default: after)
  --size <WxH>         viewport (default: 1280x800)
  --timeout <seconds>  give up on the steps after this long (default: 120)

`ck steps --build-image` builds the browser's image where it is missing and
prints its tag; the first run otherwise builds it, which takes minutes.

Exits 0 when the steps finished, 1 when they threw or ran out of time, 2 when
the browser could not run them. Runs on the host. scaffold/ck runs it in a
container, so the host needs only Docker; the module must lie below $HOME, the
civikitchen checkout or the current git work tree, in a directory ck can write
to: the runner script lies beside the module while the browser runs.
TXT;

    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        if ($arguments === [] || array_intersect($arguments, ['-h', '--help']) !== []) {
            echo self::USAGE, "\n";
            return $arguments === [] ? 2 : 0;
        }
        $prepare = $arguments === ['--build-image'];
        $options = $prepare ? null : $this->parse($arguments);
        if ($this->runner->which('docker') === null) {
            throw new RuntimeException('steps runs on the host and needs docker on PATH');
        }
        $image = (new Image($this->checkoutRoot, $this->runner))->for($this->checkoutRoot);
        if ($options === null) {
            echo $image, "\n";
            return 0;
        }
        $report = (new StepsRunner($this->checkoutRoot, $this->runner))->run(
            $image,
            Cms::named($options['cms']),
            $options['site'],
            $options['steps'],
            $options['page'],
            $options['phase'],
            $options['size'],
            $options['timeout'],
        );
        echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
        return $report['ok'] === true ? 0 : 1;
    }

    /**
     * @param list<string> $arguments
     * @return array{site: string, steps: string, page: ?string, cms: string, phase: string, size: array{width: int, height: int}, timeout: int}
     */
    private function parse(array $arguments): array
    {
        $options = ['page' => null, 'cms' => 'standalone', 'phase' => 'after', 'size' => ['width' => 1280, 'height' => 800], 'timeout' => 120];
        $positional = [];
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if (!str_starts_with($argument, '--')) {
                $positional[] = $argument;
                continue;
            }
            $value = array_shift($arguments);
            if ($value === null || $value === '') {
                throw new RuntimeException("{$argument} needs a value");
            }
            switch ($argument) {
                case '--page':
                    $options['page'] = $value;
                    break;
                case '--cms':
                    if (!in_array($value, Cms::NAMES, true)) {
                        throw new RuntimeException("unknown CMS '{$value}' (one of: " . implode(', ', Cms::NAMES) . ')');
                    }
                    $options['cms'] = $value;
                    break;
                case '--phase':
                    if (!in_array($value, ['before', 'after'], true)) {
                        throw new RuntimeException("--phase takes before or after, not {$value}");
                    }
                    $options['phase'] = $value;
                    break;
                case '--size':
                    if (preg_match('/^([1-9]\d{1,3})x([1-9]\d{1,3})$/', $value, $matches) !== 1) {
                        throw new RuntimeException("--size takes <width>x<height>, not {$value}");
                    }
                    $options['size'] = ['width' => (int) $matches[1], 'height' => (int) $matches[2]];
                    break;
                case '--timeout':
                    if (preg_match('/^[1-9]\d{0,3}$/', $value) !== 1) {
                        throw new RuntimeException("--timeout takes whole seconds, not {$value}");
                    }
                    $options['timeout'] = (int) $value;
                    break;
                default:
                    throw new RuntimeException("unknown option {$argument}");
            }
        }
        if (count($positional) !== 2) {
            throw new RuntimeException('steps takes a site URL and a steps module');
        }
        [$site, $module] = $positional;
        if (preg_match('#^https?://[^/\s]+$#', rtrim($site, '/')) !== 1) {
            throw new RuntimeException("the site is a URL without a path, such as http://localhost:8300, not {$site}");
        }
        $steps = realpath($module);
        if ($steps === false || !is_file($steps)) {
            throw new RuntimeException("no steps module at {$module}");
        }
        if (!isset(Recorder::STEPS_EXTENSIONS[pathinfo($steps, PATHINFO_EXTENSION)])) {
            throw new RuntimeException('steps takes a .' . implode(', .', array_keys(Recorder::STEPS_EXTENSIONS)) . " module, not {$module}");
        }
        return ['site' => rtrim($site, '/'), 'steps' => $steps] + $options;
    }
}
