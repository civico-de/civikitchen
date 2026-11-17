<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A `<url desc="Licensing">` (civix ships the AGPL text) that contradicts the
 * extension's `<license>`, located by attribute through SimpleXML.
 */
final class LicensingUrlCheck implements Check
{
    public function name(): string
    {
        return 'licensing-url';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $url = $this->licensingUrl($context);
        if ($url === '') {
            return;
        }

        $declared = strtolower($context->infoLicense());
        $fromUrl = $this->classify(strtolower($url));
        // Classify BOTH sides where possible: plain substring containment lets
        // "agpl-3.0" swallow a stale plain-GPL link ('agpl' contains 'gpl').
        $fromDeclared = $this->classify($declared);

        $mismatch = $fromDeclared !== ''
            ? $fromDeclared !== $fromUrl
            : !str_contains($declared, $fromUrl);
        if ($fromUrl !== '' && $mismatch) {
            $reporter->fail(sprintf(
                'info.xml declares <license>%s</license> but its Licensing url points at the %s text (%s)',
                $context->infoLicense(),
                $fromUrl,
                $url,
            ));
        } else {
            $reporter->ok('licensing url agrees with the declared licence');
        }
    }

    /**
     * Order is load-bearing: an AGPL url contains "gpl", so `agpl` and `lgpl`
     * must be decided before the plain `gpl` arm ever sees it.
     */
    private function classify(string $url): string
    {
        if (str_contains($url, 'agpl')) {
            return 'agpl';
        }
        if (str_contains($url, 'lgpl')) {
            return 'lgpl';
        }
        if (str_contains($url, 'gpl')) {
            return 'gpl';
        }
        if (str_contains($url, 'mit-license') || str_contains($url, 'licenses/mit')) {
            return 'mit';
        }
        if (str_contains($url, 'apache.org/licenses')) {
            return 'apache';
        }
        // `*mozilla.org/*mpl*` — "mpl" has to come after the host, not before.
        $host = strpos($url, 'mozilla.org/');
        if ($host !== false && str_contains(substr($url, $host), 'mpl')) {
            return 'mpl';
        }

        return '';
    }

    private function licensingUrl(Context $context): string
    {
        $info = $context->infoXml();
        if ($info === null) {
            return '';
        }

        foreach ($info->xpath('//url[@desc="Licensing"]') ?: [] as $url) {
            return (string) $url;
        }

        return '';
    }
}
