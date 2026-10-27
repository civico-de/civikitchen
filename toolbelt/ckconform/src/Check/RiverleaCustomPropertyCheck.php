<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A `var(--crm-…)` reference to a custom property nothing defines.
 *
 * `color: var(--crm-c-link, #1b5e8a)` looks right in the light theme, because
 * the fallback happens to fit it; Riverlea's name is `--crm-link-color`, so the
 * fallback renders in every stream and dark mode keeps the light colour. A
 * fallback therefore does not excuse an unknown name — it is what hides it.
 *
 * The catalogue is read from the core the check runs against: every `--crm-*`
 * declared anywhere in core (Riverlea's streams, core CSS, inline styles in
 * templates) plus the names Riverlea sets at runtime from PHP and JS, including
 * the stream editor's inputs. Names the repository declares or sets itself count
 * as defined. A name only some streams declare passes: it is part of the theme.
 */
final class RiverleaCustomPropertyCheck implements Check
{
    /** Built artefacts restate the source; a finding there is the same one twice. */
    private const SKIP = ['dist', 'node_modules', 'vendor', 'packages', 'build'];

    private const EXTENSIONS = ['css', 'scss', 'less', 'tpl', 'html', 'js', 'jsx', 'ts', 'tsx', 'mjs'];

    private const CORE_SKIP = ['node_modules', 'vendor', 'bower_components', 'packages'];

    private const CORE_EXTENSIONS = ['css', 'tpl', 'html', 'php', 'js'];

    private const NAME = '--crm-[A-Za-z0-9_-]+';

    /** Comment syntaxes per file type; `//` not after `:` or a quote, so URLs survive. */
    private const COMMENTS = [
        'block' => ['#/\*.*?\*/#s', ['css', 'scss', 'less', 'js', 'jsx', 'ts', 'tsx', 'mjs', 'php', 'tpl', 'html']],
        'html' => ['#<!--.*?-->#s', ['tpl', 'html']],
        'smarty' => ['#\{\*.*?\*\}#s', ['tpl']],
        'line' => ['#(?<![:\'"`])//[^\n]*#', ['scss', 'less', 'js', 'jsx', 'ts', 'tsx', 'mjs', 'php']],
    ];

    public function name(): string
    {
        return 'riverlea-custom-property';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if ($context->coreDir === null || !$context->isGitRepo()
            || !is_dir($context->coreDir . '/ext/riverlea')) {
            return;
        }

        $sources = [];
        foreach ($context->trackedFiles() as $file) {
            if ($this->scannable($file) && ($source = $context->read($file)) !== null
                && str_contains($source, '--crm-')) {
                $sources[$file] = $this->uncommented($file, $source);
            }
        }
        if ($sources === []) {
            return;
        }

        $known = $this->coreCatalogue($context->coreDir);
        foreach ($sources as $source) {
            $known += $this->definitions($source, true);
        }

        $references = 0;
        $unknown = 0;
        foreach ($sources as $file => $source) {
            foreach (preg_split('/\R/', $source) ?: [] as $index => $line) {
                // A name built by interpolation (`--crm-c-#{$n}`, `${n}`, `{$n}`, `@{n}`) is not read.
                $pattern = '/var\(\s*(' . self::NAME . '+)(?![#$@]?\{)\s*(,?)/';
                preg_match_all($pattern, $line, $matches, PREG_SET_ORDER);
                foreach ($matches as [, $name, $fallback]) {
                    $references++;
                    if (isset($known[$name])) {
                        continue;
                    }
                    $unknown++;
                    $suggestion = $this->closest($name, array_keys($known));
                    $at = $file . ':' . ($index + 1);
                    $reporter->failAt($file, $index + 1, "$at: nothing in core or this repository defines $name"
                        . ($suggestion === null ? '.' : " — did you mean $suggestion?")
                        . ($fallback === '' ? ' Without a fallback the property computes to unset.'
                            : ' Its fallback renders in every theme instead of following the palette.'));
                }
            }
        }

        if ($references > 0 && $unknown === 0) {
            $reporter->ok('every --crm-* custom property the repository uses exists');
        }
    }

    /**
     * @return array<string, true>
     */
    private function coreCatalogue(string $coreDir): array
    {
        $names = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($coreDir, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => !$file->isDir() || !in_array($file->getFilename(), self::CORE_SKIP, true)
        ));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !in_array($file->getExtension(), self::CORE_EXTENSIONS, true)) {
                continue;
            }
            $path = $file->getPathname();
            $source = $this->uncommented($path, (string) file_get_contents($path));
            if (str_contains($source, '--crm-')) {
                // Riverlea's stream editor and style loader set names from quoted literals.
                $names += $this->definitions($source, str_contains($path, '/ext/riverlea/'));
            }
        }

        return $names;
    }

    /**
     * Riverlea's comments suggest names no stream defines ("add '--crm-c-pink'"),
     * and a commented-out reference is none, so comments are blanked, keeping lines.
     */
    private function uncommented(string $path, string $source): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        foreach (self::COMMENTS as [$pattern, $extensions]) {
            if (in_array($extension, $extensions, true)) {
                $source = (string) preg_replace_callback(
                    $pattern,
                    static fn (array $comment): string => str_repeat("\n", substr_count($comment[0], "\n")),
                    $source
                );
            }
        }

        return $source;
    }

    /**
     * Declarations (`--crm-x:`), and with $literals also quoted names as handed
     * to `style.setProperty('--crm-x', …)`.
     *
     * @return array<string, true>
     */
    private function definitions(string $source, bool $literals): array
    {
        preg_match_all('/(' . self::NAME . ')\s*:/', $source, $declared);
        $names = $declared[1];
        if ($literals) {
            preg_match_all('/[\'"`](' . self::NAME . ')[\'"`]/', $source, $quoted);
            $names = array_merge($names, $quoted[1]);
        }

        return array_fill_keys($names, true);
    }

    /**
     * The shortest name holding every word of the unknown one (`--crm-c-link` →
     * `--crm-link-color`, not the one-letter neighbour `--crm-c-ink`), else a near-typo.
     *
     * @param list<string> $known
     */
    private function closest(string $name, array $known): ?string
    {
        $best = null;
        $words = array_filter(
            explode('-', substr($name, strlen('--crm-'))),
            static fn (string $word): bool => strlen($word) >= 3
        );
        foreach ($words === [] ? [] : $known as $candidate) {
            if (array_diff($words, explode('-', $candidate)) === []
                && ($best === null || strlen($candidate) < strlen($best))) {
                $best = $candidate;
            }
        }
        if ($best !== null) {
            return $best;
        }

        $bestDistance = 4;
        foreach ($known as $candidate) {
            $distance = levenshtein($name, $candidate);
            if ($distance < $bestDistance) {
                [$best, $bestDistance] = [$candidate, $distance];
            }
        }

        return $best;
    }

    private function scannable(string $file): bool
    {
        return !Context::isUnder($file, self::SKIP) && in_array(pathinfo($file, PATHINFO_EXTENSION), self::EXTENSIONS, true);
    }
}
