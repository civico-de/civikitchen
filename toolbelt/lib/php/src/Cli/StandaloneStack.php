<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use RuntimeException;

/** A throwaway Standalone stack (app plus MariaDB) described by a generated compose file. */
final class StandaloneStack
{
    private const IMAGE = 'ghcr.io/civico-de/civikitchen:';
    private const SOURCE_LOG = '/usr/local/share/civikitchen/civicrm-source.log';
    // The images' start period plus every retry of their healthcheck.
    private const BOOT_SECONDS = 900;

    public function __construct(private readonly Runner $runner)
    {
    }

    /** A bare civikitchen tag such as standalone-6.16 becomes the published image. */
    public static function image(string $argument): string
    {
        return preg_match('#[:/]#', $argument) === 1 ? $argument : self::IMAGE . $argument;
    }

    /** The image's tag as a compose project name part. */
    public static function label(string $image): string
    {
        $tag = substr($image, (int) strrpos($image, ':') + 1);
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($tag)), '-');
    }

    public static function mount(string $value): string
    {
        if (preg_match('#^/[^:]*:/[^:]+$#', $value) !== 1) {
            throw new RuntimeException("--mount takes <absolute host path>:<container path>, not {$value}");
        }
        return $value;
    }

    /** A comma-separated list of CiviCRM locales, as CIVIKITCHEN_LOCALES takes it. */
    public static function locales(string $value): string
    {
        if (preg_match('/^[a-z]{2,3}_[A-Z]{2}(,[a-z]{2,3}_[A-Z]{2})*$/', $value) !== 1) {
            throw new RuntimeException("--locales takes CiviCRM locales separated by commas, e.g. it_IT,fr_FR, not {$value}");
        }
        return $value;
    }

    /**
     * Writes the compose file; a project of that name must not exist yet.
     *
     * @param list<string> $mounts
     */
    public function prepare(string $project, string $image, array $mounts, ?string $locales, string $file): string
    {
        $containers = $this->runner->capture(['docker', 'ps', '-a', '-q', '--filter', "label=com.docker.compose.project={$project}"]);
        if ($containers['status'] !== 0) {
            throw new RuntimeException("docker ps failed:\n" . $containers['output']);
        }
        if (trim($containers['output']) !== '') {
            throw new RuntimeException("a stack named {$project} exists; remove it: docker compose -p {$project} down -v");
        }
        $config = [
            'name' => $project,
            'services' => [
                'app' => [
                    'image' => $image,
                    'pull_policy' => 'missing',
                    'depends_on' => ['db' => ['condition' => 'service_healthy']],
                    'environment' => [
                        'CIVICRM_AUTO_INSTALL' => '1',
                        'CIVICRM_DB_HOST' => 'db',
                        'CIVICRM_DB_NAME' => 'civicrm',
                        'CIVICRM_DB_USER' => 'civicrm',
                        'CIVICRM_DB_PASSWORD' => 'civicrm',
                        // The headless test database is created as database root.
                        'CIVICRM_DB_ROOT_PASSWORD' => 'root',
                    ],
                    'volumes' => $mounts,
                ],
                'db' => [
                    'image' => 'mariadb:11.4',
                    'environment' => [
                        'MARIADB_ROOT_PASSWORD' => 'root',
                        'MARIADB_DATABASE' => 'civicrm',
                        'MARIADB_USER' => 'civicrm',
                        'MARIADB_PASSWORD' => 'civicrm',
                    ],
                    'healthcheck' => [
                        'test' => ['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized'],
                        'interval' => '5s',
                        'timeout' => '5s',
                        'retries' => 30,
                    ],
                ],
            ],
        ];
        if ($locales !== null) {
            $config['services']['app']['environment']['CIVIKITCHEN_LOCALES'] = $locales;
        }
        file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $file;
    }

    public function up(string $file, bool $wait): void
    {
        $command = ['docker', 'compose', '-f', $file, 'up', '-d'];
        if ($wait) {
            array_push($command, '--wait', '--wait-timeout', (string) self::BOOT_SECONDS);
        }
        if ($this->runner->passthrough($command) !== 0) {
            // The caller removes the stack on its way out, so its log goes into the error.
            $log = $this->runner->capture(['docker', 'compose', '-f', $file, 'logs', '--no-color', '--tail', '40', 'app']);
            throw new RuntimeException("the stack of {$file} did not boot. The end of its log:\n" . $log['output']);
        }
    }

    /** Removes containers and volumes; false when compose could not. */
    public function down(string $file): bool
    {
        return $this->runner->passthrough(['docker', 'compose', '-f', $file, 'down', '-v', '--remove-orphans']) === 0;
    }

    /** The CiviCRM version, plus the commits a git-built image names. */
    public function core(string $file): string
    {
        $version = $this->runner->captureSeparate([...self::exec($file), 'cv', 'ev', 'echo CRM_Utils_System::version();']);
        if ($version['status'] !== 0) {
            throw new RuntimeException("cv ev failed in the stack of {$file}:\n" . $version['stdout'] . $version['stderr']);
        }
        $core = 'CiviCRM ' . trim($version['stdout']);
        $source = $this->runner->captureSeparate([...self::exec($file), 'cat', self::SOURCE_LOG]);
        if ($source['status'] === 0 && trim($source['stdout']) !== '') {
            $core .= ', ' . implode(', ', array_map('trim', explode("\n", trim($source['stdout']))));
        }
        return $core;
    }

    /**
     * The command prefix that runs in the app container as www-data, from /var/www/html.
     *
     * @return non-empty-list<string>
     */
    public static function exec(string $file): array
    {
        return ['docker', 'compose', '-f', $file, 'exec', '-T', '-u', 'www-data', '-w', '/var/www/html', 'app'];
    }
}
