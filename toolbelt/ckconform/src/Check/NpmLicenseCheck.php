<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Every tracked package.json must carry the npm licence the repo policy names
 * (`npm_license=` in `civikitchen.yaml`); node_modules and `vendored_paths` are excluded.
 */
final class NpmLicenseCheck implements Check
{
    public function name(): string
    {
        return 'npm-license';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $want = $context->policyValue('npm_license');
        if ($want === null || !$context->isGitRepo()) {
            return;
        }

        // Third-party code carried verbatim keeps its upstream licence.
        $vendored = array_map(
            static fn (string $entry): string => trim(rtrim(explode(' -- ', $entry, 2)[0]), '/'),
            $context->policyValues('vendored_paths'),
        );
        $manifests = array_filter(
            $context->tracked('package.json', Context::outsideNodeModules(...)),
            static function (string $manifest) use ($vendored): bool {
                foreach ($vendored as $path) {
                    if ($path !== '' && (str_starts_with($manifest, $path . '/') || fnmatch($path, dirname($manifest)))) {
                        return false;
                    }
                }

                return true;
            },
        );

        foreach ($manifests as $manifest) {
            $have = $this->license($context, $manifest, $want);
            if (strtolower($have) !== strtolower($want)) {
                $reporter->fail(sprintf(
                    "%s license is '%s', civikitchen.yaml expects '%s'",
                    $manifest,
                    $have === '' ? 'unset' : $have,
                    $want,
                ));
            }
        }
    }

    private function license(Context $context, string $manifest, string $want): string
    {
        $json = $context->json($manifest);
        $license = $json['license'] ?? null;

        if (is_string($license)) {
            return $license;
        }
        // An SPDX disjunctive list passes only when the expected licence is a member.
        if (is_array($license)) {
            $entries = array_values(array_filter(
                array_map(static fn ($entry): string => is_string($entry) ? $entry : '', $license),
                static fn (string $entry): bool => $entry !== '',
            ));
            foreach ($entries as $entry) {
                if (strtolower($entry) === strtolower($want)) {
                    return $entry;
                }
            }

            return implode(' or ', $entries);
        }

        return '';
    }
}
