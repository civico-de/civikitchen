<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\ImageBootstraps;
use CiviKitchen\Ckconform\PhpSource;
use CiviKitchen\Ckconform\Reporter;

/**
 * A suite whose config lacks convertDeprecationsToExceptions="true", or whose
 * bootstrap does not widen error_reporting to keep E_DEPRECATED (the CLI default
 * masks it), lets runtime deprecations pass green.
 */
final class DeprecationGateCheck implements Check
{
    private const CONFIGS = ['phpunit.xml.dist', 'phpunit.xml'];

    /** The E_* levels by value, fixed so that the host PHP (E_STRICT is gone in 8.4) does not matter. */
    private const LEVELS = [
        'E_ERROR' => 1, 'E_WARNING' => 2, 'E_PARSE' => 4, 'E_NOTICE' => 8, 'E_CORE_ERROR' => 16,
        'E_CORE_WARNING' => 32, 'E_COMPILE_ERROR' => 64, 'E_COMPILE_WARNING' => 128, 'E_USER_ERROR' => 256,
        'E_USER_WARNING' => 512, 'E_USER_NOTICE' => 1024, 'E_STRICT' => 2048, 'E_RECOVERABLE_ERROR' => 4096,
        'E_DEPRECATED' => 8192, 'E_USER_DEPRECATED' => 16384, 'E_ALL' => 32767,
        // Every bit set: a common "report everything" idiom.
        'PHP_INT_MAX' => PHP_INT_MAX,
    ];

    public function name(): string
    {
        return 'deprecation-gate';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->hasShippedUnder('tests/phpunit')) {
            return;
        }

        $config = null;
        foreach (self::CONFIGS as $candidate) {
            if ($context->ships($candidate)) {
                $config = $candidate;
                break;
            }
        }
        if ($config === null) {
            // PhpunitConfigCheck already fails on the missing config.
            return;
        }

        $xml = $context->readShipped($config) ?? '';
        if (!$this->convertsDeprecations($xml)) {
            $reporter->warn(
                $config . ' does not set convertDeprecationsToExceptions="true"'
                . ' — CiviCRM runtime deprecations pass the suite silently'
            );
        }

        [$bootstrap, $code] = ImageBootstraps::suiteBootstrap($context);
        if (!$this->widensErrorReporting($xml, $code)) {
            $reporter->warn(
                "no error_reporting(E_ALL) in {$bootstrap}"
                . ' — the CLI default masks E_DEPRECATED outside Civi\\Test tests'
            );
        }
    }

    private function convertsDeprecations(string $xml): bool
    {
        $parsed = $this->parse($xml);
        if ($parsed === null) {
            return false;
        }

        return in_array(strtolower((string) ($parsed['convertDeprecationsToExceptions'] ?? '')), ['true', '1'], true);
    }

    /**
     * Either the bootstrap widens the mask at runtime, or the config does via
     * <php><ini name="error_reporting">.
     */
    private function widensErrorReporting(string $xml, ?string $bootstrap): bool
    {
        if ($bootstrap !== null && $this->bootstrapWidens($bootstrap)) {
            return true;
        }

        $parsed = $this->parse($xml);
        // PHPUnit resolves a constant name, anything else reaches ini_set() as an
        // integer string: 'E_ALL & ~E_DEPRECATED' is 0 there.
        foreach ($parsed?->xpath('//php/ini') ?: [] as $ini) {
            $value = trim((string) $ini['value']);
            if ((string) $ini['name'] === 'error_reporting' && $this->keepsDeprecations(self::LEVELS[$value] ?? (int) $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an error_reporting() or ini_set('error_reporting', …) call sets a
     * mask that keeps both deprecation levels; the whole argument is evaluated.
     */
    private function bootstrapWidens(string $bootstrap): bool
    {
        $tokens = PhpSource::codeTokens($bootstrap);
        foreach ($tokens as $i => $token) {
            $function = strtolower(PhpSource::name($token) ?? '');
            if (!in_array($function, ['error_reporting', 'ini_set'], true)
                || ($tokens[$i + 1] ?? null)?->text !== '(' || ($tokens[$i - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION])
            ) {
                continue;
            }
            $arguments = PhpSource::arguments($tokens, $i + 1) ?? [];
            if ($function === 'ini_set') {
                $option = PhpSource::argument($arguments, 0, 'option') ?? [];
                if (count($option) !== 1 || !$option[0]->is(T_CONSTANT_ENCAPSED_STRING) || substr($option[0]->text, 1, -1) !== 'error_reporting') {
                    continue;
                }
            }
            $argument = $function === 'ini_set'
                ? PhpSource::argument($arguments, 1, 'value') ?? []
                : PhpSource::argument($arguments, 0, 'error_level') ?? [];
            $position = 0;
            $mask = $argument === [] ? null : $this->mask($argument, $position);
            if ($position === count($argument) && $this->keepsDeprecations($mask)) {
                return true;
            }
        }

        return false;
    }

    private function keepsDeprecations(?int $mask): bool
    {
        $wanted = self::LEVELS['E_DEPRECATED'] | self::LEVELS['E_USER_DEPRECATED'];

        return $mask !== null && ($mask & $wanted) === $wanted;
    }

    /**
     * Evaluates a constant mask expression (E_* names, integers, | ^ & ~ -
     * and parentheses) from $position; null for anything else.
     *
     * @param list<\PhpToken> $tokens
     */
    private function mask(array $tokens, int &$position, int $level = 0): ?int
    {
        if ($level < 3) {
            $value = $this->mask($tokens, $position, $level + 1);
            $operator = ['|', '^', '&'][$level];
            while ($value !== null && ($tokens[$position] ?? null)?->text === $operator) {
                $position++;
                $right = $this->mask($tokens, $position, $level + 1);
                $value = $right === null ? null : match ($operator) {
                    '|' => $value | $right,
                    '^' => $value ^ $right,
                    default => $value & $right,
                };
            }

            return $value;
        }

        $token = $tokens[$position++] ?? null;
        $name = $token === null ? null : PhpSource::name($token);
        if ($token !== null && in_array($token->text, ['~', '-'], true)) {
            $operand = $this->mask($tokens, $position, 3);

            return $operand === null ? null : ($token->text === '~' ? ~$operand : -$operand);
        }
        if ($token !== null && $token->text === '(') {
            $value = $this->mask($tokens, $position);

            return ($tokens[$position++] ?? null)?->text === ')' ? $value : null;
        }
        if ($token !== null && $token->is(T_LNUMBER)) {
            return intval(str_replace('_', '', $token->text), 0);
        }
        // ini_set() takes a string; a cast or a numeric literal reaches the same integer.
        if ($token !== null && $token->is([T_STRING_CAST, T_INT_CAST])) {
            return $this->mask($tokens, $position, 3);
        }
        if ($token !== null && $token->is(T_CONSTANT_ENCAPSED_STRING)) {
            return (int) substr($token->text, 1, -1);
        }

        // Constant names are case-sensitive: e_all is an undefined constant.
        return $name === null ? null : self::LEVELS[$name] ?? null;
    }

    private function parse(string $xml): ?\SimpleXMLElement
    {
        if (trim($xml) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        return $parsed === false ? null : $parsed;
    }
}
