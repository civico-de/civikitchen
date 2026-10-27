<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\PlaywrightDiagnosticsCheck;
use CiviKitchen\Ckconform\Check\SuppressionHygieneCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class PlaywrightDiagnosticsCheckTest extends CheckTestCase
{
    private const GOOD = <<<'TS'
        export default {
          reporter: process.env.CI ? 'html' : 'list',
          use: { screenshot: 'only-on-failure', trace: 'retain-on-failure', video: 'retain-on-failure' },
        };
        TS;

    private const UPLOAD = <<<'YML'
        jobs:
          e2e:
            steps:
              - run: npx playwright test
              - uses: actions/upload-artifact@v4
                if: always()
                with:
                  path: playwright-report/
        YML;

    public function testSilentWithoutPlaywright(): void
    {
        $this->assertSilent($this->run_(new PlaywrightDiagnosticsCheck(), $this->repo([], git: true)));
    }

    public function testAFullySetUpSuitePasses(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
    }

    public function testCommonJsAndNamedEsmConfigsPass(): void
    {
        foreach ([
            "module.exports = defineConfig({ reporter: 'html', use: { trace: 'retain-on-failure' } });",
            "const config = defineConfig({ reporter: 'html', use: { trace: 'retain-on-failure' } }); export default config;",
        ] as $config) {
            $context = $this->repo([
                'playwright.config.js' => $config,
                '.github/workflows/ci.yml' => self::UPLOAD,
            ], git: true);
            $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
        }
    }

    public function testNestedMetadataCannotImpersonateTopLevelReporter(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => "export default { metadata: { reporter: 'html', trace: 'retain-on-failure' } };",
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'no reporter');
    }

    public function testOnFirstRetryIsNotEnough(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => str_replace('retain-on-failure', 'on-first-retry', self::GOOD),
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'retain-on-failure trace');
    }

    public function testAMissingReporterIsReported(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => "export default { use: { trace: 'retain-on-failure' } };",
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'no reporter');
    }

    public function testRecordingWithoutUploadingIsPointless(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => "jobs:\n  e2e:\n    steps:\n      - run: npx playwright test\n",
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'does not upload its report');
    }

    /**
     * A caller job has no steps to upload from — `playwright: true` is an input
     * to the reusable workflow, and that workflow owns the upload.
     */
    public function testAReusableWorkflowCallerIsExempt(): void
    {
        $caller = <<<'YML'
            jobs:
              compat:
                uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1
                with:
                  key: demo
                  playwright: true
            YML;
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/compat.yml' => $caller . "\n",
        ], git: true);
        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
    }

    /**
     * An upload with no `if:` is skipped precisely when a test failed — the one
     * run whose report is worth keeping.
     */
    public function testAnUploadWithoutIfAlwaysFails(): void
    {
        $noIf = str_replace("        if: always()\n", '', self::UPLOAD);
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => $noIf,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'without if: always()');
    }

    public function testIfFailureIsAlsoAccepted(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => str_replace('if: always()', 'if: failure()', self::UPLOAD),
        ], git: true);
        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
    }

    /**
     * GitHub runners share no filesystem: an upload in a different job than the
     * Playwright run collects nothing.
     */
    public function testAnUploadInAnotherJobDoesNotCount(): void
    {
        $split = <<<'YML'
            jobs:
              e2e:
                steps:
                  - run: npx playwright test
              archive:
                steps:
                  - uses: actions/upload-artifact@v4
                    if: always()
                    with:
                      path: playwright-report/
            YML;
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => $split,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'same job');
    }

    public function testNoUploadDemandedWhenCiDoesNotRunPlaywright(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => "jobs:\n  lint:\n    steps:\n      - run: phpcs\n",
        ], git: true);
        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
    }

    /** A commented-out upload step must not satisfy the check. */
    public function testACommentedUploadDoesNotCount(): void
    {
        $commented = <<<'YML'
            jobs:
              e2e:
                steps:
                  - run: npx playwright test
                  # - uses: actions/upload-artifact@v4
                  #   with:
                  #     path: playwright-report/
            YML;
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => $commented,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'does not upload');
    }

    public function testADoubleQuotedTracePasses(): void
    {
        $config = str_replace("trace: 'retain-on-failure'", 'trace: "retain-on-failure"', self::GOOD);
        $context = $this->repo([
            'playwright.config.ts' => $config,
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
    }

    public function testTheObjectFormOfTracePasses(): void
    {
        $config = str_replace("trace: 'retain-on-failure'", "trace: { mode: 'retain-on-failure' }", self::GOOD);
        $context = $this->repo([
            'playwright.config.ts' => $config,
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
    }

    public function testCredentialBearingConfigCanSuppressDiagnosticsWithAReason(): void
    {
        $live = <<<'TS'
            // ckconform-ignore-file playwright-diagnostics -- live provider uses reusable credentials; traces must not persist them
            export default {
              reporter: 'list',
              use: { trace: 'off' },
            };
            TS;
        $context = $this->repo([
            'tests/e2e/entra-live/playwright.config.mjs' => $live,
            'tests/e2e/mock/playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);

        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
        $this->assertSilent($this->run_(new SuppressionHygieneCheck(), $context));
    }

    public function testSuppressedConfigDoesNotExemptAnotherConfig(): void
    {
        $context = $this->repo([
            'tests/e2e/entra-live/playwright.config.mjs' => "// ckconform-ignore-file playwright-diagnostics -- traces contain reusable credentials\nexport default { reporter: 'list', use: { trace: 'off' } };\n",
            'tests/e2e/mock/playwright.config.ts' => str_replace('retain-on-failure', 'off', self::GOOD),
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);

        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'tests/e2e/mock/playwright.config.ts');
    }

    public function testMissingReasonSuppressesNothingAndIsReported(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => "// ckconform-ignore-file playwright-diagnostics\nexport default { reporter: 'list', use: { trace: 'off' } };\n",
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);

        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'retain-on-failure trace');
        $this->assertWarns($this->run_(new SuppressionHygieneCheck(), $context), 'without a reason');
    }

    public function testStalePlaywrightSuppressionIsReported(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => "// ckconform-ignore-file playwright-diagnostics -- traces contain reusable credentials\n" . self::GOOD,
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);

        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
        $this->assertWarns($this->run_(new SuppressionHygieneCheck(), $context), 'suppressed nothing');
    }

    public function testMarkerInsideATypeScriptStringSuppressesNothing(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => "const note = '// ckconform-ignore-file playwright-diagnostics -- not a comment';\nexport default { reporter: 'list', use: { trace: 'off' } };\n",
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);

        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'retain-on-failure trace');
    }

    public function testCommentedPropertiesDoNotSatisfyDiagnostics(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => "export default {\n  // trace: 'retain-on-failure',\n  trace: 'off',\n  // reporter: 'html',\n};\n",
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);

        $result = $this->run_(new PlaywrightDiagnosticsCheck(), $context);
        $this->assertFails($result, 'retain-on-failure trace');
        $this->assertFails($result, 'no reporter');
    }

    public function testPropertiesInsideStringsDoNotSatisfyDiagnostics(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => "const note = \"trace: 'retain-on-failure'; reporter: 'html'\";\nexport default { trace: 'off' };\n",
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);

        $result = $this->run_(new PlaywrightDiagnosticsCheck(), $context);
        $this->assertFails($result, 'retain-on-failure trace');
        $this->assertFails($result, 'no reporter');
    }

    public function testObjectShapedStringDoesNotSatisfyDiagnostics(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => "const note = \"{ trace: 'retain-on-failure', reporter: 'html' }\";\nexport default {};\n",
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $result = $this->run_(new PlaywrightDiagnosticsCheck(), $context);
        $this->assertFails($result, 'retain-on-failure trace');
        $this->assertFails($result, 'no reporter that writes HTML');
    }

    public function testUnexportedObjectDoesNotSatisfyDiagnostics(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => "const fake = { trace: 'retain-on-failure', reporter: 'html' };\nexport default {};\n",
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $result = $this->run_(new PlaywrightDiagnosticsCheck(), $context);
        $this->assertFails($result, 'retain-on-failure trace');
        $this->assertFails($result, 'no reporter that writes HTML');
    }

    public function testListReporterDoesNotPromiseAnHtmlArtifact(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => "export default { reporter: 'list', use: { trace: 'retain-on-failure' } };\n",
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'no reporter that writes HTML');
    }

    public function testPackageScriptPlaywrightRunStillRequiresUpload(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            'package.json' => '{"scripts":{"test:e2e":"playwright test"}}',
            '.github/workflows/ci.yml' => "jobs:\n  e2e:\n    steps:\n      - run: npm run test:e2e\n",
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'does not upload its report');
    }

    public function testDelegatedPackageScriptPlaywrightRunStillRequiresUpload(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            'package.json' => '{"scripts":{"test":"npm run e2e","e2e":"playwright test"}}',
            '.github/workflows/ci.yml' => "jobs:\n  e2e:\n    steps:\n      - run: npm run test\n",
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'does not upload its report');
    }

    public function testCyclicPackageScriptsDoNotRecurseForever(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            'package.json' => '{"scripts":{"test":"npm run e2e","e2e":"npm run test"}}',
            '.github/workflows/ci.yml' => "jobs:\n  e2e:\n    steps:\n      - run: npm run test\n",
        ], git: true);
        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
    }

    /** An input, a make target, a lint plugin or a browser install runs no suite, so no report is owed. */
    public function testAJobThatOnlyNamesPlaywrightIsNotJudged(): void
    {
        $workflow = <<<'YML'
            jobs:
              images:
                if: inputs.playwright
                steps:
                  - name: Run the Playwright tests elsewhere
                    run: echo "playwright_images=[]" >> "$GITHUB_OUTPUT"
              lint:
                steps:
                  - run: make test-playwright-network
                  - run: npm install @playwright/test
                  - run: npx playwright install --with-deps
                  - run: npm run lint
            YML;
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            'package.json' => '{"scripts":{"lint":"eslint --plugin playwright tests/"}}',
            '.github/workflows/ci.yml' => $workflow . "\n",
        ], git: true);
        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
    }

    /** @return array<string, array{0: string}> */
    public static function suiteRuns(): array
    {
        return [
            'npx with options' => ['npx playwright test --project=chromium'],
            'bin path' => ['./node_modules/.bin/playwright test'],
            'ck playwright' => ['php "$CK" playwright playwright test'],
            'at line end' => ["bunx playwright test\n"],
        ];
    }

    /** @dataProvider suiteRuns */
    public function testEveryFormOfTheTestCommandIsJudged(string $run): void
    {
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => "jobs:\n  e2e:\n    steps:\n      - run: " . trim($run) . "\n",
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'does not upload its report');
    }

    public function testQuotedUseAndReporterKeysPass(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => "export default { 'reporter': 'html', 'use': { 'trace': 'retain-on-failure' } };\n",
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
    }

    public function testNestedFixtureTraceDoesNotCountAsUseTrace(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => "export default { reporter: 'html', use: { fixture: { trace: 'retain-on-failure' } } };\n",
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'retain-on-failure trace');
    }

    public function testHtmlTextInsideReporterOptionsDoesNotCount(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => "export default { reporter: [['json', { outputFile: 'html' }]], use: { trace: 'retain-on-failure' } };\n",
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'no reporter that writes HTML');
    }

    public function testArtifactNameCannotImpersonateReportPath(): void
    {
        $workflow = str_replace('path: playwright-report/', "name: playwright-report\n      path: unrelated.log", self::UPLOAD);
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => $workflow,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'does not upload');
    }

    public function testSameJobInvariantWithFourSpaceJobIndent(): void
    {
        $workflow = <<<'YML'
            jobs:
                e2e:
                    steps:
                      - run: npx playwright test
                archive:
                    steps:
                      - uses: actions/upload-artifact@v4
                        if: always()
                        with:
                          path: playwright-report/
            YML;
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => $workflow,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'same job');
    }

    public function testSameJobInvariantWithQuotedJobIds(): void
    {
        $workflow = <<<'YML'
            jobs:
              "e2e":
                steps:
                  - run: npx playwright test
              'archive':
                steps:
                  - uses: actions/upload-artifact@v4
                    if: always()
                    with:
                      path: playwright-report/
            YML;
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => $workflow,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'same job');
    }

    public function testHtmlReporterOnlyOutsideCiDoesNotCount(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => "export default { reporter: process.env.CI ? 'list' : 'html', use: { trace: 'retain-on-failure' } };\n",
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'no reporter that writes HTML');
    }

    public function testArtifactNameAfterPathCannotImpersonateReportPath(): void
    {
        $workflow = str_replace('path: playwright-report/', "path: unrelated.log\n      name: playwright-report", self::UPLOAD);
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => $workflow,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'does not upload');
    }

    public function testReusableWorkflowWithWideIndentIsExempt(): void
    {
        $workflow = "jobs:\n    compat:\n        uses: org/repo/.github/workflows/e2e.yml@v1\n        with:\n            playwright: true\n";
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => $workflow,
        ], git: true);
        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
    }

    public function testStandaloneDashActionStepDoesNotMakeJobReusable(): void
    {
        $workflow = "jobs:\n  e2e:\n    steps:\n      -\n        run: npx playwright test\n      -\n        uses: actions/checkout@v5\n";
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => $workflow,
        ], git: true);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'does not upload');
    }

    public function testStandaloneDashUploadStepPasses(): void
    {
        $workflow = "jobs:\n  e2e:\n    steps:\n      -\n        run: npx playwright test\n      -\n        uses: actions/upload-artifact@v4\n        if: always()\n        with:\n          path: playwright-report/\n";
        $context = $this->repo([
            'playwright.config.ts' => self::GOOD,
            '.github/workflows/ci.yml' => $workflow,
        ], git: true);
        $this->assertPasses($this->run_(new PlaywrightDiagnosticsCheck(), $context));
    }

    public function testMarkerInsideTemplateLiteralSuppressesNothing(): void
    {
        $context = $this->repo([
            'playwright.config.ts' => "const note = `first\n// ckconform-ignore-file playwright-diagnostics -- not a comment\nlast`;\nexport default { reporter: 'list', use: { trace: 'off' } };\n",
            '.github/workflows/ci.yml' => self::UPLOAD,
        ], git: true);

        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'retain-on-failure trace');
    }

    private const PW_HEALTHY_WORKFLOW = "on: push\njobs:\n  e2e:\n    runs-on: ubuntu-24.04\n    steps:\n      - uses: actions/checkout@v4\n      - run: npx playwright test\n      - uses: actions/upload-artifact@v4\n        if: always()\n        with:\n          path: playwright-report/\n";

    private function playwrightRepo(string $configFile, string $config, string $workflow = self::PW_HEALTHY_WORKFLOW): \CiviKitchen\Ckconform\Context
    {
        return $this->repo([$configFile => $config, '.github/workflows/e2e.yml' => $workflow], git: true);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function exportForms(): array
    {
        $object = "{ reporter: 'html', use: { trace: 'retain-on-failure' } }";
        return [
            'typed constant' => ['playwright.config.ts', "import type { PlaywrightTestConfig } from '@playwright/test';\nconst config: PlaywrightTestConfig = {$object};\nexport default config;\n"],
            'export without semicolon' => ['playwright.config.ts', "const config = defineConfig({$object})\nexport default config\n"],
            'CommonJS' => ['playwright.config.js', "const { defineConfig } = require('@playwright/test');\nconst config = defineConfig({$object});\nmodule.exports = config;\n"],
            'retain-on-failure-and-retries' => ['playwright.config.ts', "export default defineConfig({ reporter: 'html', use: { trace: 'retain-on-failure-and-retries' } });\n"],
            'retain-on-first-failure' => ['playwright.config.ts', "export default defineConfig({ reporter: 'html', use: { trace: 'retain-on-first-failure' } });\n"],
        ];
    }

    /** @dataProvider exportForms */
    public function testHealthyConfigFormsPass(string $file, string $config): void
    {
        $this->assertOk($this->run_(new PlaywrightDiagnosticsCheck(), $this->playwrightRepo($file, $config)), 'playwright records a trace');
    }

    public function testOnFirstRetryStillFails(): void
    {
        $context = $this->playwrightRepo('playwright.config.ts', "export default defineConfig({ reporter: 'html', use: { trace: 'on-first-retry' } });\n");
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'no retain-on-failure trace');
    }

    private const PW_SERVICES = "on: push\njobs:\n  e2e:\n    runs-on: ubuntu-24.04\n    services:\n      mysql:\n        image: mysql:8.0\n        ports:\n          - 3306:3306\n    steps:\n      - uses: actions/checkout@v4\n";

    public function testAServicesListBeforeTheStepsIsNoStep(): void
    {
        $workflow = self::PW_SERVICES . "      - run: npm ci\n        if: success()\n      - run: npx playwright test\n      - uses: actions/upload-artifact@v4\n        if: always()\n        with:\n          path: playwright-report/\n";
        $context = $this->playwrightRepo('playwright.config.ts', "export default defineConfig({ reporter: 'html', use: { trace: 'retain-on-failure' } });\n", $workflow);
        $this->assertOk($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'playwright records a trace');
    }

    public function testAnEarlierStepsIfDoesNotCoverTheUpload(): void
    {
        $workflow = self::PW_SERVICES . "      - run: npx playwright test\n        if: always()\n      - uses: actions/upload-artifact@v4\n        with:\n          path: playwright-report/\n";
        $context = $this->playwrightRepo('playwright.config.ts', "export default defineConfig({ reporter: 'html', use: { trace: 'retain-on-failure' } });\n", $workflow);
        $this->assertFails($this->run_(new PlaywrightDiagnosticsCheck(), $context), 'uploads the report without if: always()');
    }
}
