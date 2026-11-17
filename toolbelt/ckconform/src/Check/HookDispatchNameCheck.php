<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\HookCatalog;
use CiviKitchen\Ckconform\HookSurface;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\Suppressions;

/**
 * A hook implementation or listener name that never fires: a wrong prefix or a
 * removed hook fails, a deprecated hook or unknown suffix warns. Unknown
 * third-party hooks come from <requires> extensions on disk or `known_hooks`.
 */
final class HookDispatchNameCheck implements Check
{
    /**
     * Hooks CiviCRM has removed: suffix => guidance. Hand-verified: each is
     * absent from CRM/, Civi/, api/ and ext/, ignoring CRM/Upgrade/Incremental/.
     *
     * @var array<string, string>
     */
    private const REMOVED_HOOKS = [
        'alterMail' => 'removed from core — use hook_civicrm_alterMailParams',
        'crudLink' => 'removed in 5.33',
        'customFieldOptions' => 'removed in 5.78 — use hook_civicrm_fieldOptions',
        'enableDisable' => 'removed in 4.5 — use hook_civicrm_pre / hook_civicrm_post',
        'notePrivacy' => 'removed in 5.67',
        'tabs' => 'removed in 5.31 — use hook_civicrm_tabset',
        'validate' => 'removed in 4.7 — use hook_civicrm_validateForm',
    ];

    /**
     * Deprecations only in the dev docs, with no `@deprecated` tag or runtime warning.
     *
     * @var array<string, string>
     */
    private const DOCS_DEPRECATED_HOOKS = [
        'apiWrappers' => 'deprecated — use the civi.api.prepare / civi.api.respond events',
        'contactListQuery' => 'deprecated in favour of hook_civicrm_apiWrappers',
    ];

    /**
     * Hooks core names at dispatch time, `civicrm_<hook>_<argument>`
     * (CRM_Utils_Hook::postSave() appends the table, ::queueRun() the runner).
     */
    private const PARAMETERISED_HOOKS = ['postSave', 'queueRun'];

    public function name(): string
    {
        return 'hook-dispatch-name';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $expected = HookSurface::expectedPrefixes($context);
        if ($expected === []) {
            return;
        }

        // Third-party hooks: dispatched by a <requires> extension on disk, or
        // declared in civikitchen.yaml as known_hooks=acmeConnectors,otherHook
        $policyHooks = array_filter(array_map('trim', explode(',', $context->policyValue('known_hooks') ?? '')));
        foreach ($context->requiredExtensionDirs() as $dir) {
            $policyHooks = array_merge($policyHooks, HookSurface::dispatchedSuffixes($dir));
        }
        $policyHooks = array_values(array_unique($policyHooks));

        foreach (HookSurface::candidates($context) as $file) {
            $contents = $context->read($file);
            if ($contents === null || stripos($contents, '_civicrm_') === false) {
                continue;
            }
            $suppressions = Suppressions::of($contents);

            foreach (HookSurface::globalFunctions($contents) as $function => $line) {
                $parameterised = sprintf('/^([A-Za-z0-9_]+?)_civicrm_(%s)_[A-Za-z0-9_]+$/i', implode('|', self::PARAMETERISED_HOOKS));
                $isParameterised = preg_match($parameterised, $function, $m) === 1;
                if (!$isParameterised && preg_match('/^([A-Za-z0-9_]+)_civicrm_([a-zA-Z]+)$/i', $function, $m) !== 1) {
                    continue;
                }
                [, $prefix, $suffix] = $m;

                // `civicrm_civicrm_*` is core's own prefix, and the api magic
                // functions are not hooks at all.
                if (strcasecmp($prefix, 'civicrm') === 0 || stripos($function, 'civicrm_api3_') !== false) {
                    continue;
                }

                // myext_minimum_civicrm_version() is this extension's own helper, not a hook.
                if ($this->ownHelper($prefix, $expected)) {
                    continue;
                }

                if (!HookSurface::hasPrefix($prefix, $expected)) {
                    $this->emit($reporter, $suppressions, $line, [true, sprintf(
                        '%s: %s() will never fire — the hook prefix of this extension is \'%s\', so the function must be named %s%s()',
                        $file,
                        $function,
                        $expected[0],
                        $expected[0],
                        substr($function, strlen($prefix)),
                    )]);
                    continue;
                }

                if (!$isParameterised && preg_match(sprintf('/^(%s)$/i', implode('|', self::PARAMETERISED_HOOKS)), $suffix) === 1) {
                    $this->emit($reporter, $suppressions, $line, [true, sprintf(
                        '%s: %s() will never fire — core dispatches %s with the table or runner appended: %s_civicrm_%s_<name>()',
                        $file,
                        $function,
                        $suffix,
                        $prefix,
                        $suffix,
                    )]);
                    continue;
                }

                $suffix = $this->knownSpelling($suffix, $policyHooks);
                $verdict = $this->suffixVerdict($file, $function . '()', $suffix, $policyHooks);
                $this->emit($reporter, $suppressions, $line, $verdict);
            }

            foreach ($this->listenerMethods($contents) as [$method, $suffix, $line]) {
                $verdict = $this->suffixVerdict($file, $method . '() (scan-classes listener)', $suffix, $policyHooks);
                $this->emit($reporter, $suppressions, $line, $verdict);
            }

            foreach ($this->hookNameStrings($contents) as [$name, $suffix, $line]) {
                $verdict = $this->suffixVerdict($file, "listener/dispatch string '{$name}'", $suffix, $policyHooks);
                $this->emit($reporter, $suppressions, $line, $verdict);
            }
        }
    }

    /** @param list<string> $expected */
    private function ownHelper(string $prefix, array $expected): bool
    {
        foreach ($expected as $own) {
            if (stripos($prefix, $own . '_') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The suffix verdict shared by all three binding forms. Judged apart from
     * reporting so a suppression is consulted only where something fires.
     *
     * @param  list<string> $policyHooks
     * @return array{bool, string}|null [is a failure, message], null if clean
     */
    private function suffixVerdict(string $file, string $subject, string $suffix, array $policyHooks): ?array
    {
        // A suffix declared in civikitchen.yaml is a live third-party hook.
        if (in_array($suffix, $policyHooks, true)) {
            return null;
        }

        if (isset(self::REMOVED_HOOKS[$suffix])) {
            return [true, sprintf(
                '%s: %s will never fire — hook_civicrm_%s was %s',
                $file,
                $subject,
                $suffix,
                self::REMOVED_HOOKS[$suffix],
            )];
        }

        $deprecation = HookCatalog::DEPRECATED[$suffix] ?? self::DOCS_DEPRECATED_HOOKS[$suffix] ?? null;
        if ($deprecation !== null) {
            return [false, sprintf(
                '%s: %s — hook_civicrm_%s is %s',
                $file,
                $subject,
                $suffix,
                $deprecation,
            )];
        }

        if (!in_array($suffix, HookCatalog::LIVE, true)) {
            return [false, sprintf(
                '%s: %s — unknown hook suffix \'%s\'; a typo never fires, a third-party hook is fine (a <requires> extension present under the ext dir publishes the hooks it dispatches; otherwise declare it via known_hooks= in civikitchen.yaml)',
                $file,
                $subject,
                $suffix,
            )];
        }

        return null;
    }

    /**
     * The catalogued spelling of a function-form suffix (`_civicrm_Tabs()` is
     * `tabs`); listener forms bind by exact event name and keep theirs.
     *
     * @param list<string> $policyHooks
     */
    private function knownSpelling(string $suffix, array $policyHooks): string
    {
        $known = [
            ...$policyHooks,
            ...array_keys(self::REMOVED_HOOKS),
            ...array_keys(HookCatalog::DEPRECATED),
            ...array_keys(self::DOCS_DEPRECATED_HOOKS),
            ...HookCatalog::LIVE,
        ];
        foreach ($known as $name) {
            if (strcasecmp($name, $suffix) === 0) {
                return $name;
            }
        }

        return $suffix;
    }

    /**
     * Report a verdict unless an ignore on that line covers it.
     *
     * @param array{bool, string}|null $verdict
     */
    private function emit(Reporter $reporter, Suppressions $suppressions, int $line, ?array $verdict): void
    {
        if ($verdict === null || $suppressions->suppressed($this->name(), $line)) {
            return;
        }
        [$fails, $message] = $verdict;
        $fails ? $reporter->fail($message) : $reporter->warn($message);
    }

    /**
     * Methods (any depth) EventScanner binds to a hook: `hook_civicrm_x`,
     * `on_hook_civicrm_x`, `self_hook_civicrm_x`. Dotted civi.* events are skipped.
     *
     * @return list<array{string, string, int}> [method name, hook suffix, line]
     */
    private function listenerMethods(string $contents): array
    {
        $tokens = @token_get_all($contents);
        $methods = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== \T_FUNCTION) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && in_array($next[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($next === '&') {
                    continue;
                }
                if (is_array($next) && $next[0] === \T_STRING
                    && preg_match('/^(?:on_|self_)?hook_civicrm_([a-zA-Z]+)$/', $next[1], $m) === 1
                ) {
                    $methods[] = [$next[1], $m[1], $next[2]];
                }
                break;
            }
        }

        return $methods;
    }

    /**
     * String literals naming a hook event (addListener, getSubscribedEvents,
     * dispatch), with a leading '&' and an '::Entity' scope stripped.
     *
     * @return list<array{string, string, int}> [literal (unquoted), hook suffix, line]
     */
    private function hookNameStrings(string $contents): array
    {
        $tokens = @token_get_all($contents);
        $names = [];

        foreach ($tokens as $token) {
            if (!is_array($token) || $token[0] !== \T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $literal = substr($token[1], 1, -1);
            if (preg_match('/^&?hook_civicrm_([a-zA-Z]+)(?:::[A-Za-z_]+)?$/', $literal, $m) === 1) {
                $names[] = [$literal, $m[1], $token[2]];
            }
        }

        return $names;
    }
}
