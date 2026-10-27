<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Demo;

use RuntimeException;

/** What `ck demo` needs to boot, patch, log into and navigate one CMS flavor. */
final class Cms
{
    public const NAMES = ['standalone', 'wordpress', 'drupal10', 'drupal11', 'joomla', 'backdrop'];

    private const IMAGE = 'ghcr.io/civico-de/civikitchen:';
    private const BUILDKIT_SITE = '/home/buildkit/buildkit/build/site';

    /**
     * @param array<string, string> $appEnvironment without the site URL
     * @param array<string, string> $databaseEnvironment
     * @param array{url: string, username: string, password: string, dismiss?: array{role: string, name: string}} $login
     */
    private function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $image,
        public readonly string $user,
        public readonly string $siteDirectory,
        public readonly array $appEnvironment,
        public readonly array $databaseEnvironment,
        public readonly array $login,
        public readonly string $routePrefix,
        public readonly bool $publishesAssets,
    ) {
    }

    public static function named(string $name, ?string $image = null): self
    {
        $buildkitDatabase = ['MARIADB_ROOT_PASSWORD' => 'root'];
        $buildkitApp = ['CIVICRM_DB_HOST' => 'db', 'CIVICRM_DB_ROOT_PASSWORD' => 'root'];
        $drupalLogin = ['url' => '/user/login', 'username' => '#edit-name', 'password' => '#edit-pass'];
        return match ($name) {
            // Core PRs target master, which only the git-built Standalone image tracks.
            'standalone' => new self(
                $name,
                'Standalone',
                $image ?? self::IMAGE . 'standalone-master',
                'www-data',
                '/var/www/html',
                [
                    'CIVICRM_AUTO_INSTALL' => '1',
                    'CIVICRM_DB_HOST' => 'db',
                    'CIVICRM_DB_NAME' => 'civicrm',
                    'CIVICRM_DB_USER' => 'civicrm',
                    'CIVICRM_DB_PASSWORD' => 'civicrm',
                    'CIVIKITCHEN_DEMO_USER' => 'admin',
                ],
                [
                    'MARIADB_ROOT_PASSWORD' => 'root',
                    'MARIADB_DATABASE' => 'civicrm',
                    'MARIADB_USER' => 'civicrm',
                    'MARIADB_PASSWORD' => 'civicrm',
                ],
                ['url' => '/civicrm/login', 'username' => '#usernameInput', 'password' => '#passwordInput'],
                '/',
                false,
            ),
            'wordpress' => new self(
                $name,
                'WordPress',
                $image ?? self::IMAGE . 'wordpress',
                'buildkit',
                self::BUILDKIT_SITE,
                $buildkitApp,
                $buildkitDatabase,
                ['url' => '/wp-login.php', 'username' => '#user_login', 'password' => '#user_pass'],
                '/wp-admin/admin.php?page=CiviCRM&q=',
                false,
            ),
            // Drupal serves core's assets from a copy below web/libraries that the asset plugin publishes.
            'drupal10' => new self(
                $name,
                'Drupal 10',
                $image ?? self::IMAGE . 'drupal10',
                'buildkit',
                self::BUILDKIT_SITE,
                $buildkitApp,
                $buildkitDatabase,
                $drupalLogin,
                '/',
                true,
            ),
            'drupal11' => new self(
                $name,
                'Drupal 11',
                $image ?? self::IMAGE . 'drupal11',
                'buildkit',
                self::BUILDKIT_SITE,
                $buildkitApp,
                $buildkitDatabase,
                $drupalLogin,
                '/',
                true,
            ),
            'joomla' => new self(
                $name,
                'Joomla',
                $image ?? self::IMAGE . 'joomla',
                'buildkit',
                self::BUILDKIT_SITE,
                $buildkitApp,
                $buildkitDatabase,
                [
                    'url' => '/administrator/',
                    'username' => '#mod-login-username',
                    'password' => '#mod-login-password',
                    // Joomla asks once whether to send statistics; the answer is stored server-side.
                    'dismiss' => ['role' => 'button', 'name' => 'No'],
                ],
                '/administrator/index.php?option=com_civicrm&task=',
                false,
            ),
            'backdrop' => new self(
                $name,
                'Backdrop',
                $image ?? self::IMAGE . 'backdrop',
                'buildkit',
                self::BUILDKIT_SITE,
                $buildkitApp,
                $buildkitDatabase,
                $drupalLogin,
                '/',
                false,
            ),
            default => throw new RuntimeException("unknown CMS '{$name}' (one of: " . implode(', ', self::NAMES) . ', all)'),
        };
    }
}
