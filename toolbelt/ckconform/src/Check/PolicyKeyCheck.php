<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Policy;
use CiviKitchen\Ckconform\Reporter;

/**
 * Keeps the `policy:` mapping of civikitchen.yaml and CK_DEFAULT_CONFIG honest:
 * unknown, repeated, malformed or non-numeric keys and bad `extension_source`.
 * A typo'd check name in an inline `ckconform-ignore` is reported as a dead
 * ignore elsewhere; a typo'd policy key is reported here.
 */
final class PolicyKeyCheck implements Check
{
    public function name(): string
    {
        return 'policy-key';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if ($context->exists(Policy::LEGACY_FILE)) {
            $reporter->fail(Policy::LEGACY_FILE . ' is no longer supported; migrate its policy to ' . Policy::CONFIG_FILE);
        }
        // The defaults file is judged by the same rules, under its own name:
        // a typo there disables a policy for the whole fleet at once.
        try {
            $defaults = Policy::defaultsRaw();
        } catch (\RuntimeException $e) {
            $reporter->fail($e->getMessage());
            $defaults = null;
        }
        if ($defaults !== null) {
            $source = Policy::DEFAULTS_ENV . ' (' . getenv(Policy::DEFAULTS_ENV) . ')';
            $parsedDefaults = $this->validate($source, $defaults, $reporter);
            foreach (array_keys($parsedDefaults ?? []) as $key) {
                if (isset(Policy::KEYS[$key]) && !in_array($key, Policy::SHARED, true)) {
                    $reporter->fail(sprintf(
                        '%s: %s is read by a gate the defaults do not reach — set it in the repo civikitchen.yaml (shareable: %s)',
                        $source,
                        $key,
                        implode(', ', Policy::SHARED),
                    ));
                }
            }
        }

        $raw = $context->read(Policy::CONFIG_FILE);
        if ($raw !== null) {
            $this->validate(Policy::CONFIG_FILE, $raw, $reporter);
        }
    }

    /** @return array<string, list<string>>|null */
    private function validate(string $source, string $raw, Reporter $reporter): ?array
    {
        try {
            $policy = Policy::parse($raw);
        } catch (\RuntimeException $e) {
            $reporter->fail($source . ': ' . $e->getMessage());
            return null;
        }
        foreach ($policy as $key => $values) {
            if (!isset(Policy::KEYS[$key])) {
                $reporter->fail(sprintf(
                    "%s: unknown key '%s' — nothing reads it, so the policy it declares does not apply%s",
                    $source,
                    $key,
                    $this->didYouMean($key),
                ));

                continue;
            }

            if ($key === 'extension_source') {
                foreach ($values as $value) {
                    $spec = Policy::stripReason($value);
                    if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*@https://[^/?\#@]+(?:/[^?\#]*)?\#sha256=[a-f0-9]{64}$#', $spec) !== 1) {
                        $reporter->fail(sprintf(
                            "%s: extension source must use HTTPS without credentials/query/fragment and include a SHA-256 digest",
                            $source,
                        ));
                    }
                }
            }

            if (!in_array($key, Policy::PERCENT, true)) {
                continue;
            }

            foreach ($values as $value) {
                $number = Policy::stripReason($value);
                if (preg_match('/^\d{1,3}$/', $number) !== 1 || (int) $number > 100) {
                    $reporter->fail(sprintf(
                        "%s: %s must be a whole percentage 0-100, got '%s'",
                        $source,
                        $key,
                        $number,
                    ));
                }
            }
        }
        return $policy;
    }

    /**
     * Name the nearest key: distance 3 over key lengths of 5-24 characters
     * catches a transposition without pairing 'license' and 'vendor'.
     */
    private function didYouMean(string $key): string
    {
        $best = null;
        $bestDistance = 4;
        foreach (array_keys(Policy::KEYS) as $known) {
            $distance = levenshtein($key, $known);
            if ($distance < $bestDistance) {
                $best = $known;
                $bestDistance = $distance;
            }
        }

        return $best === null ? '' : " (did you mean '{$best}'?)";
    }
}
