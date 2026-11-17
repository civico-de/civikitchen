<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Binary media (not SVG) must be committed through Git LFS, judged from the index.
 * Exemptions go in the civikitchen.yaml policy: lfs_exempt=<path>[,<path>] -- <reason>,
 * and out of the LFS filter in .gitattributes.
 */
final class MediaLfsCheck implements Check
{
    private const MEDIA_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'mp4', 'webm', 'pdf'];

    public function name(): string
    {
        return 'media-lfs';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        $exempt = $this->exemptPaths($context);
        $checked = 0;
        $failed = false;
        foreach ($context->trackedFiles() as $file) {
            if (!$this->isMedia($file)) {
                continue;
            }
            if ($this->isExempt($file, $exempt)) {
                if ($context->gitAttribute($file, 'filter') === 'lfs') {
                    $failed = true;
                    $reporter->fail(
                        "{$file} is exempt from Git LFS but .gitattributes still filters it through LFS, "
                        . 'so git reports it as modified in every checkout — add a .gitattributes beside it '
                        . 'that unsets the filter (`*.' . $this->extension($file) . ' -filter -diff -merge`)'
                    );
                }
                continue;
            }
            $checked++;
            if (!$context->isLfsPointer($file)) {
                $failed = true;
                $reporter->fail(
                    "{$file} is committed to git itself, not through Git LFS — run `ckinit --update` for the "
                    . "template's .gitattributes, then `git add --renormalize {$file}`; a file that must stay "
                    . 'in git goes into policy.lfs_exempt with its reason'
                );
            }
        }

        if (!$failed && $checked > 0) {
            $reporter->ok("all {$checked} media file(s) are committed through Git LFS");
        }
    }

    private function isMedia(string $file): bool
    {
        return in_array($this->extension($file), self::MEDIA_EXTENSIONS, true);
    }

    private function extension(string $file): string
    {
        return strtolower(pathinfo($file, PATHINFO_EXTENSION));
    }

    /** @param list<string> $exempt declared files, or directories covering everything below them */
    private function isExempt(string $file, array $exempt): bool
    {
        foreach ($exempt as $path) {
            if ($file === $path || str_starts_with($file, rtrim($path, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function exemptPaths(Context $context): array
    {
        $value = $context->policyValue('lfs_exempt');
        if ($value === null) {
            return [];
        }
        $paths = explode(' -- ', $value, 2)[0];

        return array_values(array_filter(array_map('trim', explode(',', $paths))));
    }
}
