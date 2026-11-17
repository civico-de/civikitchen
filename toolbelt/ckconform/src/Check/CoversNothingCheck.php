<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\PhpSource;
use CiviKitchen\Ckconform\Reporter;

/**
 * A test class annotated `@coversNothing`, which silently records zero coverage
 * (civix's headless template carries it). A warning: the annotation is legal.
 */
final class CoversNothingCheck implements Check
{
    public function name(): string
    {
        return 'covers-nothing';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        foreach ($context->tracked('*.php') as $file) {
            $source = $this->isTestFile($file) ? $context->read($file) : null;
            if ($source === null || stripos($source, 'coversNothing') === false) {
                continue;
            }
            foreach ($this->coversNothing($source) as $line) {
                $reporter->warnAt($file, $line, sprintf(
                    '%s:%d: @coversNothing / #[CoversNothing] makes this test count toward no coverage — civix ships it'
                    . ' in the headless test template; left in, the suite runs green while measuring 0%%',
                    $file,
                    $line,
                ));
            }
        }
    }

    /**
     * Lines where a docblock carrying the annotation, or an attribute group with
     * the attribute, starts; a plain comment mentioning it is neither.
     *
     * @return list<int>
     */
    private function coversNothing(string $source): array
    {
        $lines = [];
        // Bracket depth inside an attribute group; a name is the attribute's own
        // only right after `#[` or a top-level `,`, not inside its arguments.
        $depth = 0;
        $group = 0;
        $expectName = false;
        foreach (@\PhpToken::tokenize($source) as $token) {
            if ($token->is(T_DOC_COMMENT) && preg_match('/@coversNothing(?![\w-])/', $token->text) === 1) {
                $lines[] = $token->line;
            }
            if ($token->is(T_ATTRIBUTE)) {
                [$depth, $group, $expectName] = [1, $token->line, true];
            } elseif ($depth === 0 || $token->isIgnorable()) {
                continue;
            } elseif ($token->is(['[', '('])) {
                $depth++;
            } elseif ($token->is([']', ')'])) {
                $depth--;
            } elseif ($depth === 1 && $token->is(',')) {
                $expectName = true;
            } elseif ($expectName && $depth === 1 && PhpSource::shortName($token) !== null) {
                $expectName = false;
                if (strcasecmp((string) PhpSource::shortName($token), 'CoversNothing') === 0) {
                    $lines[] = $group;
                }
            }
        }

        return $lines;
    }

    private function isTestFile(string $file): bool
    {
        if (!str_contains($file, '/tests/') && !str_starts_with($file, 'tests/')) {
            return false;
        }

        return str_ends_with($file, 'Test.php');
    }
}
