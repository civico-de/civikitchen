<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Linter;

use CiviKitchen\Mago\Linter\Support\Syntax;
use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\NodeKind;

/**
 * Bare ts() resolves in core's translation domain, so an extension's own .po
 * files are never consulted. Flags calls of the global ts() and 'ts' as the
 * whole callback argument of the higher-order functions in Syntax::CALLBACK_POSITIONS.
 */
final class UseExtensionTsRule implements Rule
{
    private const MESSAGE = 'Bare ts() resolves in core\'s translation domain — use E::ts() (the civix '
        . 'CRM_<Ext>_ExtensionUtil helper) so the extension\'s own translations apply';

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'civikitchen/use-extension-ts',
            name: 'Use E::ts()',
            description: 'Extension code translates through E::ts(), not core\'s global ts().',
            defaultLevel: Level::Error,
            defaultEnabled: true,
            targets: [NodeKind::FunctionCall, NodeKind::FunctionPartialApplication],
        );
    }

    public function lint(LintContext $context): void
    {
        $source = $context->file;
        $callee = Syntax::unwrap($source, $source->getChildren($context->node)[0]);
        $name = Syntax::globalFunctionName($source, $callee);
        if ($name === 'ts') {
            $context->report(Issue::new(self::MESSAGE, $callee->span));
            return;
        }
        if ($name === null || !isset(Syntax::CALLBACK_POSITIONS[$name]) || $context->node->kind !== NodeKind::FunctionCall) {
            return;
        }
        $callback = Syntax::parameter(Syntax::arguments($source, $context->node), Syntax::CALLBACK_POSITIONS[$name], 'callback');
        $text = $callback === null || $callback['spread'] || $callback['value'] === null ? null : Syntax::stringLiteral($source, $callback['value']);
        if ($text !== null && strcasecmp(Syntax::stripQuotes($text), 'ts') === 0) {
            $context->report(Issue::new(self::MESSAGE, $callback['value']->span));
        }
    }
}
