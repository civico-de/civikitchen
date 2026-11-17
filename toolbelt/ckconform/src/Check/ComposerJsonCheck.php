<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A missing composer.json, or one carrying a description: that belongs only in
 * info.xml, which CiviCRM shows.
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
