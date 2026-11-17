<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\ExtensionUtilStub;
use CiviKitchen\Ckconform\Reporter;

/**
 * Afform structure defects: a fieldset bound to an undeclared or duplicated
 * `<af-entity>` alias, an `af-field` outside every fieldset, a missing `.aff.json`
 * or `permission`, and extra fields below the core floor that supports them.
 */
final class AfformContractCheck implements Check
{
    /** Afform types that legitimately declare no entities of their own. */
    private const ENTITYLESS_TYPES = ['block', 'search'];

    /** Extra fields arrived in EXTRA_FIELDS_SINCE for these input types only. */
    private const EARLY_EXTRA_INPUT_TYPES = ['Email', 'Number', 'Text', 'TextArea', 'Toggle', 'Url'];

    private const EXTRA_FIELDS_SINCE = '6.15';

    private const ANY_EXTRA_INPUT_SINCE = '6.16';

    /** A start tag's attributes up to its `>`, each quoted value taken whole. */
    private const ATTRIBUTE_RUN = '(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+';

    public function name(): string
    {
        return 'afform-contract';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        // Tracked files only (repo principle) — an uncommitted local form is
        // not shipped. Outside git, fall back to the tree.
        $layouts = $context->isGitRepo()
            ? $context->trackedUnder('ang', ['.aff.html'])
            : $context->findFiles('ang', ['.aff.html']);
        if ($layouts === []) {
            return;
        }
        $floor = $context->compatibilityFloor();

        foreach ($layouts as $relative) {
            $html = $context->read($relative);
            if ($html === null) {
                continue;
            }

            $base = substr($relative, 0, -strlen('.aff.html'));
            $metaFile = $base . '.aff.json';
            $phpMetaFile = $base . '.aff.php';
            $type = null;
            if ($context->ships($phpMetaFile)) {
                // The PHP metadata variant is just as valid as .aff.json.
                // Evaluating it needs the ExtensionUtil stub for E::ts().
                ExtensionUtilStub::register($context);
                try {
                    $meta = require $context->path($phpMetaFile);
                    if (is_array($meta)) {
                        $type = is_string($meta['type'] ?? null) ? $meta['type'] : null;
                        if (!array_key_exists('permission', $meta)) {
                            $reporter->warn("$phpMetaFile: no permission key — check the intended audience explicitly");
                        }
                    } else {
                        $reporter->fail("$phpMetaFile: does not return an array — the form cannot be installed");
                    }
                } catch (\Throwable $e) {
                    $reporter->warn("$phpMetaFile: could not evaluate outside CiviCRM ({$e->getMessage()}) — metadata unchecked");
                }
            } elseif (!$context->ships($metaFile)) {
                $reporter->warn("$relative: no $metaFile (or .aff.php) beside it — the form's metadata then comes from database defaults, which is usually a forgotten file");
            } else {
                $raw = $context->read($metaFile) ?? '';
                $meta = json_decode($raw, true);
                if (!is_array($meta)) {
                    $reporter->fail("$metaFile: invalid JSON (" . json_last_error_msg() . ') — the form cannot be installed');
                } else {
                    $type = is_string($meta['type'] ?? null) ? $meta['type'] : null;
                    if (!array_key_exists('permission', $meta)) {
                        $reporter->warn("$metaFile: no permission key — check the intended audience explicitly");
                    }
                }
            }

            $aliases = $this->declaredAliases($html, $relative, $reporter);

            // Blocks and search forms are bound by the including form; enforce
            // bindings only once this file declares entities itself.
            $enforceBindings = $aliases !== []
                || ($type !== null && !in_array($type, self::ENTITYLESS_TYPES, true));

            if ($enforceBindings) {
                $this->checkBindings($html, $relative, $aliases, $reporter);
            }

            // A block's fields bind to the fieldset of the form that embeds it.
            if ($type === null || !in_array($type, self::ENTITYLESS_TYPES, true)) {
                $this->checkStrayFields($html, $relative, $reporter);
            }

            if ($floor !== null && version_compare($floor, self::ANY_EXTRA_INPUT_SINCE, '<')) {
                $this->checkExtraFields($html, $relative, $floor, $reporter);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function declaredAliases(string $html, string $relative, Reporter $reporter): array
    {
        $aliases = [];
        if (preg_match_all('/<af-entity\b[^>]*>/i', $html, $tags) < 1) {
            return [];
        }
        foreach ($tags[0] as $tag) {
            if (preg_match('/\bname\s*=\s*["\']([^"\']+)["\']/i', $tag, $m) !== 1) {
                $reporter->fail("$relative: an <af-entity> has no name attribute — nothing can bind to it");
                continue;
            }
            $alias = $m[1];
            if (in_array($alias, $aliases, true)) {
                $reporter->fail("$relative: entity alias '$alias' is declared twice — the second declaration wins and half the form writes to the wrong entity");
                continue;
            }
            $aliases[] = $alias;
        }

        return $aliases;
    }

    /**
     * @param list<string> $aliases
     */
    private function checkBindings(string $html, string $relative, array $aliases, Reporter $reporter): void
    {
        foreach (['af-fieldset', 'data-entity'] as $attribute) {
            $pattern = '/\b' . preg_quote($attribute, '/') . '\s*=\s*["\']([^"\']*)["\']/i';
            if (preg_match_all($pattern, $html, $matches) < 1) {
                continue;
            }
            foreach (array_unique($matches[1]) as $alias) {
                if ($alias === '' || in_array($alias, $aliases, true)) {
                    continue;
                }
                $reporter->fail("$relative: $attribute=\"$alias\" binds to an entity alias that is not declared by any <af-entity> (declared: " . (implode(', ', $aliases) ?: 'none') . ') — the fields render but save nothing');
            }
        }
    }

    /**
     * An af-field before the first fieldset or after nesting closes again, by
     * position; an extra field binds to the form and may stand anywhere.
     */
    private function checkStrayFields(string $html, string $relative, Reporter $reporter): void
    {
        $fields = $this->fieldTags($html);
        if ($fields === []) {
            return;
        }
        $ranges = $this->fieldsetRanges($html);
        foreach ($fields as [$tag, $offset]) {
            foreach ($ranges as [$start, $end]) {
                if ($offset >= $start && $offset < $end) {
                    continue 2;
                }
            }
            if ($this->extraFieldInputType($tag) !== null) {
                continue;
            }
            $name = $this->attributes($tag)['name'] ?? '';
            $reporter->warn("$relative: <af-field name=\"" . ($name === '' ? '?' : $name) . "\"> sits outside every af-fieldset — it has no entity to bind to");
        }
    }

    /**
     * Older cores break on extra fields, inside a fieldset or not; 6.15 throws
     * on an input type outside EARLY_EXTRA_INPUT_TYPES.
     */
    private function checkExtraFields(string $html, string $relative, string $floor, Reporter $reporter): void
    {
        $needed = null;
        $inputType = '';
        foreach ($this->fieldTags($html) as [$tag]) {
            $type = $this->extraFieldInputType($tag);
            if ($type === null) {
                continue;
            }
            $since = in_array($type, self::EARLY_EXTRA_INPUT_TYPES, true) ? self::EXTRA_FIELDS_SINCE : self::ANY_EXTRA_INPUT_SINCE;
            if ($needed === null || version_compare($since, $needed, '>')) {
                [$needed, $inputType] = [$since, $type];
            }
        }
        if ($needed !== null && version_compare($floor, $needed, '<')) {
            $reporter->warn("$relative: holds an extra <af-field> (no name) with input type $inputType, which CiviCRM supports from $needed on — info.xml's compatibility floor is $floor");
        }
    }

    /**
     * Each af-field start tag with its byte offset.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function fieldTags(string $html): array
    {
        return preg_match_all('/<af-field(?=[\s\/>])' . self::ATTRIBUTE_RUN . '>/i', $html, $fields, PREG_OFFSET_CAPTURE) > 0 ? $fields[0] : [];
    }

    /**
     * The input type of an extra field: one with no name, which core binds to
     * the form and renders from its `defn`. Null for any other field.
     */
    private function extraFieldInputType(string $tag): ?string
    {
        $attributes = $this->attributes($tag);
        if (($attributes['name'] ?? '') !== '') {
            return null;
        }
        $defn = html_entity_decode($attributes['defn'] ?? '', ENT_QUOTES | ENT_HTML5);

        return preg_match('/\binput_type[\'"]?\s*:\s*([\'"])(\w+)\1/', $defn, $m) === 1 ? $m[2] : null;
    }

    /**
     * A start tag's attributes, lowercased name => value ('' when bare). As in
     * HTML, a value may go unquoted and the first of a repeated name wins.
     *
     * @return array<string, string>
     */
    private function attributes(string $tag): array
    {
        $inner = (string) preg_replace('#^<[^\s/>]+|/?>$#', '', $tag);
        preg_match_all('/([^\s"\'\/=>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/', $inner, $matches, PREG_SET_ORDER);
        $attributes = [];
        foreach ($matches as $match) {
            $attributes[strtolower($match[1])] ??= ($match[2] ?? '') . ($match[3] ?? '') . ($match[4] ?? '');
        }

        return $attributes;
    }

    /**
     * Byte ranges covered by an element carrying af-fieldset, by tag depth;
     * self-closing and void forms are ignored.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function fieldsetRanges(string $html): array
    {
        if (preg_match_all('#<(/?)([a-z0-9-]++)(' . self::ATTRIBUTE_RUN . ')>#i', $html, $tags, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        $ranges = [];
        /** @var list<array{name: string, start: int, depth: int}> $open */
        $open = [];
        $depth = [];

        foreach ($tags[0] as $index => [$tag, $offset]) {
            $closing = $tags[1][$index][0] !== '';
            $name = strtolower($tags[2][$index][0]);
            $attributes = $tags[3][$index][0];
            if (str_ends_with(rtrim($attributes), '/')) {
                continue;
            }

            if (!$closing) {
                $depth[$name] = ($depth[$name] ?? 0) + 1;
                if (preg_match('/\baf-fieldset\s*=/i', $attributes) === 1) {
                    $open[] = ['name' => $name, 'start' => $offset + strlen($tag), 'depth' => $depth[$name]];
                }
                continue;
            }

            $current = $depth[$name] ?? 0;
            $last = $open === [] ? null : $open[count($open) - 1];
            if ($last !== null && $last['name'] === $name && $last['depth'] === $current) {
                array_pop($open);
                $ranges[] = [$last['start'], $offset];
            }
            $depth[$name] = max(0, $current - 1);
        }

        // Unclosed fieldsets: treat as running to the end of the file rather
        // than reporting every field inside them as stray.
        foreach ($open as $unclosed) {
            $ranges[] = [$unclosed['start'], strlen($html)];
        }

        return $ranges;
    }
}
