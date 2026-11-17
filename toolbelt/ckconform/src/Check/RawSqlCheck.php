<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\HookSurface;
use CiviKitchen\Ckconform\PhpSource;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\Suppressions;

/**
 * Raw SQL wants a reason: low-level CRM_Core_DAO executor calls (upgraders
 * exempt) and SQL arguments interpolating variables (upgraders included).
 * Two findings, both WARN, both escaped by `ckconform-ignore raw-sql -- <reason>`:
 * the reason is the justification and is mandatory.
 */
final class RawSqlCheck implements Check
{
    private const SINKS = ['executeQuery', 'executeUnbufferedQuery', 'singleValueQuery'];

    public function name(): string
    {
        return 'raw-sql';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        foreach (HookSurface::candidates($context) as $file) {
            $contents = $context->read($file);
            if ($contents === null || stripos($contents, 'CRM_Core_DAO') === false) {
                continue;
            }
            $suppressions = Suppressions::of($contents);
            $isUpgrader = str_contains($file, 'Upgrader');

            foreach ($this->sinkCalls($contents) as [$method, $line, $interpolates]) {
                // Suppression last: consulting it on a non-finding would mark it
                // consumed and hide SuppressionHygieneCheck's unused-ignore report.
                if ((!$interpolates && $isUpgrader) || $suppressions->suppressed($this->name(), $line)) {
                    continue;
                }
                if ($interpolates) {
                    $reporter->warn(sprintf(
                        '%s:%d: CRM_Core_DAO::%s() interpolates variables into the SQL string — bind values via %%1 parameters, or justify the composition (ckconform-ignore raw-sql -- <reason>)',
                        $file,
                        $line,
                        $method,
                    ));
                } else {
                    $reporter->warn(sprintf(
                        '%s:%d: CRM_Core_DAO::%s() — raw SQL needs a reason: say why APIv4, the BAO or a CRM_Utils_SQL builder cannot express this (ckconform-ignore raw-sql -- <reason>)',
                        $file,
                        $line,
                        $method,
                    ));
                }
            }
        }
    }

    /**
     * Sink calls with their line and whether the SQL argument interpolates
     * variables in a string or a concatenation; a bare `$sql` is not flagged.
     *
     * @return list<array{string, int, bool}> [method, line, interpolates]
     */
    private function sinkCalls(string $contents): array
    {
        $tokens = PhpSource::codeTokens($contents);
        $sinks = array_combine(array_map('strtolower', self::SINKS), self::SINKS);
        $calls = [];

        foreach ($tokens as $i => $token) {
            $method = $sinks[strtolower($tokens[$i + 2]->text ?? '')] ?? null;
            if ($method === null || strcasecmp(PhpSource::name($token) ?? '', 'CRM_Core_DAO') !== 0
                || !$tokens[$i + 1]->is(T_DOUBLE_COLON) || !$tokens[$i + 2]->is(T_STRING)
            ) {
                continue;
            }
            $arguments = ($tokens[$i + 3] ?? null)?->text === '(' ? PhpSource::arguments($tokens, $i + 3) : null;
            $query = PhpSource::argument($arguments ?? [], 0, 'query') ?? [];
            $calls[] = [$method, $tokens[$i + 2]->line, $this->interpolates($query)];
        }

        return $calls;
    }

    /**
     * @param list<\PhpToken> $argument
     */
    private function interpolates(array $argument): bool
    {
        $inString = false;
        $sawLiteral = false;
        $sawConcat = false;
        $sawVariable = false;
        foreach ($argument as $token) {
            if ($token->is(['"', T_START_HEREDOC, T_END_HEREDOC])) {
                $inString = $token->is(T_END_HEREDOC) ? false : !$inString;
                $sawLiteral = true;
            } elseif ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $sawLiteral = true;
            } elseif ($token->is([T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                if ($inString) {
                    return true;
                }
                $sawVariable = $sawVariable || $token->is(T_VARIABLE);
            } elseif ($token->text === '.' && !$inString) {
                $sawConcat = true;
            }
        }

        return $sawConcat && $sawLiteral && $sawVariable;
    }
}
