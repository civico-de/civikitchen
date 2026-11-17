<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\Suppressions;

/**
 * A Playwright job that keeps no diagnostics on failure: trace not
 * `retain-on-failure`, no reporter, or no `if: always()` report upload in the
 * same job. Jobs that call a reusable workflow are exempt.
 */
final class PlaywrightDiagnosticsCheck implements Check
{
    private const FAILURE_TOLERANT = ['always(', '!cancelled(', 'failure('];

    /** The CLI's test command, also behind npx, a path or `ck playwright`; not `@playwright/test`. */
    private const SUITE_RUN = '/(?<![\w@.-])playwright\s+test(?![\w-])/';

    public function name(): string
    {
        return 'playwright-diagnostics';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        $configs = array_values(array_filter(
            $context->trackedFiles(),
            static fn (string $f): bool
                => preg_match('/^playwright\.config\.(ts|js|mjs)$/', basename($f)) === 1
        ));
        if ($configs === []) {
            return;
        }

        $problems = [];
        foreach ($configs as $config) {
            $body = $context->read($config) ?? '';
            $code = $this->javascriptCode($this->exportedConfig($body));
            $topLevelCode = $this->topLevelObjectCode($code);
            $useCode = $this->topLevelPropertyObject($code, 'use');
            $suppressions = Suppressions::ofLineComments($body);
            // 'on' and the retain-on-* modes keep the first failing run; the
            // *-retry modes leave it traceless and don't count.
            if (!$this->traceRecordsOnFailure($this->topLevelPropertyValue($useCode, 'trace'))
                && !$suppressions->suppressed($this->name(), 1)
            ) {
                $problems[] = $config . ': no retain-on-failure trace';
            }
            if (!$this->reporterWritesHtml($this->topLevelPropertyValue($code, 'reporter'))
                && !$suppressions->suppressed($this->name(), 1)
            ) {
                $problems[] = $config . ': no reporter that writes HTML, so no playwright-report/ is written';
            }
        }

        $problems = array_merge($problems, $this->ciProblems($context));

        if ($problems !== []) {
            $reporter->fail('playwright leaves nothing to debug with: ' . implode('; ', $problems));
        } else {
            $reporter->ok('playwright records a trace and CI keeps it');
        }
    }

    /**
     * @return list<string>
     */
    private function ciProblems(Context $context): array
    {
        $jobs = [];
        foreach ($context->scopedWorkflows() as $label => $body) {
            $body = $this->stripComments($body);
            // In a monorepo the scoped text is the one caller job that runs this
            // extension, so there is nothing left to split.
            if ($context->isMonorepo()) {
                $jobs[$label] = $body;
                continue;
            }
            foreach ($context->workflowJobs($body) as $name => $text) {
                $jobs[$label . ':' . $name] = $text;
            }
        }

        // If a workflow actually invokes Playwright but its jobs cannot be
        // split, fail closed: an estate-wide upload may be on another runner.
        if ($jobs === []) {
            foreach ($context->scopedWorkflows() as $label => $body) {
                if ($this->runsPlaywright($this->stripComments($body), $context)) {
                    return [$label . ': runs playwright but its jobs could not be parsed'];
                }
            }
            return [];
        }

        $playwrightJobs = array_filter(
            $jobs,
            fn (string $t): bool => $this->runsPlaywright($t, $context) && !$this->callsReusableWorkflow($t),
        );
        if ($playwrightJobs === []) {
            return [];
        }

        $problems = [];
        foreach ($playwrightJobs as $id => $text) {
            $upload = $this->reportUploadStep($text);
            if ($upload === null) {
                $problems[] = $id . ' runs playwright but does not upload its report in the same job';
            } elseif (!$this->runsOnFailure($upload)) {
                $problems[] = $id . ' uploads the report without if: always(), so a failed run skips it';
            }
        }

        return $problems;
    }

    private function runsPlaywright(string $text, Context $context): bool
    {
        if (preg_match(self::SUITE_RUN, $text) === 1) {
            return true;
        }
        preg_match_all('/(?:npm|yarn|pnpm|bun)\s+(?:run\s+)?([A-Za-z0-9:_-]+)/', $text, $matches);
        $wanted = array_unique($matches[1]);
        if ($wanted === []) {
            return false;
        }
        foreach ($context->tracked('package.json', Context::outsideNodeModules(...)) as $manifest) {
            $scripts = $context->json($manifest)['scripts'] ?? null;
            if (!is_array($scripts)) {
                continue;
            }
            foreach ($wanted as $name) {
                $visiting = [];
                if ($this->scriptRunsPlaywright($name, $scripts, $visiting)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $scripts @param array<string, true> $visiting */
    private function scriptRunsPlaywright(string $name, array $scripts, array &$visiting): bool
    {
        if (isset($visiting[$name]) || !is_string($scripts[$name] ?? null)) {
            return false;
        }
        $visiting[$name] = true;
        $command = $scripts[$name];
        if (preg_match(self::SUITE_RUN, $command) === 1) {
            unset($visiting[$name]);
            return true;
        }
        preg_match_all('/(?:npm|yarn|pnpm|bun)\s+(?:run\s+)?([A-Za-z0-9:_-]+)/', $command, $matches);
        foreach (array_unique($matches[1]) as $dependency) {
            if ($this->scriptRunsPlaywright($dependency, $scripts, $visiting)) {
                unset($visiting[$name]);
                return true;
            }
        }
        unset($visiting[$name]);
        return false;
    }

    /**
     * A caller job: `uses:` as a job key (four-space indent, not a `- ` step).
     */
    private function callsReusableWorkflow(string $jobText): bool
    {
        $minimum = null;
        foreach (explode("\n", $jobText) as $line) {
            if (trim($line) === '') continue;
            $indent = strlen($line) - strlen(ltrim($line));
            $minimum = $minimum === null ? $indent : min($minimum, $indent);
        }
        if ($minimum === null) return false;
        foreach (explode("\n", $jobText) as $line) {
            $indent = strlen($line) - strlen(ltrim($line));
            if ($indent === $minimum && preg_match('/^\s*uses:\s*\S/', $line) === 1) return true;
        }
        return false;
    }

    private function mentionsUpload(string $text): bool
    {
        return str_contains($text, 'upload-artifact');
    }

    private function mentionsReportPath(string $text): bool
    {
        $lines = explode("\n", $text);
        foreach ($lines as $index => $line) {
            if (preg_match('/^(\s*)path:\s*(.*)$/', $line, $match) !== 1) continue;
            $value = trim($match[2]);
            if (preg_match('/^[|>][-+]?$/', $value) === 1) {
                $indent = strlen($match[1]);
                $value = '';
                for ($i = $index + 1, $count = count($lines); $i < $count; $i++) {
                    $nextIndent = strlen($lines[$i]) - strlen(ltrim($lines[$i]));
                    if (trim($lines[$i]) !== '' && $nextIndent <= $indent) break;
                    $value .= "\n" . trim($lines[$i]);
                }
            }
            foreach (preg_split('/\s+/', trim($value, " \t\n\r\0\x0B\"'")) ?: [] as $path) {
                if (preg_match('#(?:^|/)(?:playwright-report|test-results)(?:/|\*|$)#', $path) === 1) return true;
            }
        }
        return false;
    }

    /**
     * The step block within a job that uploads the report, or null.
     */
    private function reportUploadStep(string $jobText): ?string
    {
        foreach ($this->steps($jobText) as $step) {
            if ($this->mentionsUpload($step) && $this->mentionsReportPath($step)) {
                return $step;
            }
        }

        return null;
    }

    private function runsOnFailure(string $step): bool
    {
        if (preg_match('/^\s*if:\s*(.+)$/m', $step, $match) !== 1) {
            return false;
        }
        foreach (self::FAILURE_TOLERANT as $token) {
            if (str_contains($match[1], $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A job body split into its step blocks; lists under `services:` or
     * `strategy:` are not steps.
     *
     * @return list<string>
     */
    private function steps(string $jobText): array
    {
        $steps = [];
        $current = null;
        $indent = null;
        $stepsIndent = null;
        foreach (explode("\n", $jobText) as $line) {
            $lineIndent = strlen($line) - strlen(ltrim($line));
            if (preg_match('/^(\s*)steps:\s*$/', $line) === 1) {
                $stepsIndent = $lineIndent;
                continue;
            }
            if ($stepsIndent === null || (trim($line) !== '' && $lineIndent <= $stepsIndent && !str_starts_with(ltrim($line), '- '))) {
                $stepsIndent = null;
                continue;
            }
            if (preg_match('/^(\s+)-(?:\s|$)/', $line, $match) === 1
                && ($indent === null || strlen($match[1]) === $indent)) {
                if ($current !== null) {
                    $steps[] = implode("\n", $current);
                }
                $current = [$line];
                $indent = strlen($match[1]);
                continue;
            }
            if ($current !== null) {
                $current[] = $line;
            }
        }
        if ($current !== null) {
            $steps[] = implode("\n", $current);
        }

        return $steps;
    }

    /**
     * Drop `#` comments (at line start or after whitespace) so a commented-out
     * step cannot satisfy or trip the check.
     */
    private function stripComments(string $body): string
    {
        $lines = [];
        foreach (explode("\n", $body) as $line) {
            $lines[] = (string) preg_replace('/(^|\s)#.*$/', '$1', $line);
        }

        return implode("\n", $lines);
    }

    /**
     * Only the object literal exported as the Playwright config (ESM direct or
     * variable export, CommonJS module.exports).
     */
    private function exportedConfig(string $body): string
    {
        $structure = $this->javascriptStructure($body);
        $pattern = '/(?:\bexport\s+default\b|\bmodule\.exports\s*=)(?:\s+defineConfig\s*\()?\s*\{/m';
        if (preg_match($pattern, $structure, $match, PREG_OFFSET_CAPTURE) !== 1) {
            $exported = '/(?:\bexport\s+default\s+|\bmodule\.exports\s*=\s*)([A-Za-z_$][A-Za-z0-9_$]*)\s*(?:;|$)/m';
            if (preg_match($exported, $structure, $export) !== 1) {
                return '';
            }
            $name = preg_quote($export[1], '/');
            $declared = '/\b(?:const|let|var)\s+' . $name . '\s*(?::\s*[A-Za-z_$][\w$.<>, ]*)?=\s*(?:defineConfig\s*\()?\s*\{/m';
            if (preg_match($declared, $structure, $match, PREG_OFFSET_CAPTURE) !== 1) {
                return '';
            }
        }
        $matched = $match[0][0];
        $offset = $match[0][1];
        $start = $offset + (int) strrpos($matched, '{');
        $depth = 0;
        $length = strlen($structure);
        for ($i = $start; $i < $length; $i++) {
            if ($structure[$i] === '{') {
                $depth++;
            } elseif ($structure[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($body, $start, $i - $start + 1);
                }
            }
        }

        return '';
    }

    /** Keep only properties on the exported object's outermost level. */
    private function topLevelObjectCode(string $code): string
    {
        $structure = $this->javascriptStructure($code);
        $depth = 0;
        $result = '';
        $length = strlen($code);
        for ($i = 0; $i < $length; $i++) {
            $char = $structure[$i];
            if ($char === '{') {
                $depth++;
                $result .= $depth === 1 ? $code[$i] : ' ';
            } elseif ($char === '}') {
                $result .= $depth === 1 ? $code[$i] : ' ';
                $depth--;
            } else {
                $result .= $depth <= 1 || $code[$i] === "\n" ? $code[$i] : ' ';
            }
        }
        return $result;
    }

    /** Extract a top-level property expression, stopping at its outer comma. */
    private function topLevelPropertyValue(string $code, string $property): string
    {
        $structure = $this->javascriptStructure($code);
        $key = preg_quote($property, '/');
        $pattern = '/(?:\b' . $key . '\b|[\'\"]' . $key . '[\'\"])\s*:/m';
        if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE) === false) {
            return '';
        }
        foreach ($matches[0] as [$matched, $offset]) {
            $depth = 0;
            for ($i = 0; $i < $offset; $i++) {
                if ($structure[$i] === '{') $depth++;
                elseif ($structure[$i] === '}') $depth--;
            }
            if ($depth !== 1 || trim(substr($structure, $offset, strlen($matched))) === '') continue;
            $start = $offset + strlen($matched);
            $brace = $bracket = $paren = 0;
            for ($i = $start, $length = strlen($code); $i < $length; $i++) {
                $char = $structure[$i];
                if ($char === '{') $brace++;
                elseif ($char === '[') $bracket++;
                elseif ($char === '(') $paren++;
                elseif ($char === '}') {
                    if ($brace === 0 && $bracket === 0 && $paren === 0) return trim(substr($code, $start, $i - $start));
                    $brace--;
                } elseif ($char === ']') $bracket--;
                elseif ($char === ')') $paren--;
                elseif ($char === ',' && $brace === 0 && $bracket === 0 && $paren === 0) {
                    return trim(substr($code, $start, $i - $start));
                }
            }
        }
        return '';
    }

    /** True for a direct html reporter or an html entry/tuple in its array. */
    private function reporterWritesHtml(string $value): bool
    {
        if ($value === '') return false;
        $structure = $this->javascriptStructure($value);
        if (str_contains($structure, '?')) {
            $question = strpos($structure, '?');
            $prefix = trim(substr($value, 0, $question));
            $brace = $bracket = $paren = 0;
            $colon = null;
            for ($i = $question + 1, $length = strlen($value); $i < $length; $i++) {
                $char = $structure[$i];
                if ($char === '{') $brace++;
                elseif ($char === '}') $brace--;
                elseif ($char === '[') $bracket++;
                elseif ($char === ']') $bracket--;
                elseif ($char === '(') $paren++;
                elseif ($char === ')') $paren--;
                elseif ($char === ':' && $brace === 0 && $bracket === 0 && $paren === 0) { $colon = $i; break; }
            }
            if ($colon === null) return false;
            $whenTrue = substr($value, $question + 1, $colon - $question - 1);
            $whenFalse = substr($value, $colon + 1);
            if (preg_match('/^process\.env\.CI$/', $prefix) === 1) return $this->reporterWritesHtml($whenTrue);
            if (preg_match('/^!\s*process\.env\.CI$/', $prefix) === 1) return $this->reporterWritesHtml($whenFalse);
            return false;
        }
        if (preg_match_all('/[\'\"]html[\'\"]/', $value, $matches, PREG_OFFSET_CAPTURE) === false) {
            return false;
        }
        foreach ($matches[0] as [$matched, $offset]) {
            $brace = $bracket = 0;
            for ($i = 0; $i < $offset; $i++) {
                if ($structure[$i] === '{') $brace++;
                elseif ($structure[$i] === '}') $brace--;
                elseif ($structure[$i] === '[') $bracket++;
                elseif ($structure[$i] === ']') $bracket--;
            }
            if ($brace !== 0) continue;
            if ($bracket === 0 || $bracket === 1) return true;
            if ($bracket === 2) {
                $before = rtrim(substr($value, 0, $offset));
                if ($before !== '' && substr($before, -1) === '[') return true;
            }
        }
        return false;
    }

    private function traceRecordsOnFailure(string $value): bool
    {
        $accepted = '/^[\'\"](?:retain-on-failure|retain-on-failure-and-retries|retain-on-first-failure|on)[\'\"]$/';
        $value = trim($value);
        if (preg_match($accepted, $value) === 1) return true;
        if (str_starts_with($value, '{')) {
            return preg_match($accepted, trim($this->topLevelPropertyValue($value, 'mode'))) === 1;
        }
        return false;
    }

    /** Extract an object-valued property from the exported object's top level. */
    private function topLevelPropertyObject(string $code, string $property): string
    {
        $structure = $this->javascriptStructure($code);
        $key = preg_quote($property, '/');
        $pattern = '/(?:\b' . $key . '\b|[\'\"]' . $key . '[\'\"])\s*:\s*\{/m';
        if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE) === false) {
            return '';
        }
        foreach ($matches[0] as [$matched, $offset]) {
            $depth = 0;
            for ($i = 0; $i < $offset; $i++) {
                if ($structure[$i] === '{') $depth++;
                elseif ($structure[$i] === '}') $depth--;
            }
            if ($depth !== 1) continue;
            $start = $offset + (int) strrpos($matched, '{');
            if (($structure[$start] ?? '') !== '{') continue;
            $objectDepth = 0;
            for ($i = $start, $length = strlen($structure); $i < $length; $i++) {
                if ($structure[$i] === '{') $objectDepth++;
                elseif ($structure[$i] === '}' && --$objectDepth === 0) {
                    return substr($code, $start, $i - $start + 1);
                }
            }
        }
        return '';
    }

    /** Mask comments, templates and quoted strings while preserving offsets. */
    private function javascriptStructure(string $body): string
    {
        $code = $this->javascriptCode($body);
        $result = '';
        $state = 'code';
        $length = strlen($code);
        for ($i = 0; $i < $length; $i++) {
            $char = $code[$i];
            if ($state === 'single' || $state === 'double') {
                if ($char === '\\' && $i + 1 < $length) {
                    $result .= '  ';
                    $i++;
                    continue;
                }
                if (($state === 'single' && $char === "'") || ($state === 'double' && $char === '"')) {
                    $state = 'code';
                }
                $result .= $char === "\n" ? "\n" : ' ';
                continue;
            }
            if ($char === "'") {
                $state = 'single';
                $result .= ' ';
            } elseif ($char === '"') {
                $state = 'double';
                $result .= ' ';
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    /**
     * Mask JavaScript comments and template literals, keeping code, quoted
     * strings, newlines and byte positions.
     */
    private function javascriptCode(string $body): string
    {
        $result = '';
        $state = 'code';
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];
            $next = $body[$i + 1] ?? '';
            if ($state === 'line-comment') {
                if ($char === "\n") {
                    $state = 'code';
                    $result .= "\n";
                } else {
                    $result .= ' ';
                }
                continue;
            }
            if ($state === 'block-comment') {
                if ($char === '*' && $next === '/') {
                    $result .= '  ';
                    $i++;
                    $state = 'code';
                } else {
                    $result .= $char === "\n" ? "\n" : ' ';
                }
                continue;
            }
            if ($state === 'template') {
                if ($char === '\\') {
                    $result .= ' ';
                    if ($i + 1 < $length) {
                        $result .= $body[$i + 1] === "\n" ? "\n" : ' ';
                        $i++;
                    }
                } elseif ($char === '`') {
                    $result .= ' ';
                    $state = 'code';
                } else {
                    $result .= $char === "\n" ? "\n" : ' ';
                }
                continue;
            }
            if ($state === 'single' || $state === 'double') {
                $result .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $result .= $body[++$i];
                    continue;
                }
                if (($state === 'single' && $char === "'") || ($state === 'double' && $char === '"')) {
                    $state = 'code';
                }
                continue;
            }
            if ($char === '/' && $next === '/') {
                $result .= '  ';
                $i++;
                $state = 'line-comment';
            } elseif ($char === '/' && $next === '*') {
                $result .= '  ';
                $i++;
                $state = 'block-comment';
            } elseif ($char === '`') {
                $result .= ' ';
                $state = 'template';
            } elseif ($char === "'") {
                $result .= $char;
                $state = 'single';
            } elseif ($char === '"') {
                $result .= $char;
                $state = 'double';
            } else {
                $result .= $char;
            }
        }

        return $result;
    }
}
