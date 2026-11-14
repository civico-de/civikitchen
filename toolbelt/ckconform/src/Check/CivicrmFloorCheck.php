<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Holds info.xml's lowest `<compatibility><ver>` at or above the CiviCRM
 * minor that `civicrm_floor` names, typically the organisation's supported
 * ESR. A higher floor is the extension's own business and passes.
 */
final class CivicrmFloorCheck implements Check
{
    public function name(): string
    {
        return 'civicrm-floor';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $required = $context->policyValue('civicrm_floor');
        if ($required === null || !$context->exists('info.xml')) {
            return;
        }
        $declared = $context->compatibilityFloor();
        if ($declared === null) {
            $reporter->fail("info.xml declares no <compatibility><ver>, but civikitchen.yaml requires a CiviCRM floor of at least {$required} (civicrm_floor)");

            return;
        }
        if (version_compare($declared, $required, '<')) {
            $reporter->fail("info.xml's compatibility floor is {$declared}, below the {$required} that civikitchen.yaml requires (civicrm_floor): raise <compatibility><ver> to {$required}");
        }
    }
}
