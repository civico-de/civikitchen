<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Demo;

use CiviKitchen\Toolbelt\Process\Runner;
use RuntimeException;

/** A civicrm-core change as a git diff: a pull request or a local diff file. */
final class CoreDiff
{
    private const PULL = 'https://github.com/civicrm/civicrm-core/pull/';

    /** @param list<string> $files */
    private function __construct(
        public readonly string $label,
        public readonly string $title,
        public readonly ?string $url,
        public readonly string $patch,
        public readonly array $files,
    ) {
    }

    /** A PR number, a PR URL, or the path of a diff file. */
    public static function load(string $source, Runner $runner): self
    {
        $pattern = '~^(?:' . preg_quote(self::PULL, '~') . ')?(\d+)(?:[/#?].*)?$~';
        if (preg_match($pattern, $source, $match) === 1 && !is_file($source)) {
            $url = self::PULL . $match[1];
            $download = $runner->captureStdout(['curl', '-fsSL', '--max-time', '120', $url . '.diff']);
            if ($download['status'] !== 0) {
                throw new RuntimeException("could not download {$url}.diff (curl exit {$download['status']})");
            }
            return self::parse('pr' . $match[1], 'civicrm/civicrm-core#' . $match[1], $url, $download['output']);
        }
        if (!is_file($source)) {
            throw new RuntimeException("{$source} is neither a civicrm-core PR number or URL nor a diff file");
        }
        $label = trim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo($source, PATHINFO_FILENAME))), '-');
        return self::parse($label === '' ? 'diff' : $label, basename($source), null, (string) file_get_contents($source));
    }

    public static function parse(string $label, string $title, ?string $url, string $patch): self
    {
        preg_match_all('~^diff --git a/\S+ b/(\S+)$~m', $patch, $matches);
        if ($matches[1] === []) {
            throw new RuntimeException("{$title} holds no git diff");
        }
        return new self($label, $title, $url, $patch, array_values(array_unique($matches[1])));
    }
}
