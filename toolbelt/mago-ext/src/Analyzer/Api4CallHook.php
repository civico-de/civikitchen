<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\Mago\Linter\Support\Syntax;
use CiviKitchen\Mago\Workspace;
use CiviKitchen\PHPStan\Api4Contract;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

/**
 * `civicrm_api4('Entity', 'action', [...])`, `\Civi\Api4\Entity::action()`
 * and its first-class callable form against the core contract.
 */
final class Api4CallHook implements NodeAnalysisHook
{
    public function getTargets(): array
    {
        return [NodeKind::FunctionCall, NodeKind::StaticMethodCall, NodeKind::StaticMethodPartialApplication];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText, FileAnalysisRequirement::ExpressionTypes];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $contract = new Api4Contract(Workspace::root(), new CodebaseClassLookup($context->codebase));
        $problems = $context->node->kind === NodeKind::FunctionCall ? $this->functionCall($context, $contract) : $this->staticCall($context, $contract);
        foreach ($problems as [$code, $message]) {
            $context->report(Level::Error, $code, Issue::new($message, $context->node->span));
        }
    }

    /** The lowercase function PHP calls: an alias resolved, an unqualified name falling back to the global one. */
    private static function functionName(NodeAnalysisContext $context, Node $identifier): string
    {
        $source = $context->source;
        $resolved = $source->getResolvedName($identifier);
        $name = strtolower(ltrim($resolved?->name ?? $source->getText($identifier), '\\'));
        if ($identifier->kind === NodeKind::LocalIdentifier && !($resolved?->imported ?? false) && !$context->codebase->functionExists($name)) {
            return strtolower($source->getText($identifier));
        }

        return $name;
    }

    /** @return list<array{string, string}> code and message */
    private function functionCall(NodeAnalysisContext $context, Api4Contract $contract): array
    {
        $source = $context->source;
        $callee = Syntax::unwrap($source, $source->getChildren($context->node)[0]);
        $identifier = $callee->kind === NodeKind::Identifier ? $source->getChildren($callee)[0] ?? null : null;
        if ($identifier === null || !in_array($identifier->kind, [NodeKind::LocalIdentifier, NodeKind::FullyQualifiedIdentifier], true)
            || self::functionName($context, $identifier) !== 'civicrm_api4') {
            return [];
        }
        // civicrm_api4(string $entity, string $action, array $params = [], $index = NULL)
        $call = CallExpression::fromNode($source, $context->node);
        $entity = Types::literalString(Api4Calls::typeOf($context, Api4Calls::argument($call, 0, 'entity')));
        if ($entity === null) {
            return [];
        }
        $problem = $contract->entityProblem($entity, 'civicrm_api4()');
        if ($problem !== null) {
            return [['api4-unknown-entity', $problem]];
        }

        $problems = [];
        $action = Types::literalString(Api4Calls::typeOf($context, Api4Calls::argument($call, 1, 'action')));
        $problem = $action === null ? null : $contract->actionProblem($entity, $action, 'civicrm_api4()');
        if ($problem !== null) {
            $problems[] = ['api4-unknown-action', $problem];
        }
        $params = Api4Calls::argument($call, 2, 'params');
        if ($action !== null && Api4Contract::readsEntityFields($action) && $params !== null) {
            foreach (self::fieldsFromParams(Api4Calls::typeOf($context, $params)) as [$field, $clause]) {
                $problem = Api4Contract::fieldProblem($entity, $field, $clause);
                if ($problem !== null) {
                    $problems[] = ['api4-unknown-field', $problem];
                }
            }
        }

        return $problems;
    }

    /** @return list<array{string, string}> code and message */
    private function staticCall(NodeAnalysisContext $context, Api4Contract $contract): array
    {
        $source = $context->source;
        [$class, $selector] = $source->getChildren($context->node);
        $entity = Api4Calls::entityOfClassReference($source, $class, new CodebaseClassLookup($context->codebase));
        $method = $selector->kind === NodeKind::ClassLikeMemberSelector ? $source->getChildren($selector)[0] ?? null : null;
        if ($entity === null || $method === null || $method->kind !== NodeKind::LocalIdentifier) {
            return [];
        }
        $problem = $contract->entityProblem($entity, '\\Civi\\Api4\\' . $entity);
        if ($problem !== null) {
            return [['api4-unknown-entity', $problem]];
        }
        $problem = $contract->actionProblem($entity, $source->getText($method), 'fluent APIv4 call');

        return $problem === null ? [] : [['api4-unknown-action', $problem]];
    }

    /**
     * Field names in the params array, each with the clause it came from.
     *
     * @return list<array{string, string}>
     */
    private static function fieldsFromParams(?Type $params): array
    {
        $entries = [];
        foreach (Types::entries($params) ?? [] as $entry) {
            if (is_string($entry['key'])) {
                $entries[$entry['key']] = $entry['value'];
            }
        }
        // `SUM(qty) AS total` makes `total` a legal name in orderBy and groupBy.
        $aliases = [];
        foreach (isset($entries['select']) ? Types::listOfStrings($entries['select']) : [] as $select) {
            if (preg_match('/\sAS\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $select, $m) === 1) {
                $aliases[] = $m[1];
            }
        }

        $found = [];
        foreach ($entries as $key => $value) {
            $fields = match ($key) {
                'select', 'groupBy' => Types::listOfStrings($value),
                'where' => Types::fieldsFromWhere($value),
                'orderBy', 'values' => Types::keys($value),
                default => [],
            };
            foreach ($fields as $field) {
                if (!in_array($field, $aliases, true)) {
                    $found[] = [$field, $key];
                }
            }
        }

        return $found;
    }
}
