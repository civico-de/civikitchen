<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;

final class PhpUnitCommand implements Command
{
    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    public function run(array $arguments): int
    {
        if (in_array($arguments[0] ?? '', ['-h', '--help'], true)) {
            echo $this->usage();
            return 0;
        }
        if (!is_file('info.xml')) {
            fwrite(STDERR, "ckphpunit: no info.xml here - run from the extension root.\n");
            return 2;
        }
        $config = $this->configuration($arguments);
        $canary = $this->checkoutRoot . '/toolbelt/lib/tx-canary.php';
        if (getenv('CK_TX_CANARY') === '0' || $config === '') {
            return $this->runner->passthrough(['phpunit', ...$arguments]);
        }
        // phpunit keeps its result cache and looks up listener files next to the config.
        $directory = Directory::temporary('ckphpunit-canary');
        try {
            return $this->runWith($arguments, $config, "{$directory}/phpunit.xml", $canary);
        } finally {
            Directory::remove($directory);
        }
    }

    /** @param list<string> $arguments */
    private function runWith(array $arguments, string $config, string $generated, string $canary): int
    {
        if (!$this->generateConfig($config, $generated, $canary)) {
            return 2;
        }
        $clean = [];
        $skip = false;
        foreach ($arguments as $argument) {
            if ($skip) {
                $skip = false;
                continue;
            }
            if (in_array($argument, ['-c', '--configuration'], true)) {
                $skip = true;
                continue;
            }
            if (!str_starts_with($argument, '--configuration=')) {
                $clean[] = $argument;
            }
        }
        return $this->runner->passthrough(['phpunit', '-c', $generated, ...$clean]);
    }

    /** @param list<string> $arguments */
    private function configuration(array $arguments): string
    {
        $config = '';
        for ($index = 0; $index < count($arguments); $index++) {
            if (in_array($arguments[$index], ['-c', '--configuration'], true)) {
                $config = $arguments[$index + 1] ?? '';
            } elseif (str_starts_with($arguments[$index], '--configuration=')) {
                $config = substr($arguments[$index], 16);
            }
        }
        if ($config !== '' && !is_dir($config)) {
            return $config;
        }
        // Where phpunit looks: the current directory, or the one -c names.
        $directory = $config === '' ? '.' : $config;
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $candidate) {
            if (is_file("{$directory}/{$candidate}")) {
                return "{$directory}/{$candidate}";
            }
        }
        return '';
    }

    private function generateConfig(string $source, string $destination, string $canary): bool
    {
        $document = new DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;
        if (!@$document->load($source) || !$document->documentElement instanceof DOMElement) {
            fwrite(STDERR, "ckphpunit: cannot parse {$source}\n");
            return false;
        }
        $root = $document->documentElement;
        $real = realpath($source);
        $base = dirname($real === false ? $source : $real);
        $absolute = static fn(string $path): string => $path === '' || str_starts_with($path, '/') || str_contains($path, '://')
            ? $path : $base . '/' . $path;
        // Every path phpunit resolves against the config's directory; the copy lives in another one.
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('/*/@bootstrap|/*/@cacheResultFile|/*/@extensionsDirectory|/*/@cacheDirectory'
            . '|/*/@testSuiteLoaderFile|/*/@printerFile|//coverage/@cacheDirectory|//@outputFile|//@outputDirectory|//log/@target'
            . '|//listener/@file|//extension/@file|//directory|//file|//testsuite/exclude|//php/includePath');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $path = $absolute(trim($node->nodeValue ?? ''));
                // A listener or extension file missing there is looked up on the include path, so it stays as written.
                if (!($node instanceof DOMAttr && $node->name === 'file' && !is_file($path))) {
                    $node->nodeValue = $path;
                }
            }
        }
        $listeners = $root->getElementsByTagName('listeners')->item(0);
        if (!$listeners instanceof DOMElement) {
            $listeners = $document->createElement('listeners');
            $root->appendChild($listeners);
        }
        $listener = $document->createElement('listener');
        $listener->setAttribute('class', 'Civi\\CkTest\\TransactionCanaryListener');
        $listener->setAttribute('file', $canary);
        $listeners->appendChild($listener);
        return $document->save($destination) !== false;
    }

    private function usage(): string
    {
        return <<<'TXT'
ckphpunit - headless PHPUnit with a transaction canary.

  ckphpunit [phpunit args]

Adds a listener to the extension's phpunit config that fails a transactional
test whose writes were committed (DDL commits implicitly) instead of rolled back. CK_TX_CANARY=0 runs plain phpunit; see
`phpunit --help` for the phpunit arguments.

TXT;
    }
}
