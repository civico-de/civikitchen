<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A minified third-party bundle copied into the repo (outside node_modules/ and
 * vendor/): no version, no integrity hash, no
 * Dependabot visibility, and a diff nobody can review. The template's answer
 * is package.json with an exact-pinned dependency.
 */
final class VendoredBundleCheck implements Check
{
    private const BUNDLE_SUFFIXES = ['.min.js', '.min.css'];

    private const BUNDLE_DIRS = ['bower_components'];

    private const SKIP_DIRS = ['node_modules', 'vendor'];

    public function name(): string
    {
        return 'vendored-bundle';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        // Some repos ship bundles deliberately (e.g. no npm on the deploy
        // path). Declared in civikitchen.yaml: bundles=committed -- <reason>
        $policy = $context->policyValue('bundles');
        if ($policy !== null && str_starts_with($policy, 'committed')) {
            $reporter->ok("bundles committed — declared deliberate in civikitchen.yaml ({$policy})");

            return;
        }

        $flaggedDirs = [];
        foreach ($context->trackedFiles() as $file) {
            if ($this->isUnder($file, self::SKIP_DIRS)) {
                continue;
            }

            if ($this->isUnder($file, self::BUNDLE_DIRS)) {
                // One finding per directory; listing every contained file
                // would bury it.
                foreach (self::BUNDLE_DIRS as $dir) {
                    if ($this->isUnder($file, [$dir]) && !isset($flaggedDirs[$dir])) {
                        $flaggedDirs[$dir] = true;
                        $reporter->fail("vendored bundle committed: {$dir} — declare the libraries in package.json instead");
                    }
                }
                continue;
            }

            foreach (self::BUNDLE_SUFFIXES as $suffix) {
                if (str_ends_with($file, $suffix)) {
                    $reporter->fail("vendored bundle committed: {$file} — declare it in package.json and serve it from node_modules");
                }
            }
        }
    }

    /** @param list<string> $dirs */
    private function isUnder(string $file, array $dirs): bool
    {
        foreach ($dirs as $dir) {
            if (str_starts_with($file, "{$dir}/") || str_contains($file, "/{$dir}/")) {
                return true;
            }
        }

        return false;
    }
}
