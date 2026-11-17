<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\PhpSource;
use CiviKitchen\Ckconform\Reporter;

/**
 * A CRM_ class (DAO files included) whose file is not where PSR-0 says it is,
 * compared case-exactly through git: macOS resolves a case slip, Linux does not.
 */
final class Psr0ClassPathCheck implements Check
{
    public function name(): string
    {
        return 'psr0-class-path';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        $misfiled = [];
        $extras = [];
        $checked = false;
        foreach ($context->trackedFiles() as $file) {
            if (preg_match('#(?:^|/)CRM/.+\.php$#', $file) !== 1) {
                continue;
            }
            $source = $context->read($file);
            if ($source === null) {
                continue;
            }
            $classes = $this->crmClasses($source);
            $atPath = array_values(array_filter($classes, fn (string $class): bool => $this->expected($class, $file) === null));
            // The file's own class: the one at this path, else the first.
            $primary = $atPath[0] ?? $classes[0] ?? null;
            foreach ($classes as $class) {
                $checked = true;
                $expected = $this->expected($class, $file);
                if ($expected !== null && $class === $primary) {
                    $misfiled[] = $class . ' is in ' . $file . ', PSR-0 wants …/' . $expected;
                } elseif ($expected !== null && !str_starts_with($file, 'tests/') && !str_contains($file, '/tests/')) {
                    // PHPUnit loads test files by path, so a fixture class beside the test is fine.
                    $extras[] = $class . ' beside ' . $primary . ' in ' . $file;
                }
            }
        }

        if ($extras !== []) {
            $reporter->warn(
                'CRM_ classes sharing another class\'s file: ' . implode('; ', $extras)
                . ' — the autoloader finds them only once that class is loaded; give each its own PSR-0 file'
            );
        }

        if (!$checked) {
            return;
        }

        if ($misfiled !== []) {
            $reporter->fail(
                'CRM_ classes not at their PSR-0 path: ' . implode('; ', $misfiled)
                . ' — a case-only drift loads on macOS and fails on Linux'
            );
        } else {
            $reporter->ok('every CRM_ class sits at its PSR-0 path');
        }
    }

    /** The path PSR-0 wants for $class, or null when $file is it. */
    private function expected(string $class, string $file): ?string
    {
        $expected = str_replace('_', '/', $class) . '.php';

        return str_ends_with($file, '/' . $expected) || $file === $expected ? null : $expected;
    }

    /**
     * Every CRM_-prefixed class/interface/trait/enum a file declares at the top
     * level; one inside a block (`if (!class_exists(…))`) is a guarded polyfill.
     *
     * @return list<string>
     */
    private function crmClasses(string $source): array
    {
        $tokens = PhpSource::codeTokens($source);
        $classes = [];
        $depth = 0;
        foreach ($tokens as $i => $token) {
            $depth += $token->text === '}' ? -1 : ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) ? 1 : 0);
            $name = $tokens[$i + 1] ?? null;
            if ($depth === 0 && $token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && $name !== null && $name->is(T_STRING)
                && str_starts_with($name->text, 'CRM_') && !($tokens[$i - 1] ?? null)?->is(T_DOUBLE_COLON)
            ) {
                $classes[] = $name->text;
            }
        }

        return $classes;
    }
}
