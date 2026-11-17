<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * The tag the release checks compare against, or the reason there is none;
 * "cannot tell" (no tags or no history) is a distinct, reported state.
 */
final class ReleaseHistory
{
    private function __construct(
        /** The newest reachable `v*` tag, null when the rules must not judge. */
        public readonly ?string $tag,
        /** Why the rule could not be evaluated, null when it could. */
        public readonly ?string $gap,
    ) {
    }

    public static function read(Context $context): self
    {
        if (!$context->isGitRepo()) {
            return new self(null, 'not a git checkout, so there is no tag to compare against');
        }
        if ($context->isShallowClone()) {
            return new self(
                null,
                'shallow clone — its history stops short of any tag '
                . '(a workflow needs fetch-depth: 0 and fetch-tags: true)',
            );
        }

        $tag = $context->newestTag();
        if ($tag !== null) {
            return new self($tag, null);
        }

        // A full checkout without tags has released nothing yet; lost tags are
        // release-tags' finding.
        return new self(null, null);
    }

    /** Reports the gap, if there is one. True when the rule may go on. */
    public function evaluable(string $check, Reporter $reporter): bool
    {
        if ($this->gap !== null) {
            $reporter->warn("{$check} not evaluated: {$this->gap}");

            return false;
        }

        return $this->tag !== null;
    }

    /** The tag without its `v`, which is what info.xml carries. */
    public function version(): string
    {
        return ltrim((string) $this->tag, 'v');
    }
}
