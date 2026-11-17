<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/** Shared preprocessing for the checks that scan Smarty templates as text. */
final class SmartySource
{
    /**
     * Smarty comments and `{literal}` blocks, blanked to spaces of the same
     * length so every offset and line number stays correct.
     */
    public static function blankOutInertRegions(string $source): string
    {
        return (string) preg_replace_callback(
            ['/\{\*.*?\*\}/s', '#\{literal\}.*?\{/literal\}#si'],
            static fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]) ?? $m[0],
            $source,
        );
    }
}
