<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Policy;
use SimpleXMLElement;

final class LifecycleCommand implements Command
{
    private bool $failed = false;

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
            fwrite(STDERR, "cklifecycle: unknown argument: {$argument}\n" . $this->usage());
            return 2;
        }
        if (!is_file('info.xml')) {
            return $this->error('no info.xml here - run from the extension root.', 2);
        }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file('info.xml');
        if (!$xml instanceof SimpleXMLElement) {
            return $this->error('cannot parse info.xml.', 2);
        }
        $key = $key ?: trim((string) $xml['key']);
        $prefix = trim((string) $xml->file);
        if ($key === '' || $prefix === '') {
            return $this->error('could not read the extension key and <file> from info.xml.', 2);
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $key) !== 1) {
            return $this->error('extension key contains unsafe characters.', 2);
        }

        $logIgnore = (new Policy($this->checkoutRoot, $this->runner))->values('lifecycle_log_ignore');
        $extensionDirectory = (string) getcwd();
        $work = Directory::temporary('cklifecycle');
        register_shutdown_function(Directory::remove(...), $work);
        $transcript = '';
        $logDirectory = trim(str_replace("\r", '', $this->runner->capture([
            'cv', 'ev', 'echo CRM_Core_Config::singleton()->configAndLogDir;',
        ])['output']));
        $offsets = [];
        if (is_dir($logDirectory)) {
            foreach (glob($logDirectory . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    $offsets[$file] = filesize($file) ?: 0;
                }
            }
        }

        foreach ([['disable', 'ext:disable'], ['enable', 'ext:enable'], ['uninstall', 'ext:uninstall']] as [$label, $command]) {
            $this->step($label, ['cv', $command, $key], $transcript);
        }
        echo "cklifecycle: checking post-uninstall DB invariants\n";
        $environment = getenv();
        $environment['CK_LC_KEY'] = $key;
        $environment['CK_LC_DIR'] = $extensionDirectory;
        $environment['CK_LC_PREFIX'] = $prefix;
        $invariant = $this->runner->capture(['cv', 'scr', $this->checkoutRoot . '/toolbelt/lib/lifecycle-check.php'], $environment);
        echo $invariant['output'];
        $transcript .= $invariant['output'];
        if ($invariant['status'] !== 0) {
            $this->failed = true;
        }
        $this->step('re-enable', ['cv', 'ext:enable', $key], $transcript);

        echo "cklifecycle: checking settings metadata (option loading)\n";
        $settingsCheck = $this->runner->capture(['cv', 'scr', $this->checkoutRoot . '/toolbelt/lib/settings-metadata-check.php'], $environment);
        echo $settingsCheck['output'];
        $transcript .= $settingsCheck['output'];
        if ($settingsCheck['status'] !== 0) {
            $this->failed = true;
        }

        $status = preg_replace('/\s+/', '', $this->runner->capture([
            'cv', 'ev', "echo CRM_Extension_System::singleton()->getManager()->getStatus((string) getenv('CK_LC_KEY'));",
        ], $environment)['output']);
        if ($status !== 'installed') {
            fwrite(STDERR, "cklifecycle: extension ended up '{$status}', expected 'installed'\n");
            $this->failed = true;
        }

        $logText = $transcript;
        foreach ($offsets as $file => $offset) {
            $handle = fopen($file, 'rb');
            if (is_resource($handle)) {
                fseek($handle, $offset);
                $delta = stream_get_contents($handle);
                fclose($handle);
                $logText .= $delta === false ? '' : $delta;
            }
        }
        $signature = '/SQLSTATE\[|DB Error|Table .[^ ]*. doesn.t exist|Unknown table|Unknown column|Duplicate column|Syntax error or access violation|PHP Fatal error|PHP Parse error|PHP Warning|PHP Recoverable/';
        $hits = [];
        foreach (preg_split('/\R/', $logText) ?: [] as $number => $line) {
            if (preg_match($signature, $line) === 1) {
                $hits[] = ($number + 1) . ':' . $line;
            }
        }
        $ignored = [];
        foreach ($logIgnore as $rule) {
            if (!str_contains($rule, ' -- ') || trim(explode(' -- ', $rule, 2)[1]) === '') {
                fwrite(STDERR, "cklifecycle: policy.lifecycle.log_ignore needs a non-empty reason: {$rule}\n");
                $this->failed = true;
                continue;
            }
            $pattern = explode(' -- ', $rule, 2)[0];
            $regex = '~' . str_replace('~', '\\~', $pattern) . '~';
            if ($pattern === '' || @preg_match($regex, '') === false) {
                fwrite(STDERR, "cklifecycle: invalid policy.lifecycle.log_ignore regex: {$pattern}\n");
                $this->failed = true;
                continue;
            }
            $before = count($hits);
            $hits = array_values(array_filter($hits, static fn(string $hit): bool => preg_match($regex, $hit) !== 1));
            if (($count = $before - count($hits)) > 0) {
                $ignored[] = "  {$count}x ignored by: {$rule}";
            }
        }

        echo "\n### Lifecycle gate (`{$key}`)\n\n";
        if ($ignored !== []) {
            echo "Declared exceptions:\n\n", implode("\n", $ignored), "\n\n";
        }
        if ($hits !== []) {
            echo "**Failures found in the lifecycle logs** (CiviCRM ConfigAndLog + step output):\n\n```\n",
                implode("\n", array_slice($hits, 0, 100)),
                "\n```\n\nA swallowed SQL error is still a broken install. Fix the cause, or declare\nit as policy.lifecycle.log_ignore with pattern and reason in civikitchen.yaml.\n";
            $this->failed = true;
        } else {
            echo "No SQL or PHP failure signatures in the lifecycle logs.\n";
        }
        if ($this->failed) {
            echo "\nResult: **FAILED**\n";
            return 1;
        }
        echo "\nResult: passed (disable -> enable -> uninstall -> enable, extension `installed`).\n";
        return 0;
    }

    /** @param non-empty-list<string> $command */
    private function step(string $label, array $command, string &$transcript): void
    {
        echo "cklifecycle: {$label}\n";
        $result = $this->runner->capture($command);
        $transcript .= "--- {$label}\n{$result['output']}\n";
        foreach (preg_split('/\R/', rtrim($result['output'])) ?: [] as $line) {
            echo "    {$line}\n";
        }
        if ($result['status'] !== 0) {
            fwrite(STDERR, "cklifecycle: step failed: {$label} (exit {$result['status']})\n");
            $this->failed = true;
        }
    }

    private function error(string $message, int $status = 1): int
    {
        fwrite(STDERR, "cklifecycle: {$message}\n");
        return $status;
    }

    private function usage(): string
    {
        return <<<'TXT'
cklifecycle - exercise the extension lifecycle and gate on DB leftovers + logs.

  cklifecycle             run the full cycle for the extension in this directory
  cklifecycle --key KEY   override the extension key (default: from info.xml)

Sequence: disable -> enable -> uninstall -> DB invariant check -> enable.
TXT;
    }
}
