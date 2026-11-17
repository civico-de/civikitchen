<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A tool config (phpstan, phpunit, Playwright) that nothing in CI runs; `ck ci`,
 * the shared CI, `npm test` and `composer test` count as running theirs.
 */
final class ConfigWithoutRunnerCheck implements Check
{
    /**
     * config glob => [runner tokens that count as invoking it, human name]
     *
     * @var array<string, array{0: list<string>, 1: string}>
     */
    private const CONFIGS = [
        'phpstan.neon.dist' => [['phpstan'], 'phpstan'],
        'phpstan.neon' => [['phpstan'], 'phpstan'],
        'phpunit.xml.dist' => [['phpunit', 'ckcoverage'], 'phpunit'],
        // Run by a phpunit step that names it, see NAMED_CONFIGS, or inside the main suite.
        'phpunit-unit.xml.dist' => [[], 'phpunit'],
        'playwright.config.ts' => [['playwright', 'npx playwright'], 'playwright'],
        'playwright.config.js' => [['playwright', 'npx playwright'], 'playwright'],
        'vitest.config.ts' => [['vitest'], 'vitest'],
        'vitest.config.js' => [['vitest'], 'vitest'],
    ];

    /** A second config of a runner counts where a step of that runner names it. */
    private const NAMED_CONFIGS = ['phpunit-unit.xml.dist' => 'phpunit'];

    public function name(): string
    {
        return 'config-without-runner';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }
        $reachable = CiCommands::reachable($context);
        if ($reachable === '') {
            // No workflows at all is CiWorkflowCheck's finding, not ours.
            return;
        }

        $orphans = [];
        foreach (self::CONFIGS as $config => [$tokens, $tool]) {
            if (!$context->isTracked($config)) {
                continue;
            }
            $runner = self::NAMED_CONFIGS[$config] ?? null;
            $found = $runner !== null && CiCommands::runs($reachable, $runner)
                && str_contains($reachable, substr($config, 0, -strlen('.dist')));
            foreach ($tokens as $token) {
                if (CiCommands::runs($reachable, $token)) {
                    $found = true;
                    break;
                }
            }
            if (!$found && $config === 'phpunit-unit.xml.dist' && self::runsWithMainSuite($context, $reachable, $config)) {
                $found = true;
            }
            if (!$found) {
                $orphans[] = $config . ' (no ' . $tool . ' step)';
            }
        }

        if ($orphans !== []) {
            $reporter->fail('config present but never run in CI: ' . implode(', ', $orphans));
        } else {
            $reporter->ok('every tool config has a CI step that runs it');
        }
    }

    /** Every test path of $config lies in a test path of phpunit.xml.dist that it does not exclude, and CI runs that. */
    private static function runsWithMainSuite(Context $context, string $reachable, string $config): bool
    {
        $mainRuns = $context->isTracked('phpunit.xml.dist')
            && array_filter(self::CONFIGS['phpunit.xml.dist'][0], static fn (string $token): bool => CiCommands::runs($reachable, $token)) !== [];
        [$roots, $excluded] = self::testPaths($context->read('phpunit.xml.dist'));
        [$own] = self::testPaths($context->read($config));
        if (!$mainRuns || $own === []) {
            return false;
        }
        $within = static fn (string $path, array $dirs): bool => array_filter(
            $dirs,
            static fn (string $dir): bool => $dir === '' || $path === $dir || str_starts_with($path, $dir . '/'),
        ) !== [];

        return array_filter($own, static fn (string $path): bool => !$within($path, $roots) || $within($path, $excluded)) === [];
    }

    /**
     * The testsuite directories and files of a phpunit config, and its excludes, relative and without `./`.
     *
     * @return array{list<string>, list<string>}
     */
    private static function testPaths(?string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $parsed = $xml === null ? false : simplexml_load_string($xml);
        libxml_use_internal_errors($previous);
        if ($parsed === false) {
            return [[], []];
        }
        $paths = static fn (string $query): array => array_map(
            static fn (\SimpleXMLElement $node): string => trim(preg_replace('#^(\./)+#', '', trim((string) $node)) ?? '', '/'),
            $parsed->xpath($query) ?: [],
        );

        return [
            $paths('/phpunit/testsuites/testsuite/*[self::directory or self::file] | /phpunit/testsuite/*[self::directory or self::file]'),
            $paths('/phpunit/testsuites/testsuite/exclude | /phpunit/testsuite/exclude'),
        ];
    }
}
