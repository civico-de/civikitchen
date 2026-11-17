<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\ExtensionUtilStub;
use CiviKitchen\Ckconform\Reporter;

/**
 * Settings metadata that fatals its admin page: a settings_pages entry without
 * html_type/quick_form_type, or a bad 'table' pseudoconstant. Files are evaluated
 * with a stubbed ExtensionUtil; one that throws is a warning.
 */
final class SettingsMetadataCheck implements Check
{
    /**
     * Keys of a 'table' pseudoconstant core reads (core 6.18, camelCase); a
     * snake_case sibling is ignored and fatals the page.
     */
    private const TABLE_PSEUDOCONSTANT_KEYS = [
        'table', 'keyColumn', 'labelColumn', 'condition', 'nameColumn', 'abbrColumn',
    ];

    /** snake_case (or other common misspelling) => the accepted camelCase key */
    private const TABLE_PSEUDOCONSTANT_ALIASES = [
        'key_column' => 'keyColumn',
        'label_column' => 'labelColumn',
        'name_column' => 'nameColumn',
        'abbr_column' => 'abbrColumn',
        'keycolumn' => 'keyColumn',
        'labelcolumn' => 'labelColumn',
    ];

    public function name(): string
    {
        return 'settings-metadata';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        // Tracked files only (repo principle) — an uncommitted local settings
        // file must not sway the verdict. Outside git, fall back to the tree.
        $files = $context->isGitRepo()
            ? $context->trackedUnder('settings', ['setting.php'])
            : $context->findFiles('settings', ['setting.php']);
        if ($files === []) {
            return;
        }

        ExtensionUtilStub::register($context);

        foreach ($files as $relative) {
            try {
                $settings = require $context->path($relative);
            } catch (\Throwable $e) {
                $reporter->warn("$relative: could not evaluate settings file outside CiviCRM ({$e->getMessage()}) — settings metadata unchecked");
                continue;
            }

            if (!is_array($settings)) {
                $reporter->fail("$relative: does not return an array of setting definitions");
                continue;
            }

            foreach ($settings as $key => $meta) {
                if (!is_array($meta)) {
                    $reporter->fail("$relative: setting '$key' is not an array");
                    continue;
                }
                if (($meta['name'] ?? null) !== $key) {
                    $reporter->fail("$relative: setting '$key' has a diverging 'name' attribute ('" . ($meta['name'] ?? '') . "')");
                }
                if (!empty($meta['settings_pages'])
                    && empty($meta['html_type'])
                    && empty($meta['quick_form_type'])
                ) {
                    $reporter->fail("$relative: setting '$key' is on a settings page (settings_pages) but has no html_type/quick_form_type — the generic settings form fatals with QuickForm \"unregistered element\"");
                }

                $this->checkTablePseudoconstant($relative, (string) $key, $meta['pseudoconstant'] ?? null, $reporter);
            }
        }
    }

    /**
     * @param mixed $pseudoconstant
     */
    private function checkTablePseudoconstant(string $relative, string $key, $pseudoconstant, Reporter $reporter): void
    {
        if (!is_array($pseudoconstant) || empty($pseudoconstant['table'])) {
            return;
        }

        foreach (array_keys($pseudoconstant) as $pcKey) {
            if (in_array($pcKey, self::TABLE_PSEUDOCONSTANT_KEYS, true)) {
                continue;
            }
            $canonical = self::TABLE_PSEUDOCONSTANT_ALIASES[strtolower((string) $pcKey)] ?? null;
            $reporter->fail($canonical !== null
                ? "$relative: setting '$key' pseudoconstant key '$pcKey' is not read by core's settings code — use '$canonical'"
                : "$relative: setting '$key' pseudoconstant key '$pcKey' is not read by core's settings code (table pseudoconstant)");
        }

        // Measured on core 6.18: missing keyColumn/labelColumn throws the same
        // "not of the type MysqlColumnNameOrAlias" fatal.
        if (!isset($pseudoconstant['keyColumn']) || !isset($pseudoconstant['labelColumn'])) {
            $reporter->fail("$relative: setting '$key' has a 'table' pseudoconstant without both keyColumn and labelColumn — CRM_Core_PseudoConstant::renderOptionsFromTablePseudoconstant() fatals with \"not of the type MysqlColumnNameOrAlias\"");
        }
    }

}
