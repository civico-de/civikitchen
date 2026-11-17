<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * phpstan.neon must run level 10 with no baseline, and scan no path outside the
 * repo. Comments are stripped and the level matched as a whole token.
 */
final class PhpstanConfigCheck implements Check
{
    public function name(): string
    {
        return 'phpstan-config';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $hasDist = $context->exists('phpstan.neon.dist');
        $hasPlain = $context->exists('phpstan.neon');

        if (!$hasDist && !$hasPlain) {
            $reporter->fail('no phpstan.neon.dist');

            return;
        }

        if (!$hasDist) {
            $reporter->warn('phpstan.neon should be phpstan.neon.dist (local overrides stay untracked)');
        }

        $config = $this->stripComments($context->readAny('phpstan.neon.dist', 'phpstan.neon') ?? '');

        if (preg_match('/(?:^|[\s{,\[])level:\s*(?:10|max)(?![0-9A-Za-z_.\-])/m', $config) === 1) {
            $reporter->ok('phpstan at level 10');
        } else {
            $reporter->fail('phpstan below level 10 (template: level 10, no baseline)');
        }

        if (preg_match('/class\.notFound|function\.notFound/', $config) === 1) {
            $reporter->warn('phpstan has blanket notFound ignores — use the bootstrap instead');
        }

        if ($this->hasBaselineFile($context)) {
            $reporter->fail('phpstan baseline file present (template: none)');
        }

        $escaping = $this->escapingScanPaths($config);
        if ($escaping !== []) {
            $reporter->fail(
                'phpstan scans a path outside the repo: ' . implode(', ', $escaping)
                . ' — a `../sibling` exists on the laptop it was written on and nowhere else,'
                . ' so CI dies with "Scanned directory does not exist". If an optional'
                . ' extension supplies a symbol, mount it at a fixed container path or cross'
                . ' the boundary with an APIv4 call instead of a compile-time reference'
            );
        }
    }

    /**
     * scanFiles / scanDirectories entries that climb out of the repo with `..`,
     * absent in CI; use a fixed container path (/var/www/html/ext/<key>).
     *
     * @return list<string>
     */
    private function escapingScanPaths(string $config): array
    {
        // Only items inside a scanFiles:/scanDirectories: block count (phpstan
        // ignores nonexistent excludes). NEON quotes are optional.
        $escaping = [];
        $scanIndent = null;
        foreach (preg_split('/\R/', $config) ?: [] as $line) {
            if (preg_match('/^(\s*)(scanFiles|scanDirectories):\s*$/', $line, $m) === 1) {
                $scanIndent = strlen($m[1]);
                continue;
            }
            if ($scanIndent === null || trim($line) === '') {
                continue;
            }
            if (preg_match('/^(\s*)-\s*[\'"]?(\.\.\/[^\'"\s]+)/', $line, $m) === 1
                && strlen($m[1]) > $scanIndent
            ) {
                $escaping[] = $m[2];
                continue;
            }
            // Any construct back at (or above) the key's indent ends the block.
            if (preg_match('/^(\s*)\S/', $line, $m) === 1 && strlen($m[1]) <= $scanIndent) {
                $scanIndent = null;
            }
        }

        return array_values(array_unique($escaping));
    }

    /**
     * NEON comments run from an unquoted '#' to the end of the line.
     */
    private function stripComments(string $config): string
    {
        $lines = [];
        foreach (explode("\n", $config) as $line) {
            $lines[] = (string) preg_replace('/(^|\s)#.*$/', '$1', $line);
        }

        return implode("\n", $lines);
    }

    /** Mirrors `ls phpstan-baseline*` in the repo root. */
    private function hasBaselineFile(Context $context): bool
    {
        $entries = scandir($context->path('.'));
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if (str_starts_with($entry, 'phpstan-baseline')) {
                return true;
            }
        }

        return false;
    }
}
