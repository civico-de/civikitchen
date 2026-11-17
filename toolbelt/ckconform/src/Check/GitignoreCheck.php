<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * No .gitignore for the extension, its own or one in a directory above it.
 */
final class GitignoreCheck implements Check
{
    public function name(): string
    {
        return 'gitignore';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        if (!GitignoreScope::applies($context)) {
            $reporter->fail('no .gitignore (build output and vendor/ land in git)');
        }
    }
}
