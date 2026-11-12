<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Ab;

use RuntimeException;

/** Reads the outcome of every test case from a JUnit report, as phpunit --log-junit writes it. */
final class JUnit
{
    public const PASSED = 'passed';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    /** @return array<string, self::PASSED|self::FAILED|self::SKIPPED> by "Class::test name" */
    public static function outcomes(string $file): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $report = simplexml_load_file($file);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($report === false) {
            throw new RuntimeException("{$file} is not a JUnit report");
        }
        $outcomes = [];
        foreach ($report->xpath('//testcase') ?: [] as $case) {
            $class = (string) ($case['class'] ?? $case['classname'] ?? '');
            $name = ($class === '' ? '' : "{$class}::") . (string) $case['name'];
            $outcomes[$name] = match (true) {
                isset($case->failure), isset($case->error) => self::FAILED,
                isset($case->skipped) => self::SKIPPED,
                default => self::PASSED,
            };
        }
        return $outcomes;
    }
}
