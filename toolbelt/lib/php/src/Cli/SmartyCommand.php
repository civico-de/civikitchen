<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Policy;
use SimpleXMLElement;

final class SmartyCommand implements Command
{
    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    public function run(array $arguments): int
    {
        $key = '';
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if (in_array($argument, ['-h', '--help'], true)) {
                echo $this->usage();
                return 0;
            }
            if ($argument === '--key') {
                $key = (string) (array_shift($arguments) ?? '');
                continue;
            }
            fwrite(STDERR, "cksmarty: unknown argument: {$argument}\n" . $this->usage());
            return 2;
        }
        if (!is_file('info.xml')) {
            fwrite(STDERR, "cksmarty: no info.xml here - run from the extension root.\n");
            return 2;
        }
        if ($key === '') {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_file('info.xml');
            $key = $xml instanceof SimpleXMLElement ? trim((string) $xml['key']) : '';
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]*$/', $key) !== 1) {
            fwrite(STDERR, "cksmarty: could not read a usable extension key from info.xml (got: '{$key}')\n");
            return 2;
        }

        $payload = $this->checkoutRoot . '/toolbelt/lib/smarty-compile.php';
        $skip = (new Policy($this->checkoutRoot, $this->runner))->values('smarty_skip_templates');
        $environment = getenv();
        $environment['CK_SMARTY_ROOT'] = (string) getcwd();
        $environment['CK_SMARTY_KEY'] = $key;
        $environment['CK_SMARTY_SKIP'] = implode(',', array_map(
            static fn(string $value): string => explode(' -- ', $value, 2)[0],
            $skip,
        ));
        // cv exits 0 when CiviCRM fails to boot, so only the payload's marker proves it ran.
        $marker = sys_get_temp_dir() . '/cksmarty-done-' . bin2hex(random_bytes(8));
        $environment['CK_SMARTY_DONE'] = $marker;
        $status = $this->runner->passthrough(['cv', 'scr', $payload], $environment);
        $finished = is_file($marker);
        @unlink($marker);
        if ($status === 0 && !$finished) {
            fwrite(STDERR, "cksmarty: the compile payload never finished - CiviCRM did not boot, nothing was checked.\n");
            return 2;
        }
        return $status;
    }

    private function usage(): string
    {
        return <<<'TXT'
cksmarty - compile the extension's Smarty templates in a booted CiviCRM.

  cksmarty              compile .tpl files + installed managed MessageTemplates
  cksmarty --key KEY    override the extension key (default: from info.xml)

Compiles, never renders. Run from the extension root, inside a container where
the extension is enabled.
TXT;
    }
}
