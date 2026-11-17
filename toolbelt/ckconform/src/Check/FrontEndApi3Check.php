<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * CRM.api3, and core's Angular wrapper crmApi() around it, in tracked JS/TS/Smarty
 * outside vendored and generated code; an ignore needs a written reason.
 */
final class FrontEndApi3Check implements Check
{
    private const GLOBS = ['*.js', '*.jsx', '*.ts', '*.tsx', '*.tpl', '*.html', '*.vue'];

    public function name(): string
    {
        return 'front-end-api3';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        foreach ($this->candidates($context) as $file) {
            $contents = $context->read($file) ?? '';
            $call = match (true) {
                str_contains($contents, 'CRM.api3') => 'CRM.api3',
                preg_match('/(?<![\w$.])crmApi\s*\(/', $contents) === 1 => 'crmApi',
                default => null,
            };
            if ($call === null) {
                continue;
            }

            if ($this->hasDocumentedReason($contents)) {
                $reporter->ok("{$file} uses {$call} with a documented reason");
            } else {
                $reporter->fail(
                    "{$file} calls {$call} — migrate to " . ($call === 'crmApi' ? 'crmApi4' : 'CRM.api4')
                    . ", or annotate 'ck-allow-api3 -- <reason>'",
                );
            }
        }
    }

    /**
     * Sorted and de-duplicated so the output order matches `git ls-files`,
     * which the golden comparison depends on.
     *
     * @return list<string>
     */
    private function candidates(Context $context): array
    {
        $files = [];
        foreach (self::GLOBS as $glob) {
            foreach ($context->tracked($glob) as $file) {
                if ($this->isExcluded($file)) {
                    continue;
                }
                $files[$file] = true;
            }
        }
        $files = array_keys($files);
        sort($files);

        return $files;
    }

    /**
     * Excluded: 'node_modules' anywhere in the path, '/dist/' with both slashes
     * (so a top-level dist/ is *not* excluded), and a .min.js suffix.
     */
    private function isExcluded(string $file): bool
    {
        return str_contains($file, 'node_modules')
            || str_contains($file, '/dist/')
            || str_ends_with($file, '.min.js');
    }

    /**
     * The annotation may sit anywhere in the file with a non-empty reason after
     * '--' on the same line.
     */
    private function hasDocumentedReason(string $contents): bool
    {
        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/ck-allow-api3[[:space:]]+--[[:space:]]*[^[:space:]]/', $line) === 1) {
                return true;
            }
        }

        return false;
    }
}
