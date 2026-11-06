<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;

/**
 * Core's GenericHookEvent exposes every hook parameter as a magic property, so
 * access on that class is its contract (phpstan: universalObjectCratesClasses).
 * A subclass documents its own fields with @property and keeps the finding.
 */
final class GenericHookEventFilterHook implements IssueFilterHook
{
    private const MESSAGE = '/^Ambiguous property access: \$\w+ on class `\\\\?Civi\\\\Core\\\\Event\\\\GenericHookEvent`\.$/i';

    public function getCodes(): array
    {
        return ['non-documented-property'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        return preg_match(self::MESSAGE, $context->issue->message) === 1 ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }
}
