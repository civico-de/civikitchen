<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\ExtensionUtilStub;
use CiviKitchen\Ckconform\Reporter;

/**
 * A `schema/*.entityType.php` whose `class` DAO stub is not shipped (the install
 * fails halfway), or whose stub's `$_tableName` addresses another table.
 */
final class EntityDaoStubCheck implements Check
{
    public function name(): string
    {
        return 'entity-dao-stub';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $files = $context->isGitRepo()
            ? $context->trackedUnder('schema', ['.entityType.php'])
            : $context->findFiles('schema', ['.entityType.php']);
        if ($files === []) {
            return;
        }

        ExtensionUtilStub::register($context);

        $checked = 0;
        foreach ($files as $relative) {
            try {
                $schema = require $context->path($relative);
            } catch (\Throwable $e) {
                $reporter->warn("$relative: could not evaluate entity schema outside CiviCRM ({$e->getMessage()}) — DAO stub unchecked");
                continue;
            }
            if (!is_array($schema)) {
                $reporter->fail("$relative: does not return an entity definition array");
                continue;
            }

            $class = is_string($schema['class'] ?? null) ? trim($schema['class']) : '';
            if ($class === '') {
                $reporter->fail("$relative: no 'class' key — entity-types-php needs the DAO class name to load the entity");
                continue;
            }

            $checked++;
            $this->verify($context, $reporter, $relative, $class, $schema);
        }

        if ($checked > 0 && $reporter->failures() === 0) {
            $reporter->ok("every entity schema ships its DAO stub ($checked entities)");
        }
    }

    /**
     * @param array<mixed> $schema
     */
    private function verify(
        Context $context,
        Reporter $reporter,
        string $relative,
        string $class,
        array $schema,
    ): void {
        $expected = str_replace('_', '/', $class) . '.php';
        if (!$context->ships($expected)) {
            $reporter->fail(
                "$relative: declares class $class but the repo ships no $expected — generate it with `civix generate:entity` (enabling the extension fatals with 'Class $class not found')"
            );

            return;
        }

        $table = is_string($schema['table'] ?? null) ? trim($schema['table']) : '';
        if ($table === '') {
            return;
        }

        $declared = $this->tableName($context->read($expected) ?? '');
        if ($declared !== null && $declared !== $table) {
            $reporter->fail(
                "$expected: \$_tableName is '$declared' but $relative declares table '$table' — the DAO stub is stale and queries would address the wrong table"
            );
        }
    }

    /** The `$_tableName` a DAO stub declares, or null when it declares none. */
    private function tableName(string $source): ?string
    {
        $pattern = '/\$_tableName\s*=\s*([\'"])([A-Za-z0-9_]+)\1\s*;/';

        return preg_match($pattern, $source, $match) === 1 ? $match[2] : null;
    }
}
