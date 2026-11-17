<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A Page/Form class whose derived Smarty template (CRM_Foo_Page_Bar ->
 * templates/CRM/Foo/Page/Bar.tpl) is not shipped. Overrides of the template
 * methods, run() without parent::run() and own-class parents are exempt.
 */
final class TemplateReferenceCheck implements Check
{
    public function name(): string
    {
        return 'template-reference';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $namespaces = ExtensionNamespace::all($context);

        foreach ($context->sourceFiles('', ['.php']) as $relative) {
            if (preg_match('#^CRM/([A-Za-z0-9]+)/(?:Page|Form)/#', $relative, $match) !== 1) {
                continue;
            }
            if (!in_array(strtolower($match[1]), $namespaces, true)) {
                continue;
            }
            $source = $context->read($relative);
            if ($source === null) {
                continue;
            }
            $this->judgeClass($context, $reporter, $namespaces, $relative, $source);
        }

        foreach ($context->sourceFiles('', ['.php']) as $relative) {
            $source = $context->read($relative);
            if ($source === null) {
                continue;
            }
            foreach ($this->literalTemplates($source) as $template) {
                if (!$this->isOwnTemplate($template, $namespaces)) {
                    continue;
                }
                $expected = 'templates/' . $template;
                if (!$context->ships($expected)) {
                    $reporter->fail("$relative: renders '$template' but $expected is missing — Smarty fatals on render");
                }
            }
        }
    }

    /** @param list<string> $namespaces */
    private function judgeClass(
        Context $context,
        Reporter $reporter,
        array $namespaces,
        string $relative,
        string $source,
    ): void {
        if (preg_match('/\babstract\s+class\b/', $source) === 1) {
            return;
        }
        if (preg_match('/\bfunction\s+(?:getTemplateFileName|getTemplate)\s*\(/', $source) === 1) {
            return;
        }
        if (preg_match('/\bfunction\s+run\s*\(/', $source) === 1
            && preg_match('/\bparent::run\s*\(/', $source) !== 1
        ) {
            // run() is overridden and never delegates to the parent, so the
            // derived template is never handed to Smarty.
            return;
        }
        $parent = $this->parentClass($source);
        // An own parent is judged at its own definition.
        if ($parent === null || ExtensionNamespace::isOwnClass($parent, $namespaces) || !$this->derivesTemplate($context, $parent)) {
            return;
        }

        $expected = 'templates/' . preg_replace('/\.php$/', '.tpl', $relative);
        if (!$context->ships($expected)) {
            $reporter->fail("$relative: no template $expected — the page fatals in Smarty when rendered");
        }
    }

    private function parentClass(string $source): ?string
    {
        return preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+\w+\s+extends\s+\\\\?(\w+)/m', $source, $match) === 1
            ? $match[1] : null;
    }

    /** Does the template still come from the class name, following core parents up to CRM_Core_Page/Form? */
    private function derivesTemplate(Context $context, string $parent): bool
    {
        for ($depth = 0; $depth < 10; $depth++) {
            if (in_array($parent, ['CRM_Core_Page', 'CRM_Core_Form'], true)) {
                return true;
            }
            $file = $context->coreDir === null ? null : $context->coreDir . '/' . str_replace('_', '/', $parent) . '.php';
            $source = $file !== null && is_file($file) ? (string) file_get_contents($file) : '';
            $parent = preg_match('/\bfunction\s+(?:getTemplateFileName|getTemplate)\s*\(/', $source) === 1 ? null : $this->parentClass($source);
            if ($parent === null) {
                return false;
            }
        }

        return false;
    }

    /**
     * Literal '….tpl' arguments to a render call. 'string:' templates are inline
     * Smarty source, not files.
     *
     * @return list<string>
     */
    private function literalTemplates(string $source): array
    {
        $found = [];
        $pattern = '/(?:fetch|assign|assignTemplate|setTemplate|include)\s*\(\s*[^)\'"]*[\'"]([^\'"]+\.tpl)[\'"]/';
        if (preg_match_all($pattern, $source, $matches) !== false) {
            foreach ($matches[1] as $template) {
                if (!str_starts_with($template, 'string:')) {
                    $found[] = $template;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** @param list<string> $namespaces */
    private function isOwnTemplate(string $template, array $namespaces): bool
    {
        return preg_match('#^CRM/([A-Za-z0-9]+)/#', $template, $match) === 1
            && in_array(strtolower($match[1]), $namespaces, true);
    }

}
