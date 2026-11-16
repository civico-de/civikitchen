<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Policy;
use CiviKitchen\Ckconform\Reporter;

/**
 * Holds info.xml's lowest `<compatibility><ver>` at CiviCRM's current ESR.
 * An extension that declares another version says why in
 * `policy.civicrm_floor_reasons`, one entry per reason with the version that
 * reason needs; one entry names the declared version, and none lies on the
 * ESR's side of it or past it.
 */
final class CivicrmFloorCheck implements Check
{
    /** CiviCRM's current extended security release; scaffold/ckcreate defaults to the same. */
    public const ESR = '6.16';

    public function name(): string
    {
        return 'civicrm-floor';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->exists('info.xml')) {
            return;
        }
        $esr = self::ESR;
        $declared = $context->compatibilityFloor();
        if ($declared === null) {
            $reporter->fail("info.xml declares no <compatibility><ver>: declare the ESR, {$esr}, or the version the extension needs");

            return;
        }
        $side = version_compare($declared, $esr);
        $explained = false;
        foreach ($context->policyValues('civicrm_floor_reasons') as $value) {
            $version = Policy::stripReason($value);
            $explained = $explained || version_compare($version, $declared, '==');
            $reason = substr($value, strlen($version) + 4);
            $ownSide = version_compare($version, $esr);
            if ($side === 0 || $ownSide !== $side) {
                $reporter->fail("civikitchen.yaml: civicrm_floor_reasons gives a reason for {$version} that is not needed, the ESR is {$esr} and info.xml declares {$declared} — remove it: \"{$reason}\"");
            } elseif (version_compare($version, $declared) === $side) {
                $reporter->fail("civikitchen.yaml: civicrm_floor_reasons gives a reason for {$version}, but info.xml declares {$declared} — name the same version in both: \"{$reason}\"");
            }
        }
        if ($side !== 0 && !$explained) {
            $where = $side > 0 ? 'above' : 'below';
            $reporter->fail("info.xml declares CiviCRM {$declared}, {$where} the ESR {$esr}, and civikitchen.yaml gives no reason for {$declared}: declare {$esr}, or add the reason to policy.civicrm_floor_reasons");
        }
    }
}
