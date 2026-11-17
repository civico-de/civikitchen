<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Own tokens in a message template with no registered provider, or registered
 * under core's `contact.` namespace, which core blanks. A foreign namespace
 * only warns: a dependency may provide it.
 */
final class MessageTemplateTokenCheck implements Check
{
    /**
     * Namespaces the framework and the bundled extensions provide. No
     * registration duty for these.
     *
     * @var list<string>
     */
    private const CORE_NAMESPACES = [
        'contact', 'contribution', 'domain', 'mailing', 'action', 'event',
        'participant', 'membership', 'activity', 'case', 'user', 'import',
        'recur', 'contributionRecur', 'contribution_recur', 'pledge', 'grant',
        'petition', 'eventcart', 'financialType', 'lineItem', 'membershipType',
        'participantRole', 'smarty', 'resourceUrls', 'site', 'group', 'survey',
        'financial_trxn', 'contribution_product', 'welcome', 'subscribe',
        'unsubscribe', 'resubscribe',
    ];

    /**
     * Text that means "this repo registers tokens somewhere"; presence is enough.
     *
     * @var list<string>
     */
    private const REGISTRATION_MARKERS = [
        '_civicrm_tokens',
        'CRM_Utils_Hook::tokenValues',
        '_civicrm_tokenValues',
        'new \\Civi\\Token\\',
        'new Civi\\Token\\',
        'Civi\\Token\\TokenProcessor',
        'TokenProcessor',
        'evaluateToken',
        'addToken(',
        'registerToken',
        'TokenValueEvent',
        'TokenRegisterEvent',
        'AbstractTokenSubscriber',
        'entityToken',
    ];

    public function name(): string
    {
        return 'message-template-token';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $files = $context->isGitRepo() ? $context->trackedUnder('') : $context->findFiles('');
        if ($files === []) {
            return;
        }

        $shortnames = ExtensionNamespace::all($context);
        $registers = $this->registersTokens($context, $files);

        $this->reportContactNamespaceSquatting($context, $files, $shortnames, $reporter);

        /** @var array<string, list<string>> $namespaces namespace => files */
        $namespaces = [];
        foreach ($this->templateFiles($context, $files) as $file) {
            $contents = $context->read($file);
            if ($contents === null) {
                continue;
            }
            foreach ($this->tokenNamespaces($contents) as $namespace) {
                $namespaces[$namespace] ??= [];
                if (!in_array($file, $namespaces[$namespace], true)) {
                    $namespaces[$namespace][] = $file;
                }
            }
        }
        ksort($namespaces);

        if ($registers) {
            return;
        }

        foreach ($namespaces as $namespace => $where) {
            if ($this->isCoreNamespace($namespace)) {
                continue;
            }
            $origin = implode(', ', array_slice($where, 0, 3));
            $message = "$origin: token namespace '{$namespace}.' is used but this extension registers "
                . 'no tokens (no hook_civicrm_tokens, no TokenProcessor code)';
            if ($this->looksLikeOwnNamespace($namespace, $shortnames)) {
                $reporter->fail("$message — '{$namespace}.' is this extension's own namespace, so nothing provides it");
            } else {
                $reporter->warn("$message — fine if a dependency provides '{$namespace}.'");
            }
        }
    }

    private function isCoreNamespace(string $namespace): bool
    {
        foreach (self::CORE_NAMESPACES as $core) {
            if (strcasecmp($namespace, $core) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $shortnames
     */
    private function looksLikeOwnNamespace(string $namespace, array $shortnames): bool
    {
        $needle = strtolower($namespace);
        foreach ($shortnames as $shortname) {
            if ($needle === $shortname || str_starts_with($needle, $shortname . '_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * `$tokens['contact']['myext_foo'] = …`, an own value in the namespace core
     * clobbers. Only keys carrying the shortname count, sparing custom fields.
     *
     * @param list<string> $files
     * @param list<string> $shortnames
     */
    private function reportContactNamespaceSquatting(
        Context $context,
        array $files,
        array $shortnames,
        Reporter $reporter,
    ): void {
        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }
            $contents = $context->read($file);
            if ($contents === null || !str_contains($contents, 'contact')) {
                continue;
            }
            if (preg_match_all(
                '/\$tokens\s*\[\s*([\'"])contact\1\s*\]\s*\[\s*([\'"])(.+?)\2\s*\]/s',
                $contents,
                $matches,
            ) === 0) {
                continue;
            }
            foreach ($matches[3] as $key) {
                if (!$this->looksLikeOwnNamespace($key, $shortnames)) {
                    continue;
                }
                $reporter->warn(
                    "$file: registers '$key' under the contact.* namespace — custom tokens under "
                    . "contact.* get clobbered by core with '' — use an own namespace",
                );
            }
        }
    }

    /**
     * @param  list<string> $files
     */
    private function registersTokens(Context $context, array $files): bool
    {
        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }
            $contents = $context->read($file);
            if ($contents === null) {
                continue;
            }
            foreach (self::REGISTRATION_MARKERS as $marker) {
                if (str_contains($contents, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Files that carry mail copy: template-ish directories (msg_templates, msg*,
     * templates, xml/templates) and any *.mgd.php that ships a message body.
     *
     * @param  list<string> $files
     * @return list<string>
     */
    private function templateFiles(Context $context, array $files): array
    {
        $found = [];
        foreach ($files as $file) {
            // Page templates under templates/ render no message tokens.
            $isMsgPath = preg_match('#(^|/)(msg_templates|msg|xml/templates)/#', $file) === 1;
            $isMsgDir = preg_match('#(^|/)msg[^/]*/#', $file) === 1;

            if (str_ends_with($file, '.mgd.php')) {
                $contents = $context->read($file);
                if ($contents !== null
                    && (str_contains($contents, 'msg_text') || str_contains($contents, 'msg_html'))
                ) {
                    $found[] = $file;
                }
                continue;
            }

            if (!$isMsgPath && !$isMsgDir) {
                continue;
            }
            foreach (['.tpl', '.html', '.txt'] as $extension) {
                if (str_ends_with($file, $extension)) {
                    $found[] = $file;
                    break;
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Distinct namespaces of `{ns.token}` occurrences; Smarty tags and Angular's
     * `{{row.name}}` do not match.
     *
     * @return list<string>
     */
    private function tokenNamespaces(string $contents): array
    {
        $found = [];
        if (preg_match_all(
            '/(?<![$\w{])\{([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z0-9_:]+)\}/',
            $contents,
            $matches,
        ) > 0) {
            foreach ($matches[1] as $namespace) {
                $found[$namespace] = true;
            }
        }

        return array_keys($found);
    }
}
