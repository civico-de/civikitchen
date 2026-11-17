<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Legacy XML entity schemas where the compatibility floor is 5.75 or later,
 * which makes `schema/*.entityType.php` canonical; WARNs. Migration is
 * `civix upgrade` + `civix convert-entity`; verify fresh install and upgrade.
 */
final class EntitySchemaFormatCheck implements Check
{
    private const CANONICAL_SINCE = '5.75';

    public function name(): string
    {
        return 'entity-schema-format';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $floor = $context->compatibilityFloor();
        if ($floor === null || version_compare($floor, self::CANONICAL_SINCE, '<')) {
            return;
        }

        $xmlSchema = array_filter(
            $context->isGitRepo() ? $context->trackedFiles() : $context->findFiles(''),
            static fn (string $f): bool => str_starts_with($f, 'xml/schema/'),
        );
        if ($xmlSchema !== []) {
            $reporter->warn(sprintf(
                'xml/schema/ holds %d legacy entity schema file(s) — for a %s+ floor the canonical format is schema/*.entityType.php (entity-types-php@2); migrate with a current civix (`civix upgrade`, `civix convert-entity`) and verify fresh install plus upgrade',
                count($xmlSchema),
                self::CANONICAL_SINCE,
            ));
        }

        foreach ($context->declaredMixins() as $mixin) {
            if (str_starts_with($mixin, 'entity-types-php@1')) {
                $reporter->warn(
                    "info.xml declares {$mixin} — entity-types-php@2 loads the canonical schema/*.entityType.php format; part of the same civix migration"
                );
            }
        }
    }
}
