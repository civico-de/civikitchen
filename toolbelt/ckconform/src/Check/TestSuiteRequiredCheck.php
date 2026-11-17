<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A repo with PHP logic (Civi/, CRM/, root .php; not .civix.php, DAO/,
 * phpstanBootstrap.php) and no suite. Opt-out in civikitchen.yaml: tests=optional -- <reason>
 */
final class TestSuiteRequiredCheck implements Check
{
    private const EXCLUDED = [
        '.civix.php',
        '/DAO/',
        'phpstanBootstrap.php',
    ];

    public function name(): string
    {
        return 'test-suite-required';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        // Shipped, not merely on disk: an untracked local tests/phpunit is not
        // a suite anyone else can run.
        if ($context->hasShippedUnder('tests/phpunit')) {
            return;
        }

        $optout = $context->policyValue('tests');
        if ($optout !== null) {
            // Only the documented form opts out, and the reason is mandatory.
            if (preg_match('/^optional\s+--\s+\S/', $optout) === 1) {
                $reporter->ok("no test suite — declared optional in civikitchen.yaml ({$optout})");

                return;
            }
            $reporter->fail("unrecognised tests= policy '{$optout}' — only 'tests=optional -- <reason>' opts out");

            return;
        }

        $count = count($this->sourceFiles($context));
        if ($count > 0) {
            $reporter->fail("no test suite (tests/phpunit) but {$count} PHP source file(s) — add tests, or declare 'tests=optional -- <reason>' in civikitchen.yaml");
        } else {
            $reporter->ok('no test suite — no PHP source to cover');
        }
    }

    /** @return list<string> */
    private function sourceFiles(Context $context): array
    {
        // sourceFiles() falls back to the filesystem outside a git checkout;
        // the root files need their own non-recursive fallback.
        $candidates = array_merge(
            $context->sourceFiles('Civi', ['.php']),
            $context->sourceFiles('CRM', ['.php']),
            $context->isGitRepo()
                ? $this->rootFiles($context)
                : (glob($context->path('*.php')) ?: []),
        );

        return array_values(array_filter(
            $candidates,
            static function (string $file): bool {
                foreach (self::EXCLUDED as $needle) {
                    if (str_contains($file, $needle)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * Tracked .php files in the repo root only; an untracked <key>.php does not
     * count as source.
     *
     *
     * @return list<string>
     */
    private function rootFiles(Context $context): array
    {
        $files = [];
        foreach ($context->trackedUnder('', ['.php']) as $file) {
            if (!str_contains($file, '/')) {
                $files[] = $file;
            }
        }
        sort($files);

        return $files;
    }
}
