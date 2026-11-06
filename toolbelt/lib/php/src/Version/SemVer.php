<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Version;

/**
 * SemVer 2.0 precedence (semver.org §11). version_compare() is not it: it
 * reads `alpha.10` below `alpha.2` and `1.0.0-rc` equal to `1.0.0rc`.
 */
final class SemVer
{
    private const PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
        . '(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?'
        . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/';

    public static function valid(string $version): bool
    {
        return preg_match(self::PATTERN, $version) === 1;
    }

    /** <0, 0 or >0 as $a is below, equal to or above $b. Both must be valid(). */
    public static function compare(string $a, string $b): int
    {
        preg_match(self::PATTERN, $a, $left);
        preg_match(self::PATTERN, $b, $right);
        for ($i = 1; $i <= 3; $i++) {
            $order = self::compareNumeric($left[$i], $right[$i]);
            if ($order !== 0) {
                return $order;
            }
        }

        $leftPre = $left[4] ?? '';
        $rightPre = $right[4] ?? '';
        if ($leftPre === '' || $rightPre === '') {
            // A release outranks its own pre-releases.
            return ($leftPre === '' ? 1 : 0) - ($rightPre === '' ? 1 : 0);
        }

        $leftIds = explode('.', $leftPre);
        $rightIds = explode('.', $rightPre);
        foreach ($leftIds as $index => $id) {
            if (!isset($rightIds[$index])) {
                return 1;
            }
            $order = self::compareIdentifier($id, $rightIds[$index]);
            if ($order !== 0) {
                return $order;
            }
        }

        return count($leftIds) <=> count($rightIds);
    }

    private static function compareIdentifier(string $a, string $b): int
    {
        $aNumeric = ctype_digit($a);
        $bNumeric = ctype_digit($b);
        if ($aNumeric && $bNumeric) {
            return self::compareNumeric($a, $b);
        }
        if ($aNumeric !== $bNumeric) {
            // Numeric identifiers rank below alphanumeric ones.
            return $aNumeric ? -1 : 1;
        }

        return strcmp($a, $b) <=> 0;
    }

    /** Digit strings without leading zeros, of any length: longer is larger. */
    private static function compareNumeric(string $a, string $b): int
    {
        return [strlen($a), $a] <=> [strlen($b), $b];
    }
}
