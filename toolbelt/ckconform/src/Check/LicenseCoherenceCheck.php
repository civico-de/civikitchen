<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Licence coherence between info.xml and composer.json, and with the optional
 * `license=` policy; both files are read with real parsers.
 */
final class LicenseCoherenceCheck implements Check
{
    public function name(): string
    {
        return 'license-coherence';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $xml = $context->infoLicense();
        $composerLicenses = $this->composerLicenses($context);
        $composer = $this->describe($composerLicenses);
        $want = $context->policyValue('license');

        if ($want !== null) {
            if (!$this->sameLicense($xml, $want)) {
                $reporter->fail(sprintf(
                    "info.xml <license> is '%s', civikitchen.yaml expects '%s'",
                    $xml === '' ? 'empty' : $xml,
                    $want,
                ));
            }
            if ($composerLicenses !== [] && !$this->anyMatches($composerLicenses, $want)) {
                $reporter->fail(
                    "composer.json license is '{$composer}', civikitchen.yaml expects '{$want}'"
                );
            }

            return;
        }

        if ($xml !== '' && $composerLicenses !== [] && !$this->anyMatches($composerLicenses, $xml)) {
            $reporter->fail(
                "licence declarations disagree: info.xml '{$xml}' vs composer.json '{$composer}'"
            );
        }
    }

    /**
     * Composer licences; a disjunctive list (`["MIT", "GPL-2.0"]`, `(MIT or GPL-2.0)`)
     * satisfies the policy when the expected licence is one of its members.
     *
     *
     * @return list<string>
     */
    private function composerLicenses(Context $context): array
    {
        $composer = $context->json('composer.json');
        $license = $composer['license'] ?? null;

        if (is_string($license)) {
            return $license === '' ? [] : $this->alternatives($license);
        }
        if (is_array($license)) {
            return array_values(array_filter(
                array_map(static fn ($entry): string => is_string($entry) ? $entry : '', $license),
                static fn (string $entry): bool => $entry !== '',
            ));
        }

        return [];
    }

    /**
     * The members of an SPDX disjunction, `(A or B)` or `A OR B` (composer's
     * grammar takes the operator in any case); any other expression stays whole.
     *
     * @return list<string>
     */
    private function alternatives(string $expression): array
    {
        $inner = preg_replace('/^\(\s*([^()]*?)\s*\)$/', '$1', trim($expression)) ?? $expression;
        if (str_contains($inner, '(') || preg_match('/\s(?:and|with)\s/i', $inner) === 1) {
            return [$expression];
        }

        return preg_split('/\s+or\s+/i', $inner) ?: [$expression];
    }

    /**
     * How the composer declaration reads in a message: a single licence as
     * itself, a disjunctive list as the list.
     *
     * @param list<string> $licenses
     */
    private function describe(array $licenses): string
    {
        return count($licenses) === 1 ? $licenses[0] : implode(' or ', $licenses);
    }

    private function sameLicense(string $a, string $b): bool
    {
        return strtolower($a) === strtolower($b);
    }

    /**
     * @param list<string> $licenses
     */
    private function anyMatches(array $licenses, string $want): bool
    {
        foreach ($licenses as $license) {
            if ($this->sameLicense($license, $want)) {
                return true;
            }
        }

        return false;
    }
}
