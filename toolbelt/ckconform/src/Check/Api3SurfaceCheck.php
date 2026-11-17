<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\HookSurface;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\Suppressions;

/**
 * An extension defining `civicrm_api3_<entity>_<action>()` functions; new APIs
 * should be APIv4. WARN per function; the escape for a documented external compatibility
 * contract is an inline `ckconform-ignore api3-surface -- <reason>` (or the
 * file/repo levels).
 */
final class Api3SurfaceCheck implements Check
{
    public function name(): string
    {
        return 'api3-surface';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        foreach (HookSurface::candidates($context) as $file) {
            $contents = $context->read($file);
            if ($contents === null || !str_contains($contents, 'civicrm_api3_')) {
                continue;
            }
            $suppressions = Suppressions::of($contents);

            foreach (HookSurface::globalFunctions($contents) as $function => $line) {
                if (!str_starts_with($function, 'civicrm_api3_')
                    || $suppressions->suppressed($this->name(), $line)
                ) {
                    continue;
                }
                $reporter->warn(sprintf(
                    '%s: %s() ships an APIv3 endpoint — new public extension APIs should be APIv4; for a scheduled job, set the job\'s parameters to {"version":4} and point it at the APIv4 action instead of wrapping it; keep this only for a documented external compatibility contract (ckconform-ignore api3-surface -- <reason>)',
                    $file,
                    $function,
                ));
            }
        }
    }
}
