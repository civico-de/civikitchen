<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Toolbelt\Version\SemVer;

/**
 * `info.xml` ahead of the newest tag (bumped, never tagged) or below a reachable
 * tag (sorts under what is published), by SemVer precedence. Silent for
 * `release: none`; non-SemVer values are left to version-format.
 */
final class ReleaseTagCoherenceCheck implements Check
{
    public function name(): string
    {
        return 'release-tag-coherence';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $version = $context->infoVersion();
        if ($version === '' || $context->policyValue('release') !== null) {
            return;
        }

        $history = ReleaseHistory::read($context);
        if (!$history->evaluable($this->name(), $reporter)) {
            return;
        }

        if (!SemVer::valid($version)) {
            return;
        }

        $highest = null;
        foreach ($context->reachableTags() as $tag) {
            $tagVersion = substr($tag, 1);
            if (SemVer::valid($tagVersion) && ($highest === null || SemVer::compare($tagVersion, substr($highest, 1)) > 0)) {
                $highest = $tag;
            }
        }
        if ($highest !== null && SemVer::compare($version, substr($highest, 1)) < 0) {
            $reporter->fail(sprintf(
                'info.xml <version> %s is below the tag %s — a release from here sorts under what is already '
                . 'published and reaches no consumer as an update; release a version above %s',
                $version,
                $highest,
                substr($highest, 1),
            ));

            return;
        }

        $newest = $history->version();
        if (SemVer::valid($newest) && SemVer::compare($version, $newest) > 0) {
            $reporter->fail(sprintf(
                'info.xml <version> %s is ahead of the newest tag %s — the bump was committed but never tagged, '
                . 'so nothing released carries it (git tag -a v%s && git push origin v%s)',
                $version,
                (string) $history->tag,
                $version,
                $version,
            ));
        }
    }
}
