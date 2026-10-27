<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\PolicyKeyCheck;
use CiviKitchen\Ckconform\PhpSource;
use CiviKitchen\Ckconform\Policy;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class PolicyKeyCheckTest extends CheckTestCase
{
    public function testNoPolicyFileIsSilent(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([]));
        $this->assertSilent($reporter);
    }

    public function testKnownKeysPass(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '__policy_fixture' => "# policy\nmin_coverage=70\ntests=optional -- config only\ndist_exclude=build\n",
        ]));
        $this->assertPasses($reporter);
    }

    /** The bug this check exists for: a typo disables a floor in silence. */
    public function testTypoedKeyFails(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '__policy_fixture' => "min_covrage=70\n",
        ]));
        $this->assertFails($reporter, 'unknown property min_covrage');
    }

    public function testUnrelatedUnknownKeyFailsWithoutASuggestion(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '__policy_fixture' => "enable_everything=yes\n",
        ]));
        $this->assertFails($reporter, 'unknown property enable_everything');
        self::assertStringNotContainsString('did you mean', $reporter->render());
    }

    /**
     * @dataProvider badPercentages
     */
    public function testNonNumericPercentageFails(string $value): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '__policy_fixture' => "min_coverage={$value}\n",
        ]));
        $this->assertFails($reporter, 'coverage.minimum');
    }

    /** @return array<string, list<string>> */
    public static function badPercentages(): array
    {
        return [
            'a word' => ['seventy'],
            'a percent sign' => ['70%'],
            'over 100' => ['101'],
            'a fraction' => ['70.5'],
        ];
    }

    /** A reason on a numeric key is legal, and must not be compared as part of the number. */
    public function testPercentageWithAReasonPasses(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '__policy_fixture' => "min_coverage=70 -- measured 2026-01, ratchet from here\n",
        ]));
        $this->assertPasses($reporter);
    }

    /** A pin the entrypoint can hand to cv ext:download verbatim, repeated per dependency. */
    public function testExtensionSourcesPass(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '__policy_fixture' => "extension_source=org.project60.banking@https://example.org/banking-1.4.1.zip -- not in the feed\n"
                . "extension_source=de.systopia.xcm@https://example.org/xcm.zip -- pinned release\n",
        ]));
        $this->assertPasses($reporter);
    }

    /**
     * @dataProvider badSources
     */
    public function testExtensionSourceWithoutKeyAndUrlFails(string $value): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '__policy_fixture' => "extension_source={$value}\n",
        ]));
        $this->assertFails($reporter, 'extension_sources');
    }

    /** @return array<string, list<string>> */
    public static function badSources(): array
    {
        return [
            'a bare key' => ['org.project60.banking'],
            'a bare URL' => ['https://example.org/banking.zip'],
            'a local path' => ['org.project60.banking@/srv/banking.zip'],
            'a space in the URL' => ['org.project60.banking@https://example.org/banking 1.zip'],
        ];
    }

    /** Malformed YAML must fail before any policy can silently disappear. */
    public function testMalformedLineFails(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            'civikitchen.yaml' => "version: 1\npolicy: [\n",
        ]));
        $this->assertFails($reporter, 'invalid civikitchen.yaml');
    }

    public function testValuelessKeyFails(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '__policy_fixture' => "=70\n",
        ]));
        $this->assertFails($reporter, 'unknown property');
    }

    public function testCommentsAndBlankLinesAreNotMalformed(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '__policy_fixture' => "# a comment, no equals sign\n\nmin_coverage=70\n",
        ]));
        $this->assertPasses($reporter);
    }

    /** Duplicate YAML keys are rejected instead of silently using one. */
    public function testRepeatedScalarKeyFails(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            'civikitchen.yaml' => "version: 1\npolicy:\n  license: MIT\n  license: Proprietary\n",
        ]));
        $this->assertFails($reporter, 'Duplicate key');
    }

    public function testRepeatedRepeatableKeyPasses(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '__policy_fixture' => "lifecycle_log_ignore=one -- a\nlifecycle_log_ignore=two -- b\n",
        ]));
        $this->assertPasses($reporter);
    }

    public function testEveryMutationFloorIsValidated(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '__policy_fixture' => "mutation_min_msi=high\nmutation_min_covered_msi=also_high\n",
        ]));
        $this->assertFails($reporter, 'mutation');
    }

    /**
     * The inventory is only useful if it is complete: a key a tool reads but
     * KEYS does not list would be reported as unknown in every repo using it.
     * Every PHP file of the tool belt, read as tokens so prose never counts:
     * the checks' policyValue() calls and Repository\Policy's value()/values().
     */
    public function testInventoryCoversEveryKeyTheToolbeltReads(): void
    {
        $root = dirname(__DIR__, 3);
        $files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => !in_array($file->getFilename(), ['vendor', 'node_modules', 'tests'], true),
        ));
        $read = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $tokens = PhpSource::codeTokens((string) file_get_contents($file->getPathname()));
            foreach ($tokens as $i => $token) {
                if ($token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
                    && in_array($tokens[$i + 1]->text ?? '', ['policyValue', 'policyValues', 'value', 'values'], true)
                    && ($tokens[$i + 2] ?? null)?->text === '('
                    && ($tokens[$i + 3] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING)
                ) {
                    $read[] = trim($tokens[$i + 3]->text, '\'"');
                }
            }
        }

        self::assertContains('mutation_min_msi', $read, 'the scan misses Repository\Policy in the tool belt');
        $missing = array_diff(array_unique($read), array_keys(Policy::KEYS));
        self::assertSame(
            [],
            array_values($missing),
            'read by the toolbelt but missing from Policy::KEYS: ' . implode(', ', $missing),
        );
    }
}
