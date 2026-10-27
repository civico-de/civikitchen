<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Files;
use CiviKitchen\Toolbelt\Repository\Policy;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimpleXMLElement;
use SplFileInfo;
use ZipArchive;

final class ReleaseCommand implements Command
{
    /** What a `policy.dist.build` tool runs; the release workflow runs the same. */
    private const BUILD_COMMANDS = ['bun' => 'bun install --frozen-lockfile && bun run build'];

    /** @var array{key:string,file:string,version:string} */
    private array $metadata;
    /** @var list<string> */
    private array $excludedDirectories = [];
    /** @var list<string> */
    private array $excludedFiles = [];
    /** @var list<string> */
    private array $stagedPaths = [];

    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    public function run(array $arguments): int
    {
        $command = array_shift($arguments) ?? '';
        if (in_array($command, ['help', '-h', '--help'], true)) {
            echo $this->usage();
            return 0;
        }
        if (!in_array($command, ['check', 'dist', 'verify', 'info'], true)) {
            fwrite(STDERR, ($command === '' ? '' : "ckrelease: unknown command: {$command}\n") . $this->usage());
            return 2;
        }
        $options = ['version' => '', 'ref' => 'HEAD', 'output' => '.ckrelease', 'requireChangelog' => false];
        $positionals = [];
        $literal = false;
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if (!$literal && $argument === '--') {
                $literal = true;
                continue;
            }
            if (!$literal && in_array($argument, ['-h', '--help'], true)) {
                echo $this->usage();
                return 0;
            }
            if (!$literal && $argument === '--require-changelog') {
                $options['requireChangelog'] = true;
                continue;
            }
            $names = ['--version' => 'version', '--ref' => 'ref', '--output' => 'output'];
            if (!$literal && isset($names[$argument])) {
                if ($arguments === []) {
                    return $this->error("{$argument} needs a value");
                }
                $options[$names[$argument]] = (string) array_shift($arguments);
                continue;
            }
            if (!$literal && str_starts_with($argument, '-')) {
                return $this->error("unknown option: {$argument}");
            }
            $positionals[] = $argument;
        }
        $version = (string) $options['version'];
        $options['version'] = str_starts_with($version, 'v') ? substr($version, 1) : $version;
        if (!$this->loadContext()) {
            return 1;
        }

        return match ($command) {
            'info' => $this->info($positionals),
            'check' => $this->check((string) $options['version'], (bool) $options['requireChangelog']),
            'dist' => $this->dist((string) $options['version'], (bool) $options['requireChangelog'], (string) $options['ref'], (string) $options['output']),
            'verify' => $this->verifyCommand($positionals, (string) $options['version'], (bool) $options['requireChangelog']),
        };
    }

    private function loadContext(): bool
    {
        if (!is_file('info.xml')) {
            $this->error('no info.xml here - run from the extension root.');
            return false;
        }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file('info.xml');
        if (!$xml instanceof SimpleXMLElement) {
            $this->error('cannot parse info.xml');
            return false;
        }
        $this->metadata = [
            'key' => trim((string) $xml['key']),
            'file' => trim((string) $xml->file),
            'version' => trim((string) $xml->version),
        ];
        foreach (['key' => 'key attribute', 'file' => '<file>', 'version' => '<version>'] as $field => $label) {
            if ($this->metadata[$field] === '') {
                $this->error("info.xml has no {$label}.");
                return false;
            }
        }
        if (preg_match('#[\s/]#', $this->metadata['key']) === 1) {
            $this->error("info.xml key is not a usable directory name: {$this->metadata['key']}");
            return false;
        }
        $paths = $this->runner->capture([$this->ckconform(), '--dist-paths']);
        if ($paths['status'] !== 0) {
            $this->error("could not read the release exclusion list:\n" . rtrim($paths['output']));
            return false;
        }
        foreach (preg_split('/\R/', trim($paths['output'])) ?: [] as $line) {
            if (str_starts_with($line, 'dir ')) {
                $this->excludedDirectories[] = substr($line, 4);
            } elseif (str_starts_with($line, 'file ')) {
                $this->excludedFiles[] = substr($line, 5);
            } elseif (str_starts_with($line, 'stage ')) {
                $this->stagedPaths[] = substr($line, 6);
            }
        }
        if ($this->excludedDirectories === []) {
            $this->error('the release exclusion list came back empty');
            return false;
        }
        return true;
    }

    private function ckconform(): string
    {
        return $this->checkoutRoot . '/toolbelt/bin/ckconform';
    }

    /** @param list<string> $positionals */
    private function info(array $positionals): int
    {
        if (count($positionals) !== 1) {
            return $this->error('info needs exactly one field.');
        }
        $value = match ($positionals[0]) {
            'key', 'file', 'version' => $this->metadata[$positionals[0]],
            'dist-name' => "{$this->metadata['key']}-{$this->metadata['version']}.zip",
            default => null,
        };
        if ($value === null) {
            return $this->error("unknown field: {$positionals[0]} (key|file|version|dist-name)");
        }
        echo $value, "\n";
        return 0;
    }

    private function check(string $wantedVersion, bool $requireChangelog): int
    {
        $failed = false;
        if ($wantedVersion !== '' && $wantedVersion !== $this->metadata['version']) {
            fwrite(STDERR, "ckrelease: FAIL - releasing {$wantedVersion}, but info.xml <version> is {$this->metadata['version']}.\n  Bump info.xml (and composer.json) in the release commit, then tag it.\n");
            $failed = true;
        }
        if (is_file('composer.json')) {
            $composer = json_decode((string) file_get_contents('composer.json'), true);
            if (!is_array($composer)) {
                return $this->error('invalid composer.json');
            }
            $version = $composer['version'] ?? '';
            if (is_string($version) && $version !== '' && $version !== $this->metadata['version']) {
                fwrite(STDERR, "ckrelease: FAIL - composer.json says {$version}, info.xml says {$this->metadata['version']}.\n");
                $failed = true;
            }
        }
        if (is_file('CHANGELOG.md')) {
            if (str_contains((string) file_get_contents('CHANGELOG.md'), $this->metadata['version'])) {
                echo "ckrelease: CHANGELOG.md mentions {$this->metadata['version']}.\n";
            } else {
                fwrite(STDERR, "ckrelease: FAIL - CHANGELOG.md has no entry for {$this->metadata['version']}.\n");
                $failed = true;
            }
        } elseif ($requireChangelog) {
            fwrite(STDERR, "ckrelease: FAIL - no CHANGELOG.md (required by --require-changelog).\n");
            $failed = true;
        } else {
            echo "ckrelease: no CHANGELOG.md - release notes come from --generate-notes.\n";
        }
        if ($failed) {
            return 1;
        }
        echo "ckrelease: {$this->metadata['key']} {$this->metadata['version']} - version metadata is consistent.\n";
        return 0;
    }

    private function dist(string $version, bool $requireChangelog, string $ref, string $outputDirectory): int
    {
        $status = $this->check($version, $requireChangelog);
        if ($status !== 0) {
            return $status;
        }
        $repository = new Files($this->checkoutRoot, $this->runner);
        if ($repository->git(['rev-parse', '--is-inside-work-tree'])['status'] !== 0) {
            return $this->error('not a git repository - the archive is built from tracked files.');
        }
        if ($repository->git(['rev-parse', '-q', '--verify', "{$ref}^{tree}"])['status'] !== 0) {
            return $this->error("cannot resolve ref: {$ref}");
        }
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
            return $this->error("cannot create output directory: {$outputDirectory}");
        }
        $zip = rtrim($outputDirectory, '/') . "/{$this->metadata['key']}-{$this->metadata['version']}.zip";
        @unlink($zip);
        @unlink("{$zip}.sha256");
        $tree = $this->stagedPaths === [] ? $ref : $this->stage($ref);
        if ($tree === null) {
            return 1;
        }
        $pathspec = [];
        foreach ([...$this->excludedDirectories, ...$this->excludedFiles] as $item) {
            $pathspec[] = ($item === '.env.*' ? ':(glob,exclude)' : ':(exclude)') . $item;
        }
        $status = $this->runner->passthrough($repository->gitCommand(['archive', '--format=zip', '-9', "--prefix={$this->metadata['key']}/", '-o', $zip, $tree, '--', '.', ...$pathspec]));
        if ($status !== 0) {
            return $status;
        }
        $digest = hash_file('sha256', $zip);
        if ($digest === false || file_put_contents("{$zip}.sha256", "{$digest}  " . basename($zip) . "\n") === false) {
            return $this->error('could not write SHA-256 digest');
        }
        $status = $this->verify($zip);
        if ($status !== 0) {
            return $status;
        }
        echo "ckrelease: built {$zip}\nckrelease: {$digest}  ", basename($zip), "\n";
        return 0;
    }

    /**
     * The tree of $ref plus the declared build output, written through a throwaway
     * index so the checkout's own index and refs stay untouched. Null after a FAIL.
     */
    private function stage(string $ref): ?string
    {
        $missing = array_values(array_filter($this->stagedPaths, static fn(string $path): bool => !self::built($path)));
        if ($missing !== []) {
            $tool = (new Policy($this->checkoutRoot, $this->runner))->value('dist_build_tool');
            $build = self::BUILD_COMMANDS[$tool] ?? 'the build civikitchen.yaml declares';
            fwrite(STDERR, "ckrelease: FAIL - policy.dist.build output missing:\n  " . implode("\n  ", $missing)
                . "\n  Run {$build} first: the archive stages the build output the working tree holds.\n");
            return null;
        }
        $repository = new Files($this->checkoutRoot, $this->runner);
        $tracked = $repository->git(['--literal-pathspecs', 'ls-tree', '-r', '--name-only', $ref, '--', ...$this->stagedPaths]);
        if ($tracked['status'] !== 0) {
            $this->error("cannot list {$ref}:\n" . rtrim($tracked['output']));
            return null;
        }
        if (trim($tracked['output']) !== '') {
            fwrite(STDERR, "ckrelease: FAIL - policy.dist.build output is tracked at {$ref}:\n  " . str_replace("\n", "\n  ", trim($tracked['output']))
                . "\n  A tracked file ships from git already; declare only output the build writes.\n");
            return null;
        }
        $directory = Directory::temporary('ckrelease');
        $environment = [...getenv(), 'GIT_INDEX_FILE' => "{$directory}/index"];
        try {
            foreach ([$repository->gitCommand(['read-tree', $ref]),
                $repository->gitCommand(['--literal-pathspecs', 'add', '--force', '--', ...$this->stagedPaths])] as $command) {
                $result = $this->runner->capture($command, $environment);
                if ($result['status'] !== 0) {
                    $this->error('could not stage the build output: ' . rtrim($result['output']));
                    return null;
                }
            }
            $tree = $this->runner->capture($repository->gitCommand(['write-tree']), $environment);
            if ($tree['status'] !== 0) {
                $this->error('could not stage the build output: ' . rtrim($tree['output']));
                return null;
            }
            return trim($tree['output']);
        } finally {
            Directory::remove($directory);
        }
    }

    /** A file, or a directory holding at least one file. */
    private static function built(string $path): bool
    {
        if (!is_dir($path)) {
            return is_file($path);
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile()) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $positionals */
    private function verifyCommand(array $positionals, string $version, bool $requireChangelog): int
    {
        if (count($positionals) !== 1) {
            return $this->error('verify needs exactly one archive path.');
        }
        if ($version !== '' && ($status = $this->check($version, $requireChangelog)) !== 0) {
            return $status;
        }
        return $this->verify($positionals[0]);
    }

    private function verify(string $archive): int
    {
        if (!is_file($archive)) {
            return $this->error("no such archive: {$archive}");
        }
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true || $zip->numFiles === 0) {
            return $this->error("archive is empty or unreadable: {$archive}");
        }
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (is_string($name)) {
                $entries[] = $name;
            }
        }
        $tops = array_values(array_unique(array_map(static fn(string $entry): string => explode('/', $entry, 2)[0], $entries)));
        $failed = false;
        if ($tops !== [$this->metadata['key']]) {
            fwrite(STDERR, "ckrelease: FAIL - the archive must hold exactly one top-level directory named {$this->metadata['key']}, found:\n  " . implode("\n  ", $tops) . "\n");
            $failed = true;
        }
        $infoName = "{$this->metadata['key']}/info.xml";
        $info = $zip->getFromName($infoName);
        if (!is_string($info)) {
            fwrite(STDERR, "ckrelease: FAIL - the archive has no {$infoName}.\n");
            $failed = true;
        } else {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($info);
            if (!$xml instanceof SimpleXMLElement || trim((string) $xml->version) !== $this->metadata['version']) {
                fwrite(STDERR, "ckrelease: FAIL - the info.xml inside the archive is not version {$this->metadata['version']}.\n");
                $failed = true;
            }
        }
        $offenders = [];
        foreach ($entries as $entry) {
            $segments = explode('/', trim($entry, '/'));
            array_shift($segments);
            foreach ($segments as $segment) {
                if (in_array($segment, $this->excludedDirectories, true) || in_array($segment, $this->excludedFiles, true)
                    || $segment === '.env' || str_starts_with($segment, '.env.')) {
                    $offenders[] = $entry;
                    break;
                }
            }
        }
        // git archive writes pointer files where Git LFS objects were not fetched.
        $pointers = array_values(array_filter($entries, static function (string $entry) use ($zip): bool {
            $stat = $zip->statName($entry);
            return $stat !== false && $stat['size'] < 1024
                && str_starts_with((string) $zip->getFromName($entry), 'version https://git-lfs.github.com/spec/v1');
        }));
        $zip->close();
        if ($pointers !== []) {
            fwrite(STDERR, "ckrelease: FAIL - Git LFS pointers instead of files:\n  " . implode("\n  ", $pointers) . "\n  Fetch the LFS objects (actions/checkout with lfs: true) before the release.\n");
            $failed = true;
        }
        if ($offenders !== []) {
            fwrite(STDERR, "ckrelease: FAIL - dev/CI paths in the archive:\n  " . implode("\n  ", array_unique($offenders)) . "\n  Configure policy.dist.exclude/include in civikitchen.yaml.\n");
            $failed = true;
        }
        $absent = array_values(array_filter(
            $this->stagedPaths,
            fn(string $path): bool => !self::carries($entries, "{$this->metadata['key']}/{$path}"),
        ));
        if ($absent !== []) {
            fwrite(STDERR, "ckrelease: FAIL - policy.dist.build output missing from the archive:\n  " . implode("\n  ", $absent) . "\n");
            $failed = true;
        }
        if ($failed) {
            return 1;
        }
        $files = count(array_filter($entries, static fn(string $entry): bool => !str_ends_with($entry, '/')));
        $staged = $this->stagedPaths === [] ? '' : count($this->stagedPaths) . ' declared build outputs, ';
        echo "ckrelease: {$files} files, {$staged}no dev/CI paths, info.xml {$this->metadata['version']}.\n";
        return 0;
    }

    /** @param list<string> $entries */
    private static function carries(array $entries, string $name): bool
    {
        foreach ($entries as $entry) {
            if ($entry === $name || str_starts_with($entry, "{$name}/")) {
                return true;
            }
        }
        return false;
    }

    private function error(string $message): int
    {
        fwrite(STDERR, "ckrelease: {$message}\n");
        return 1;
    }

    private function usage(): string
    {
        return <<<'TXT'
ckrelease - release helper for CiviCRM extensions (version check, dist zip).

  ckrelease check  [--version <v>] [--require-changelog]
  ckrelease dist   [--version <v>] [--ref <ref>] [--output <dir>] [--require-changelog]
  ckrelease verify <zip> [--version <v>]
  ckrelease info   key|file|version|dist-name
TXT;
    }
}
