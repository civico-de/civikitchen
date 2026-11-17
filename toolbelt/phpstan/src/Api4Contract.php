<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Type;

/**
 * The shared judgement behind the three APIv4 contract rules. It reports only
 * provable errors: non-literal names, unresolvable dotted paths and entities
 * with an incomplete field list pass silently.
 */
final class Api4Contract
{
    private const CLAUSE_OPERATORS = ['AND', 'OR', 'NOT'];

    /**
     * Actions whose select/where/orderBy/values name the entity's own fields.
     * getFields, getActions and custom actions filter rows of another shape.
     */
    private const RECORD_ACTIONS = ['get', 'create', 'update', 'save', 'delete', 'replace'];

    private string $extensionDir;

    private ClassLookup $classes;

    /** @var list<string>|null */
    private ?array $ownEntities = null;

    public function __construct(string $currentWorkingDirectory, ClassLookup $classes)
    {
        $this->extensionDir = $currentWorkingDirectory;
        $this->classes = $classes;
    }

    /**
     * The entity name behind a literal expression, or null to stay silent.
     */
    public function literalString(Expr $expr, Scope $scope): ?string
    {
        return $this->literalStringFromType($scope->getType($expr));
    }

    private function literalStringFromType(Type $type): ?string
    {
        $strings = $type->getConstantStrings();

        return count($strings) === 1 ? $strings[0]->getValue() : null;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function checkEntity(string $entity, string $context): array
    {
        return self::errors($this->entityProblem($entity, $context), 'ck.api4.unknownEntity');
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function checkAction(string $entity, string $action, string $context): array
    {
        return self::errors($this->actionProblem($entity, $action, $context), 'ck.api4.unknownAction');
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function checkField(string $entity, string $field, string $clause): array
    {
        return self::errors(self::fieldProblem($entity, $field, $clause), 'ck.api4.unknownField');
    }

    /**
     * @param list<string> $shadowedAliases aliases an explicit join rebound
     *
     * @return list<IdentifierRuleError>
     */
    public function checkJoinField(string $entity, string $path, string $clause, array $shadowedAliases): array
    {
        return self::errors(self::joinFieldProblem($entity, $path, $clause, $shadowedAliases), 'ck.api4.unknownField');
    }

    /** @return list<IdentifierRuleError> */
    private static function errors(?string $message, string $identifier): array
    {
        return $message === null ? [] : [RuleErrorBuilder::message($message)->identifier($identifier)->build()];
    }

    /** The unknown-entity message, or null when the name is fine or not provably wrong. */
    public function entityProblem(string $entity, string $context): ?string
    {
        if ($this->entityIsKnown($entity)) {
            return null;
        }

        // An unknown entity is reported only as a near-miss of a core one;
        // other extensions' entities are not in the analysis.
        $suggestion = self::nearestEntity($entity);
        if ($suggestion === null) {
            return null;
        }

        return sprintf(
            'APIv4 entity %s does not exist in CiviCRM %s — did you mean %s? (%s)',
            $entity,
            Api4Catalog::CORE_VERSION,
            $suggestion,
            $context,
        );
    }

    /** The unknown-action message, or null. */
    public function actionProblem(string $entity, string $action, string $context): ?string
    {
        // Only core's actions are catalogued. An entity from another
        // extension brings its own, unknown, set.
        if (!Api4Catalog::knowsEntity($entity)) {
            return null;
        }
        // Request::create() resolves the action through is_callable(), and php
        // method names are case-insensitive: `User::Update` runs `update`.
        if (in_array(strtolower($action), array_map(strtolower(...), Api4Catalog::actions($entity)), true)) {
            return null;
        }
        // Extensions add actions to core entities via Civi\Api4\Action\<Entity>\.
        if ($this->classes->declaredName(sprintf(
            'Civi\\Api4\\Action\\%s\\%s',
            self::entityClass($entity),
            ucfirst($action),
        )) !== null) {
            return null;
        }

        return sprintf(
            'APIv4 action %s::%s does not exist in CiviCRM %s — %s',
            $entity,
            $action,
            Api4Catalog::CORE_VERSION,
            $context,
        );
    }

    /** The unknown-field message, or null. */
    public static function fieldProblem(string $entity, string $field, string $clause): ?string
    {
        if (!self::rejectsField($entity, $field, $clause)) {
            return null;
        }
        [$name] = explode(':', $field, 2);

        return sprintf(
            'APIv4 field %s.%s does not exist in CiviCRM %s — %s',
            $entity,
            $name,
            Api4Catalog::CORE_VERSION,
            $clause,
        );
    }

    /**
     * The right-hand side of a dotted path, against the join's target entity;
     * checked only when the left side is an implicit join the catalog knows.
     *
     * @param list<string> $shadowedAliases aliases an explicit join rebound
     */
    public static function joinFieldProblem(string $entity, string $path, string $clause, array $shadowedAliases): ?string
    {
        $parts = explode('.', $path);
        if (count($parts) !== 2) {
            return null;
        }
        [$alias, $field] = $parts;
        if (in_array($alias, $shadowedAliases, true)) {
            return null;
        }
        $target = Api4Catalog::joins($entity)[$alias] ?? null;
        if ($target === null) {
            return null;
        }

        return self::fieldProblem($target, $field, $path . ' in ' . $clause);
    }

    /**
     * The core entity an unknown name is a slip of, if any.
     *
     * An added, dropped or changed letter makes another extension's entity
     * (Contract, Groups, Vote); so does a swap in a name under four letters.
     */
    private static function nearestEntity(string $entity): ?string
    {
        if (strlen($entity) < 4) {
            return null;
        }
        foreach (array_keys(Api4Catalog::ENTITIES) as $known) {
            if (self::isSlipOf($entity, (string) $known)) {
                return (string) $known;
            }
        }

        return null;
    }

    /** Equal ignoring case, or but for one swapped pair of neighbouring letters. */
    private static function isSlipOf(string $typed, string $known): bool
    {
        [$a, $b] = [strtolower($typed), strtolower($known)];
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        $differ = array_keys(array_diff_assoc(str_split($a), str_split($b)));
        if ($differ === []) {
            return true;
        }

        return count($differ) === 2 && $differ[1] === $differ[0] + 1
            && $a[$differ[0]] === $b[$differ[1]] && $a[$differ[1]] === $b[$differ[0]];
    }

    /** Whether the action's clauses name fields of the entity itself. */
    public static function readsEntityFields(string $action): bool
    {
        return in_array(strtolower($action), self::RECORD_ACTIONS, true);
    }

    /**
     * Whether checkField reports the name. Static and free of phpstan types,
     * so the live getFields drift test asks the same question on a booted site.
     */
    public static function rejectsField(string $entity, string $field, string $clause): bool
    {
        if (!Api4Catalog::hasCompleteFields($entity) || !self::isCheckableFieldName($field)) {
            return false;
        }
        // `status_id:label` selects the option label of a real field.
        [$name] = explode(':', $field, 2);
        if (in_array($name, Api4Catalog::fields($entity), true) || in_array($name, Api4Catalog::ANY_ENTITY_FIELDS, true)) {
            return false;
        }

        // A write passes its values on to the BAO, which reads control params
        // next to the fields (core's skipStatusCal); only the case tells them apart.
        return !(self::isWriteClause($clause) && preg_match('/[A-Z]/', $name) === 1);
    }

    /** Clauses that write, and therefore reach the BAO. */
    private static function isWriteClause(string $clause): bool
    {
        return $clause === 'values' || strtolower($clause) === 'addvalue()';
    }

    /**
     * Names the catalog may judge: no dot (join, custom field), no SQL
     * expression characters, not SearchKit's synthetic `row_count`.
     */
    private static function isCheckableFieldName(string $field): bool
    {
        if ($field === '' || $field === 'row_count') {
            return false;
        }
        foreach (['.', '*', '(', ')', ' ', '"', "'", '@'] as $needle) {
            if (str_contains($field, $needle)) {
                return false;
            }
        }
        foreach (Api4Catalog::DYNAMIC_FIELD_PREFIXES as $prefix) {
            if (str_starts_with($field, $prefix)) {
                return false;
            }
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*(:[A-Za-z_]+)?$/', $field) === 1;
    }

    private function entityIsKnown(string $entity): bool
    {
        if (Api4Catalog::knowsEntity($entity)) {
            return true;
        }
        foreach (Api4Catalog::DYNAMIC_PREFIXES as $prefix) {
            if (str_starts_with($entity, $prefix)) {
                return true;
            }
        }
        // Entities the analysis sees a class for (own and required extensions);
        // core looks them up by exact name, so `contact` is not Contact.
        $class = 'Civi\\Api4\\' . self::entityClass($entity);
        if ($this->classes->declaredName($class) === $class) {
            return true;
        }

        return in_array($entity, $this->ownEntities(), true);
    }

    /** The php class behind an entity name — `Case` lives in CiviCase. */
    private static function entityClass(string $entity): string
    {
        $class = array_search($entity, Api4Catalog::CLASS_ALIASES, true);

        return $class === false ? $entity : $class;
    }

    /**
     * Entity names the analysed extension defines itself, for classes the
     * autoloader does not reach.
     *
     * @return list<string>
     */
    private function ownEntities(): array
    {
        if ($this->ownEntities !== null) {
            return $this->ownEntities;
        }

        $this->ownEntities = [];
        foreach (glob($this->extensionDir . '/Civi/Api4/*.php') ?: [] as $file) {
            $this->ownEntities[] = basename($file, '.php');
        }

        return $this->ownEntities;
    }

    /**
     * Field names in the params array of civicrm_api4().
     *
     * @return list<array{string, string}> field name and the clause it came from
     */
    public function fieldsFromParams(Type $params, Scope $scope): array
    {
        $arrays = $params->getConstantArrays();
        if (count($arrays) !== 1) {
            return [];
        }
        $entries = self::entries($arrays[0]);
        // `SUM(qty) AS total` makes `total` a legal name in orderBy and groupBy.
        $aliases = [];
        if (isset($entries['select'])) {
            foreach ($this->listOfStrings($entries['select']) as $select) {
                if (preg_match('/\sAS\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $select, $m) === 1) {
                    $aliases[] = $m[1];
                }
            }
        }

        $found = [];
        foreach ($entries as $key => $value) {
            switch ($key) {
                case 'select':
                case 'groupBy':
                    foreach ($this->listOfStrings($value) as $field) {
                        $found[] = [$field, $key];
                    }
                    break;

                case 'where':
                    foreach ($this->fieldsFromWhere($value) as $field) {
                        $found[] = [$field, 'where'];
                    }
                    break;

                case 'orderBy':
                case 'values':
                    foreach ($this->keys($value) as $field) {
                        $found[] = [$field, $key];
                    }
                    break;
            }
        }

        return array_values(array_filter(
            $found,
            static fn (array $entry): bool => !in_array($entry[0], $aliases, true),
        ));
    }

    /**
     * Field names in a where clause: [['field', '=', 1], ['OR', [...]]].
     *
     * @return list<string>
     */
    public function fieldsFromWhere(Type $where, int $depth = 0): array
    {
        $arrays = $where->getConstantArrays();
        if (count($arrays) !== 1 || $depth > 4) {
            return [];
        }
        $found = [];
        foreach ($arrays[0]->getValueTypes() as $clause) {
            $clauseArrays = $clause->getConstantArrays();
            if (count($clauseArrays) !== 1) {
                continue;
            }
            $parts = $clauseArrays[0]->getValueTypes();
            $first = isset($parts[0]) ? $this->literalStringFromType($parts[0]) : null;
            if ($first === null) {
                continue;
            }
            if (in_array(strtoupper($first), self::CLAUSE_OPERATORS, true)) {
                if (isset($parts[1])) {
                    $found = array_merge($found, $this->fieldsFromWhere($parts[1], $depth + 1));
                }
                continue;
            }
            $found[] = $first;
        }

        return $found;
    }

    /** @return list<string> */
    public function listOfStrings(Type $type): array
    {
        $arrays = $type->getConstantArrays();
        if (count($arrays) !== 1) {
            return [];
        }
        $strings = [];
        foreach ($arrays[0]->getValueTypes() as $value) {
            $string = $this->literalStringFromType($value);
            if ($string !== null) {
                $strings[] = $string;
            }
        }

        return $strings;
    }

    /** @return list<string> */
    public function keys(Type $type): array
    {
        $arrays = $type->getConstantArrays();
        if (count($arrays) !== 1) {
            return [];
        }
        $keys = [];
        foreach ($arrays[0]->getKeyTypes() as $key) {
            $string = $this->literalStringFromType($key);
            if ($string !== null) {
                $keys[] = $string;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, Type>
     */
    private static function entries(ConstantArrayType $array): array
    {
        $entries = [];
        foreach ($array->getKeyTypes() as $i => $key) {
            $strings = $key->getConstantStrings();
            if (count($strings) === 1) {
                $entries[$strings[0]->getValue()] = $array->getValueTypes()[$i];
            }
        }

        return $entries;
    }
}
