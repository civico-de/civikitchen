<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Runtime\ExtensionInspector;
use CiviKitchen\Toolbelt\Runtime\ExtensionProvisioner;
use CiviKitchen\Toolbelt\Runtime\ProfileData;
use CiviKitchen\Toolbelt\Runtime\ProfileProvisioner;
use CiviKitchen\Toolbelt\Runtime\VendorInstaller;
use CiviKitchen\Toolbelt\Scaffold\ExtensionEditor;
use Throwable;

final class InternalRuntimeCommand implements Command
{
    /** @var array<string, array{int, int, string}> operands per provisioning operation: minimum, maximum, usage */
    private const PROVISION = [
        'provision-extras' => [1, 1, 'LIST'],
        'provision-enable' => [1, 1, 'LIST'],
        'provision-requires' => [1, 2, 'KEY [DIR]'],
        'provision-profile' => [2, 2, '--replaced-state FILE PROFILE_JSON UF'],
    ];

    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    public function run(array $arguments): int
    {
        $operation = array_shift($arguments) ?? '';
        if (isset(self::PROVISION[$operation])) {
            return $this->provision($operation, $arguments);
        }
        $autoload = $this->checkoutRoot . '/vendor/autoload.php';
        $inspector = new ExtensionInspector($autoload);
        try {
            if ($operation === 'composer-install') {
                return (new VendorInstaller())->install(array_values($arguments));
            }
            if ($operation === 'extension-key' && count($arguments) === 1) {
                echo $inspector->key($arguments[0]);
                return 0;
            }
            if ($operation === 'extension-requires' && count($arguments) === 1) {
                foreach ($inspector->requirements($arguments[0]) as $key) {
                    echo $key, "\n";
                }
                return 0;
            }
            if ($operation === 'xml-field' && count($arguments) === 2) {
                libxml_use_internal_errors(true);
                $xml = simplexml_load_file($arguments[0]);
                if ($xml === false) {
                    throw new \RuntimeException("cannot parse {$arguments[0]}");
                }
                if ($arguments[1] === 'key') {
                    echo trim((string) $xml['key']);
                    return 0;
                }
                foreach (explode('/', $arguments[1]) as $child) {
                    $xml = $xml->{$child};
                }
                echo trim((string) $xml);
                return 0;
            }
            if ($operation === 'xml-has-child' && count($arguments) === 2) {
                libxml_use_internal_errors(true);
                $xml = simplexml_load_file($arguments[0]);
                if ($xml === false) {
                    throw new \RuntimeException("cannot parse {$arguments[0]}");
                }
                return isset($xml->{$arguments[1]}) ? 0 : 1;
            }
            if ($operation === 'json-field' && count($arguments) === 2) {
                if (!is_file($arguments[0])) {
                    return 0;
                }
                $data = json_decode((string) file_get_contents($arguments[0]), true, 512, JSON_THROW_ON_ERROR);
                $value = is_array($data) ? ($data[$arguments[1]] ?? null) : null;
                echo is_string($value) ? $value : '';
                return 0;
            }
            if ($operation === 'hook-catalog-core-version' && $arguments === []) {
                require_once $this->checkoutRoot . '/toolbelt/ckconform/src/HookCatalog.php';
                echo \CiviKitchen\Ckconform\HookCatalog::CORE_VERSION;
                return 0;
            }
            $profiles = new ProfileData();
            if ($operation === 'profile-api-users-present' && count($arguments) === 1) {
                return $profiles->hasApiUsers($arguments[0], true) ? 0 : 1;
            }
            if ($operation === 'profile-api-users-declared' && count($arguments) === 1) {
                return $profiles->hasApiUsers($arguments[0], false) ? 0 : 1;
            }
            if ($operation === 'profile-authx-policy' && count($arguments) >= 1) {
                echo $profiles->authxPolicy($arguments[0], ($arguments[1] ?? '') === '--sort');
                return 0;
            }
            if ($operation === 'profile-includes' && count($arguments) === 1) {
                foreach ($profiles->includes($arguments[0]) as $name) {
                    echo $name, "\n";
                }
                return 0;
            }
            if ($operation === 'profile-cms' && count($arguments) === 1) {
                echo $profiles->cms($arguments[0]);
                return 0;
            }
            if ($operation === 'profile-skipped' && count($arguments) === 2) {
                foreach ($profiles->skipped($arguments[0], $arguments[1]) as $message) {
                    echo $message, "\n";
                }
                return 0;
            }
            if ($operation === 'profile-has-snapshot' && count($arguments) === 1) {
                return $profiles->snapshot($arguments[0]) === null ? 1 : 0;
            }
            if ($operation === 'profile-scripts' && count($arguments) === 1) {
                foreach ($profiles->scripts($arguments[0]) as $script) {
                    echo $script, "\n";
                }
                return 0;
            }
            if ($operation === 'profiles-merge' && count($arguments) >= 3) {
                $profiles->merge($arguments[0], $arguments[1], array_slice($arguments, 2));
                return 0;
            }
            if ($operation === 'database-ready' && count($arguments) === 1) {
                $root = $arguments[0] === 'root';
                mysqli_report(MYSQLI_REPORT_OFF);
                $database = new \mysqli(
                    (string) getenv('CIVICRM_DB_HOST'),
                    $root ? 'root' : (string) getenv('CIVICRM_DB_USER'),
                    $root ? (string) getenv('CIVICRM_DB_ROOT_PASSWORD') : (string) getenv('CIVICRM_DB_PASSWORD'),
                    $root ? '' : (string) getenv('CIVICRM_DB_NAME'),
                    (int) getenv('CIVICRM_DB_PORT'),
                );
                return $database->connect_errno ? 1 : 0;
            }
            if ($operation === 'database-sentinel-exists' && $arguments === []) {
                // 0 = a site is recorded, 1 = none, 3 = could not tell. The caller
                // drops databases on 1, so an unanswerable probe must not look like 1.
                try {
                    $database = $this->rootDatabase();
                } catch (\RuntimeException $e) {
                    fwrite(STDERR, $e->getMessage() . "\n");
                    return self::SENTINEL_UNKNOWN;
                }
                $result = @$database->query('SELECT 1 FROM civikitchen_state.site_installed LIMIT 1');
                $status = self::sentinelStatus($result, $database->errno);
                if ($status === self::SENTINEL_UNKNOWN) {
                    fwrite(STDERR, "could not read the install sentinel: {$database->error}\n");
                }
                return $status;
            }
            if ($operation === 'database-sentinel-write' && $arguments === []) {
                $database = $this->rootDatabase();
                if (!$database->query('CREATE DATABASE IF NOT EXISTS civikitchen_state')
                    || !$database->query('CREATE TABLE IF NOT EXISTS civikitchen_state.site_installed (id TINYINT UNSIGNED PRIMARY KEY, installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)')
                    || !$database->query('REPLACE INTO civikitchen_state.site_installed (id) VALUES (1)')) {
                    throw new \RuntimeException('could not write database install sentinel');
                }
                return 0;
            }
            $editor = new ExtensionEditor();
            if ($operation === 'scaffold-license' && count($arguments) === 3) {
                $editor->rewriteLicense($arguments[0], $arguments[1], $arguments[2]);
                return 0;
            }
            if ($operation === 'scaffold-composer' && count($arguments) === 3) {
                $editor->updateComposer($arguments[0], $arguments[1], $arguments[2]);
                return 0;
            }
            if ($operation === 'scaffold-php-floor' && count($arguments) === 3) {
                $editor->alignPhpFloor($arguments[0], $arguments[1], $arguments[2]);
                return 0;
            }
            if ($operation === 'scaffold-version' && count($arguments) === 1) {
                $editor->setInitialVersion($arguments[0]);
                return 0;
            }
            if ($operation === 'scaffold-policy' && count($arguments) === 4) {
                $editor->updatePolicy($arguments[0], $arguments[1], $arguments[2], $arguments[3]);
                return 0;
            }
            fwrite(STDERR, "ck: invalid internal runtime command\n");
            return 2;
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
    }

    /**
     * `provision-* --ext-dir DIR [--archive-dir DIR] [--mounted DIR]... OPERAND...`, the mounted dirs
     * in pin precedence; an empty archive dir stages none, and only provision-profile takes `--replaced-state`.
     *
     * @param list<string> $arguments
     */
    private function provision(string $operation, array $arguments): int
    {
        $options = ['--ext-dir' => [], '--archive-dir' => [], '--mounted' => [], '--replaced-state' => []];
        while ($arguments !== [] && str_starts_with($arguments[0], '--')) {
            $option = array_shift($arguments);
            if (!isset($options[$option]) || $arguments === []) {
                return $this->provisionUsage($operation);
            }
            $options[$option][] = array_shift($arguments);
        }
        [$minimum, $maximum] = self::PROVISION[$operation];
        $profile = $operation === 'provision-profile';
        if (count($options['--ext-dir']) !== 1 || $options['--ext-dir'][0] === '' || count($options['--archive-dir']) > 1
            || count($options['--replaced-state']) !== ($profile ? 1 : 0) || in_array('', $options['--replaced-state'], true)
            || count($arguments) < $minimum || count($arguments) > $maximum) {
            return $this->provisionUsage($operation);
        }
        $provisioner = new ExtensionProvisioner(
            $this->checkoutRoot,
            $options['--ext-dir'][0],
            $options['--archive-dir'][0] ?? '',
            $options['--mounted'],
            $this->runner,
        );
        try {
            if ($operation === 'provision-extras') {
                $provisioner->extras($arguments[0]);
            } elseif ($operation === 'provision-enable') {
                $provisioner->enable($arguments[0]);
            } elseif ($operation === 'provision-requires') {
                $provisioner->requires($arguments[0], $arguments[1] ?? '');
            } else {
                (new ProfileProvisioner($this->checkoutRoot, $options['--ext-dir'][0], $options['--replaced-state'][0], $provisioner, $this->runner))
                    ->provision($arguments[0], $arguments[1]);
            }
        } catch (Throwable $e) {
            // The profile driver's own lines are indented under its "==>" headings.
            fwrite(STDERR, ($profile ? '  ERROR: ' : '[civikitchen] ERROR: ') . $e->getMessage() . "\n");
            return 1;
        }
        return 0;
    }

    private function provisionUsage(string $operation): int
    {
        fwrite(STDERR, "usage: ck internal {$operation} --ext-dir DIR [--archive-dir DIR] [--mounted DIR]... " . self::PROVISION[$operation][2] . "\n");
        return 2;
    }

    public const SENTINEL_PRESENT = 0;
    public const SENTINEL_ABSENT = 1;
    public const SENTINEL_UNKNOWN = 3;

    /**
     * Only "no such database" (1049) and "no such table" (1146) mean the sentinel
     * was never written; any other failed query is an unanswered question.
     */
    public static function sentinelStatus(\mysqli_result|bool $result, int $errno): int
    {
        if ($result instanceof \mysqli_result) {
            return $result->num_rows > 0 ? self::SENTINEL_PRESENT : self::SENTINEL_ABSENT;
        }
        return in_array($errno, [1049, 1146], true) ? self::SENTINEL_ABSENT : self::SENTINEL_UNKNOWN;
    }

    private function rootDatabase(): \mysqli
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        $database = new \mysqli(
            (string) getenv('CIVICRM_DB_HOST'),
            'root',
            (string) getenv('CIVICRM_DB_ROOT_PASSWORD'),
            '',
            (int) getenv('CIVICRM_DB_PORT'),
        );
        if ($database->connect_errno) {
            throw new \RuntimeException('could not connect to database');
        }
        return $database;
    }
}
