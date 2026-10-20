<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Runtime;

use RuntimeException;

final class ProfileData
{
    /** @return array<string, mixed> */
    public function load(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException("profile is not a JSON object: {$file}");
        }
        return $data;
    }

    public function hasApiUsers(string $file, bool $requireNonEmpty): bool
    {
        $profile = $this->load($file);
        return $requireNonEmpty ? count($profile['apiUsers'] ?? []) > 0 : array_key_exists('apiUsers', $profile);
    }

    public function authxPolicy(string $file, bool $sort): string
    {
        $profile = $this->load($file);
        if (count($profile['apiUsers'] ?? []) === 0) {
            return '';
        }
        $policy = $profile['authx']['header_cred'] ?? ['jwt', 'api_key'];
        $policy = array_values(array_map('strval', is_array($policy) ? $policy : []));
        if ($sort) {
            sort($policy);
        }
        return implode(',', $policy);
    }

    /** @return list<string> */
    public function includes(string $file): array
    {
        return array_values(array_map('strval', $this->load($file)['include'] ?? []));
    }

    public function cms(string $file): string
    {
        $profile = $this->load($file);
        return is_string($profile['cms'] ?? null) ? $profile['cms'] : '';
    }

    /** @return list<string> */
    public function skipped(string $file, string $uf): array
    {
        $messages = [];
        foreach ($this->load($file)['dependencies'] ?? [] as $dependency) {
            if (!is_array($dependency)) {
                continue;
            }
            if (!in_array($uf, $dependency['skipUf'] ?? [], true)) {
                continue;
            }
            $reason = $dependency['skipUfReason'] ?? 'declared incompatible in profile.json';
            $messages[] = "  SKIP {$dependency['name']} on {$uf}: {$reason}";
        }
        return $messages;
    }

    /**
     * The profile's `scripts`, resolved against its directory, in profile order.
     *
     * @return list<string>
     */
    public function scripts(string $file): array
    {
        $problems = $this->problems($file);
        if ($problems !== []) {
            throw new RuntimeException(implode("\n", $problems));
        }
        return $this->resolve($file, $this->load($file)['scripts'] ?? []);
    }

    /**
     * The profile's `snapshot` block with its defaults, scripts resolved like
     * `scripts`, or null for a profile without one.
     *
     * @return array{calendar: string, sizes: list<string>, after_restore: list<string>}|null
     */
    public function snapshot(string $file): ?array
    {
        $snapshot = $this->load($file)['snapshot'] ?? null;
        if (!is_array($snapshot)) {
            return null;
        }
        $problems = $this->problems($file);
        if ($problems !== []) {
            throw new RuntimeException(implode("\n", $problems));
        }
        $sizes = $snapshot['sizes'] ?? ['demo'];
        return [
            'calendar' => is_string($snapshot['calendar'] ?? null) ? $snapshot['calendar'] : 'year',
            'sizes' => array_values(array_map('strval', is_array($sizes) ? $sizes : [])),
            'after_restore' => $this->resolve($file, is_array($snapshot['after_restore'] ?? null) ? $snapshot['after_restore'] : []),
        ];
    }

    /**
     * @param array<mixed> $scripts
     * @return list<string>
     */
    private function resolve(string $file, array $scripts): array
    {
        $directory = (string) realpath(dirname($file));
        return array_values(array_map(static fn(mixed $script): string => (string) realpath($directory . '/' . (string) $script), $scripts));
    }

    /**
     * What keeps a schema-valid profile from applying: a retired seeds/ directory
     * or a `scripts` or `snapshot.after_restore` entry that is no file inside the
     * profile directory.
     *
     * @return list<string>
     */
    public function problems(string $file): array
    {
        $directory = realpath(dirname($file));
        if ($directory === false) {
            return ["profile directory not found: " . dirname($file)];
        }
        $problems = [];
        if (is_dir($directory . '/seeds')) {
            $problems[] = "{$directory}/seeds: the seeds/ directory is no longer run; name its PHP files in the profile's \"scripts\" list";
        }
        $profile = $this->load($file);
        $snapshot = is_array($profile['snapshot'] ?? null) ? $profile['snapshot'] : [];
        $listed = [...($profile['scripts'] ?? []), ...($snapshot['after_restore'] ?? [])];
        foreach ($listed as $script) {
            $path = is_string($script) && !str_starts_with($script, '/') ? realpath($directory . '/' . $script) : false;
            if ($path === false || !is_file($path) || !str_starts_with($path, $directory . '/')) {
                $problems[] = "{$directory}: script " . json_encode($script) . ' is no file inside the profile directory';
            }
        }
        return $problems;
    }

    /** @return list<array<string, mixed>> */
    public function dependencies(string $file, string $uf): array
    {
        $profile = $this->load($file);
        return array_values(array_filter($profile['dependencies'] ?? [], static fn(mixed $dependency): bool =>
            is_array($dependency) && !in_array($uf, $dependency['skipUf'] ?? [], true)));
    }

    /** @param list<string> $files */
    public function merge(string $output, string $policy, array $files): void
    {
        /** @var array<string, array{role:string,permissions:list<string>}> $users */
        $users = [];
        foreach ($files as $file) {
            foreach ($this->load($file)['apiUsers'] ?? [] as $user) {
                if (!is_array($user)) {
                    continue;
                }
                $username = (string) ($user['username'] ?? '');
                $role = (string) ($user['role'] ?? '');
                if (isset($users[$username]) && $users[$username]['role'] !== $role) {
                    throw new RuntimeException("same API username declares conflicting roles: {$username}");
                }
                $permissions = array_map('strval', is_array($user['permissions'] ?? null) ? $user['permissions'] : []);
                $users[$username] = [
                    'role' => $role,
                    'permissions' => array_values(array_unique([...($users[$username]['permissions'] ?? []), ...$permissions])),
                ];
            }
        }
        $rolePermissions = [];
        foreach ($users as $user) {
            $rolePermissions[$user['role']] = array_values(array_unique([
                ...($rolePermissions[$user['role']] ?? []), ...$user['permissions'],
            ]));
            sort($rolePermissions[$user['role']]);
        }
        ksort($users);
        $apiUsers = [];
        foreach ($users as $username => $user) {
            $apiUsers[] = [
                'username' => $username,
                'role' => $user['role'],
                'permissions' => $rolePermissions[$user['role']],
            ];
        }
        $merged = [
            'description' => 'Aggregated CiviKitchen API users',
            'dependencies' => [],
            'apiUsers' => $apiUsers,
        ];
        // An empty policy (no API users in the set) must not reach AuthX as [].
        if ($policy !== '') {
            $merged['authx'] = ['header_cred' => array_values(array_filter(explode(',', $policy), 'strlen'))];
        }
        $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($output, $json . "\n") === false) {
            throw new RuntimeException("cannot write merged profile: {$output}");
        }
    }
}
