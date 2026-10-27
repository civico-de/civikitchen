<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Declared dependencies versus what the code actually uses.
 *
 * A missing <ext> only bites on a fresh site: the extension installs, then
 * fatals on a SearchKit entity that isn't there — so nothing catches it during
 * normal development, where the dependency happens to be installed anyway.
 *
 * The <requires><ext> children are read via SimpleXML and compared as exact,
 * trimmed keys: a substring match is too loose (a key that is a prefix of
 * another satisfies it), a regex misses an element carrying an attribute.
 *
 * Only failures are reported — a satisfied dependency is silent.
 */
final class RequiredExtensionsCheck implements Check
{
    public function name(): string
    {
        return 'required-extensions';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $required = $context->requiredExtensions();

        if ($this->shipsSearchKitEntities($context)) {
            $this->needsExt(
                $reporter,
                $required,
                'org.civicrm.search_kit',
                'ships SearchDisplays (managed/ or an Afform <crm-search-display>)',
            );
        }

        if ($this->shipsAfforms($context)) {
            $this->needsExt($reporter, $required, 'org.civicrm.afform', 'ang/ ships Afforms');
        }

        if ($this->extendsCiviRules($context)) {
            $this->needsExt(
                $reporter,
                $required,
                'org.civicoop.civirules',
                'PHP extends CiviRules base classes',
            );
        }
    }

    /**
     * @param list<string> $required
     */
    private function needsExt(Reporter $reporter, array $required, string $key, string $reason): void
    {
        if (!in_array($key, $required, true)) {
            $reporter->fail("info.xml does not <requires> {$key} — {$reason}");
        }
    }

    /**
     * SearchDisplay is SearchKit's; SavedSearch is a core entity whose smart
     * groups core evaluates. Managed entity names are single-quoted strings;
     * recursive, since repos nest managed/ by entity type.
     */
    private function shipsSearchKitEntities(Context $context): bool
    {
        foreach ($context->trackedUnder('managed') as $file) {
            if (str_contains($context->read($file) ?? '', "'SearchDisplay'")) {
                return true;
            }
        }
        foreach ($context->trackedUnder('ang', ['.aff.html']) as $file) {
            if (preg_match('/<crm-search-display\b/i', $context->read($file) ?? '') === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursive on purpose: the common ang/afform/*.aff.html layout must count.
     */
    private function shipsAfforms(Context $context): bool
    {
        return $context->trackedUnder('ang', ['.aff.html', '.aff.json']) !== [];
    }

    private function extendsCiviRules(Context $context): bool
    {
        foreach ($context->trackedUnder('', ['.php']) as $file) {
            // Static-analysis stubs and tests are never loaded by CiviCRM, so
            // a CiviRules class they extend is no runtime dependency.
            if (str_starts_with($file, 'phpstan-stubs/') || Context::isUnder($file, ['tests'])) {
                continue;
            }
            $contents = $context->read($file);
            if ($contents === null) {
                continue;
            }
            $optionalAdapter = preg_match(
                '/^[ \t]*\/\/ ckconform-optional civirules -- [^\r\n]+\R'
                . '[ \t]*if\s*\(\s*class_exists\(\s*[\'\"]CRM_Civirules(?:Actions)?_[^\'\"]*[\'\"]\s*\)\s*\)\s*\{'
                . '\s*(?:\/\*\*[\s\S]*?\*\/\s*)?(?:(?:final|abstract)\s+)?class\s+\S+\s+extends\s+CRM_Civirules(?:Actions)?_/m',
                $contents,
            ) === 1;
            if (
                !$optionalAdapter
                && (
                    str_contains($contents, 'extends CRM_Civirules_')
                    || str_contains($contents, 'extends CRM_CivirulesActions_')
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
