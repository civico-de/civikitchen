<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * The copyright holder named in `civikitchen.yaml` must appear in LICENSE.txt,
 * as a literal, case-sensitive substring.
 */
final class CopyrightCheck implements Check
{
    public function name(): string
    {
        return 'copyright';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $want = $context->policyValue('copyright');
        if ($want === null || !$context->exists('LICENSE.txt')) {
            return;
        }

        if (!str_contains($context->read('LICENSE.txt') ?? '', $want)) {
            $reporter->fail(
                "LICENSE.txt does not name the copyright holder '{$want}' from civikitchen.yaml"
            );
        }
    }
}
