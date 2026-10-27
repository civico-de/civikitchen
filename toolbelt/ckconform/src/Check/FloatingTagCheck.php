<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * ':latest', an untagged image and 'releases/latest/download' in CI make a green run
 * unreproducible and a red one unattributable — the workflow can start
 * building against a different image or binary tomorrow with no diff to
 * point at.
 *
 * Images are read where GitHub Actions takes them: `container:` (shorthand or
 * `image:`), a service's `image:` and a `docker://` step. Only the first hit
 * per file is reported.
 */
final class FloatingTagCheck implements Check
{
    public function name(): string
    {
        return 'floating-tag';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        foreach ($context->workflows() as $workflow) {
            $contents = $context->read($workflow);
            if ($contents === null) {
                continue;
            }
            $lines = explode("\n", $contents);
            // A trailing newline yields a trailing empty element that grep
            // never numbers as a line; drop it so line numbers stay true.
            if ($lines[array_key_last($lines)] === '') {
                array_pop($lines);
            }
            $parents = [];
            foreach ($lines as $index => $line) {
                if ($this->isFloating($line, $parents)) {
                    $match = $workflow . ':' . ($index + 1) . ':' . $line;
                    $reporter->warn('CI pins nothing (floating :latest): ' . substr($match, 0, 70));

                    return;
                }
            }
        }
    }

    /** @param list<array{0: int, 1: string}> $parents indent and key of the enclosing mappings, kept across lines */
    private function isFloating(string $line, array &$parents): bool
    {
        $line = self::withoutComment($line);
        $trimmed = ltrim($line);
        if ($trimmed === '') {
            return false;
        }
        $indent = strlen($line) - strlen($trimmed);
        while ($parents !== [] && $parents[array_key_last($parents)][0] >= $indent) {
            array_pop($parents);
        }
        $enclosing = array_reverse(array_column($parents, 1));
        if (preg_match('/^(?:-\s+)?([\w.-]+):(?:\s+(.*))?$/', $trimmed, $key) === 1) {
            $parents[] = [$indent, $key[1]];
        }
        if (preg_match('/image:.*:latest|releases\/latest\/download/', $line) === 1) {
            return true;
        }
        if (preg_match('/^(?:-\s+)?uses:\s*["\']?docker:\/\/([^\s"\']+)/', $trimmed, $docker) === 1) {
            return ImageReference::floats($docker[1]);
        }
        $value = $key[2] ?? '';
        $isImage = match ($key[1] ?? '') {
            'container' => ($enclosing[1] ?? '') === 'jobs',
            'image' => ($enclosing[0] ?? '') === 'container' || ($enclosing[1] ?? '') === 'services',
            default => false,
        };

        return $isImage && ImageReference::floats($value);
    }

    /** The line up to a YAML comment: a `#` at the start or after whitespace, outside quotes that open a word. */
    private static function withoutComment(string $line): string
    {
        $quote = null;
        for ($i = 0, $length = strlen($line); $i < $length; $i++) {
            $char = $line[$i];
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif (($char === '"' || $char === "'") && ($i === 0 || strpbrk($line[$i - 1], " \t=:(") !== false)) {
                $quote = $char;
            } elseif ($char === '#' && ($i === 0 || ctype_space($line[$i - 1]))) {
                return rtrim(substr($line, 0, $i));
            }
        }

        return $line;
    }
}
