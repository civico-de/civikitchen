<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\PhpSource;
use CiviKitchen\Ckconform\Reporter;

/**
 * Upgrader defects that break only during an upgrade: a missing <upgrader> class
 * file, colliding or non-public upgrade_* steps, missing or orphaned SQL files,
 * an undeclared Upgrader. Read as text; the class cannot load outside a boot.
 */
final class UpgraderIntegrityCheck implements Check
{
    /** Filenames civix executes itself; not referenced from PHP by design. */

    public function name(): string
    {
        return 'upgrader-integrity';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        // Tracked files only (repo principle): an untracked local upgrader
        // cannot break anyone else's upgrade. Outside git, fall back to disk.
        $tracked = $context->isGitRepo()
            ? $context->trackedUnder('', ['.php', '.sql'])
            : $context->findFiles('', ['.php', '.sql']);

        $declared = $this->declaredUpgrader($context);
        $file = null;

        if ($declared === null) {
            $this->reportUndeclaredUpgrader($context, $reporter, $tracked);
        } elseif (($schema = $this->automaticUpgraderShortName($declared)) !== null) {
            // CiviMix\Schema\<X>\AutomaticUpgrader is generated at runtime; a shipped
            // CRM/<X>/Upgrader.php is then its delegating subclass and must match.
            $candidate = 'CRM/' . $schema . '/Upgrader.php';
            if (in_array($candidate, $tracked, true)) {
                $file = $candidate;
                $source = $context->read($candidate) ?? '';
                if (!$this->declaresClass($source, 'CRM_' . $schema . '_Upgrader')) {
                    $reporter->fail(
                        $candidate . ' does not declare class CRM_' . $schema . '_Upgrader'
                        . ' — the AutomaticUpgrader delegation in info.xml cannot reach it'
                    );
                }
            }
        } else {
            $file = $this->classFile($context, $declared, $tracked);
            if ($file === null) {
                $reporter->fail(
                    'info.xml <upgrader> declares ' . $declared . ' but no file for that class is committed'
                    . ' — the extension upgrade fatals before its first step'
                );
            }
        }

        if ($file !== null) {
            $this->checkUpgradeSteps($context, $reporter, $file, $tracked);
        }

        $this->reportOrphanSql($context, $reporter, $tracked);
    }

    /** The <upgrader> value from info.xml, backslash-normalised. */
    private function declaredUpgrader(Context $context): ?string
    {
        $info = $context->infoXml();
        if ($info === null) {
            return null;
        }
        foreach ($info->xpath('//upgrader') ?: [] as $node) {
            $value = trim((string) $node);
            if ($value !== '') {
                return ltrim(str_replace('/', '\\', $value), '\\');
            }
        }

        return null;
    }

    /** 'CiviMix\Schema\Foo\AutomaticUpgrader' => 'Foo'. */
    private function automaticUpgraderShortName(string $class): ?string
    {
        if (preg_match('/^CiviMix\\\\Schema\\\\([A-Za-z0-9_]+)\\\\AutomaticUpgrader$/', $class, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * The committed file for a class name, PSR-0 for CRM_ and PSR-4-ish for
     * namespaced classes; a suffix match counts, for repos nesting either.
     *
     * @param list<string> $tracked
     */
    private function classFile(Context $context, string $class, array $tracked): ?string
    {
        $relative = str_contains($class, '\\')
            ? str_replace('\\', '/', $class) . '.php'
            : str_replace('_', '/', $class) . '.php';

        foreach ($tracked as $candidate) {
            if ($candidate === $relative || str_ends_with($candidate, '/' . $relative)) {
                return $candidate;
            }
        }

        return null;
    }

    private function declaresClass(string $source, string $class): bool
    {
        return preg_match('/\b(?:class|interface|trait)\s+' . preg_quote($class, '/') . '\b/', $source) === 1;
    }

    /**
     * Revision collisions, non-public steps and missing SQL files.
     *
     * @param list<string> $tracked
     */
    private function checkUpgradeSteps(Context $context, Reporter $reporter, string $file, array $tracked): void
    {
        $source = $context->read($file);
        if ($source === null) {
            return;
        }
        // A commented-out step or executeSqlFile() call runs nothing.
        $source = PhpSource::withoutComments($source);

        $pattern = '/(?:^|[\r\n])[ \t]*((?:(?:public|protected|private|static|final|abstract)\s+)*)'
            . 'function\s+(upgrade_(\d+))\s*\(/i';
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return;
        }

        /** @var array<int, list<string>> $byRevision */
        $byRevision = [];
        foreach ($matches[2] as $index => [$method, $offset]) {
            $modifiers = strtolower(trim($matches[1][$index][0]));
            $revision = (int) $matches[3][$index][0];
            $byRevision[$revision][] = $method;

            if (str_contains($modifiers, 'private') || str_contains($modifiers, 'protected')) {
                $reporter->fail(
                    $file . ': ' . $method . '() is not public — CiviCRM calls upgrade steps from outside,'
                    . ' so this step never runs and its schema change never lands'
                );
            }

            $this->checkSqlReferences($reporter, $file, $method, $this->methodBody($source, $offset), $tracked);
        }

        foreach ($byRevision as $revision => $methods) {
            if (count($methods) > 1) {
                sort($methods);
                $reporter->fail(
                    $file . ': ' . implode(' and ', $methods) . ' are both revision ' . $revision
                    . ' — core sorts revisions numerically, so these collide and one is lost'
                );
            }
        }
    }

    /**
     * Source from a method declaration up to the next function declaration —
     * enough to see the executeSqlFile() literals that belong to this step.
     */
    private function methodBody(string $source, int $offset): string
    {
        $next = preg_match('/\bfunction\s+\w+\s*\(/', $source, $ignored, PREG_OFFSET_CAPTURE, $offset + 1) === 1
            ? $ignored[0][1]
            : strlen($source);

        return substr($source, $offset, $next - $offset);
    }

    /**
     * @param list<string> $tracked
     */
    private function checkSqlReferences(Reporter $reporter, string $file, string $method, string $body, array $tracked): void
    {
        foreach ($this->sqlLiterals($body) as $reference) {
            if ($this->sqlFile($reference, $tracked) === null) {
                $reporter->fail(
                    $file . ': ' . $method . '() runs executeSqlFile(\'' . $reference . '\')'
                    . ' but that file is not committed — the upgrade fatals mid-step'
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function sqlLiterals(string $source): array
    {
        preg_match_all('/executeSqlFile\s*\(\s*[\'"]([^\'"]+)[\'"]/i', $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * A referenced SQL path resolved against the committed files. References are
     * extension-root relative and may or may not carry the sql/ prefix.
     *
     * @param list<string> $tracked
     */
    private function sqlFile(string $reference, array $tracked): ?string
    {
        $reference = ltrim($reference, '/');
        $candidates = [$reference, 'sql/' . $reference];
        foreach ($candidates as $candidate) {
            foreach ($tracked as $file) {
                if ($file === $candidate || str_ends_with($file, '/' . $candidate)) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * Committed SQL that no PHP file names. A warning: an unreferenced upgrade
     * script cannot break an upgrade, it can only mean a step is missing.
     *
     * @param list<string> $tracked
     */
    private function reportOrphanSql(Context $context, Reporter $reporter, array $tracked): void
    {
        $php = '';
        foreach ($tracked as $file) {
            if (str_ends_with($file, '.php')) {
                $php .= "\n" . ($context->read($file) ?? '');
            }
        }

        $orphans = [];
        foreach ($tracked as $file) {
            // Only the extension's own sql/ dir is upgrade territory —
            // .docker/ seeds, test fixtures etc. are legitimately PHP-free.
            if (!str_ends_with($file, '.sql') || !str_starts_with($file, 'sql/')) {
                continue;
            }
            $name = basename($file);
            // CRM_Extension_Upgrader_Base runs sql/*_install.sql and sql/*_uninstall.sql itself.
            if (dirname($file) === 'sql' && preg_match('/_(?:un)?install\.sql$/', $name) === 1) {
                continue;
            }
            if (!str_contains($php, $name)) {
                $orphans[] = $file;
            }
        }

        if ($orphans !== []) {
            $reporter->warn(
                'committed SQL no PHP references: ' . implode(', ', $orphans)
                . ' — either an upgrade step is missing or the file is a vestige to delete'
            );
        }
    }

    /**
     * An Upgrader class the repo ships but info.xml never declares. Silent for
     * civix-legacy repos, whose .civix shim calls it from hook_civicrm_upgrade.
     *
     * @param list<string> $tracked
     */
    private function reportUndeclaredUpgrader(Context $context, Reporter $reporter, array $tracked): void
    {
        $upgraders = [];
        foreach ($tracked as $file) {
            if (preg_match('#(?:^|/)CRM/[^/]+/Upgrader\.php$#', $file) === 1) {
                $upgraders[] = $file;
            }
        }
        if ($upgraders === []) {
            return;
        }

        foreach ($tracked as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }
            $source = $context->read($file) ?? '';
            if (str_ends_with($file, '.civix.php') && str_contains($source, '_upgrade')) {
                return;
            }
            if (preg_match('/\bfunction\s+\w+_civicrm_upgrade\s*\(/', $source) === 1) {
                return;
            }
        }

        $reporter->warn(
            'info.xml has no <upgrader> although ' . implode(', ', $upgraders) . ' is committed'
            . ' — the upgrade steps in it are never called'
        );
    }
}
