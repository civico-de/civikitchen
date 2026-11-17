<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * LICENSE.txt scaffolding leftovers: a foreign extension key (compared with
 * info.xml's `key` attribute) or a FIXME copyright holder.
 */
final class LicenseSkeletonCheck implements Check
{
    public function name(): string
    {
        return 'license-skeleton';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->exists('LICENSE.txt')) {
            return;
        }

        $license = $context->read('LICENSE.txt') ?? '';
        $package = $this->packageLine($license);
        $key = ($context->extensionKey() ?? '');

        if ($package !== '' && $key !== '' && $package !== $key) {
            $reporter->fail(
                "LICENSE.txt says 'Package: {$package}' but info.xml key is '{$key}' (copied skeleton)"
            );
        }

        if (str_contains($license, 'FIXME')) {
            $reporter->warn('LICENSE.txt still contains FIXME (copyright holder unfilled)');
        }
    }

    /** First `Package:` line, with the label, padding and a CRLF's \r stripped. */
    private function packageLine(string $license): string
    {
        foreach (explode("\n", $license) as $line) {
            if (str_starts_with($line, 'Package:')) {
                return trim(substr($line, strlen('Package:')));
            }
        }

        return '';
    }
}
