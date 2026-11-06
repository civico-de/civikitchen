<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * An extension under an open-source licence is meant for people outside the
 * team, so it ships a handbook/ — shown inside CiviCRM by the handbook
 * extension — and, on GitHub, publishes it through the shared docs workflow.
 */
final class HandbookCheck implements Check
{
    public const INDEX = 'handbook/index.md';

    public const SHARED_DOCS = 'extension-docs.yml';

    public function name(): string
    {
        return 'handbook';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $license = $context->infoLicense();
        // A repo that never releases is never installed, so nobody reads its handbook.
        if ($license === '' || strcasecmp($license, 'Proprietary') === 0 || $context->policyValue('release') !== null) {
            return;
        }
        if (!$context->exists(self::INDEX)) {
            $reporter->fail(
                "info.xml declares {$license}, but there is no " . self::INDEX . ' — an open-source extension ships a '
                . 'handbook for its users; see civikitchen\'s docs/reusable-workflows.md#documentation-site'
            );

            return;
        }
        if ($context->isMonorepo() || $context->policyValue('ci') === 'gitlab') {
            return;
        }
        if ($context->jobsCalling(self::SHARED_DOCS) === []) {
            $reporter->fail(
                'handbook/ is not published: no workflow calls ' . self::SHARED_DOCS
                . ' — add the docs caller from civikitchen\'s docs/reusable-workflows.md#documentation-site'
            );
        }
    }
}
