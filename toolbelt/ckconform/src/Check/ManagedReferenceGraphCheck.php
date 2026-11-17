<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * SearchDisplays and Afform `<crm-search-display>` tags naming a SavedSearch or
 * display nobody ships: an own-prefixed name fails, a foreign one warns, and all
 * warn while any managed file could not be evaluated.
 */
final class ManagedReferenceGraphCheck implements Check
{
    public function name(): string
    {
        return 'managed-reference-graph';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $afformFiles = $context->isGitRepo()
            ? $context->trackedUnder('ang', ['.aff.html'])
            : $context->findFiles('ang', ['.aff.html']);

        $prefix = $context->shortName();
        /** @var array<string, list<string>> $names entity => defined names */
        $names = ['SavedSearch' => [], 'SearchDisplay' => [], 'Afform' => [], 'Navigation' => []];
        /** @var list<array{file: string, label: string, target: string}> $displayRefs */
        $displayRefs = [];

        $unevaluated = [];
        $records = ManagedFiles::records(
            $context,
            $reporter,
            'reference graph unchecked',
            onUnevaluated: static function (string $file) use (&$unevaluated): void {
                $unevaluated[] = $file;
            },
        );
        foreach ($records as [$relative, $records]) {
            foreach ($records as $index => $record) {
                if (!is_array($record)) {
                    continue;
                }
                $entity = $record['entity'] ?? null;
                $params = $record['params'] ?? null;
                $values = is_array($params)
                    ? (is_array($params['values'] ?? null) ? $params['values'] : $params)
                    : [];

                $bucket = match ($entity) {
                    'SavedSearch' => 'SavedSearch',
                    'SearchDisplay' => 'SearchDisplay',
                    'Afform', 'AfformBlock' => 'Afform',
                    'Navigation' => 'Navigation',
                    default => null,
                };
                if ($bucket !== null && is_string($values['name'] ?? null)) {
                    $names[$bucket][] = $values['name'];
                }

                if ($entity === 'SearchDisplay') {
                    // Either the implicit-join form or a plain name string.
                    $target = $values['saved_search_id.name'] ?? null;
                    if (!is_string($target) && is_string($values['saved_search_id'] ?? null)) {
                        $target = $values['saved_search_id'];
                    }
                    if (is_string($target) && $target !== '') {
                        $label = is_string($record['name'] ?? null)
                            ? "SearchDisplay '{$record['name']}'"
                            : "SearchDisplay #$index";
                        $displayRefs[] = ['file' => $relative, 'label' => $label, 'target' => $target];
                    }
                }
            }
        }

        // A record in a file that could not be evaluated may be the target.
        $prefix = $unevaluated === [] ? $prefix : null;

        foreach ($displayRefs as $ref) {
            if (in_array($ref['target'], $names['SavedSearch'], true)) {
                continue;
            }
            self::report(
                $reporter,
                $prefix,
                $ref['target'],
                "{$ref['file']}: {$ref['label']} references SavedSearch '{$ref['target']}'",
            );
        }

        foreach ($afformFiles as $relative) {
            $html = $context->read($relative) ?? '';
            foreach (self::searchDisplayTags($html) as $tag) {
                if ($tag['search'] !== null && !in_array($tag['search'], $names['SavedSearch'], true)) {
                    self::report(
                        $reporter,
                        $prefix,
                        $tag['search'],
                        "$relative: crm-search-display search-name=\"{$tag['search']}\" has no SavedSearch",
                    );
                }
                if ($tag['display'] !== null && !in_array($tag['display'], $names['SearchDisplay'], true)) {
                    self::report(
                        $reporter,
                        $prefix,
                        $tag['display'],
                        "$relative: crm-search-display display-name=\"{$tag['display']}\" has no SearchDisplay",
                    );
                }
            }
        }
    }

    /**
     * Own-looking names must be shipped here (fail); foreign-looking ones may
     * come from a dependency (warn).
     */
    private static function report(Reporter $reporter, ?string $prefix, string $target, string $message): void
    {
        if ($prefix !== null && str_starts_with(strtolower($target), strtolower($prefix))) {
            $reporter->fail("$message — no such record is shipped in this extension");

            return;
        }
        $reporter->warn("$message — target not shipped here; if it comes from another extension, ensure <requires> covers it");
    }

    /**
     * Text-scan the layout for `<crm-search-display ...>` tags; the attributes
     * are always literal strings.
     *
     * @return list<array{search: string|null, display: string|null}>
     */
    private static function searchDisplayTags(string $html): array
    {
        preg_match_all('/<crm-search-display\b[^>]*>/i', $html, $matches);
        $tags = [];
        foreach ($matches[0] as $tag) {
            $search = preg_match('/\bsearch-name\s*=\s*"([^"]*)"/i', $tag, $m) === 1 ? $m[1] : null;
            $display = preg_match('/\bdisplay-name\s*=\s*"([^"]*)"/i', $tag, $m) === 1 ? $m[1] : null;
            $tags[] = [
                'search' => ($search === null || $search === '') ? null : $search,
                'display' => ($display === null || $display === '') ? null : $display,
            ];
        }

        return $tags;
    }

}
