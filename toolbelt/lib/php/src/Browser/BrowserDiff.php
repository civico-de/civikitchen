<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Browser;

use RuntimeException;

/**
 * Compares two Standalone browser probe reports (A vs B) per (mode, route)
 * and surfaces new console/page/network errors, render regressions, a11y
 * regressions, screenshot pairs, and structured risk flags.
 */
final class BrowserDiff
{
    /** @var array<string, int> */
    private const IMPACT_RANK = [
        'minor' => 1,
        'moderate' => 2,
        'serious' => 3,
        'critical' => 4,
    ];

    /**
     * @param list<array<string, mixed>> $routes
     * @param list<array{mode: string, route: string, message: string}> $newConsoleErrors
     * @param list<array{mode: string, route: string, message: string}> $newPageErrors
     * @param list<array{mode: string, route: string, status: int, method: string, urlPath: string}> $newNetworkFailures
     * @param list<array<string, mixed>> $newA11yViolations
     * @param list<array{mode: string, route: string, type: string, reason: string}> $renderRegressions
     * @param list<array{route: string, mode: string, before: ?string, after: ?string}> $screenshots
     * @param list<array{code: string, severity: string, mode: string, route: string, target: string, reason: string}> $riskFlags
     * @param array{routes: int, routes_with_regressions: int, new_console_errors: int, new_page_errors: int, new_network_failures: int, new_a11y_violations: int, render_regressions: int, risk_flags: int} $counts
     */
    private function __construct(
        private readonly array $routes,
        private readonly array $newConsoleErrors,
        private readonly array $newPageErrors,
        private readonly array $newNetworkFailures,
        private readonly array $newA11yViolations,
        private readonly array $renderRegressions,
        private readonly array $screenshots,
        private readonly array $riskFlags,
        private readonly array $counts,
    ) {
    }

    public static function fromFiles(string $beforeFile, string $afterFile): self
    {
        return self::compare(self::decodeFile($beforeFile), self::decodeFile($afterFile));
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeFile(string $file): array
    {
        if (!is_file($file)) {
            throw new RuntimeException("browser probe file does not exist: {$file}");
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException("cannot read browser probe file: {$file}");
        }
        return self::decodeJson($raw, $file);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeJson(string $raw, string $source = 'browser report'): array
    {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException("{$source} is not valid JSON");
        }
        if (!array_key_exists('routes', $decoded) || !is_array($decoded['routes'])) {
            throw new RuntimeException("{$source} is not a browser probe payload (missing routes)");
        }
        return $decoded;
    }

    /**
     * Strips volatile localhost ports, cache-buster values (CiviCRM's per-install `r=`
     * resource code, jQuery's `_=` timestamp) and script line:column positions, which
     * shift when B edits a file in the trace, so A and B messages compare cleanly.
     */
    public static function normalizeMessage(string $message): string
    {
        $normalized = preg_replace(
            [
                '/(localhost|127\.0\.0\.1|\[::1\]):\d+/i',
                '/([?&](?:r|_)=)[^&#\s:)\'"]*/',
                '/(\.m?js(?:\?[^\s:)\'"]*)?)(?::\d+){1,2}(?=[\s)\'"]|$)/mi',
            ],
            ['$1', '$1', '$1'],
            $message,
        );
        return $normalized ?? $message;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function compare(array $before, array $after): self
    {
        $indexedA = self::indexRoutes($before['routes'] ?? []);
        $indexedB = self::indexRoutes($after['routes'] ?? []);

        $orderedKeys = array_keys($indexedA);
        foreach (array_keys($indexedB) as $key) {
            if (!array_key_exists($key, $indexedA)) {
                $orderedKeys[] = $key;
            }
        }

        $routeDiffs = [];
        $allConsoleErrors = [];
        $allPageErrors = [];
        $allNetworkFailures = [];
        $allA11yViolations = [];
        $allRenderRegressions = [];
        $screenshots = [];
        $riskFlags = [];
        $routesWithRegressions = 0;

        foreach ($orderedKeys as $key) {
            $routeA = $indexedA[$key] ?? null;
            $routeB = $indexedB[$key] ?? null;
            $sample = $routeB ?? $routeA;
            if ($sample === null) {
                continue;
            }
            $mode = $sample['mode'];
            $route = $sample['route'];
            $target = "{$mode}:{$route}";

            $newConsole = self::diffMessages($routeA['consoleErrors'] ?? [], $routeB['consoleErrors'] ?? []);
            $newPage = self::diffMessages($routeA['pageErrors'] ?? [], $routeB['pageErrors'] ?? []);
            $newNetwork = self::diffNetworkFailures($routeA['networkFailures'] ?? [], $routeB['networkFailures'] ?? []);
            $newA11y = self::diffA11yViolations($routeA['a11yViolations'] ?? [], $routeB['a11yViolations'] ?? []);
            $renderRegs = self::diffRender($routeA, $routeB);

            foreach ($newPage as $message) {
                $allPageErrors[] = ['mode' => $mode, 'route' => $route, 'message' => $message];
                $riskFlags[] = self::riskFlag('browser-page-error', 'high', $mode, $route, $target, "Uncaught page error: {$message}");
            }
            foreach ($newConsole as $message) {
                $allConsoleErrors[] = ['mode' => $mode, 'route' => $route, 'message' => $message];
                $riskFlags[] = self::riskFlag('browser-console-error', 'medium', $mode, $route, $target, "New console error: {$message}");
            }
            foreach ($newNetwork as $failure) {
                $allNetworkFailures[] = ['mode' => $mode, 'route' => $route, ...$failure];
                $riskFlags[] = self::riskFlag(
                    'browser-network-failure',
                    'high',
                    $mode,
                    $route,
                    $target,
                    "HTTP {$failure['status']} on {$failure['method']} {$failure['urlPath']}",
                );
            }
            foreach ($renderRegs as $regression) {
                $allRenderRegressions[] = ['mode' => $mode, 'route' => $route, ...$regression];
                $riskFlags[] = self::riskFlag('browser-render-regression', 'high', $mode, $route, $target, $regression['reason']);
            }
            foreach ($newA11y as $violation) {
                $allA11yViolations[] = ['mode' => $mode, 'route' => $route, ...$violation];
                $severity = in_array($violation['impact'], ['critical', 'serious'], true) ? 'high' : 'medium';
                $label = $violation['change'] === 'impact-increased'
                    ? "A11y violation '{$violation['id']}' impact increased ({$violation['previous_impact']} -> {$violation['impact']})"
                    : "New a11y violation '{$violation['id']}' ({$violation['impact']}, {$violation['nodes']} nodes)";
                $riskFlags[] = self::riskFlag('browser-a11y-regression', $severity, $mode, $route, $target, $label);
            }

            $beforeShot = self::screenshotFilename($routeA['screenshot'] ?? null);
            $afterShot = self::screenshotFilename($routeB['screenshot'] ?? null);
            $screenshots[] = [
                'route' => $route,
                'mode' => $mode,
                'before' => $beforeShot,
                'after' => $afterShot,
            ];

            $hasRouteRegression = $newConsole !== [] || $newPage !== [] || $newNetwork !== [] || $newA11y !== [] || $renderRegs !== [];
            if ($hasRouteRegression) {
                $routesWithRegressions++;
            }

            $routeDiffs[] = [
                'route' => $route,
                'mode' => $mode,
                'new_console_errors' => $newConsole,
                'new_page_errors' => $newPage,
                'new_network_failures' => $newNetwork,
                'new_a11y_violations' => $newA11y,
                'render_regressions' => $renderRegs,
                'screenshots' => [
                    'before' => $beforeShot,
                    'after' => $afterShot,
                ],
            ];
        }

        $counts = [
            'routes' => count($routeDiffs),
            'routes_with_regressions' => $routesWithRegressions,
            'new_console_errors' => count($allConsoleErrors),
            'new_page_errors' => count($allPageErrors),
            'new_network_failures' => count($allNetworkFailures),
            'new_a11y_violations' => count($allA11yViolations),
            'render_regressions' => count($allRenderRegressions),
            'risk_flags' => count($riskFlags),
        ];

        return new self(
            $routeDiffs,
            $allConsoleErrors,
            $allPageErrors,
            $allNetworkFailures,
            $allA11yViolations,
            $allRenderRegressions,
            $screenshots,
            $riskFlags,
            $counts,
        );
    }

    public function hasRegressions(): bool
    {
        return $this->riskFlags !== [];
    }

    /**
     * @return list<array{code: string, severity: string, mode: string, route: string, target: string, reason: string}>
     */
    public function riskFlags(): array
    {
        return $this->riskFlags;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'routes' => $this->routes,
            'new_console_errors' => $this->newConsoleErrors,
            'new_page_errors' => $this->newPageErrors,
            'new_network_failures' => $this->newNetworkFailures,
            'new_a11y_violations' => $this->newA11yViolations,
            'render_regressions' => $this->renderRegressions,
            'screenshots' => $this->screenshots,
            'risk_flags' => $this->riskFlags,
            'counts' => $this->counts,
        ];
    }

    public function formatSummary(): string
    {
        if (!$this->hasRegressions()) {
            return 'Browser diff: no regressions (' . $this->counts['routes'] . " routes)\n";
        }

        $lines = [
            'Browser diff:',
            "  routes: {$this->counts['routes']} ({$this->counts['routes_with_regressions']} with regressions)",
            "  new_page_errors: {$this->counts['new_page_errors']}",
            "  new_console_errors: {$this->counts['new_console_errors']}",
            "  new_network_failures: {$this->counts['new_network_failures']}",
            "  render_regressions: {$this->counts['render_regressions']}",
            "  new_a11y_violations: {$this->counts['new_a11y_violations']}",
            'Risk flags (' . count($this->riskFlags) . '):',
        ];
        foreach ($this->riskFlags as $flag) {
            $lines[] = "  [{$flag['severity']}] {$flag['mode']} {$flag['route']} ({$flag['code']}): {$flag['reason']}";
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * @param mixed $routes
     * @return array<string, array<string, mixed>>
     */
    private static function indexRoutes(mixed $routes): array
    {
        $indexed = [];
        if (!is_array($routes)) {
            return $indexed;
        }
        foreach ($routes as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $mode = (string) ($entry['mode'] ?? 'auth');
            $route = (string) ($entry['route'] ?? '');
            $entry['mode'] = $mode;
            $entry['route'] = $route;
            $indexed["{$mode}:{$route}"] = $entry;
        }
        return $indexed;
    }

    /**
     * @param mixed $before
     * @param mixed $after
     * @return list<string>
     */
    private static function diffMessages(mixed $before, mixed $after): array
    {
        $seen = [];
        if (is_array($before)) {
            foreach ($before as $message) {
                $normalized = self::normalizeMessage((string) $message);
                if ($normalized !== '') {
                    $seen[$normalized] = true;
                }
            }
        }
        $added = [];
        $addedSet = [];
        if (is_array($after)) {
            foreach ($after as $message) {
                $normalized = self::normalizeMessage((string) $message);
                if ($normalized === '' || isset($seen[$normalized]) || isset($addedSet[$normalized])) {
                    continue;
                }
                $addedSet[$normalized] = true;
                $added[] = $normalized;
            }
        }
        return $added;
    }

    /**
     * @param mixed $before
     * @param mixed $after
     * @return list<array{status: int, method: string, urlPath: string}>
     */
    private static function diffNetworkFailures(mixed $before, mixed $after): array
    {
        $seen = [];
        if (is_array($before)) {
            foreach ($before as $failure) {
                $normalized = self::normalizeFailure($failure);
                if ($normalized !== null) {
                    $seen[self::failureKey($normalized)] = true;
                }
            }
        }
        $added = [];
        $addedSet = [];
        if (is_array($after)) {
            foreach ($after as $failure) {
                $normalized = self::normalizeFailure($failure);
                if ($normalized === null) {
                    continue;
                }
                $key = self::failureKey($normalized);
                if (isset($seen[$key]) || isset($addedSet[$key])) {
                    continue;
                }
                $addedSet[$key] = true;
                $added[] = $normalized;
            }
        }
        return $added;
    }

    /**
     * @return array{status: int, method: string, urlPath: string}|null
     */
    private static function normalizeFailure(mixed $failure): ?array
    {
        if (!is_array($failure)) {
            return null;
        }
        return [
            'status' => (int) ($failure['status'] ?? 0),
            'method' => strtoupper((string) ($failure['method'] ?? 'GET')),
            'urlPath' => self::normalizeMessage((string) ($failure['urlPath'] ?? '')),
        ];
    }

    /**
     * @param array{status: int, method: string, urlPath: string} $failure
     */
    private static function failureKey(array $failure): string
    {
        return "{$failure['status']} {$failure['method']} {$failure['urlPath']}";
    }

    /**
     * @param mixed $before
     * @param mixed $after
     * @return list<array<string, mixed>>
     */
    private static function diffA11yViolations(mixed $before, mixed $after): array
    {
        $indexedA = [];
        if (is_array($before)) {
            foreach ($before as $violation) {
                if (!is_array($violation)) {
                    continue;
                }
                $id = (string) ($violation['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $impact = strtolower((string) ($violation['impact'] ?? 'unknown'));
                $rank = self::IMPACT_RANK[$impact] ?? 0;
                if (!isset($indexedA[$id]) || $rank > $indexedA[$id]['rank']) {
                    $indexedA[$id] = ['impact' => $impact, 'rank' => $rank];
                }
            }
        }

        $regressions = [];
        if (!is_array($after)) {
            return $regressions;
        }
        foreach ($after as $violation) {
            if (!is_array($violation)) {
                continue;
            }
            $id = (string) ($violation['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $impact = strtolower((string) ($violation['impact'] ?? 'unknown'));
            $rank = self::IMPACT_RANK[$impact] ?? 0;
            $targets = [];
            if (is_array($violation['targets'] ?? null)) {
                foreach ($violation['targets'] as $target) {
                    $targets[] = (string) $target;
                }
            }
            $base = [
                'id' => $id,
                'impact' => $impact,
                'description' => (string) ($violation['description'] ?? ''),
                'nodes' => (int) ($violation['nodes'] ?? 0),
                'targets' => $targets,
            ];
            if (!isset($indexedA[$id])) {
                $regressions[] = [...$base, 'change' => 'new'];
            } elseif ($rank > $indexedA[$id]['rank']) {
                $regressions[] = [...$base, 'change' => 'impact-increased', 'previous_impact' => $indexedA[$id]['impact']];
            }
        }
        return $regressions;
    }

    /**
     * @param array<string, mixed>|null $routeA
     * @param array<string, mixed>|null $routeB
     * @return list<array{type: string, reason: string}>
     */
    private static function diffRender(?array $routeA, ?array $routeB): array
    {
        if ($routeA === null || $routeB === null) {
            return [];
        }
        $regressions = [];
        if (($routeA['hasCrmContainer'] ?? false) === true && ($routeB['hasCrmContainer'] ?? false) === false) {
            $regressions[] = [
                'type' => 'missing-crm-container',
                'reason' => '#crm-container present on A but missing on B',
            ];
        }
        $redirectedA = (bool) ($routeA['redirectedToLogin'] ?? false);
        $redirectedB = (bool) ($routeB['redirectedToLogin'] ?? false);
        if (!$redirectedA && $redirectedB) {
            $regressions[] = [
                'type' => 'redirected-to-login',
                'reason' => 'Unexpected redirect to /civicrm/login on B',
            ];
        }
        return $regressions;
    }

    private static function screenshotFilename(mixed $path): ?string
    {
        if (!is_string($path) || trim($path) === '') {
            return null;
        }
        return basename($path);
    }

    /**
     * @return array{code: string, severity: string, mode: string, route: string, target: string, reason: string}
     */
    private static function riskFlag(string $code, string $severity, string $mode, string $route, string $target, string $reason): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'mode' => $mode,
            'route' => $route,
            'target' => $target,
            'reason' => $reason,
        ];
    }
}
