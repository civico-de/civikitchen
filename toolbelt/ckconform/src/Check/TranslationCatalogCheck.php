<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\PhpSource;
use CiviKitchen\Ckconform\Reporter;

/**
 * A shipped `l10n/` whose `.mo` is missing or behind its `.po` (compared by
 * content: git keeps no mtimes). Warns on `ts()` strings in no catalog and on
 * non-literal `ts()` arguments; `translations.complete` makes missing and
 * untranslated strings failures. Silent without `l10n/`.
 */
final class TranslationCatalogCheck implements Check
{
    /** How many offending strings to name before the message gets unhelpful. */
    private const EXAMPLES = 3;

    public function name(): string
    {
        return 'translation-catalog';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $catalogs = $this->catalogs($context);
        if ($catalogs === []) {
            return;
        }
        $complete = $context->policyValue('translations_complete') === 'true';

        /** @var array<string, list<string>> $msgids catalog => every msgid in it */
        $msgids = [];
        foreach ($catalogs as $catalog) {
            $source = $context->read($catalog);
            if ($source === null) {
                continue;
            }
            $entries = $this->parsePo($source);
            $msgids[$catalog] = array_keys($entries);
            if ($complete && str_ends_with($catalog, '.po')) {
                $this->judgeUntranslated($reporter, $catalog, $entries);
            }

            if (str_ends_with($catalog, '.pot')) {
                // A .pot is the extraction template, not a translation: it has
                // no target language and nothing to compile.
                continue;
            }
            // CRM_Core_I18n loads only l10n/<locale>/LC_MESSAGES/<domain>.mo;
            // a catalog anywhere else is never read.
            if (preg_match('#^l10n/[^/]+/LC_MESSAGES/[^/]+\.po$#', $catalog) !== 1) {
                $reporter->fail(
                    "$catalog: not under l10n/<locale>/LC_MESSAGES/ — CiviCRM builds the runtime "
                    . 'path with LC_MESSAGES, so a catalog placed elsewhere is never loaded'
                );
                continue;
            }
            $this->judgeCompiledCatalog($context, $reporter, $catalog, $entries);
        }

        $this->judgeSourceStrings($context, $reporter, $msgids, $complete);
    }

    /**
     * @param array<string, array{translated: bool, present: bool, compiled: array<string, string>}> $entries
     */
    private function judgeUntranslated(Reporter $reporter, string $catalog, array $entries): void
    {
        $untranslated = array_keys(array_filter($entries, static fn (array $e): bool => !$e['translated']));
        if ($untranslated === []) {
            return;
        }
        $reporter->fail(sprintf(
            '%s: %d entr(y/ies) without a translation or marked fuzzy (%s) — translations.complete '
            . 'requires every string translated',
            $catalog,
            count($untranslated),
            $this->examples($untranslated),
        ));
    }

    /**
     * @param array<string, array{translated: bool, present: bool, compiled: array<string, string>}> $entries
     */
    private function judgeCompiledCatalog(
        Context $context,
        Reporter $reporter,
        string $catalog,
        array $entries,
    ): void {
        $compiled = substr($catalog, 0, -3) . '.mo';
        if (!$context->ships($compiled)) {
            $reporter->fail(
                "$catalog: no compiled $compiled beside it — CiviCRM reads only the .mo at runtime, "
                . 'so every translation in this .po is inert'
            );

            return;
        }

        $binary = $context->read($compiled);
        $compiledEntries = $binary === null ? null : $this->moEntries($binary);
        if ($compiledEntries === null) {
            $reporter->fail(
                "$compiled: not a readable gettext catalog — msgfmt never produced it, or it was truncated"
            );

            return;
        }

        $originals = array_column($compiledEntries, 0);
        $byKey = array_column($compiledEntries, 1, 2);
        $missing = [];
        $changed = [];
        foreach ($entries as $msgid => $entry) {
            if (!$entry['present']) {
                continue;
            }
            if (!in_array($msgid, $originals, true)) {
                $missing[] = $msgid;
                continue;
            }
            foreach ($entry['compiled'] as $key => $translation) {
                if (isset($byKey[$key]) && $byKey[$key] !== $translation) {
                    $changed[] = $msgid;
                    break;
                }
            }
        }
        if ($missing !== []) {
            $reporter->fail(sprintf(
                '%s: %d translated string(s) from %s are missing from it (%s) — the compiled catalog is '
                . 'behind its source, so those translations never reach anyone; recompile with msgfmt',
                $compiled,
                count($missing),
                basename($catalog),
                $this->examples($missing),
            ));
        }
        if ($changed !== []) {
            $reporter->fail(sprintf(
                '%s: %d string(s) carry another translation than in %s (%s) — the compiled catalog is '
                . 'behind its source; recompile with msgfmt',
                $compiled,
                count($changed),
                basename($catalog),
                $this->examples($changed),
            ));
        }
    }

    /**
     * @param array<string, list<string>> $msgids
     */
    private function judgeSourceStrings(Context $context, Reporter $reporter, array $msgids, bool $complete): void
    {
        $literals = [];
        // Tests are not shipped UI and vendored code has its own catalogs, so
        // Context's default exclusions fit.
        foreach ($context->sourceFiles('', ['.php', '.tpl']) as $file) {
            $source = $context->read($file);
            if ($source === null) {
                continue;
            }
            [$found, $dynamic] = $this->translatableStrings($file, $source);
            foreach ($found as $literal) {
                $literals[$literal] = true;
            }
            if ($dynamic > 0) {
                $reporter->warn(sprintf(
                    '%s: %d ts() call(s) with a non-literal first argument — no extractor can see those '
                    . 'strings, so they never reach l10n/ and can never be translated',
                    $file,
                    $dynamic,
                ));
            }
        }
        if ($literals === []) {
            return;
        }

        foreach ($msgids as $catalog => $known) {
            $known = array_flip($known);
            $missing = [];
            foreach (array_keys($literals) as $literal) {
                if (!isset($known[$literal]) && !isset($known[trim($literal)])) {
                    $missing[] = $literal;
                }
            }
            if ($missing === []) {
                continue;
            }
            $message = sprintf(
                '%s: %d string(s) passed to E::ts()/{ts} appear in it as no msgid (%s) — the catalog was '
                . 'extracted before those strings existed',
                $catalog,
                count($missing),
                $this->examples($missing),
            );
            if ($complete) {
                $reporter->fail($message);
            } else {
                $reporter->warn($message);
            }
        }
    }

    /**
     * The `.po`/`.pot` files the repo ships. Empty means no `l10n/`, which is
     * the silent case.
     *
     * @return list<string>
     */
    private function catalogs(Context $context): array
    {
        $files = $context->isGitRepo()
            ? $context->trackedUnder('l10n', ['.po', '.pot'])
            : $context->findFiles('l10n', ['.po', '.pot']);
        sort($files);

        return $files;
    }

    /**
     * Literal strings this file offers for translation, and the number of calls
     * that offer something an extractor cannot read.
     *
     * @return array{0: list<string>, 1: int}
     */
    private function translatableStrings(string $file, string $source): array
    {
        if (str_ends_with($file, '.tpl')) {
            return [$this->smartyStrings($source), 0];
        }

        $literals = [];
        $dynamic = 0;
        $tokens = PhpSource::codeTokens($source);
        foreach ($tokens as $i => $token) {
            $class = PhpSource::shortName($token) ?? '';
            if (($class !== 'E' && !str_ends_with($class, 'ExtensionUtil'))
                || !($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON) || strcasecmp($tokens[$i + 2]->text ?? '', 'ts') !== 0
                || ($tokens[$i + 3] ?? null)?->text !== '('
            ) {
                continue;
            }
            $text = PhpSource::argument(PhpSource::arguments($tokens, $i + 3) ?? [], 0, 'text') ?? [];
            if (count($text) !== 1 || !$text[0]->is(T_CONSTANT_ENCAPSED_STRING)) {
                // Interpolated, concatenated or computed: gettext looks up the runtime value.
                ++$dynamic;
                continue;
            }
            $body = substr($text[0]->text, 1, -1);
            $literals[] = $text[0]->text[0] === "'"
                ? str_replace(['\\\'', '\\\\'], ["'", '\\'], $body)
                : $this->unescape($body);
        }

        // A .mgd.php can carry Smarty message-template bodies with {ts} blocks.
        if (str_ends_with($file, '.mgd.php')) {
            $literals = array_merge($literals, $this->smartyStrings($source));
        }

        $literals = array_filter($literals, static fn (string $l): bool => $l !== '');

        return [array_values(array_unique($literals)), $dynamic];
    }

    /**
     * `{ts}…{/ts}` block bodies. A body containing further Smarty tags is not a
     * fixed string and is left alone.
     *
     * @return list<string>
     */
    private function smartyStrings(string $source): array
    {
        $found = [];
        if (preg_match_all('#\{ts(?=[\s}])[^}]*\}(.*?)\{/ts\}#s', $source, $matches) > 0) {
            foreach ($matches[1] as $body) {
                if ($body === '' || str_contains($body, '{') || str_contains($body, '}')) {
                    continue;
                }
                $found[] = $body;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * msgid => whether every entry for it is translated in all its plural forms, whether
     * any entry is (msgfmt compiles it), and the fully translated entries as gettext key
     * ("ctxt\x04msgid\0plural") => forms joined by "\0". Fuzzy entries count as present but
     * untranslated (msgfmt omits them); obsolete `#~` entries and the header do not count.
     *
     * @return array<string, array{translated: bool, present: bool, compiled: array<string, string>}>
     */
    private function parsePo(string $source): array
    {
        $records = $this->poRecords($source);
        $nplurals = null;
        foreach ($records as $r) {
            if ($r['msgid'] === '' && $r['ctxt'] === null && preg_match('/nplurals\s*=\s*(\d+)/', implode('', $r['forms']), $m) === 1) {
                $nplurals = (int) $m[1];
            }
        }

        $entries = [];
        foreach ($records as $r) {
            $msgid = $r['msgid'];
            if (!is_string($msgid) || $msgid === '') {
                continue;
            }
            $forms = $r['forms'];
            $expected = $r['plural'] === null ? 1 : ($nplurals ?? 2);
            $done = !$r['fuzzy'] && count($forms) >= $expected && !in_array('', $forms, true);
            $some = !$r['fuzzy'] && array_filter($forms, static fn (string $f): bool => $f !== '') !== [];
            $previous = $entries[$msgid] ?? ['translated' => true, 'present' => false, 'compiled' => []];
            $compiled = $previous['compiled'];
            if ($done) {
                $key = ($r['ctxt'] === null ? '' : $r['ctxt'] . "\x04") . $msgid . ($r['plural'] === null ? '' : "\0" . $r['plural']);
                $compiled[$key] = implode("\0", $forms);
            }
            $entries[$msgid] = [
                'translated' => $previous['translated'] && $done,
                'present' => $previous['present'] || $some,
                'compiled' => $compiled,
            ];
        }

        return $entries;
    }

    /**
     * The non-obsolete entries of a .po source in file order, the header included.
     *
     * @return list<array{ctxt: ?string, msgid: ?string, plural: ?string, forms: list<string>, fuzzy: bool}>
     */
    private function poRecords(string $source): array
    {
        $records = [];
        $record = null;
        $fuzzy = false;
        $obsolete = false;
        $target = null;
        $flush = static function () use (&$records, &$record, &$fuzzy, &$obsolete, &$target): void {
            if ($record !== null && !$obsolete) {
                $records[] = $record + ['fuzzy' => $fuzzy];
            }
            $record = null;
            $fuzzy = false;
            $obsolete = false;
            $target = null;
        };

        foreach (preg_split('/\r\n|\n|\r/', $source) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                $flush();
                continue;
            }
            if (str_starts_with($line, '#~')) {
                $obsolete = true;
                continue;
            }
            if (str_starts_with($line, '#')) {
                if (str_starts_with($line, '#,') && str_contains($line, 'fuzzy')) {
                    $fuzzy = true;
                }
                continue;
            }
            if (preg_match('/^(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?)\s+"(.*)"$/', $line, $match) === 1) {
                $value = $this->unescape($match[2]);
                if ($match[1] === 'msgctxt' || ($match[1] === 'msgid' && ($record['msgid'] ?? null) !== null)) {
                    $flush();
                }
                $record ??= ['ctxt' => null, 'msgid' => null, 'plural' => null, 'forms' => []];
                $target = match (true) {
                    $match[1] === 'msgctxt' => 'ctxt',
                    $match[1] === 'msgid' => 'msgid',
                    $match[1] === 'msgid_plural' => 'plural',
                    default => 'forms',
                };
                if ($target === 'forms') {
                    $record['forms'][] = $value;
                } else {
                    $record[$target] = $value;
                }
                continue;
            }
            if ($record !== null && $target !== null && preg_match('/^"(.*)"$/', $line, $match) === 1) {
                $continuation = $this->unescape($match[1]);
                if ($target === 'forms') {
                    $record['forms'][array_key_last($record['forms'])] .= $continuation;
                } else {
                    $record[$target] .= $continuation;
                }
            }
        }
        $flush();

        return $records;
    }

    /**
     * The entries of a compiled catalog as [msgid, translation, gettext key], or
     * null if the file is not a gettext catalog. Parsed with unpack(): msgunfmt is often absent.
     *
     * @return list<array{0: string, 1: string, 2: string}>|null
     */
    private function moEntries(string $binary): ?array
    {
        if (strlen($binary) < 24) {
            return null;
        }
        $magic = unpack('V', substr($binary, 0, 4));
        if ($magic === false) {
            return null;
        }
        $format = match ($magic[1]) {
            0x950412de => 'V',
            0xde120495 => 'N',
            default => null,
        };
        if ($format === null) {
            return null;
        }

        $header = unpack("{$format}4", substr($binary, 8, 16));
        if ($header === false) {
            return null;
        }
        $count = $header[1];
        $table = $header[2];
        $translations = $header[3];
        // Reject a truncated catalog before indexing: unpack() on a short read warns.
        if ($count < 0 || strlen($binary) < max($table, $translations) + $count * 8) {
            return null;
        }

        $entries = [];
        for ($i = 0; $i < $count; ++$i) {
            $original = $this->moString($binary, $format, $table + $i * 8);
            $translation = $this->moString($binary, $format, $translations + $i * 8);
            if ($original === null || $translation === null) {
                return null;
            }
            // A context is stored as "ctxt\x04msgid", a plural as "singular\0plural".
            $msgid = str_contains($original, "\x04") ? substr($original, strpos($original, "\x04") + 1) : $original;
            $entries[] = [explode("\0", $msgid)[0], $translation, $original];
        }

        return $entries;
    }

    private function moString(string $binary, string $format, int $descriptor): ?string
    {
        $entry = unpack("{$format}2", substr($binary, $descriptor, 8));
        if ($entry === false || strlen($binary) < $entry[2] + $entry[1]) {
            return null;
        }

        return substr($binary, $entry[2], $entry[1]);
    }

    private function unescape(string $value): string
    {
        return (string) preg_replace_callback(
            '/\\\\(.)/',
            static fn (array $m): string => match ($m[1]) {
                'n' => "\n",
                't' => "\t",
                'r' => "\r",
                default => $m[1],
            },
            $value,
        );
    }

    /**
     * A few of the offending strings, shortened on a character boundary.
     *
     * @param list<string> $strings
     */
    private function examples(array $strings): string
    {
        sort($strings);
        $shown = array_slice($strings, 0, self::EXAMPLES);
        $quoted = array_map(static function (string $s): string {
            $s = str_replace("\n", ' ', $s);
            if (preg_match('/^.{0,40}/us', $s, $match) === 1) {
                $s = $match[0] === $s ? $s : $match[0] . '…';
            } elseif (strlen($s) > 40) {
                $s = substr($s, 0, 40) . '…';
            }

            return "'$s'";
        }, $shown);
        $rest = count($strings) - count($shown);

        return implode(', ', $quoted) . ($rest > 0 ? ", +$rest more" : '');
    }

}
