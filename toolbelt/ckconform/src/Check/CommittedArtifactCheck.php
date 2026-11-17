<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Tracked machine-owned artifacts: node_modules anywhere, vendor/ beside the
 * composer.json that installs it, the phpunit result cache, *.tsbuildinfo.
 */
final class CommittedArtifactCheck implements Check
{
    private const ARTIFACTS = ['.phpunit.result.cache', 'node_modules', 'vendor'];

    /**
     * Suffix-matched artifacts, for caches named after their file
     * (<tsconfig-name>.tsbuildinfo).
     */
    private const ARTIFACT_SUFFIXES = ['.tsbuildinfo'];

    public function name(): string
    {
        return 'committed-artifact';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        foreach (self::ARTIFACT_SUFFIXES as $suffix) {
            foreach ($context->trackedFiles() as $file) {
                if (str_ends_with($file, $suffix)) {
                    $reporter->fail("build/cache artifact committed: {$file}");
                    break;
                }
            }
        }

        // Extensions may ship vendor/ on purpose, declared in the
        // civikitchen.yaml policy: vendor=committed -- <reason>
        $vendorPolicy = $context->policyValue('vendor');
        $allowVendor = $vendorPolicy !== null && str_starts_with($vendorPolicy, 'committed');
        if ($allowVendor) {
            $reporter->ok("vendor committed — declared deliberate in civikitchen.yaml ({$vendorPolicy})");
        }

        foreach (self::ARTIFACTS as $bad) {
            if ($bad === 'vendor' && $allowVendor) {
                continue;
            }
            foreach ($this->committed($context, $bad) as $path) {
                $reporter->fail("build/cache artifact committed: {$path}");
            }
        }
    }

    /** @return list<string> the tracked file, or each tracked directory of that name, outermost first */
    private function committed(Context $context, string $bad): array
    {
        if ($context->isTracked($bad)) {
            return [$bad];
        }

        $paths = [];
        foreach ($context->trackedFiles() as $file) {
            $segments = explode('/', $file);
            foreach (array_slice($segments, 0, -1) as $index => $segment) {
                if ($segment !== $bad) {
                    continue;
                }
                $parent = implode('/', array_slice($segments, 0, $index));
                $prefix = $parent === '' ? '' : $parent . '/';
                if ($bad === 'vendor' && !$context->isTracked($prefix . 'composer.json')) {
                    continue;
                }
                $paths[$prefix . $bad . '/'] = true;
                break;
            }
        }
        $paths = array_keys($paths);
        sort($paths);

        return $paths;
    }
}
