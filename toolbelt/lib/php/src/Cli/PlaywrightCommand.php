<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Playwright\Image;
use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Files;
use RuntimeException;

/** Runs a command in the project's Playwright image, so the host needs neither Node nor browsers. */
final class PlaywrightCommand implements Command
{
    private const USAGE = <<<'TXT'
ck playwright — run a command with the project's Playwright, in Docker

  ck playwright [--network <mode>] [--] <command> [<argument> ...]

  ck playwright npm run test:e2e
  ck playwright playwright test --grep @smoke
  ck playwright playwright test --ui-host=127.0.0.1 --ui-port=9323

The project is the nearest directory with a package.json, from the current one
up to the root of its git work tree. The image holds the browsers and Node of
the Playwright version its lockfile resolves, its npm or Bun dependency tree,
and the docker CLI with the host's Docker socket. The git work tree is mounted
at its own path and the command runs in the current directory, so paths and
`docker compose` calls work as on the host. Every environment variable but the
host's paths, identity and locale (HOME, PATH, TMPDIR, USER, DOCKER_HOST, LANG,
LC_*, …) reaches the command.

  --network <mode>   the container's network, as `docker run --network` takes it
                     (default: host)

Runs on the host. scaffold/ck runs it in a container, so the host needs only Docker.
TXT;

    private const SOCKET = '/var/run/docker.sock';

    /** Variables that describe the host; the container has its own or reaches Docker through the socket. */
    private const HOST_ONLY = [
        'DOCKER_CERT_PATH', 'DOCKER_CONFIG', 'DOCKER_CONTEXT', 'DOCKER_HOST', 'DOCKER_TLS_VERIFY',
        'HOME', 'HOSTNAME', 'LANG', 'LOGNAME', 'OLDPWD', 'PATH', 'PLAYWRIGHT_BROWSERS_PATH', 'PWD', 'SHELL', 'SHLVL',
        'TEMP', 'TMP', 'TMPDIR', 'USER', '_',
    ];

    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    public function run(array $arguments): int
    {
        if ($arguments === [] || in_array($arguments[0], ['-h', '--help'], true)) {
            echo self::USAGE, "\n";
            return $arguments === [] ? 2 : 0;
        }
        $network = 'host';
        while ($arguments !== [] && str_starts_with($arguments[0], '--')) {
            $option = array_shift($arguments);
            if ($option === '--') {
                break;
            }
            if ($option !== '--network' || $arguments === [] || $arguments[0] === '') {
                throw new RuntimeException("playwright takes --network <mode> before the command, not {$option}");
            }
            $network = array_shift($arguments);
        }
        if ($arguments === []) {
            throw new RuntimeException('playwright needs a command to run');
        }
        if ($this->runner->which('docker') === null) {
            throw new RuntimeException('playwright runs on the host and needs docker on PATH');
        }
        $directory = (string) realpath((string) getcwd());
        $workTree = Files::worktreeRoot();
        $project = $this->project($directory, $workTree);
        $image = (new Image($this->checkoutRoot, $this->runner))->for($project);
        $command = [
            'docker', 'run', '--rm', '--init', '-i', '--ipc=host', '--network', $network,
            '--user', posix_getuid() . ':' . posix_getgid(), '--group-add', $this->socketGroup($image), '-e', 'HOME=/tmp',
            '-v', self::SOCKET . ':' . self::SOCKET, '-v', "{$workTree}:{$workTree}", '-w', $directory,
        ];
        // A tree installed on the host would shadow the image's, and its native binaries may not even run here.
        for ($parent = $project; str_starts_with($parent, $workTree); $parent = dirname($parent)) {
            if (is_dir("{$parent}/node_modules")) {
                array_push($command, '--mount', "type=tmpfs,destination={$parent}/node_modules");
            }
            if ($parent === $workTree) {
                break;
            }
        }
        foreach (array_keys(getenv()) as $name) {
            // The image has none of the host's locales, and CI runs in its default one.
            if (!in_array($name, self::HOST_ONLY, true) && preg_match('/^(?!LC_)[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1) {
                array_push($command, '-e', $name);
            }
        }
        if (posix_isatty(STDIN) && posix_isatty(STDOUT)) {
            $command[] = '-t';
        }
        return $this->runner->passthrough([...$command, $image, ...$arguments]);
    }

    /** The nearest directory with a package.json, at most up to the work tree. */
    private function project(string $directory, string $workTree): string
    {
        for ($candidate = $directory; !is_file("{$candidate}/package.json"); $candidate = dirname($candidate)) {
            if ($candidate === $workTree) {
                throw new RuntimeException("no package.json in {$directory} or above it in {$workTree}");
            }
        }
        return $candidate;
    }

    /** The socket's group as the Docker daemon sees it, which the host may not (colima, Docker Desktop). */
    private function socketGroup(string $image): string
    {
        $result = $this->runner->capture(['docker', 'run', '--rm', '--entrypoint', 'stat', '-v', self::SOCKET . ':' . self::SOCKET, $image, '-c', '%g', self::SOCKET]);
        $group = trim($result['output']);
        if ($result['status'] !== 0 || preg_match('/^\d+$/', $group) !== 1) {
            throw new RuntimeException('cannot read the group of the Docker socket ' . self::SOCKET . ":\n" . $result['output']);
        }
        return $group;
    }
}
