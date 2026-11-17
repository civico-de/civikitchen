<?php

declare(strict_types=1);

/**
 * Regenerate src/Api4Catalog.php from a CiviCRM checkout.
 *
 * Usage:
 *   php tools/gen-api4-catalog.php <core-dir> [out-file]
 *
 * Tokenizes the source tree only, no booted CiviCRM. Entities are the classes
 * in Civi/Api4/, actions their static factories plus Action/<Entity>/ classes,
 * fields come from schema/*.entityType.php; entities without one are incomplete.
 */

require_once __DIR__ . '/catalog-common.php';

if ($argc < 2) {
    fwrite(STDERR, "usage: gen-api4-catalog.php <core-dir> [out-file]\n");
    exit(64);
}

$coreDir = rtrim($argv[1], '/');

if (!is_file($coreDir . '/Civi.php') || !is_dir($coreDir . '/Civi/Api4')) {
    fwrite(STDERR, "not a CiviCRM core checkout: $coreDir has no Civi.php/Civi/Api4\n");
    exit(66);
}

/**
 * Static methods on entity classes that are not API actions.
 */
const NOT_ACTIONS = ['getEntityName', 'getEntityTitle', 'getInfo', 'getDaoName', 'permissions'];

/**
 * Entity name prefixes whose entities only exist once a site is configured;
 * the rule assumes they exist.
 */
const DYNAMIC_PREFIXES = ['Custom_', 'Eck_', 'SK_', 'Import_'];

/**
 * Field-name prefixes core computes from site configuration (SearchKit's
 * `segment_<name>`).
 */
const DYNAMIC_FIELD_PREFIXES = ['segment_'];

/**
 * Providers that compute field names, each covered elsewhere: Contact by
 * DYNAMIC_SPEC_FIELDS, SearchSegment by DYNAMIC_FIELD_PREFIXES, PriceField by
 * the dot rule, FullTextSearch by ftsDefaultIndexFields(). Others get a warning.
 */
const REVIEWED_COMPUTED_PROVIDERS = [
    'ContactSpecProvider',
    'FullTextSearchFilterSpecProvider',
    'PriceFieldSpecProvider',
    'SearchSegmentExtraFieldProvider',
];

/**
 * Spec-provider fields whose names core computes: ContactSpecProvider builds
 * `strtolower($entity) . '_' . $type` over two nested literal arrays.
 */
const DYNAMIC_SPEC_FIELDS = [
    'Contact' => [
        'address_primary', 'address_billing',
        'email_primary', 'email_billing',
        'phone_primary', 'phone_billing',
        'im_primary', 'im_billing',
    ],
];

/**
 * Joins ContactSchemaMapSubscriber adds on `api.schema_map.build`; another
 * subscriber to that event would have to be added here.
 */
const IMPLICIT_JOINS = [
    'Contact' => [
        'address_primary' => 'Address', 'address_billing' => 'Address',
        'email_primary' => 'Email', 'email_billing' => 'Email',
        'phone_primary' => 'Phone', 'phone_billing' => 'Phone',
        'im_primary' => 'IM', 'im_billing' => 'IM',
    ],
];

/** Tokens without whitespace and comments, reindexed. */
function significantTokens(string $source): array
{
    $tokens = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $tokens[] = $token;
    }

    return array_values($tokens);
}

function tokenIs(array $tokens, int $i, $type): bool
{
    $token = $tokens[$i] ?? null;
    if ($token === null) {
        return false;
    }

    return is_string($type) ? $token === $type : (is_array($token) && $token[0] === $type);
}

function tokenText(array $tokens, int $i): string
{
    $token = $tokens[$i] ?? '';

    return is_array($token) ? $token[1] : $token;
}

/**
 * The class declared in a PHP source, as [name, parent, isAbstract].
 */
function classDeclaration(string $source): ?array
{
    $tokens = significantTokens($source);
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_CLASS) {
            continue;
        }
        // `::class` and anonymous classes are not declarations.
        if (tokenIs($tokens, $i - 1, T_DOUBLE_COLON) || tokenIs($tokens, $i + 1, '(')) {
            continue;
        }
        $abstract = tokenIs($tokens, $i - 1, T_ABSTRACT)
            || tokenIs($tokens, $i - 2, T_ABSTRACT);
        $name = tokenText($tokens, $i + 1);
        $parent = null;
        if (tokenIs($tokens, $i + 2, T_EXTENDS)) {
            $parent = ltrim(tokenText($tokens, $i + 3), '\\');
        }

        return [$name, $parent, $abstract];
    }

    return null;
}

/**
 * Traits a class pulls in, by short name: a `use` inside the class body, not
 * an import or a closure's `use (...)`. Traits carry actions too.
 *
 * @return list<string>
 */
function traitNames(string $source): array
{
    $tokens = significantTokens($source);
    $count = count($tokens);
    $depth = 0;
    $names = [];

    for ($i = 0; $i < $count; $i++) {
        if (tokenIs($tokens, $i, '{') || tokenIs($tokens, $i, T_CURLY_OPEN) || tokenIs($tokens, $i, T_DOLLAR_OPEN_CURLY_BRACES)) {
            $depth++;
            continue;
        }
        if (tokenIs($tokens, $i, '}')) {
            $depth--;
            continue;
        }
        if ($depth < 1 || !tokenIs($tokens, $i, T_USE) || tokenIs($tokens, $i + 1, '(')) {
            continue;
        }
        for ($j = $i + 1; $j < $count; $j++) {
            if (tokenIs($tokens, $j, ';') || tokenIs($tokens, $j, '{')) {
                break;
            }
            if (tokenIs($tokens, $j, ',')) {
                continue;
            }
            $name = ltrim(tokenText($tokens, $j), '\\');
            if ($name !== '') {
                $names[] = substr($name, (int) strrpos('\\' . $name, '\\'));
            }
        }
    }

    return array_values(array_unique($names));
}

/**
 * Public static methods of a class, as name => body source; abstract ones
 * (AbstractEntity::getFields) map to ''.
 */
function staticMethods(string $source, bool $staticOnly = true): array
{
    $tokens = significantTokens($source);
    $methods = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (!tokenIs($tokens, $i, T_FUNCTION)) {
            continue;
        }
        $isStatic = false;
        for ($back = $i - 1; $back >= 0 && $back >= $i - 4; $back--) {
            if (tokenIs($tokens, $back, T_STATIC)) {
                $isStatic = true;
            } elseif (!is_array($tokens[$back]) || !in_array($tokens[$back][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_ABSTRACT, T_FINAL], true)) {
                break;
            }
        }
        if ($staticOnly && !$isStatic) {
            continue;
        }
        $name = tokenText($tokens, $i + 1);
        // Walk to the body, or to the `;` of an abstract declaration.
        $body = '';
        for ($j = $i + 2; $j < $count; $j++) {
            if (tokenIs($tokens, $j, ';')) {
                break;
            }
            if (tokenIs($tokens, $j, '{')) {
                $depth = 0;
                for ($k = $j; $k < $count; $k++) {
                    if (tokenIs($tokens, $k, '{') || tokenIs($tokens, $k, T_CURLY_OPEN) || tokenIs($tokens, $k, T_DOLLAR_OPEN_CURLY_BRACES)) {
                        $depth++;
                    } elseif (tokenIs($tokens, $k, '}')) {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                    $body .= tokenText($tokens, $k);
                }
                break;
            }
        }
        $methods[$name] = $body;
    }

    return $methods;
}

/** Does this static method body build and return an API action object? */
function isActionFactory(string $name, string $body): bool
{
    if (in_array($name, NOT_ACTIONS, true)) {
        return false;
    }
    if ($body === '') {
        // Abstract declaration on a base class: getFields and friends.
        return true;
    }

    return str_contains($body, '__FUNCTION__')
        || preg_match('/new\s*\\\\?(\w+\\\\)*\w*Action\s*\(/', $body) === 1;
}

/**
 * Field names and implicit-join targets of an entityType definition, parsed:
 * depth-1 keys of getFields are fields, a depth-2 `entity_reference` makes the
 * field a join to its target entity (SchemaMapBuilder::addJoins).
 *
 * @return array{list<string>, array<string, string>} [fields, join name => target]
 */
function entityTypeSchema(string $source): array
{
    $tokens = significantTokens($source);
    $count = count($tokens);
    $fields = [];
    $references = [];

    for ($i = 0; $i < $count; $i++) {
        if (!tokenIs($tokens, $i, T_CONSTANT_ENCAPSED_STRING) || trim(tokenText($tokens, $i), "'\"") !== 'getFields') {
            continue;
        }
        // 'getFields' => fn() => [
        $open = null;
        for ($j = $i + 1; $j < min($i + 12, $count); $j++) {
            if (tokenIs($tokens, $j, '[')) {
                $open = $j;
                break;
            }
        }
        if ($open === null) {
            continue;
        }
        $depth = 1;
        $field = null;
        for ($j = $open + 1; $j < $count && $depth > 0; $j++) {
            if (tokenIs($tokens, $j, '[')) {
                $depth++;
                continue;
            }
            if (tokenIs($tokens, $j, ']')) {
                $depth--;
                continue;
            }
            if (!tokenIs($tokens, $j, T_CONSTANT_ENCAPSED_STRING) || !tokenIs($tokens, $j + 1, T_DOUBLE_ARROW)) {
                continue;
            }
            $key = trim(tokenText($tokens, $j), "'\"");
            if ($depth === 1) {
                $fields[] = $key;
                $field = $key;
                continue;
            }
            if ($depth !== 2 || $key !== 'entity_reference' || $field === null) {
                continue;
            }
            // 'entity_reference' => ['entity' => 'Contact', 'key' => 'id'],
            for ($k = $j + 2; $k < min($j + 24, $count); $k++) {
                if (tokenIs($tokens, $k, ']')) {
                    break;
                }
                if (
                    tokenIs($tokens, $k, T_CONSTANT_ENCAPSED_STRING)
                    && trim(tokenText($tokens, $k), "'\"") === 'entity'
                    && tokenIs($tokens, $k + 1, T_DOUBLE_ARROW)
                    && tokenIs($tokens, $k + 2, T_CONSTANT_ENCAPSED_STRING)
                ) {
                    $references[$field] = trim(tokenText($tokens, $k + 2), "'\"");
                    break;
                }
            }
        }
        break;
    }

    return [$fields, $references];
}

/** The entity name a class declares, when it differs from the class name. */
function declaredEntityName(array $methods, string $fallback): string
{
    $body = $methods['getEntityName'] ?? '';
    if ($body !== '' && preg_match('/return\s*(\'[^\']+\'|"[^"]+")/', $body, $m) === 1) {
        return trim($m[1], '\'"');
    }

    return $fallback;
}

// ---------------------------------------------------------------------------
// Collect classes: entities, the Generic base classes they extend and the
// traits they use.
// ---------------------------------------------------------------------------

$roots = classloaderRoots($coreDir);

/** @var array<string, array{parent: ?string, uses: list<string>, abstract: bool, actions: list<string>, entity: string, isEntity: bool}> */
$classes = [];
/** @var array<string, list<string>> class name => actions from Action/<Class>/ */
$magicActions = [];

foreach ($roots as $root) {
    foreach (array_merge(
        glob($root . '/Civi/Api4/*.php') ?: [],
        glob($root . '/Civi/Api4/Generic/*.php') ?: [],
        glob($root . '/Civi/Api4/Generic/Traits/*.php') ?: [],
    ) as $file) {
        $source = (string) file_get_contents($file);
        $declaration = classDeclaration($source);
        if ($declaration === null) {
            // A trait carries actions the same way a base class does.
            if (preg_match('/\btrait\s+(\w+)/', $source, $m) !== 1) {
                continue;
            }
            $declaration = [$m[1], null, true];
        }
        [$name, $parent, $abstract] = $declaration;
        $methods = staticMethods($source);
        $actions = [];
        foreach ($methods as $method => $body) {
            if (isActionFactory($method, $body)) {
                $actions[] = $method;
            }
        }
        $classes[$name] = [
            'parent' => $parent === null ? null : preg_replace('/^Generic\\\\/', '', $parent),
            'uses' => traitNames($source),
            'abstract' => $abstract,
            'actions' => $actions,
            'entity' => declaredEntityName($methods, $name),
            'isEntity' => str_ends_with(dirname($file), '/Civi/Api4'),
        ];
    }

    foreach (glob($root . '/Civi/Api4/Action/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $class = basename($dir);
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            // Abstract bases and traits share the directory with the actions
            // but are not callable — AbstractProcessor is not an action.
            $declaration = classDeclaration((string) file_get_contents($file));
            if ($declaration === null || $declaration[2]) {
                continue;
            }
            $magicActions[$class][] = lcfirst(basename($file, '.php'));
        }
    }
}

// ---------------------------------------------------------------------------
// Collect fields from the entityType definitions.
// ---------------------------------------------------------------------------

/** @var array<string, list<string>> entity name => field names */
$entityTypeFields = [];

/** @var array<string, array<string, string>> entity name => join name => target */
$entityTypeJoins = [];

foreach ($roots as $root) {
    $schemaDir = $root . '/schema';
    if (!is_dir($schemaDir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($schemaDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $entry) {
        if (!$entry->isFile() || !str_ends_with($entry->getFilename(), '.entityType.php')) {
            continue;
        }
        $source = (string) file_get_contents($entry->getPathname());
        if (preg_match('/[\'"]name[\'"]\s*=>\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $source, $m) !== 1) {
            continue;
        }
        [$fields, $joins] = entityTypeSchema($source);
        if ($fields !== []) {
            $entityTypeFields[$m[1]] = $fields;
            $entityTypeJoins[$m[1]] = $joins;
        }
    }
}

// ---------------------------------------------------------------------------
// Extra fields from spec providers: literal `new FieldSpec('x', ...)` names,
// attributed to the entity the provider names, else to every entity.
// ---------------------------------------------------------------------------

/**
 * Entities CoreUtil::isType() matches: the base class's short name and every
 * trait up the ancestry, as AbstractEntity::getInfo() lists them.
 *
 * @return list<string>
 */
function entitiesOfType(string $type, array $classes): array
{
    $entities = [];
    foreach ($classes as $name => $class) {
        if (!$class['isEntity'] || $class['abstract']) {
            continue;
        }
        $types = [$class['parent']];
        for ($c = $name, $seen = []; $c !== null && isset($classes[$c]) && !isset($seen[$c]); $c = $classes[$c]['parent']) {
            $seen[$c] = true;
            $types = array_merge($types, $classes[$c]['uses']);
        }
        if (in_array($type, $types, true)) {
            $entities[] = $class['entity'];
        }
    }

    return $entities;
}

/**
 * Names of an option group's values: core's sqldata seed plus the managed
 * records of the bundled extensions.
 *
 * @param  list<string> $roots
 * @return list<string>
 */
function optionGroupNames(string $group, array $roots): array
{
    $names = [];
    foreach ($roots as $root) {
        $seed = $root . '/sql/civicrm_data/civicrm_option_group/' . $group . '.sqldata.php';
        $source = is_file($seed) ? (string) file_get_contents($seed) : '';
        if (preg_match('/addValueTable\(\s*\[([^\]]*)\]\s*,\s*\[(.*)\]\s*\)/s', $source, $table) === 1) {
            preg_match_all("/'(\\w+)'/", $table[1], $header);
            $column = array_search('name', $header[1], true);
            preg_match_all('/^\s*\[(.*)\],?\s*$/m', $table[2], $rows);
            foreach ($column === false ? [] : $rows[1] as $row) {
                // ts('Label') is one cell; its argument must not shift the columns.
                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", (string) preg_replace("/ts\\('(?:[^'\\\\]|\\\\.)*'\\)/", "''", $row), $cells);
                $names[] = $cells[1][$column] ?? '';
            }
        }
        foreach (glob($root . '/managed/*.mgd.php') ?: [] as $file) {
            $managed = (string) file_get_contents($file);
            if (preg_match("/'option_group_id(\\.name)?'\\s*=>\\s*'" . preg_quote($group, '/') . "'/", $managed) === 1) {
                preg_match_all("/'name'\\s*=>\\s*'(\\w+)'/", $managed, $values);
                $names = array_merge($names, $values[1]);
            }
        }
    }

    return array_values(array_filter(array_unique($names)));
}

/**
 * Index names of core's default full-text indices, by entity: the keys of
 * FullTextSearch::setDefaultIndices() that carry a 'label'.
 *
 * @param  array<string, true> $entityNames
 * @return array<string, list<string>>
 */
function ftsDefaultIndexFields(string $coreDir, array $entityNames): array
{
    $file = $coreDir . '/Civi/Schema/FullTextSearch.php';
    $body = is_file($file) ? (staticMethods((string) file_get_contents($file), false)['setDefaultIndices'] ?? null) : null;
    if ($body === null) {
        fwrite(STDERR, "FullTextSearchFilterSpecProvider exists but $file has no setDefaultIndices() to read the index names from\n");
        exit(70);
    }
    $fields = [];
    $entity = null;
    preg_match_all("/'(\\w+)'\\s*=>\\s*\\[\\s*('label')?/", $body, $keys, PREG_SET_ORDER);
    foreach ($keys as $key) {
        if (($key[2] ?? '') !== '' && $entity !== null) {
            $fields[$entity][] = $key[1];
        } elseif (isset($entityNames[$key[1]])) {
            $entity = $key[1];
        } elseif ($key[1] !== 'columns') {
            fwrite(STDERR, "$file: setDefaultIndices() key '{$key[1]}' is neither an entity nor an index with 'label' first\n");
            exit(70);
        }
    }
    // Every index declares its columns; a count mismatch means the shape changed.
    $indices = array_sum(array_map('count', $fields));
    if ($indices === 0 || $indices !== preg_match_all("/'columns'\\s*=>/", $body)) {
        fwrite(STDERR, "$file: read $indices index name(s) from setDefaultIndices(), which does not match its 'columns' entries\n");
        exit(70);
    }

    return $fields;
}

/** @var array<string, list<string>> */
$specFields = [];
/** @var list<string> fields from providers with no identifiable entity */
$anyEntityFields = [];
/** @var array<string, list<string>> entity => providers that compute field names */
$computedFieldProviders = [];

foreach (DYNAMIC_SPEC_FIELDS as $entity => $names) {
    $specFields[$entity] = $names;
}

$entityNames = [];
foreach ($classes as $name => $class) {
    if ($class['isEntity']) {
        $entityNames[$class['entity']] = true;
    }
}

foreach ($roots as $root) {
    $providerDir = $root . '/Civi/Api4/Service/Spec/Provider';
    if (!is_dir($providerDir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($providerDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $entry) {
        if (!$entry->isFile() || !str_ends_with($entry->getFilename(), '.php')) {
            continue;
        }
        $source = (string) file_get_contents($entry->getPathname());
        $methods = staticMethods($source, false);
        if ($entry->getFilename() === 'FullTextSearchFilterSpecProvider.php') {
            foreach (ftsDefaultIndexFields($coreDir, $entityNames) as $entity => $names) {
                $specFields[$entity] = array_merge($specFields[$entity] ?? [], $names);
            }
        }
        preg_match_all('/new\s+FieldSpec\s*\(\s*\'([a-z_][A-Za-z0-9_]*)\'/', $source, $m);
        $fields = array_values(array_unique($m[1]));
        $buildsNames = preg_match('/new\s+FieldSpec\s*\(\s*\$/', $source) === 1;
        if ($fields === [] && !$buildsNames) {
            continue;
        }

        $targets = [];
        // Only applies() and `new FieldSpec('x', 'Foo')` name the target; other
        // entity literals in the file are references.
        preg_match_all('/\'([A-Z][A-Za-z0-9_]*)\'/', $methods['applies'] ?? '', $applies);
        preg_match_all('/new\s+FieldSpec\s*\(\s*\'[^\']+\'\s*,\s*\'([A-Z][A-Za-z0-9_]*)\'/', $source, $explicit);
        foreach (array_merge($applies[1], $explicit[1]) as $literal) {
            if (isset($entityNames[$literal])) {
                $targets[$literal] = true;
            }
        }
        // isContact() covers Contact; its subtypes inherit through the ancestry.
        if (str_contains($methods['applies'] ?? '', 'isContact(')) {
            $targets['Contact'] = true;
        }
        // `CoreUtil::isType($entity, 'HierarchicalEntity')` reads the entity's
        // base class and traits; `OptionGroup::values('tag_used_for')` the
        // option values a default install ships.
        preg_match_all('/isType\(\s*\$\w+\s*,\s*\'(\w+)\'/', $methods['applies'] ?? '', $types);
        foreach ($types[1] as $type) {
            $targets += array_fill_keys(entitiesOfType($type, $classes), true);
        }
        preg_match_all('/OptionGroup::values\(\s*\'(\w+)\'/', $methods['applies'] ?? '', $groups);
        foreach ($groups[1] as $group) {
            $targets += array_fill_keys(array_filter(optionGroupNames($group, $roots), static fn (string $n): bool => isset($entityNames[$n])), true);
        }
        // The class name is evidence only where there is no applies().
        $undecidableApplies = isset($methods['applies']) && $targets === [];
        if (!isset($methods['applies'])) {
            $className = basename($entry->getFilename(), '.php');
            $stem = preg_replace('/(Creation|Get|Filter)?SpecProvider$/', '', $className);
            if (isset($entityNames[$stem])) {
                $targets[$stem] = true;
            }
        }

        if ($buildsNames) {
            foreach ($targets === [] ? ['*'] : array_keys($targets) as $target) {
                $computedFieldProviders[$target][] = basename($entry->getFilename(), '.php');
            }
        }

        if ($targets === []) {
            $anyEntityFields = array_merge($anyEntityFields, $fields);
            continue;
        }
        foreach (array_keys($targets) as $target) {
            $specFields[$target] = array_merge($specFields[$target] ?? [], $fields);
        }
    }
}

// ---------------------------------------------------------------------------
// Fold class hierarchy into the entity catalog.
// ---------------------------------------------------------------------------

/** Actions of a class including everything it inherits. */
function inheritedActions(string $class, array $classes, array $magicActions, array $seen = []): array
{
    if (!isset($classes[$class]) || isset($seen[$class])) {
        return [];
    }
    $seen[$class] = true;
    $actions = array_merge(
        $classes[$class]['actions'],
        $magicActions[$class] ?? [],
    );
    // A trait can use traits; $seen keeps the recursion from looping.
    foreach ($classes[$class]['uses'] as $trait) {
        $actions = array_merge($actions, inheritedActions($trait, $classes, $magicActions, $seen));
    }
    $parent = $classes[$class]['parent'];
    if ($parent !== null) {
        $actions = array_merge($actions, inheritedActions($parent, $classes, $magicActions, $seen));
    }

    return $actions;
}

/** Does the class descend from DAOEntity — i.e. is it backed by a table? */
function isDaoEntity(string $class, array $classes, array $seen = []): bool
{
    if (!isset($classes[$class]) || isset($seen[$class])) {
        return false;
    }
    $seen[$class] = true;
    if ($classes[$class]['parent'] === 'DAOEntity') {
        return true;
    }

    return $classes[$class]['parent'] !== null && isDaoEntity($classes[$class]['parent'], $classes, $seen);
}

/** The nearest entity in the ancestry that owns an entityType definition. */
function inheritedFieldSource(string $class, array $classes, array $entityTypeFields, array $seen = []): ?string
{
    if (!isset($classes[$class]) || isset($seen[$class])) {
        return null;
    }
    $seen[$class] = true;
    $entity = $classes[$class]['entity'];
    if ($classes[$class]['isEntity'] && isset($entityTypeFields[$entity])) {
        return $entity;
    }
    $parent = $classes[$class]['parent'];

    return $parent === null ? null : inheritedFieldSource($parent, $classes, $entityTypeFields, $seen);
}

/**
 * Entity names along the ancestry, nearest first, so Individual inherits
 * Contact's spec fields.
 *
 * @return list<string>
 */
function ancestryEntities(string $class, array $classes, array $seen = []): array
{
    if (!isset($classes[$class]) || isset($seen[$class])) {
        return [];
    }
    $seen[$class] = true;
    $entities = $classes[$class]['isEntity'] ? [$classes[$class]['entity']] : [];
    $parent = $classes[$class]['parent'];

    return $parent === null
        ? $entities
        : array_merge($entities, ancestryEntities($parent, $classes, $seen));
}

$catalog = [];
$aliases = [];

foreach ($classes as $class => $info) {
    if (!$info['isEntity'] || $info['abstract']) {
        continue;
    }
    $entity = $info['entity'];
    if ($entity !== $class) {
        $aliases[$class] = $entity;
    }

    $actions = array_values(array_unique(inheritedActions($class, $classes, $magicActions)));
    sort($actions);

    $source = inheritedFieldSource($class, $classes, $entityTypeFields);
    $fields = $source === null ? [] : $entityTypeFields[$source];
    foreach (ancestryEntities($class, $classes) as $ancestor) {
        $fields = array_merge($fields, $specFields[$ancestor] ?? []);
    }
    $fields = array_values(array_unique($fields));
    sort($fields);

    $joins = $source === null ? [] : ($entityTypeJoins[$source] ?? []);
    foreach (ancestryEntities($class, $classes) as $ancestor) {
        $joins = array_merge($joins, IMPLICIT_JOINS[$ancestor] ?? []);
    }
    ksort($joins);

    // Complete only for DAOEntity descendants: Setting has a table but its
    // API fields are the setting names.
    $catalog[$entity] = [
        'actions' => $actions,
        'fields' => $fields,
        'joins' => $joins,
        'complete' => $source !== null && isDaoEntity($class, $classes),
    ];
}

// A join whose target is not in this catalog cannot be validated; drop it.
$knownEntities = array_fill_keys(array_keys($catalog), true);
foreach ($catalog as $entity => $info) {
    $catalog[$entity]['joins'] = array_filter(
        $info['joins'],
        static fn (string $target): bool => isset($knownEntities[$target]),
    );
}

ksort($catalog);
ksort($aliases);
$anyEntityFields = array_values(array_unique($anyEntityFields));
sort($anyEntityFields);

$version = coreVersion($coreDir);

// ---------------------------------------------------------------------------
// Render.
// ---------------------------------------------------------------------------

$export = static fn (string $value): string => var_export($value, true);

$entityLines = '';
$joinLines = '';
foreach ($catalog as $entity => $info) {
    $entityLines .= sprintf(
        "        %s => ['a' => %s, 'f' => %s, 'c' => %s],\n",
        $export((string) $entity),
        $export(implode(' ', $info['actions'])),
        $export(implode(' ', $info['fields'])),
        $info['complete'] ? 'true' : 'false',
    );
    if ($info['joins'] === []) {
        continue;
    }
    $pairs = [];
    foreach ($info['joins'] as $name => $target) {
        $pairs[] = $name . ':' . $target;
    }
    $joinLines .= sprintf(
        "        %s => %s,\n",
        $export((string) $entity),
        $export(implode(' ', $pairs)),
    );
}

$aliasLines = '';
foreach ($aliases as $class => $entity) {
    $aliasLines .= sprintf("        %s => %s,\n", $export((string) $class), $export($entity));
}

$prefixes = renderCatalogList(DYNAMIC_PREFIXES, 6);
$fieldPrefixes = renderCatalogList(DYNAMIC_FIELD_PREFIXES, 6);
$anyFields = renderCatalogList($anyEntityFields, 6);

$out = <<<PHP
<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

/**
 * The APIv4 contract of CiviCRM core — GENERATED, do not edit.
 *
 * Regenerate with:
 *   php tools/gen-api4-catalog.php <core-dir>
 *
 * Per entity: the actions it answers to and the field names a source tree
 * can prove. `c` (complete) says whether the field list may be used to
 * reject an unknown name. It is false wherever the truth only exists on a
 * live site — entities without a schema definition, and everything custom
 * fields, ECK or SearchKit add at runtime. Api4ContractRule checks fields
 * only where `c` is true, and never checks a name containing a dot.
 */
final class Api4Catalog
{
    /**
     * The core release this was generated from.
     *
     * CI reads this to fetch the matching source tree, so the drift gate
     * compares against the exact release rather than a moving branch.
     */
    public const CORE_VERSION = '{$version}';

    /**
     * Entity name => actions, fields, field-list completeness.
     *
     * Space-separated strings rather than nested lists: this is a few
     * thousand names, and one line per entity stays diffable.
     *
     * @var array<string, array{a: string, f: string, c: bool}>
     */
    public const ENTITIES = [
{$entityLines}    ];

    /**
     * Class name => entity name, where the two differ.
     *
     * `\\Civi\\Api4\\CiviCase` is the entity `Case`, because Case is a php
     * keyword. The fluent form uses the class, civicrm_api4() the entity.
     *
     * @var array<string, string>
     */
    public const CLASS_ALIASES = [
{$aliasLines}    ];

    /**
     * Entity name => implicit joins, as `name:TargetEntity` pairs.
     *
     * The left-hand side of `address_primary.street_address`: every field
     * carrying an `entity_reference` becomes a joinable of the same name
     * (SchemaMapBuilder::addJoins), plus the eight primary/billing links
     * ContactSchemaMapSubscriber adds to Contact. Entities with no implicit
     * join are absent.
     *
     * NOT in here, and therefore never judged: explicit `addJoin()` aliases,
     * custom-group joins (named after site data) and multi-level paths.
     *
     * @var array<string, string>
     */
    public const JOINS = [
{$joinLines}    ];

    /**
     * Entity name prefixes that only exist on a configured site.
     *
     * @var list<string>
     */
    public const DYNAMIC_PREFIXES = [
{$prefixes}    ];

    /**
     * Field name prefixes core computes from a site's configuration.
     *
     * SearchKit publishes every search segment as `segment_<name>` on the
     * entity it segments; the names live in the database, the prefix does
     * not. Unknown names starting like this are left alone.
     *
     * @var list<string>
     */
    public const DYNAMIC_FIELD_PREFIXES = [
{$fieldPrefixes}    ];

    /**
     * Fields from spec providers that apply to no single entity.
     *
     * Accepted on every entity: these come from providers whose target is a
     * runtime condition, so the alternative is a false positive.
     *
     * @var list<string>
     */
    public const ANY_ENTITY_FIELDS = [
{$anyFields}    ];

    /** Is this a known entity, or a name only a live site could confirm? */
    public static function knowsEntity(string \$entity): bool
    {
        return isset(self::ENTITIES[\$entity]);
    }

    /** @return list<string> */
    public static function actions(string \$entity): array
    {
        \$actions = self::ENTITIES[\$entity]['a'] ?? '';

        return \$actions === '' ? [] : explode(' ', \$actions);
    }

    /** Whether the field list is exhaustive enough to reject unknown names. */
    public static function hasCompleteFields(string \$entity): bool
    {
        return (self::ENTITIES[\$entity]['c'] ?? false) === true;
    }

    /** @return list<string> */
    public static function fields(string \$entity): array
    {
        \$fields = self::ENTITIES[\$entity]['f'] ?? '';

        return \$fields === '' ? [] : explode(' ', \$fields);
    }

    /**
     * The implicit joins of an entity, join name => target entity.
     *
     * @return array<string, string>
     */
    public static function joins(string \$entity): array
    {
        \$joins = self::JOINS[\$entity] ?? '';
        if (\$joins === '') {
            return [];
        }
        \$map = [];
        foreach (explode(' ', \$joins) as \$pair) {
            [\$name, \$target] = explode(':', \$pair, 2);
            \$map[\$name] = \$target;
        }

        return \$map;
    }
}

PHP;

$target = $argv[2] ?? dirname(__DIR__) . '/src/Api4Catalog.php';
if (file_put_contents($target, $out) === false) {
    fwrite(STDERR, "could not write $target\n");
    exit(73);
}

$complete = count(array_filter($catalog, static fn (array $i): bool => $i['complete']));
$fieldCount = array_sum(array_map(static fn (array $i): int => count($i['fields']), $catalog));

fwrite(STDOUT, sprintf(
    "%s: %d entities (%d with a complete field list), %d fields, %d aliases (CiviCRM %s)\n",
    $target,
    count($catalog),
    $complete,
    $fieldCount,
    count($aliases),
    $version,
));

// An unreviewed provider that computes field names on a complete entity
// would cause false positives.
foreach ($computedFieldProviders as $entity => $providers) {
    $unreviewed = array_diff(array_unique($providers), REVIEWED_COMPUTED_PROVIDERS);
    if ($unreviewed === [] || $entity === '*' || ($catalog[$entity]['complete'] ?? false) === false) {
        continue;
    }
    fwrite(STDERR, sprintf(
        "warning: %s builds field names for %s that no literal names — review and list it in REVIEWED_COMPUTED_PROVIDERS\n",
        implode(', ', $unreviewed),
        $entity,
    ));
}
