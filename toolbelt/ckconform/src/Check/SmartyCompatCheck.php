<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\SmartySource;

/**
 * Smarty tags in shipped templates that Smarty 5 refuses to compile ("unknown
 * tag" fatal); warns, since Smarty 4 still renders them. Tags inside `{literal}`
 * or `{* *}`, and tags the repo registers as its own plugin, are exempt.
 *
 * SUPPRESSION. Inline `ckconform-ignore` comments elsewhere in this tool are
 * PHP-token based and cannot work in a .tpl. Use a Smarty comment with a
 * mandatory reason, on the finding's line or the line above it:
 *
 *     {* ckconform-ignore smarty-compat -- kept for the Smarty 4 branch *}
 */
final class SmartyCompatCheck implements Check
{
    /**
     * Tag => what to do instead. Every key throws "unknown tag" in the Smarty 5
     * core ships, with or without BCPluginsAdapter; shimmed tags are not listed.
     *
     * @var array<string, string>
     */
    private const REMOVED_TAGS = [
        'php' => 'move the logic into the Page/Form class and assign() the result',
        'include_php' => 'do the work in PHP and assign() it, or register a Smarty plugin for it',
        'insert' => 'removed with no replacement — assign() the fragment from the page class',
        'popup' => 'removed after Smarty 2 — use a CRM_Core_Resources-loaded tooltip instead',
        'popup_init' => 'removed after Smarty 2 — use a CRM_Core_Resources-loaded tooltip instead',
    ];

    /** @var array<string, true> "file:line" of markers already reported as reasonless. */
    private array $reportedMarkers = [];

    public function name(): string
    {
        return 'smarty-compat';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $registered = $this->locallyRegisteredTags($context);

        foreach ($this->sources($context) as $relative) {
            $source = $context->read($relative);
            if ($source === null) {
                continue;
            }
            $scannable = SmartySource::blankOutInertRegions($source);
            $lines = explode("\n", $source);

            foreach (self::REMOVED_TAGS as $tag => $advice) {
                if (in_array($tag, $registered, true)) {
                    continue;
                }
                foreach ($this->offsets($scannable, $tag) as $offset) {
                    $line = substr_count($source, "\n", 0, $offset) + 1;
                    if ($this->isSuppressed($lines, $line, $reporter, $relative)) {
                        continue;
                    }
                    $reporter->warn(
                        "$relative:$line: {{$tag}} was removed in Smarty 5 — the compiler throws "
                        . "\"unknown tag '$tag'\" and the page fatals; $advice"
                    );
                }
            }
        }
    }

    /**
     * Offsets of the tag's opening occurrences; one block is one finding.
     *
     * @return list<int>
     */
    private function offsets(string $source, string $tag): array
    {
        // (?=[\s}]) so {insert …} matches and {inserted} does not; auto_literal
        // treats "{ php}" as text, so no whitespace after the delimiter.
        $pattern = '/\{' . preg_quote($tag, '/') . '(?=[\s}])/';
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $found = [];
        foreach ($matches[0] as $match) {
            $found[] = (int) $match[1];
        }

        return $found;
    }

    /**
     * A `{* ckconform-ignore smarty-compat -- reason *}` on the finding's line
     * or the one above it. A marker without a reason silences nothing and is
     * reported in its own right.
     *
     * @param list<string> $lines
     * @param int          $line  1-based
     */
    private function isSuppressed(array $lines, int $line, Reporter $reporter, string $relative): bool
    {
        foreach ([$line, $line - 1] as $candidate) {
            $text = $lines[$candidate - 1] ?? '';
            if (preg_match('/\{\*\s*ckconform-ignore\s+' . preg_quote($this->name(), '/') . '\b(.*?)\*\}/s', $text, $match) !== 1) {
                continue;
            }
            $reason = trim(preg_replace('/^\s*--/', '', trim($match[1])) ?? '');
            if (str_starts_with(trim($match[1]), '--') && $reason !== '') {
                return true;
            }
            if (!isset($this->reportedMarkers["$relative:$candidate"])) {
                $this->reportedMarkers["$relative:$candidate"] = true;
                $reporter->warn(
                    "$relative:$candidate: ckconform-ignore smarty-compat has no reason — "
                    . 'write "-- <why>" after it; as written it suppresses nothing'
                );
            }
        }

        return false;
    }

    /**
     * Tags this repo registers itself, from the Smarty plugin filenames
     * `<type>.<tag>.php` that addPluginsDir() turns into registerPlugin() calls.
     *
     * @return list<string>
     */
    private function locallyRegisteredTags(Context $context): array
    {
        $tags = [];
        $files = $context->isGitRepo()
            ? $context->trackedUnder('', ['.php'])
            : $context->findFiles('', ['.php']);
        foreach ($files as $file) {
            if (preg_match('/^(?:function|block|compiler)\.([A-Za-z0-9_]+)\.php$/', basename($file), $match) === 1) {
                $tags[] = $match[1];
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * Shipped templates; tests and vendored code are not rendered on an install.
     *
     * @return list<string>
     */
    private function sources(Context $context): array
    {
        return $context->sourceFiles('', ['.tpl']);
    }
}
