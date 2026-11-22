<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Surface;

use RuntimeException;

/**
 * Compares two CiviCRM surface JSON payloads (A vs B) and flags permission
 * widenings, unguarded additions, and potential permission regressions.
 */
final class SurfaceDiff
{
    /** @var list<string> */
    public const CATEGORIES = ['api4', 'api3', 'routes', 'afform'];

    /** @var array{api4: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>}, api3: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>}, routes: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>}, afform: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>}} */
    private readonly array $diff;

    /** @var list<array{code: string, severity: string, category: string, target: string, reason: string}> */
    private readonly array $riskFlags;

    /**
     * @param array{api4: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>}, api3: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>}, routes: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>}, afform: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>}} $diff
     * @param list<array{code: string, severity: string, category: string, target: string, reason: string}> $riskFlags
     */
    private function __construct(array $diff, array $riskFlags)
    {
        $this->diff = $diff;
        $this->riskFlags = $riskFlags;
    }

    public static function fromFiles(string $beforeFile, string $afterFile): self
    {
        $before = self::decodeFile($beforeFile);
        $after = self::decodeFile($afterFile);
        return self::compare($before, $after);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeFile(string $file): array
    {
        if (!is_file($file)) {
            throw new RuntimeException("surface file does not exist: {$file}");
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException("cannot read surface file: {$file}");
        }
        return self::decodeJson($raw, $file);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeJson(string $raw, string $source = 'surface'): array
    {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException("{$source} is not valid JSON");
        }
        $hasCategory = false;
        foreach (self::CATEGORIES as $category) {
            if (array_key_exists($category, $decoded)) {
                if (!is_array($decoded[$category])) {
                    throw new RuntimeException("{$source} has non-array '{$category}' field");
                }
                $hasCategory = true;
            }
        }
        if (!$hasCategory) {
            throw new RuntimeException("{$source} is not a surface payload (missing api4/api3/routes/afform)");
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function compare(array $before, array $after): self
    {
        $riskFlags = [];

        $api4 = self::diffApi('api4', self::indexApi($before['api4'] ?? []), self::indexApi($after['api4'] ?? []), $riskFlags);
        $api3 = self::diffApi('api3', self::indexApi($before['api3'] ?? []), self::indexApi($after['api3'] ?? []), $riskFlags);
        $routes = self::diffRoutes(self::indexRoutes($before['routes'] ?? []), self::indexRoutes($after['routes'] ?? []), $riskFlags);
        $afform = self::diffAfform(self::indexAfform($before['afform'] ?? []), self::indexAfform($after['afform'] ?? []), $riskFlags);

        return new self(
            [
                'api4' => $api4,
                'api3' => $api3,
                'routes' => $routes,
                'afform' => $afform,
            ],
            $riskFlags,
        );
    }

    /**
     * @return array{
     *   api4: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>},
     *   api3: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>},
     *   routes: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>},
     *   afform: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>},
     *   risk_flags: list<array{code: string, severity: string, category: string, target: string, reason: string}>,
     *   counts: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        $counts = [];
        foreach (self::CATEGORIES as $category) {
            $counts[$category] = [
                'added' => count($this->diff[$category]['added']),
                'removed' => count($this->diff[$category]['removed']),
                'changed' => count($this->diff[$category]['changed']),
            ];
        }
        $counts['risk_flags'] = count($this->riskFlags);

        return [
            'api4' => $this->diff['api4'],
            'api3' => $this->diff['api3'],
            'routes' => $this->diff['routes'],
            'afform' => $this->diff['afform'],
            'risk_flags' => $this->riskFlags,
            'counts' => $counts,
        ];
    }

    public function hasChanges(): bool
    {
        foreach (self::CATEGORIES as $category) {
            if ($this->diff[$category]['added'] !== [] || $this->diff[$category]['removed'] !== [] || $this->diff[$category]['changed'] !== []) {
                return true;
            }
        }
        return false;
    }

    public function hasRiskFlags(): bool
    {
        return $this->riskFlags !== [];
    }

    /**
     * @return list<array{code: string, severity: string, category: string, target: string, reason: string}>
     */
    public function riskFlags(): array
    {
        return $this->riskFlags;
    }

    public function formatSummary(): string
    {
        if (!$this->hasChanges() && !$this->hasRiskFlags()) {
            return "Surface diff: no changes\n";
        }

        $lines = ['Surface diff:'];
        foreach (self::CATEGORIES as $category) {
            $bucket = $this->diff[$category];
            $added = count($bucket['added']);
            $removed = count($bucket['removed']);
            $changed = count($bucket['changed']);
            if ($added === 0 && $removed === 0 && $changed === 0) {
                $lines[] = "  {$category}: no changes";
                continue;
            }
            $details = [];
            if ($added > 0) {
                $details[] = 'added: ' . self::summarizeNames($category, $bucket['added']);
            }
            if ($removed > 0) {
                $details[] = 'removed: ' . self::summarizeNames($category, $bucket['removed']);
            }
            if ($changed > 0) {
                $details[] = 'changed: ' . self::summarizeNames($category, $bucket['changed']);
            }
            $lines[] = "  {$category}: +{$added} -{$removed} ~{$changed} (" . implode('; ', $details) . ')';
        }

        if ($this->riskFlags !== []) {
            $lines[] = 'Risk flags (' . count($this->riskFlags) . '):';
            foreach ($this->riskFlags as $flag) {
                $lines[] = "  [{$flag['severity']}] {$flag['category']} {$flag['target']} ({$flag['code']}): {$flag['reason']}";
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private static function summarizeNames(string $category, array $items, int $limit = 10): string
    {
        $names = [];
        foreach (array_slice($items, 0, $limit) as $item) {
            $names[] = match ($category) {
                'routes' => (string) ($item['path'] ?? ''),
                'afform' => (string) ($item['name'] ?? ''),
                default => (string) ($item['entity'] ?? '') . '.' . (string) ($item['action'] ?? ''),
            };
        }
        $extra = count($items) - count($names);
        if ($extra > 0) {
            $names[] = "... (+{$extra} more)";
        }
        return implode(', ', $names);
    }

    /**
     * @param mixed $items
     * @return array<string, array{entity: string, action: string, permissions: mixed}>
     */
    private static function indexApi(mixed $items): array
    {
        $indexed = [];
        if (!is_array($items)) {
            return $indexed;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $entity = (string) ($item['entity'] ?? '');
            $action = (string) ($item['action'] ?? '');
            $indexed["{$entity}.{$action}"] = [
                'entity' => $entity,
                'action' => $action,
                'permissions' => $item['permissions'] ?? null,
            ];
        }
        ksort($indexed);
        return $indexed;
    }

    /**
     * @param mixed $items
     * @return array<string, array{path: string, access: mixed, callback: ?string}>
     */
    private static function indexRoutes(mixed $items): array
    {
        $indexed = [];
        if (!is_array($items)) {
            return $indexed;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $path = (string) ($item['path'] ?? '');
            $indexed[$path] = [
                'path' => $path,
                'access' => $item['access'] ?? null,
                'callback' => isset($item['callback']) && is_string($item['callback']) ? $item['callback'] : null,
            ];
        }
        ksort($indexed);
        return $indexed;
    }

    /**
     * @param mixed $items
     * @return array<string, array{name: string, server_route: ?string, permission: mixed, is_public: bool}>
     */
    private static function indexAfform(mixed $items): array
    {
        $indexed = [];
        if (!is_array($items)) {
            return $indexed;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = (string) ($item['name'] ?? '');
            $indexed[$name] = [
                'name' => $name,
                'server_route' => isset($item['server_route']) && is_string($item['server_route']) ? $item['server_route'] : null,
                'permission' => $item['permission'] ?? null,
                'is_public' => (bool) ($item['is_public'] ?? false),
            ];
        }
        ksort($indexed);
        return $indexed;
    }

    /**
     * @param array<string, array{entity: string, action: string, permissions: mixed}> $before
     * @param array<string, array{entity: string, action: string, permissions: mixed}> $after
     * @param list<array{code: string, severity: string, category: string, target: string, reason: string}> $riskFlags
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>}
     */
    private static function diffApi(string $category, array $before, array $after, array &$riskFlags): array
    {
        $added = [];
        $removed = [];
        $changed = [];

        foreach ($after as $key => $itemB) {
            if (!array_key_exists($key, $before)) {
                $added[] = $itemB;
                if (self::isEmptyPermission($itemB['permissions'])) {
                    $riskFlags[] = [
                        'code' => 'api-no-permissions',
                        'severity' => 'medium',
                        'category' => $category,
                        'target' => $key,
                        'reason' => strtoupper($category) . ' action added with null/empty permissions',
                    ];
                }
                continue;
            }
            $itemA = $before[$key];
            if ($itemA['permissions'] === $itemB['permissions']) {
                continue;
            }
            $changed[] = [
                'entity' => $itemB['entity'],
                'action' => $itemB['action'],
                'before' => ['permissions' => $itemA['permissions']],
                'after' => ['permissions' => $itemB['permissions']],
            ];
            $direction = self::analyzePermissionChange($itemA['permissions'], $itemB['permissions'], false);
            if ($direction === 'weakened') {
                $riskFlags[] = [
                    'code' => 'api-perm-weakened',
                    'severity' => self::isEmptyPermission($itemB['permissions']) || self::isBroadBaseline($itemB['permissions'], false) ? 'high' : 'medium',
                    'category' => $category,
                    'target' => $key,
                    'reason' => 'Permissions weakened from ' . self::formatValue($itemA['permissions']) . ' to ' . self::formatValue($itemB['permissions']),
                ];
            } elseif ($direction === 'tightened') {
                $riskFlags[] = [
                    'code' => 'rg-perm',
                    'severity' => 'medium',
                    'category' => $category,
                    'target' => $key,
                    'reason' => 'Permissions tightened from ' . self::formatValue($itemA['permissions']) . ' to ' . self::formatValue($itemB['permissions']),
                ];
            }
        }

        foreach ($before as $key => $itemA) {
            if (!array_key_exists($key, $after)) {
                $removed[] = $itemA;
            }
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /**
     * @param array<string, array{path: string, access: mixed, callback: ?string}> $before
     * @param array<string, array{path: string, access: mixed, callback: ?string}> $after
     * @param list<array{code: string, severity: string, category: string, target: string, reason: string}> $riskFlags
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>}
     */
    private static function diffRoutes(array $before, array $after, array &$riskFlags): array
    {
        $added = [];
        $removed = [];
        $changed = [];

        foreach ($after as $path => $itemB) {
            if (!array_key_exists($path, $before)) {
                $added[] = $itemB;
                if (self::isEmptyPermission($itemB['access'])) {
                    $riskFlags[] = [
                        'code' => 'route-no-access',
                        'severity' => 'high',
                        'category' => 'routes',
                        'target' => $path,
                        'reason' => 'Route added without access_arguments',
                    ];
                }
                continue;
            }
            $itemA = $before[$path];
            if ($itemA['access'] === $itemB['access'] && $itemA['callback'] === $itemB['callback']) {
                continue;
            }
            $changed[] = [
                'path' => $path,
                'before' => ['access' => $itemA['access'], 'callback' => $itemA['callback']],
                'after' => ['access' => $itemB['access'], 'callback' => $itemB['callback']],
            ];
            if ($itemA['access'] === $itemB['access']) {
                continue;
            }
            $direction = self::analyzePermissionChange($itemA['access'], $itemB['access'], true);
            if ($direction === 'weakened') {
                $riskFlags[] = [
                    'code' => 'route-perm-weakened',
                    'severity' => 'high',
                    'category' => 'routes',
                    'target' => $path,
                    'reason' => 'Route access weakened from ' . self::formatValue($itemA['access']) . ' to ' . self::formatValue($itemB['access']),
                ];
            } elseif ($direction === 'tightened') {
                $riskFlags[] = [
                    'code' => 'rg-perm',
                    'severity' => 'medium',
                    'category' => 'routes',
                    'target' => $path,
                    'reason' => 'Route access tightened from ' . self::formatValue($itemA['access']) . ' to ' . self::formatValue($itemB['access']),
                ];
            }
        }

        foreach ($before as $path => $itemA) {
            if (!array_key_exists($path, $after)) {
                $removed[] = $itemA;
            }
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /**
     * @param array<string, array{name: string, server_route: ?string, permission: mixed, is_public: bool}> $before
     * @param array<string, array{name: string, server_route: ?string, permission: mixed, is_public: bool}> $after
     * @param list<array{code: string, severity: string, category: string, target: string, reason: string}> $riskFlags
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>}
     */
    private static function diffAfform(array $before, array $after, array &$riskFlags): array
    {
        $added = [];
        $removed = [];
        $changed = [];

        foreach ($after as $name => $itemB) {
            if (!array_key_exists($name, $before)) {
                $added[] = $itemB;
                if ($itemB['is_public']) {
                    $routeNote = $itemB['server_route'] !== null && $itemB['server_route'] !== ''
                        ? " (route: {$itemB['server_route']})"
                        : '';
                    $riskFlags[] = [
                        'code' => 'public-afform-added',
                        'severity' => 'high',
                        'category' => 'afform',
                        'target' => $name,
                        'reason' => "Public/anonymous Afform added{$routeNote}",
                    ];
                } elseif ($itemB['server_route'] !== null && $itemB['server_route'] !== '' && self::isEmptyPermission($itemB['permission'])) {
                    $riskFlags[] = [
                        'code' => 'afform-no-permission',
                        'severity' => 'medium',
                        'category' => 'afform',
                        'target' => $name,
                        'reason' => "Afform with route '{$itemB['server_route']}' added without permission",
                    ];
                }
                continue;
            }
            $itemA = $before[$name];
            if ($itemA['server_route'] === $itemB['server_route'] && $itemA['permission'] === $itemB['permission'] && $itemA['is_public'] === $itemB['is_public']) {
                continue;
            }
            $changed[] = [
                'name' => $name,
                'before' => [
                    'server_route' => $itemA['server_route'],
                    'permission' => $itemA['permission'],
                    'is_public' => $itemA['is_public'],
                ],
                'after' => [
                    'server_route' => $itemB['server_route'],
                    'permission' => $itemB['permission'],
                    'is_public' => $itemB['is_public'],
                ],
            ];
            if (!$itemA['is_public'] && $itemB['is_public']) {
                $riskFlags[] = [
                    'code' => 'afform-made-public',
                    'severity' => 'high',
                    'category' => 'afform',
                    'target' => $name,
                    'reason' => 'Afform became public/anonymous (is_public: false -> true)',
                ];
            } elseif ($itemA['is_public'] && !$itemB['is_public']) {
                $riskFlags[] = [
                    'code' => 'rg-perm',
                    'severity' => 'medium',
                    'category' => 'afform',
                    'target' => $name,
                    'reason' => 'Public Afform restricted to non-public (is_public: true -> false)',
                ];
            }
            if ($itemA['permission'] !== $itemB['permission']) {
                $direction = self::analyzePermissionChange($itemA['permission'], $itemB['permission'], false);
                if ($direction === 'weakened') {
                    $riskFlags[] = [
                        'code' => 'afform-perm-weakened',
                        'severity' => 'high',
                        'category' => 'afform',
                        'target' => $name,
                        'reason' => 'Afform permission weakened from ' . self::formatValue($itemA['permission']) . ' to ' . self::formatValue($itemB['permission']),
                    ];
                } elseif ($direction === 'tightened') {
                    $riskFlags[] = [
                        'code' => 'rg-perm',
                        'severity' => 'medium',
                        'category' => 'afform',
                        'target' => $name,
                        'reason' => 'Afform permission tightened from ' . self::formatValue($itemA['permission']) . ' to ' . self::formatValue($itemB['permission']),
                    ];
                }
            }
        }

        foreach ($before as $name => $itemA) {
            if (!array_key_exists($name, $after)) {
                $removed[] = $itemA;
            }
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    private static function isEmptyPermission(mixed $spec): bool
    {
        return self::extractPermissionSpec($spec, false)['empty'];
    }

    private static function isBroadBaseline(mixed $spec, bool $isRoute): bool
    {
        return self::extractPermissionSpec($spec, $isRoute)['tokens'] === ['access civicrm'];
    }

    /**
     * @return 'weakened'|'tightened'|null
     */
    private static function analyzePermissionChange(mixed $before, mixed $after, bool $isRoute): ?string
    {
        $b = self::extractPermissionSpec($before, $isRoute);
        $a = self::extractPermissionSpec($after, $isRoute);

        if ($b['empty'] && $a['empty']) {
            return null;
        }
        if (!$b['empty'] && $a['empty']) {
            return 'weakened';
        }
        if ($b['empty'] && !$a['empty']) {
            return 'tightened';
        }
        if ($b['tokens'] === $a['tokens'] && $b['operator'] === $a['operator']) {
            return null;
        }

        $bBroad = $b['tokens'] === ['access civicrm'];
        $aBroad = $a['tokens'] === ['access civicrm'];
        if (!$bBroad && $aBroad) {
            return 'weakened';
        }
        if ($bBroad && !$aBroad) {
            return 'tightened';
        }

        if ($b['operator'] === 'and' && $a['operator'] === 'or') {
            return array_diff($b['tokens'], $a['tokens']) === [] ? 'weakened' : 'tightened';
        }
        if ($b['operator'] === 'or' && $a['operator'] === 'and') {
            return 'tightened';
        }
        if ($b['operator'] === 'and') {
            return array_diff($a['tokens'], $b['tokens']) === [] && count($a['tokens']) < count($b['tokens'])
                ? 'weakened'
                : 'tightened';
        }
        // Both 'or'
        return array_diff($b['tokens'], $a['tokens']) === [] && count($a['tokens']) > count($b['tokens'])
            ? 'weakened'
            : 'tightened';
    }

    /**
     * @return array{empty: bool, operator: 'and'|'or', tokens: list<string>}
     */
    private static function extractPermissionSpec(mixed $spec, bool $isRoute): array
    {
        if ($spec === null || $spec === [] || $spec === '' || $spec === false) {
            return ['empty' => true, 'operator' => 'and', 'tokens' => []];
        }

        $operator = 'and';
        $raw = $spec;
        if ($isRoute && is_array($spec) && count($spec) === 2 && isset($spec[0], $spec[1]) && is_array($spec[0]) && is_string($spec[1])) {
            $op = strtolower($spec[1]);
            if ($op === 'and' || $op === 'or') {
                $operator = $op;
                $raw = $spec[0];
            }
        } elseif (!$isRoute && is_array($spec)) {
            foreach (array_keys($spec) as $key) {
                if (is_string($key) && strtolower($key) === 'or') {
                    $operator = 'or';
                    break;
                }
            }
            if ($operator === 'and' && count($spec) === 1 && isset($spec[0]) && is_array($spec[0]) && count($spec[0]) > 1) {
                $operator = 'or';
            }
        }

        $tokens = [];
        self::collectStrings($raw, $tokens);
        $tokens = array_values(array_unique($tokens));
        sort($tokens);

        return [
            'empty' => $tokens === [],
            'operator' => $operator,
            'tokens' => $tokens,
        ];
    }

    /**
     * @param list<string> $tokens
     */
    private static function collectStrings(mixed $value, array &$tokens): void
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized !== '') {
                $tokens[] = $normalized;
            }
            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::collectStrings($item, $tokens);
            }
        }
    }

    private static function formatValue(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        return $encoded === false ? 'null' : $encoded;
    }
}
