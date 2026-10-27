<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;

/**
 * cv, curl and composer for a site whose $site learns a new ext dir directory only on a rescan, and
 * whose enable and download refuse what core's do; ckconform, git and mv run for real.
 */
final class ProvisionSite extends Runner
{
    /** @var array<string, string> extension key => its feed URL */
    public array $feed = [];

    /** @var array<string, string> extension key => its short name in the feed */
    public array $names = [];

    /** @var array<string, string> URL => the archive behind it */
    public array $archives = [];

    /** @var array<string, string> extension key => status */
    public array $site = [];

    /** @var array<string, string> extension key => its directory outside the ext dir */
    public array $paths = [];

    /** @var list<string> keys core's extension-compatibility.json declares obsolete */
    public array $obsolete = [];

    /** @var array<string, int> how many more times a step fails */
    public array $failures = [];

    public ?string $extensionList = null;
    public ?string $feedList = null;
    public ?string $pathOutput = null;
    public int $requiresReads = 0;
    public bool $composer = true;

    /** @var list<array{list<string>, string}> composer calls and the COMPOSER_HOME they ran with */
    public array $composerRuns = [];

    /** @var list<string> cv calls that change the site, abbreviated */
    public array $log = [];

    /** @var list<string> every cv call */
    public array $raw = [];

    /** @var list<list<string>> */
    public array $curls = [];

    /** @var list<array{string, string}> mv calls: source, destination */
    public array $moves = [];

    /** A pattern of the sources mv fails to move. */
    public ?string $failingMoves = null;

    /** @var list<string> directories ckconform read a policy in */
    public array $policyReads = [];

    public function __construct(private readonly string $extDir)
    {
    }

    public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
    {
        if ($command[0] === 'curl') {
            return $this->curl($command);
        }
        if ($command[0] === 'git') {
            return parent::passthrough($command, $environment, $workingDirectory);
        }
        if ($command[0] === 'mv' && count($command) === 3) {
            $this->moves[] = [$command[1], $command[2]];
            $fails = $this->failingMoves !== null && preg_match($this->failingMoves, $command[1]) === 1;
            return $fails ? 1 : parent::passthrough($command, $environment, $workingDirectory);
        }
        $arguments = $this->cv($command);
        if ($arguments === ['ev', 'CRM_Extension_System::singleton()->getFullContainer()->refresh();']) {
            $this->log[] = 'refresh';
            if ($this->fails('refresh')) {
                return 255;
            }
            $this->scan();
            return 0;
        }
        if ($arguments[0] === 'ext:enable') {
            $keys = array_slice($arguments, 1);
            $this->log[] = 'enable ' . implode(' ', $keys);
            if ($this->fails('enable')) {
                return 1;
            }
            // cv rescans for a key it does not know; core then installs the whole <requires> closure.
            if (array_diff($keys, array_keys($this->infos())) !== []) {
                $this->scan();
            }
            $infos = $this->infos();
            $order = self::closure($keys, $infos);
            if ($order === null || array_diff($order, array_keys($infos)) !== []) {
                return 1;
            }
            foreach ($order as $key) {
                $this->site[$key] = 'installed';
            }
            return 0;
        }
        if (count($arguments) === 4 && array_slice($arguments, 0, 3) === ['ext:download', '-n', '--no-install']) {
            $this->log[] = 'download ' . $arguments[3];
            return $this->download($arguments[3]) ? 0 : 1;
        }
        throw new LogicException('unexpected command: ' . implode(' ', $command));
    }

    public function captureStdout(array $command, ?string $input = null, ?string $workingDirectory = null): array
    {
        $arguments = $this->cv($command);
        $key = substr($arguments[3] ?? '', 4);
        if ($arguments === ['api4', 'Extension.get', '+w', "key={$key}", '+s', 'key,status', '--out=json-strict']) {
            $rows = isset($this->site[$key]) ? [['key' => $key, 'status' => $this->site[$key]]] : [];
            return $this->answer('api4', $this->extensionList ?? json_encode($rows, JSON_THROW_ON_ERROR));
        }
        if ($input !== null && $arguments === ['ev', 'echo implode("\n", CRM_Extension_Info::loadFromString(file_get_contents("php://stdin"))->requires);']) {
            $this->requiresReads++;
            return $this->answer('requires', implode("\n", $this->requires($input)));
        }
        if ($arguments === ['ext:list', '-R', '-r', '--columns=key,name,downloadUrl', '--out=json-strict']) {
            $this->log[] = 'ext:list';
            $feed = [];
            foreach ($this->feed as $extension => $url) {
                $feed[] = ['key' => $extension, 'name' => $this->names[$extension] ?? '', 'downloadUrl' => $url];
            }
            return $this->answer('ext:list', $this->feedList ?? json_encode($feed, JSON_THROW_ON_ERROR));
        }
        if (count($arguments) === 3 && array_slice($arguments, 0, 2) === ['path', '-x']) {
            return $this->answer('path', $this->pathOutput ?? $this->path($arguments[2]) . "\n");
        }
        if ($arguments === ['vset', 'http_timeout=120']) {
            return $this->answer('vset', '');
        }
        if ($arguments === ['vdel', 'http_timeout']) {
            return $this->answer('vdel', '');
        }
        if (count($arguments) === 2 && $arguments[0] === 'ev' && str_contains($arguments[1], '$c->getKeys()')) {
            $lines = [];
            foreach ($this->site as $extension => $status) {
                if (!str_ends_with($status, '-missing')) {
                    $lines[] = $extension . "\t" . $this->path($extension) . "\n";
                }
            }
            return $this->answer('discover', implode('', $lines));
        }
        throw new LogicException('unexpected command: ' . implode(' ', $command));
    }

    public function captureSeparate(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        if (str_ends_with($command[0], '/ckconform')) {
            $this->policyReads[] = (string) $workingDirectory;
        }
        if ($command[0] === 'git' || str_ends_with($command[0], '/ckconform')) {
            return parent::captureSeparate($command, $environment, $workingDirectory);
        }
        $arguments = $this->cv($command);
        if (count($arguments) === 4 && $arguments[0] === 'ext:list' && array_slice($arguments, 2) === ['--columns=key', '--out=list']) {
            $statuses = explode(',', substr($arguments[1], strlen('--statuses=')));
            $keys = array_keys(array_filter($this->site, static fn (string $status): bool => in_array($status, $statuses, true)));
            $answer = $this->answer("statuses {$statuses[0]}", implode('', array_map(static fn (string $key): string => "{$key}\n", $keys)));
            return ['status' => $answer['status'], 'stdout' => $answer['output'], 'stderr' => ''];
        }
        throw new LogicException('unexpected command: ' . implode(' ', $command));
    }

    /** composer, which puts a vendor/ into its working dir */
    public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        if ($command[0] !== 'composer') {
            throw new LogicException('unexpected command: ' . implode(' ', $command));
        }
        $arguments = array_slice($command, 1);
        $this->log[] = 'composer ' . $arguments[0];
        $this->composerRuns[] = [$arguments, (string) ($environment['COMPOSER_HOME'] ?? '')];
        if ($this->fails('composer')) {
            return ['status' => 2, 'output' => "fixture: could not resolve dependencies\n"];
        }
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--working-dir=')) {
                mkdir(substr($argument, strlen('--working-dir=')) . '/vendor');
            }
        }
        return ['status' => 0, 'output' => 'Generating autoload files'];
    }

    public function which(string $program): ?string
    {
        if ($program === 'composer') {
            return $this->composer ? '/fake/composer' : null;
        }
        return parent::which($program);
    }

    /**
     * @param non-empty-list<string> $command
     * @return list<string>
     */
    private function cv(array $command): array
    {
        if ($command[0] !== 'cv' || count($command) < 2) {
            throw new LogicException('unexpected command: ' . implode(' ', $command));
        }
        $arguments = array_slice($command, 1);
        $this->raw[] = implode(' ', $arguments);
        return $arguments;
    }

    /** @return array{status: int, output: string} */
    private function answer(string $step, string $output): array
    {
        return $this->fails($step) ? ['status' => 1, 'output' => ''] : ['status' => 0, 'output' => $output];
    }

    private function fails(string $step): bool
    {
        if (($this->failures[$step] ?? 0) <= 0) {
            return false;
        }
        $this->failures[$step]--;
        return true;
    }

    /** @param non-empty-list<string> $command */
    private function curl(array $command): int
    {
        $this->curls[] = $command;
        $output = $command[(int) array_search('-o', $command, true) + 1];
        $url = $command[count($command) - 3];
        if ($this->fails('curl') || !isset($this->archives[$url])) {
            file_put_contents($output, 'partial');
            return 22;
        }
        return copy($this->archives[$url], $output) ? 0 : 23;
    }

    /** @return list<string> <requires> as core reads them, an empty <ext/> included */
    private function requires(string $info): array
    {
        $xml = simplexml_load_string($info);
        $keys = [];
        foreach ($xml === false ? [] : $xml->requires->ext ?? [] as $extension) {
            if (!in_array((string) $extension, $this->obsolete, true)) {
                $keys[] = (string) $extension;
            }
        }
        return $keys;
    }

    /** Like core's download queue: every extension in the <requires> closure but KEY is installed. */
    private function download(string $spec): bool
    {
        $key = explode('@', $spec, 2)[0];
        $archive = $this->archives[$key === $spec ? ($this->feed[$key] ?? '') : substr($spec, strlen($key) + 1)] ?? null;
        if ($this->fails('download') || $archive === null) {
            return false;
        }
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            return false;
        }
        try {
            $closure = self::closure([$key], [$key => $this->required((string) $zip->getFromName("{$key}/info.xml"))] + $this->infos());
            if ($closure === null) {
                return false;
            }
            foreach ($closure as $required) {
                if ($required !== $key && ($this->site[$required] ?? '') !== 'installed') {
                    return false;
                }
            }
            Directory::remove("{$this->extDir}/{$key}");
            $this->site[$key] = 'uninstalled';
            return $zip->extractTo($this->extDir);
        } finally {
            $zip->close();
        }
    }

    /** A rescan: the extension in each ext dir directory is known, its files no longer missing. */
    private function scan(): void
    {
        $errors = libxml_use_internal_errors(true);
        foreach (glob("{$this->extDir}/*/info.xml") ?: [] as $info) {
            $xml = simplexml_load_file($info);
            $key = $xml === false ? '' : (string) $xml['key'];
            if ($key !== '') {
                $this->site[$key] = (string) preg_replace('/-missing$/', '', $this->site[$key] ?? 'uninstalled');
                $this->paths[$key] ??= dirname($info);
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
    }

    /** @return array<string, list<string>> the <requires> of every extension whose files the site has */
    private function infos(): array
    {
        $infos = [];
        foreach ($this->site as $key => $status) {
            if (!str_ends_with($status, '-missing')) {
                $file = $this->path($key) . '/info.xml';
                $infos[$key] = is_file($file) ? $this->required((string) file_get_contents($file)) : [];
            }
        }
        return $infos;
    }

    /** @return list<string> */
    private function required(string $info): array
    {
        return array_values(array_filter($this->requires($info), static fn (string $key): bool => $key !== ''));
    }

    private function path(string $key): string
    {
        return $this->paths[$key] ?? "{$this->extDir}/{$key}";
    }

    /**
     * KEYS and all they require, required first, as core's install order; null on a cycle.
     *
     * @param list<string> $keys
     * @param array<string, list<string>> $infos
     * @return list<string>|null
     */
    private static function closure(array $keys, array $infos): ?array
    {
        $state = [];
        $order = [];
        $visit = static function (string $key) use (&$visit, &$state, &$order, $infos): bool {
            if (isset($state[$key])) {
                return $state[$key];
            }
            $state[$key] = false;
            foreach ($infos[$key] ?? [] as $required) {
                if (!$visit($required)) {
                    return false;
                }
            }
            $order[] = $key;
            return $state[$key] = true;
        };
        foreach ($keys as $key) {
            if (!$visit($key)) {
                return null;
            }
        }
        return $order;
    }
}
