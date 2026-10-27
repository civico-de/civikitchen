<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Demo;

use CiviKitchen\Toolbelt\Process\Runner;
use RuntimeException;

/**
 * One CMS site in its own compose project, described by a JSON compose file.
 * The file outlives a --keep run, so the next run finds the stack on its port.
 */
final class Stack
{
    private const DIFF = '/tmp/ck-demo.diff';
    // The images' start period plus every retry of their healthcheck.
    private const BOOT_SECONDS = 900;

    private ?string $coreDirectory = null;

    private function __construct(
        public readonly Cms $cms,
        public readonly string $project,
        public readonly int $port,
        private readonly string $composeFile,
        private readonly Runner $runner,
    ) {
    }

    /** Writes the compose file; an existing one is a kept stack, resumed only with the same configuration. */
    public static function prepare(Cms $cms, string $project, string $composeFile, int $freePort, Runner $runner): self
    {
        $port = $freePort;
        if (is_file($composeFile)) {
            $existing = json_decode((string) file_get_contents($composeFile), true);
            $port = is_array($existing) && is_int($existing['x-ck-demo']['port'] ?? null) ? $existing['x-ck-demo']['port'] : 0;
            if ($port === 0) {
                throw new RuntimeException("{$composeFile} names no port; remove the stack: docker compose -f {$composeFile} down -v");
            }
            if ($existing !== (new self($cms, $project, $port, $composeFile, $runner))->config()) {
                throw new RuntimeException("{$composeFile} holds a kept stack of another change or image; remove it (docker compose -f {$composeFile} down -v) or choose another --out");
            }
        } else {
            $containers = $runner->capture(['docker', 'ps', '-a', '-q', '--filter', "label=com.docker.compose.project={$project}"]);
            if ($containers['status'] !== 0) {
                throw new RuntimeException("docker ps failed:\n" . $containers['output']);
            }
            if (trim($containers['output']) !== '') {
                throw new RuntimeException("a stack named {$project} exists without {$composeFile}; remove it: docker compose -p {$project} down -v");
            }
        }
        $stack = new self($cms, $project, $port, $composeFile, $runner);
        $directory = dirname($composeFile);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("cannot create {$directory}");
        }
        file_put_contents($composeFile, json_encode($stack->config(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $stack;
    }

    public function siteUrl(): string
    {
        return "http://localhost:{$this->port}";
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return [
            'name' => $this->project,
            'x-ck-demo' => ['port' => $this->port],
            'services' => [
                'app' => [
                    'image' => $this->cms->image,
                    'pull_policy' => 'missing',
                    'depends_on' => ['db' => ['condition' => 'service_healthy']],
                    'ports' => ["127.0.0.1:{$this->port}:80"],
                    'environment' => $this->cms->appEnvironment + ['CIVIKITCHEN_SITE_URL' => $this->siteUrl()],
                ],
                'db' => [
                    'image' => 'mariadb:11.4',
                    'environment' => $this->cms->databaseEnvironment,
                    'healthcheck' => [
                        'test' => ['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized'],
                        'interval' => '5s',
                        'timeout' => '5s',
                        'retries' => 30,
                    ],
                ],
            ],
        ];
    }

    /** Starts the stack; with $wait, returns once the image's healthcheck reports the site provisioned. */
    public function up(bool $wait): void
    {
        $command = [...$this->compose(), 'up', '-d'];
        if ($wait) {
            array_push($command, '--wait', '--wait-timeout', (string) self::BOOT_SECONDS);
        }
        if ($this->runner->passthrough($command) !== 0) {
            // The run removes the stack on its way out, so its log goes into the error.
            $log = $this->runner->capture([...$this->compose(), 'logs', '--no-color', '--tail', '40', 'app']);
            throw new RuntimeException("{$this->cms->label} did not boot. The end of its log:\n" . $log['output']);
        }
    }

    public function down(): void
    {
        if ($this->runner->passthrough([...$this->compose(), 'down', '-v', '--remove-orphans']) !== 0) {
            throw new RuntimeException("could not remove {$this->project}");
        }
        unlink($this->composeFile);
    }

    /** Copies the diff into the app container, where state(), apply() and revert() read it. */
    public function load(CoreDiff $diff): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ck-demo-');
        if ($file === false) {
            throw new RuntimeException('cannot create a temporary file');
        }
        try {
            file_put_contents($file, $diff->patch);
            chmod($file, 0644);
            $copy = $this->runner->capture([...$this->compose(), 'cp', $file, 'app:' . self::DIFF]);
        } finally {
            unlink($file);
        }
        if ($copy['status'] !== 0) {
            throw new RuntimeException("could not copy the diff into {$this->project}:\n" . $copy['output']);
        }
    }

    /** 'stock' or 'applied'; a diff that fits neither way is an error naming the hunks that failed. */
    public function state(): string
    {
        $forward = $this->patch(['-N', '--dry-run']);
        if ($forward['status'] === 0) {
            return 'stock';
        }
        if ($this->patch(['-R', '-N', '--dry-run'])['status'] === 0) {
            return 'applied';
        }
        throw new RuntimeException("the change does not apply to {$this->cms->label} {$this->version()} ({$this->coreDirectory()}):\n" . $forward['output']);
    }

    public function apply(): void
    {
        $this->change(['-N'], 'apply');
    }

    public function revert(): void
    {
        $this->change(['-R', '-N'], 'revert');
    }

    public function flush(): void
    {
        $this->site(['cv', 'flush'], 'cv flush');
    }

    /** `cv <arguments>`, split by the container's shell. */
    public function cv(string $arguments): void
    {
        $this->site(['sh', '-c', 'cv ' . $arguments], "cv {$arguments}");
    }

    public function version(): string
    {
        return trim($this->site(['cv', 'ev', 'echo CRM_Utils_System::version();'], 'cv ev'));
    }

    /** @param list<string> $flags */
    private function change(array $flags, string $verb): void
    {
        $result = $this->patch($flags);
        if ($result['status'] !== 0) {
            throw new RuntimeException("could not {$verb} the change on {$this->cms->label}:\n" . $result['output']);
        }
        if ($this->cms->publishesAssets) {
            $this->site(['composer', 'civicrm:publish'], 'composer civicrm:publish');
        }
        $this->flush();
    }

    /**
     * As the site user, who owns the core files on every image.
     *
     * @param list<string> $flags
     * @return array{status: int, output: string}
     */
    private function patch(array $flags): array
    {
        $result = $this->exec(['patch', '-p1', '--batch', '--no-backup-if-mismatch', '-i', self::DIFF, ...$flags], $this->coreDirectory());
        return ['status' => $result['status'], 'output' => $result['stdout'] . $result['stderr']];
    }

    private function coreDirectory(): string
    {
        return $this->coreDirectory ??= $this->site(['cv', 'path', '-d', '[civicrm.root]/.'], 'cv path');
    }

    /** @param non-empty-list<string> $command */
    private function site(array $command, string $what): string
    {
        $result = $this->exec($command, $this->cms->siteDirectory);
        if ($result['status'] !== 0) {
            throw new RuntimeException("{$what} failed on {$this->cms->label}:\n" . $result['stdout'] . $result['stderr']);
        }
        return trim($result['stdout']);
    }

    /**
     * @param non-empty-list<string> $command
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function exec(array $command, string $workingDirectory): array
    {
        return $this->runner->captureSeparate([...$this->compose(), 'exec', '-T', '-u', $this->cms->user, '-w', $workingDirectory, 'app', ...$command]);
    }

    /** @return non-empty-list<string> */
    private function compose(): array
    {
        return ['docker', 'compose', '-f', $this->composeFile];
    }
}
