<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\PhpSource;
use CiviKitchen\Ckconform\Reporter;

/**
 * A procedural civicrm_api4('Foo', ...) call to one of this extension's own
 * entities that does not exist.
 *
 * Api4EntityCheck catches the object form: \Civi\Api4\WidgetTypo::get() fails
 * because WidgetTypo is in neither core nor this extension. But the procedural
 * form takes the entity as a string — civicrm_api4('WidgetTypo', 'get', ...) —
 * and no static analyser resolves it, so a typo or a half-finished rename
 * fatals only at runtime, on whichever call path happens to hit it.
 *
 * The trap is telling a typo from a legitimate call to ANOTHER extension's
 * entity. widget calls civicrm_api4('CiviRulesRule', ...) constantly; that
 * entity lives in the civirules extension, not core and not widget, and a check
 * that flagged it would be wrong. So this stays inside the family it can be sure
 * about: a literal that shares its leading word with an entity this extension
 * defines (WidgetState alongside a local Widget/WidgetLog) is one of ours, and
 * if we do not define it, it is a mistake. Core entities and other extensions'
 * entities share no leading word with ours and are left alone.
 */
final class Api4LiteralEntityCheck implements Check
{
    /** Built artefacts restate the source; sourceFiles() already drops tests/vendor. */
    private const SKIP = ['dist', 'build'];

    public function name(): string
    {
        return 'api4-literal-entity';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        // Without core, a typo cannot be told from a core entity sharing our
        // leading word (MembershipType beside an own MembershipPeriod).
        if ($context->coreDir === null || !$context->isGitRepo()) {
            return;
        }

        $local = $this->localEntities($context);
        if ($local === []) {
            return;
        }
        $family = [];
        foreach ($local as $entity) {
            $word = $this->leadingWord($entity);
            // A local CiviRulesRule-style entity would put core's whole
            // Civi* family (CiviCase, CiviMail, ...) under suspicion —
            // too broad to tell a typo from a legitimate core call.
            if ($word === 'Civi') {
                continue;
            }
            $family[$word] = true;
        }

        $external = CoreApi4::declaredExternal($context);
        $dangling = [];
        foreach ($context->sourceFiles('', ['.php']) as $file) {
            if (Context::isUnder($file, self::SKIP)) {
                continue;
            }
            $source = $context->read($file);
            if ($source === null || stripos($source, 'civicrm_api4') === false) {
                continue;
            }
            foreach ($this->calledEntities($source) as $entity) {
                if (in_array($entity, [...$local, ...$external], true) || !isset($family[$this->leadingWord($entity)])
                    || CoreApi4::classFile($context->coreDir, $entity) !== null
                    || CoreApi4::inRequiredExtension($context, $entity)
                ) {
                    continue;
                }
                $dangling[$entity][$file] = true;
            }
        }

        if ($dangling === []) {
            $reporter->ok('every civicrm_api4() call to an own entity resolves');

            return;
        }

        $parts = [];
        foreach ($dangling as $entity => $files) {
            $parts[] = $entity . ' (' . implode(', ', array_keys($files)) . ')';
        }
        $reporter->fail(
            'civicrm_api4() names own entities that are not defined in Civi/Api4: '
            . implode('; ', $parts) . ' — the call fatals at runtime'
        );
    }

    /**
     * Entity names this extension defines, from a shipped Civi/Api4/<Name>.php —
     * a fixture entity under tests/ must not seed the family.
     *
     * @return list<string>
     */
    private function localEntities(Context $context): array
    {
        $entities = [];
        foreach ($context->sourceFiles('', ['.php']) as $file) {
            if (preg_match('#(?:^|/)Civi/Api4/([A-Z][A-Za-z0-9_]*)\.php$#', $file, $match) === 1) {
                $entities[] = $match[1];
            }
        }

        return array_values(array_unique($entities));
    }

    /**
     * Literal entity names passed to civicrm_api4() in code, positionally or
     * as `entity:`, directly or through call_user_func('civicrm_api4', …).
     *
     * @return list<string>
     */
    private function calledEntities(string $source): array
    {
        $tokens = PhpSource::codeTokens($source);
        $entities = [];
        foreach ($tokens as $i => $token) {
            $function = strtolower(PhpSource::name($token) ?? '');
            if (!in_array($function, ['civicrm_api4', 'call_user_func'], true)
                || ($tokens[$i + 1] ?? null)?->text !== '('
            ) {
                continue;
            }
            $arguments = PhpSource::arguments($tokens, $i + 1) ?? [];
            if ($function === 'call_user_func') {
                $callback = PhpSource::argument($arguments, 0, 'callback') ?? [];
                if (strcasecmp($this->literal($callback) ?? '', 'civicrm_api4') !== 0) {
                    continue;
                }
                $arguments = array_slice($arguments, 1);
            }
            $entity = $this->literal(PhpSource::argument($arguments, 0, 'entity') ?? []);
            if ($entity !== null && preg_match('/^[A-Z][A-Za-z0-9_]*$/', $entity) === 1) {
                $entities[] = $entity;
            }
        }

        return array_values(array_unique($entities));
    }

    /**
     * The value of an argument that is one plain string literal.
     *
     * @param list<\PhpToken> $argument
     */
    private function literal(array $argument): ?string
    {
        return count($argument) === 1 && $argument[0]->is(T_CONSTANT_ENCAPSED_STRING)
            ? ltrim(substr($argument[0]->text, 1, -1), '\\')
            : null;
    }

    private function leadingWord(string $name): string
    {
        return preg_match('/^[A-Z][a-z0-9]*/', $name, $match) === 1 ? $match[0] : $name;
    }
}
