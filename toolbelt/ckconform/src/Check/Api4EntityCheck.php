<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\PhpSource;
use CiviKitchen\Ckconform\Policy;
use CiviKitchen\Ckconform\Reporter;

/**
 * APIv4 entities that do not exist, or that exist only in a core newer than the
 * one this extension claims to support.
 *
 * A `\Civi\Api4\Foo` reference resolves at runtime, so a migration can move code
 * onto an entity core never shipped — or shipped only @since a core newer than
 * the declared floor — and phpstan, the linters and every test that does not load
 * that page stay green while the page fatals on every live site.
 *
 * Hence two questions, not one:
 *   1. does the entity exist at all?
 *   2. does it exist in the OLDEST core we promise to run on?
 *
 * Only (1) is answerable from the container alone, and answering only (1) is a
 * check on our build rather than on the customer's site.
 */
final class Api4EntityCheck implements Check
{
    public function name(): string
    {
        return 'api4-entity';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if ($context->coreDir === null || !$context->isGitRepo()) {
            return;
        }

        $declaredVer = $context->compatibilityFloor();
        $missing = [];
        $tooNew = [];
        $undeclared = [];

        $classUses = [];
        $referenced = $this->referencedEntities($context, $classUses);
        $external = $this->declaredExternalEntities($context, $referenced, $reporter);
        $optional = $this->optionalExtensions($context, $reporter);
        $providers = [];

        foreach ($referenced as $entity) {
            // Entities the extension defines itself are its own business.
            if ($context->shipsApi4Entity($entity) || in_array($entity, $external, true)) {
                continue;
            }

            $classFile = CoreApi4::classFile($context->coreDir, $entity);
            if ($classFile === null) {
                // `use Civi\Api4\Generic;` imports a namespace, not an entity.
                if (isset($classUses[$entity]) || !$this->isCoreNamespace($context->coreDir, $entity)) {
                    $missing[] = $entity;
                }
                continue;
            }

            $provider = $this->providingExtension($classFile);
            if ($provider !== null) {
                $providers[$provider] = true;
            }
            if ($provider !== null && !in_array($provider, [...$context->requiredExtensions(), ...$optional], true)) {
                $undeclared[$provider] = $entity;
            }

            if ($declaredVer === null) {
                continue;
            }
            $since = $this->parseSince($classFile);
            if ($since !== null && version_compare($since, $declaredVer, '>')) {
                $tooNew[] = sprintf('%s(@since %s)', $entity, $since);
            }
        }

        foreach ($optional as $key) {
            if (!isset($providers[$key])) {
                $reporter->fail("civikitchen.yaml: optional_extensions lists '{$key}', whose entities the code never uses — remove the stale exception");
            }
        }

        foreach ($undeclared as $provider => $entity) {
            $reporter->warn(sprintf(
                'info.xml does not <requires> %s — \\Civi\\Api4\\%s comes from that extension',
                $provider,
                $entity
            ));
        }

        if ($missing !== []) {
            $reporter->fail(
                'APIv4 entities referenced but not found in core or this extension: '
                . implode(' ', $missing)
            );
        } else {
            $reporter->ok('every referenced APIv4 entity exists');
        }

        if ($tooNew !== []) {
            $reporter->fail(sprintf(
                'APIv4 entities newer than the declared <ver>%s</ver>: %s — they fatal on every supported site below that',
                $declaredVer,
                implode(' ', $tooNew)
            ));
        } elseif ($declaredVer !== null) {
            $reporter->ok('every referenced APIv4 entity exists as of the declared core ' . $declaredVer);
        }
    }

    /**
     * Entities supplied by an info.xml dependency are not present in core.
     * The exact names stay explicit so a typo in extension code still fails.
     *
     * @param list<string> $referenced
     * @return list<string>
     */
    private function declaredExternalEntities(Context $context, array $referenced, Reporter $reporter): array
    {
        $raw = $context->policyValue('known_api4_entities');
        if ($raw === null) {
            return [];
        }
        if (!str_contains($raw, ' -- ') || trim(explode(' -- ', $raw, 2)[1]) === '') {
            $reporter->fail('civikitchen.yaml: known_api4_entities needs ` -- <reason>` naming the provider');
        }
        if ($context->requiredExtensions() === []) {
            $reporter->fail('civikitchen.yaml: known_api4_entities is set but info.xml requires no provider extension');
        }

        $entities = array_values(array_filter(array_map(
            'trim',
            explode(',', Policy::stripReason($raw)),
        )));
        foreach ($entities as $entity) {
            if (preg_match('/^[A-Z][A-Za-z0-9_]*$/', $entity) !== 1) {
                $reporter->fail("civikitchen.yaml: invalid APIv4 entity name in known_api4_entities: '{$entity}'");
            } elseif (!in_array($entity, $referenced, true)) {
                $reporter->fail("civikitchen.yaml: known_api4_entities lists unused '{$entity}' — remove the stale exception");
            }
        }

        return $entities;
    }

    /**
     * Extensions the code checks for at runtime before using them, so their
     * entities need no <requires>. Listing a required extension is a contradiction.
     *
     * @return list<string>
     */
    private function optionalExtensions(Context $context, Reporter $reporter): array
    {
        $raw = $context->policyValue('optional_extensions');
        if ($raw === null) {
            return [];
        }
        $keys = array_values(array_filter(array_map('trim', explode(',', Policy::stripReason($raw)))));
        foreach (array_intersect($keys, $context->requiredExtensions()) as $key) {
            $reporter->fail("civikitchen.yaml: optional_extensions lists '{$key}', which info.xml <requires>");
        }

        return $keys;
    }

    /**
     * Entity names referenced as \Civi\Api4\Foo in the extension's shipped PHP.
     * An entity is a class directly in Civi\Api4; a deeper name such as
     * \Civi\Api4\Provider\ActionObjectProvider lives in a sub-namespace.
     *
     * Shipped source only: tests never run on a customer's site, so a test-only
     * reference cannot fatal there, and test fixtures may name fake entities on
     * purpose. A broken reference in a test fails that test in CI on its own.
     *
     * @param array<string, true> $classUses names used as a class (`Name::`), filled in
     * @return list<string>
     */
    private function referencedEntities(Context $context, array &$classUses = []): array
    {
        $entities = [];
        foreach ($context->sourceFiles('', ['.php']) as $file) {
            if (str_contains($file, '.civix.php') || str_contains($file, '/DAO/')) {
                continue;
            }
            $source = $context->read($file);
            if ($source === null || !str_contains($source, 'Civi\\Api4\\')) {
                continue;
            }
            // Strip comments first: `\Civi\Api4\Xxx` written in a docblock to
            // name the pattern in prose ("rather than the concrete \Civi\Api4\Xxx
            // classes") is not a reference to an entity, and Xxx does not exist.
            // A check tripped by prose is the mirror of one satisfied by it.
            $source = PhpSource::withoutComments($source);
            // Fully qualified (\Civi\Api4\Foo) or imported (use Civi\Api4\Foo).
            // A bare `Civi\Api4\Foo` without the leading backslash is NOT a
            // reference to the entity — inside a namespaced file it resolves
            // relative to that namespace — so it is usually prose in a comment.
            preg_match_all('/\\\\Civi\\\\Api4\\\\([A-Z][A-Za-z0-9_]*)(?![\\\\\w])/', $source, $qualified);
            foreach ([...$qualified[1], ...$this->importedEntities($source)] as $name) {
                $entities[$name] = true;
                // `Action::get()` names a class; a namespace only ever prefixes one.
                if (preg_match('/(?<![\\\\\w])(?:\\\\Civi\\\\Api4\\\\)?' . $name . '\s*::/', $source) === 1) {
                    $classUses[$name] = true;
                }
            }
        }
        $names = array_keys($entities);
        sort($names);

        return $names;
    }

    /**
     * Entities a top-level `use` imports, including a group use
     * (`use Civi\Api4\{Contact, Email}`) and comma-separated clauses.
     *
     * @return list<string>
     */
    private function importedEntities(string $source): array
    {
        $tokens = PhpSource::codeTokens($source);
        $names = [];
        foreach ($tokens as $i => $token) {
            // A closure's `use (` and `use function`/`use const` import no class.
            if (!$token->is(T_USE) || PhpSource::name($tokens[$i + 1] ?? $token) === null) {
                continue;
            }
            $prefix = '';
            for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j]->text !== ';'; $j++) {
                $name = PhpSource::name($tokens[$j]);
                if ($tokens[$j]->is(T_NS_SEPARATOR) && ($tokens[$j + 1] ?? null)?->text === '{') {
                    $prefix = (PhpSource::name($tokens[$j - 1]) ?? '') . '\\';
                    $names = array_slice($names, 0, -1);
                } elseif ($tokens[$j]->text === '}') {
                    $prefix = '';
                } elseif ($name !== null && !$tokens[$j - 1]->is(T_AS)) {
                    $names[] = $prefix . $name;
                }
            }
        }

        $entities = [];
        foreach ($names as $name) {
            if (preg_match('/^Civi\\\\Api4\\\\([A-Z][A-Za-z0-9_]*)$/', $name, $match) === 1) {
                $entities[] = $match[1];
            }
        }

        return $entities;
    }

    /**
     * A Civi\Api4 sub-namespace core or a bundled extension ships, such as
     * Generic or Action, as opposed to an entity class.
     */
    private function isCoreNamespace(string $coreDir, string $name): bool
    {
        $roots = [
            $coreDir . '/Civi/Api4',
            ...(glob($coreDir . '/ext/*/Civi/Api4') ?: []),
            ...(glob(dirname($coreDir) . '/ext/*/Civi/Api4') ?: []),
        ];
        foreach ($roots as $root) {
            if (is_dir($root . '/' . $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The @since tag from the class docblock.
     *
     * Read through the PHP tokenizer rather than by regex over the whole file: a
     * version that comes back empty makes the check pass on every entity, and a
     * rule that cannot fail is worse than no rule.
     */
    private function parseSince(string $file): ?string
    {
        $source = file_get_contents($file);
        if ($source === false) {
            return null;
        }

        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || $token[0] !== T_DOC_COMMENT) {
                continue;
            }
            if (preg_match('/@since\s+(\d+\.\d+(?:\.\d+)?)/', $token[1], $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * The extension key that provides this entity, when it is not core proper.
     *
     * Extensions tagged `mgmt:required` or `component` are core plumbing that is
     * always present (search_kit, flexmailer, civi_mail) — no core extension
     * declares those, and demanding it would be noise nobody reads. What is left
     * is a genuine optional dependency: riverlea, and anything like it.
     */
    private function providingExtension(string $classFile): ?string
    {
        if (!preg_match('#/ext/(.+?)/(?:[^/]+/)*Civi/Api4/#', $classFile, $match)) {
            return null;
        }
        $dir = substr($classFile, 0, strpos($classFile, '/Civi/Api4/') ?: 0);
        while ($dir !== '' && !is_file($dir . '/info.xml')) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }
        $info = @simplexml_load_file($dir . '/info.xml');
        if ($info === false) {
            return null;
        }
        foreach ($info->xpath('//tags/tag') ?: [] as $tag) {
            if (in_array(trim((string) $tag), ['mgmt:required', 'component'], true)) {
                return null;
            }
        }
        $key = trim((string) ($info['key'] ?? ''));

        return $key === '' ? null : $key;
    }
}
