<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * WARNs on smarty-v2@1 (deprecated alias of smarty@1) and on mgd-php@1 once every
 * tracked *.mgd.php lies where @2 scans. Escape: `ignore_checks=`, since info.xml
 * has no comment tokens an inline ignore could live in.
 */
final class MixinVersionCheck implements Check
{
    public function name(): string
    {
        return 'mixin-version';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        foreach ($context->declaredMixins() as $mixin) {
            [$name] = explode('@', $mixin) + [''];

            if ($name === 'smarty-v2') {
                $reporter->warn(
                    "info.xml declares {$mixin} — a deprecated alias of the version-independent smarty@1; switch via `civix upgrade`"
                );
            }

            if ($name === 'mgd-php' && str_starts_with($mixin, 'mgd-php@1')
                && $this->allMgdFilesInV2Paths($context)
            ) {
                $reporter->warn(
                    "info.xml declares {$mixin} — mgd-php@2 scans only the conventional homes (root, managed/, api/, CRM/, Civi/) instead of the whole tree; every tracked *.mgd.php already lies there, so the switch is safe"
                );
            }
        }
    }

    private function allMgdFilesInV2Paths(Context $context): bool
    {
        $files = $context->isGitRepo()
            ? $context->trackedUnder('', ['.mgd.php'])
            : $context->findFiles('', ['.mgd.php']);
        if ($files === []) {
            return false;
        }
        foreach ($files as $file) {
            $inV2Home = !str_contains($file, '/')
                || str_starts_with($file, 'managed/')
                || str_starts_with($file, 'api/')
                || str_starts_with($file, 'CRM/')
                || str_starts_with($file, 'Civi/');
            if (!$inV2Home) {
                return false;
            }
        }

        return true;
    }
}
