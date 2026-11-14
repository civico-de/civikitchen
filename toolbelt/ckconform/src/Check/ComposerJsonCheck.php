<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * civix scaffolds a composer.json for every extension; its absence usually
 * means a hand-rolled repo that skipped the generator and has no
 * machine-readable metadata (name, autoload, dependencies) at all.
 *
 * The description lives only in info.xml, which CiviCRM shows: a second copy
 * in composer.json is read by nothing and drifts.
 */
final class ComposerJsonCheck implements Check
{
    public function name(): string
    {
        return 'composer-json';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->exists('composer.json')) {
            $reporter->fail('no composer.json (extension metadata)');

            return;
        }
        if (array_key_exists('description', $context->json('composer.json') ?? [])) {
            $reporter->fail(
                "composer.json repeats the extension's description: info.xml's <description> is the one CiviCRM "
                . 'shows, so remove "description" from composer.json'
            );
        }
    }
}
