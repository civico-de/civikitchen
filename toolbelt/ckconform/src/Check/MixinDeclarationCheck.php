<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\HookSurface;
use CiviKitchen\Ckconform\PhpSource;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\Suppressions;

/**
 * A conventional file that CiviCRM never loads because its mixin is not declared.
 * scan-classes fails (core reports it on the status page); the others warn, as
 * older civix loaded those families through hooks in the .civix shim.
 */
final class MixinDeclarationCheck implements Check
{
    /**
     * civix mixin => the paths it loads, mirroring core's mixin.php globs (`*`
     * stays within one directory). 'hook' is the hook older civix used instead,
     * 'fail' a gap core reports itself, 'class' counts only class files.
     *
     * @var array<string, array{globs: list<string>, label: string, hook?: string, class?: bool, fail?: bool}>
     */
    private const REQUIREMENTS = [
        'mgd-php' => [
            'globs' => ['*.mgd.php', 'managed/**/*.mgd.php', 'api/**/*.mgd.php', 'CRM/**/*.mgd.php', 'Civi/**/*.mgd.php'],
            'label' => 'managed records (managed/*.mgd.php)',
            'hook' => 'managed',
        ],
        // @1 read xml/schema/CRM/, @2 reads schema/.
        'entity-types-php' => [
            'globs' => ['schema/*.entityType.php', 'xml/schema/CRM/*/*.entityType.php'],
            'label' => 'entity schemas (schema/*.entityType.php)',
            'hook' => 'entityTypes',
        ],
        'menu-xml' => ['globs' => ['xml/Menu/*.xml'], 'label' => 'menu routes (xml/Menu/*.xml)', 'hook' => 'xmlMenu'],
        'setting-php' => [
            'globs' => ['settings/**/*.setting.php'],
            'label' => 'settings (settings/*.setting.php)',
            'hook' => 'alterSettingsFolders',
        ],
        'ang-php' => ['globs' => ['ang/*.ang.php'], 'label' => 'Angular modules (ang/*.ang.php)'],
        // LegacyEntityScanner globs Civi/Api4/*.php without recursing and skips
        // what class_exists() rejects, so only a declared class counts.
        'scan-classes' => [
            'globs' => ['Civi/Api4/*.php'],
            'class' => true,
            'fail' => true,
            'label' => 'APIv4 entities (Civi/Api4/*.php)',
        ],
    ];

    public function name(): string
    {
        return 'mixin-declaration';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo() || $context->infoXml() === null) {
            return;
        }

        $declared = $this->declaredMixins($context);
        $missing = ['fail' => [], 'warn' => []];
        foreach (self::REQUIREMENTS as $mixin => $spec) {
            if (in_array($mixin, $declared, true) || $this->implementsHook($context, $spec['hook'] ?? null)) {
                continue;
            }
            if ($this->hasArtefact($context, $spec['globs'], $spec['class'] ?? false)) {
                $missing[($spec['fail'] ?? false) ? 'fail' : 'warn'][$mixin] = $spec['label'] . ' need the ' . $mixin . ' mixin';
            }
        }

        foreach ($missing as $level => $labels) {
            if ($labels === []) {
                continue;
            }
            // civix insists on name@version; `civix mixin` lists what it has.
            $reporter->{$level}(
                'info.xml ships files no declared mixin loads: ' . implode('; ', $labels)
                . ' — enable with `civix mixin --enable=' . implode(',', array_map(
                    static fn (string $mixin): string => $mixin . '@<version>',
                    array_keys($labels),
                ))
                . '` (`civix mixin` lists the versions), or delete the files if they are a vestige'
            );
        }
    }

    /**
     * Mixin names from info.xml, version stripped (menu-xml@1.0.0 -> menu-xml).
     *
     * @return list<string>
     */
    private function declaredMixins(Context $context): array
    {
        return array_map(
            static fn (string $mixin): string => explode('@', $mixin)[0],
            $context->declaredMixins(),
        );
    }

    /** Whether the extension's own source defines <prefix>_civicrm_<hook>(). */
    private function implementsHook(Context $context, ?string $hook): bool
    {
        if ($hook === null) {
            return false;
        }
        $names = array_map(static fn (string $p): string => $p . '_civicrm_' . $hook, HookSurface::expectedPrefixes($context));
        foreach (HookSurface::candidates($context) as $file) {
            if (array_intersect($names, array_keys(HookSurface::globalFunctions((string) $context->read($file)))) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $globs */
    private function hasArtefact(Context $context, array $globs, bool $class): bool
    {
        foreach ($context->trackedFiles() as $file) {
            if (!$this->matchesAny($file, $globs)) {
                continue;
            }
            if ($class && !$this->declaresClass((string) $context->read($file))) {
                continue;
            }
            // A file that opts out is no evidence: an Api4 class loaded some
            // other way still lives at Civi/Api4/, and only its author knows.
            if (str_ends_with($file, '.php')
                && Suppressions::of((string) $context->read($file))->suppressed($this->name(), 0)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** @param list<string> $globs */
    private function matchesAny(string $file, array $globs): bool
    {
        foreach ($globs as $glob) {
            [$dir, $name] = array_pad(explode('/**/', $glob, 2), 2, null);
            $matches = $name === null
                ? fnmatch($glob, $file, FNM_PATHNAME)
                : str_starts_with($file, $dir . '/') && fnmatch($name, basename($file));
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /** Whether the source declares a named class (not an interface, trait or enum). */
    private function declaresClass(string $source): bool
    {
        $tokens = PhpSource::codeTokens($source);
        foreach ($tokens as $i => $token) {
            if ($token->is(T_CLASS) && ($tokens[$i + 1] ?? null)?->is(T_STRING)
                && !($tokens[$i - 1] ?? null)?->is([T_DOUBLE_COLON, T_NEW])
            ) {
                return true;
            }
        }

        return false;
    }
}
