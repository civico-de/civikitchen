<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * The .gitignore has to cover the artifacts this repo can actually produce
 * (result cache, vendor/, node_modules/, *.tsbuildinfo), decided by git check-ignore.
 */
final class GitignoreCoverageCheck implements Check
{
    /**
     * Ignore patterns for what a repo can produce; samplesFor() picks the paths git is asked about.
     *
     * @var list<string>
     */
    private const ARTIFACTS = ['.phpunit.result.cache', 'vendor/', 'node_modules/', '*.tsbuildinfo'];

    public function name(): string
    {
        return 'gitignore-coverage';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo() || !GitignoreScope::applies($context)) {
            // A missing .gitignore is GitignoreCheck's business, not ours.
            return;
        }

        // vendor=committed -- <reason> in civikitchen.yaml: the repo ships vendor/
        // deliberately, so demanding an ignore pattern for it is nonsense.
        $vendorPolicy = $context->policyValue('vendor');
        $allowVendor = $vendorPolicy !== null && str_starts_with($vendorPolicy, 'committed');

        $missing = [];
        foreach (self::ARTIFACTS as $pattern) {
            if ($pattern === 'vendor/' && $allowVendor) {
                continue;
            }
            $samples = $this->samplesFor($context, $pattern);
            if ($samples === []) {
                continue;
            }
            foreach ($samples as $sample) {
                if (!$context->isIgnored($sample)) {
                    $missing[] = $pattern === '.phpunit.result.cache' ? $sample : $pattern;
                    break;
                }
            }
        }

        if ($missing !== []) {
            $reporter->fail(
                '.gitignore does not cover artifacts this repo produces: ' . implode(' ', $missing)
            );
        } else {
            $reporter->ok('.gitignore covers the artifacts this repo produces');
        }
    }

    /**
     * Where this artifact would appear, beside the manifest or config that
     * produces it; an empty list means the repo cannot produce it.
     *
     *
     * @return list<string>
     */
    private function samplesFor(Context $context, string $pattern): array
    {
        $dirs = static function (array $files): array {
            $out = [];
            foreach ($files as $file) {
                $dir = dirname($file);
                $out[$dir === '.' ? '' : $dir . '/'] = TRUE;
            }

            return array_keys($out);
        };

        switch ($pattern) {
            case '.phpunit.result.cache':
                return $this->resultCache($context);

            case 'vendor/':
                return $context->exists('composer.json') ? ['vendor/autoload.php'] : [];

            case 'node_modules/':
                $manifests = $context->tracked('package.json', Context::outsideNodeModules(...));

                return array_map(
                    static fn (string $d): string => $d . 'node_modules/left-pad/package.json',
                    $dirs($manifests)
                );

            case '*.tsbuildinfo':
                return array_map(
                    static fn (string $d): string => $d . 'tsconfig.tsbuildinfo',
                    $dirs($context->tracked('tsconfig*.json'))
                );
        }

        return [];
    }

    /**
     * Where phpunit writes its result cache: nowhere with cacheResult="false",
     * at cacheResultFile (relative to the config) when set, else .phpunit.result.cache.
     *
     *
     * @return list<string>
     */
    private function resultCache(Context $context): array
    {
        $xml = $context->readAny('phpunit.xml.dist', 'phpunit.xml');
        if ($xml === null) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);
        if ($root === false) {
            return ['.phpunit.result.cache'];
        }
        if (strtolower(trim((string) $root['cacheResult'])) === 'false') {
            return [];
        }
        $file = preg_replace('#^(\./)+#', '', trim((string) $root['cacheResultFile'])) ?? '';
        if ($file === '') {
            return ['.phpunit.result.cache'];
        }

        return [str_ends_with($file, '/') || is_dir($context->path($file)) ? rtrim($file, '/') . '/.phpunit.result.cache' : $file];
    }

}
