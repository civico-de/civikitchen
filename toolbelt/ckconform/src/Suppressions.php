<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * Inline suppressions, parsed from real comment tokens:
 *
 *   // ckconform-ignore <check-name> -- <reason>        (this line + the next)
 *   // ckconform-ignore-file <check-name> -- <reason>   (the whole file)
 *
 * Several checks may be listed comma-separated; the reason is mandatory.
 * Instances are cached per contents hash so every check shares one record of
 * which entries were consumed, the input to the unused-ignore report.
 */
final class Suppressions
{
    /**
     * @var array<int, array<string, list<int>>> line => check name => entry indexes
     */
    private array $byLine = [];

    /** @var array<string, list<int>> check name => entry indexes, file-wide */
    private array $fileWide = [];

    /** @var list<array{line: int, names: list<string>, file: bool, consumed: array<string, bool>}> */
    private array $entries = [];

    /** @var list<int> lines carrying a marker without a reason */
    private array $missingReason = [];

    /** @var array<string, self> contents hash => instance */
    private static array $cache = [];

    public static function of(string $contents): self
    {
        // A file with no marker needs neither parsing nor caching.
        if (!str_contains($contents, 'ckconform-ignore')) {
            return new self();
        }

        $key = md5($contents);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $self = new self();
        foreach (@token_get_all($contents) as $token) {
            if (!is_array($token) || !in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            [, $text, $line] = $token;
            if (!str_contains($text, 'ckconform-ignore')) {
                continue;
            }
            if (preg_match(
                '/ckconform-ignore(-file)?\s+([A-Za-z0-9-]+(?:\s*,\s*[A-Za-z0-9-]+)*)\s+--\s*(\S.*?)\s*(?:\*\/)?$/m',
                $text,
                $m,
            ) !== 1) {
                $self->missingReason[] = $line;
                continue;
            }
            $names = array_values(array_map('trim', explode(',', $m[2])));
            $fileWide = $m[1] === '-file';
            $index = count($self->entries);
            $self->entries[] = [
                'line' => $line,
                'names' => $names,
                'file' => $fileWide,
                'consumed' => [],
            ];
            foreach ($names as $name) {
                if ($fileWide) {
                    $self->fileWide[$name][] = $index;
                } else {
                    $self->byLine[$line][$name][] = $index;
                    $self->byLine[$line + 1][$name][] = $index;
                }
            }
        }

        return self::$cache[$key] = $self;
    }

    /**
     * Suppressions in JS/TS-style source files, which PHP's tokenizer reads as
     * inline HTML. Only a standalone // or one-line block comment counts.
     */
    public static function ofLineComments(string $contents): self
    {
        if (!str_contains($contents, 'ckconform-ignore')) {
            return new self();
        }

        $key = 'line-comments:' . md5($contents);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $self = new self();
        foreach (self::standaloneJavaScriptComments($contents) as [$text, $line]) {
            if (str_starts_with($text, 'ckconform-ignore')) {
                $self->parseMarker($text, $line);
            }
        }

        return self::$cache[$key] = $self;
    }

    /** Drop the shared instances; tests only. */
    public static function reset(): void
    {
        self::$cache = [];
    }

    private function __construct()
    {
    }

    /** @return list<array{0: string, 1: int}> comment text + source line */
    private static function standaloneJavaScriptComments(string $contents): array
    {
        $comments = [];
        $state = 'code';
        $line = 1;
        $lineStart = 0;
        $length = strlen($contents);
        for ($i = 0; $i < $length; $i++) {
            $char = $contents[$i];
            $next = $contents[$i + 1] ?? '';
            if ($char === "\n") {
                $line++;
                $lineStart = $i + 1;
                if ($state === 'line-comment') {
                    $state = 'code';
                }
                continue;
            }
            if ($state === 'line-comment') {
                continue;
            }
            if ($state === 'block-comment') {
                if ($char === '*' && $next === '/') {
                    $state = 'code';
                    $i++;
                }
                continue;
            }
            if (in_array($state, ['single', 'double', 'template'], true)) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                $closing = ['single' => "'", 'double' => '"', 'template' => '`'][$state];
                if ($char === $closing) {
                    $state = 'code';
                }
                continue;
            }
            if ($char === "'") {
                $state = 'single';
                continue;
            }
            if ($char === '"') {
                $state = 'double';
                continue;
            }
            if ($char === '`') {
                $state = 'template';
                continue;
            }
            if ($char === '/' && $next === '/') {
                $lineEnd = strpos($contents, "\n", $i + 2);
                $lineEnd = $lineEnd === false ? $length : $lineEnd;
                if (trim(substr($contents, $lineStart, $i - $lineStart)) === '') {
                    $comments[] = [trim(substr($contents, $i + 2, $lineEnd - $i - 2)), $line];
                }
                $state = 'line-comment';
                $i++;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $close = strpos($contents, '*/', $i + 2);
                $lineEnd = strpos($contents, "\n", $i + 2);
                $lineEnd = $lineEnd === false ? $length : $lineEnd;
                if ($close !== false && $close < $lineEnd
                    && trim(substr($contents, $lineStart, $i - $lineStart)) === ''
                    && trim(substr($contents, $close + 2, $lineEnd - $close - 2)) === ''
                ) {
                    $comments[] = [trim(substr($contents, $i + 2, $close - $i - 2)), $line];
                    $i = $close + 1;
                } else {
                    $state = 'block-comment';
                    $i++;
                }
            }
        }

        return $comments;
    }

    private function parseMarker(string $text, int $line): void
    {
        if (preg_match(
            '/^ckconform-ignore(-file)?\s+([A-Za-z0-9-]+(?:\s*,\s*[A-Za-z0-9-]+)*)\s+--\s*(\S.*?)\s*$/',
            $text,
            $m,
        ) !== 1) {
            $this->missingReason[] = $line;
            return;
        }

        $names = array_values(array_map('trim', explode(',', $m[2])));
        $fileWide = $m[1] === '-file';
        $index = count($this->entries);
        $this->entries[] = [
            'line' => $line,
            'names' => $names,
            'file' => $fileWide,
            'consumed' => [],
        ];
        foreach ($names as $name) {
            if ($fileWide) {
                $this->fileWide[$name][] = $index;
            } else {
                $this->byLine[$line][$name][] = $index;
                $this->byLine[$line + 1][$name][] = $index;
            }
        }
    }

    /**
     * Does an ignore cover this check on this line? Answering marks the entry
     * consumed, so ask only where a finding is about to be reported.
     */
    public function suppressed(string $check, int $line): bool
    {
        $indexes = array_merge($this->fileWide[$check] ?? [], $this->byLine[$line][$check] ?? []);
        foreach ($indexes as $index) {
            $this->entries[$index]['consumed'][$check] = true;
        }

        return $indexes !== [];
    }

    /**
     * Every ignore entry, for hygiene checks (unknown check names).
     *
     * @return list<array{line: int, names: list<string>, file: bool, consumed: array<string, bool>}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Listed check names that never silenced anything, one row per name.
     * Meaningful only once every check has run.
     *
     * @return list<array{line: int, name: string, file: bool}>
     */
    public function unconsumed(): array
    {
        $unused = [];
        foreach ($this->entries as $entry) {
            foreach ($entry['names'] as $name) {
                if (!isset($entry['consumed'][$name])) {
                    $unused[] = ['line' => $entry['line'], 'name' => $name, 'file' => $entry['file']];
                }
            }
        }

        return $unused;
    }

    /**
     * Lines whose marker lacks the mandatory `-- <reason>`.
     *
     * @return list<int>
     */
    public function missingReason(): array
    {
        return $this->missingReason;
    }
}
