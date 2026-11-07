<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;

/**
 * GenericHookEvent's magic properties are its contract (phpstan: universalObjectCratesClasses);
 * a subclass documents its fields with @property.
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
