<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\PHPStan\ExtensionBoundary;
use PHPUnit\Framework\TestCase;

/**
 * The boundary rule end to end: phpat rules are assembled by phpat's own
 * container, so the fixture extension is analysed by a real phpstan run
 * with the shipped extension.neon, exactly as the image does it.
 */
final class ArchitectureTestTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/architecture';
    private const CONSUMER = self::FIXTURES . '/de.example.consumer';
    private const IDENTIFIER = 'phpat.testOnlyCoreAndOwnCivicrmDependencies';

    public function testDeclaredRequirementsAreAllowedAndEverythingElseIsReported(): void
    {
        $errors = $this->analyse(self::FIXTURES . '/ext-dir');

        // Own, core, core-extension (search_kit), sibling checkout, CI sibling
        // mount and image ext dir pass; unknown, key-mismatched and undeclared
        // extensions are reported.
        self::assertSame(
            [
                17 => 'CRM_Consumer_Uses should not depend on CRM_Missing_Thing',
                18 => 'CRM_Consumer_Uses should not depend on CRM_Mismatch_Thing',
                19 => 'CRM_Consumer_Uses should not depend on CRM_Unrelated_Thing',
            ],
            array_map(static fn (array $e) => $e['message'], $errors),
        );
        $tip = $errors[17]['tip'];
        self::assertStringContainsString('required but not found beside this extension: de.example.missing, de.example.mismatch', $tip);
        self::assertStringContainsString('.civikitchen-siblings/<key> and ' . self::FIXTURES . '/ext-dir/<key>', $tip);
    }

    public function testARequirementOnlyPresentUnderTheExtDirIsReportedWhenThatDirIsElsewhere(): void
    {
        $errors = $this->analyse(self::FIXTURES . '/nowhere');

        self::assertSame([16, 17, 18, 19], array_keys($errors));
        self::assertSame('CRM_Consumer_Uses should not depend on CRM_Mounted_Thing', $errors[16]['message']);
        self::assertStringContainsString('de.example.mounted', $errors[16]['tip']);
    }

    public function testRequiredExtensionKeysComeFromParsedXml(): void
    {
        self::assertSame(
            ['de.example.provider', 'de.example.cisibling', 'de.example.mounted', 'de.example.missing', 'de.example.mismatch', 'org.civicrm.search_kit'],
            ExtensionBoundary::requiredExtensionKeys(self::CONSUMER),
        );
        self::assertSame([], ExtensionBoundary::requiredExtensionKeys(self::FIXTURES . '/de.example.provider'));
        self::assertSame([], ExtensionBoundary::requiredExtensionKeys(self::FIXTURES));
    }

    public function testLocateExtensionTriesSiblingThenCiMountThenExtDirAndChecksTheKey(): void
    {
        $previous = getenv('CK_EXT_DIR');
        putenv('CK_EXT_DIR=' . self::FIXTURES . '/ext-dir');
        try {
            self::assertSame(self::FIXTURES . '/de.example.provider', ExtensionBoundary::locateExtension(self::CONSUMER, 'de.example.provider'));
            self::assertSame(self::CONSUMER . '/.civikitchen-siblings/de.example.cisibling', ExtensionBoundary::locateExtension(self::CONSUMER, 'de.example.cisibling'));
            self::assertSame(self::FIXTURES . '/ext-dir/de.example.mounted', ExtensionBoundary::locateExtension(self::CONSUMER, 'de.example.mounted'));
            self::assertNull(ExtensionBoundary::locateExtension(self::CONSUMER, 'de.example.missing'));
            // The directory exists but its info.xml declares another key.
            self::assertNull(ExtensionBoundary::locateExtension(self::CONSUMER, 'de.example.mismatch'));
        } finally {
            putenv($previous === false ? 'CK_EXT_DIR' : 'CK_EXT_DIR=' . $previous);
        }
    }

    public function testPrefixRegexesDeriveFromTreesClassloaderAndCivixNamespace(): void
    {
        self::assertSame(
            ['~^CRM_Consumer(_|$)~'],
            ExtensionBoundary::prefixRegexes(self::CONSUMER),
        );
        self::assertSame(
            ['~^Civi\\\\CiSibling(\\\\|$)~'],
            ExtensionBoundary::prefixRegexes(self::CONSUMER . '/.civikitchen-siblings/de.example.cisibling'),
        );
        self::assertSame([], ExtensionBoundary::prefixRegexes(self::FIXTURES));
    }

    /**
     * Runs phpstan on the consumer fixture with CK_EXT_DIR set, and returns
     * the boundary rule's errors keyed by line. The result cache is cleared
     * first: the environment is not part of phpstan's cache key.
     *
     * @return array<int, array{message: string, tip: string}>
     */
    private function analyse(string $extDir): array
    {
        $phpstan = dirname(__DIR__, 3) . '/vendor/bin/phpstan';
        $env = 'CK_EXT_DIR=' . escapeshellarg($extDir);
        $cd = 'cd ' . escapeshellarg(self::CONSUMER);
        exec(sprintf('%s && %s %s clear-result-cache -c phpstan.neon 2>&1', $cd, escapeshellarg(PHP_BINARY), escapeshellarg($phpstan)), $cleared, $status);
        self::assertSame(0, $status, 'clear-result-cache failed: ' . implode("\n", $cleared));

        exec(sprintf(
            '%s && %s %s %s analyse --no-progress --error-format=json -c phpstan.neon 2>/dev/null',
            $cd,
            $env,
            escapeshellarg(PHP_BINARY),
            escapeshellarg($phpstan),
        ), $output);
        $report = json_decode(implode("\n", $output), true);
        self::assertIsArray($report, 'phpstan produced no JSON report: ' . implode("\n", $output));
        self::assertSame([], $report['errors'], 'phpstan reported non-file errors');

        $errors = [];
        foreach ($report['files'] as $file) {
            foreach ($file['messages'] as $message) {
                self::assertSame(self::IDENTIFIER, $message['identifier'], 'unexpected error: ' . $message['message']);
                $errors[$message['line']] = ['message' => $message['message'], 'tip' => $message['tip']];
            }
        }
        ksort($errors);

        return $errors;
    }
}
