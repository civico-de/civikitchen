<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\Scalar;
use CiviKitchen\Ckconform\Suppressions;

/**
 * Malformed `*.mgd.php` records (missing name/entity or v4 `params.values`, a
 * wrong `module`, duplicate identities) that abort or corrupt reconciliation;
 * a missing `cleanup` on an admin-editable entity warns.
 */
final class ManagedEntityMetadataCheck implements Check
{
    private const VERSIONS = [3, 4, '3', '4'];

    private const CLEANUP = ['always', 'never', 'unused'];

    private const UPDATE = ['always', 'never', 'unmodified'];

    /**
     * Entities an admin routinely edits through the UI, where the default
     * cleanup=always turns an uninstall (or a revert) into data loss.
     */
    private const ADMIN_EDITABLE = [
        'Job',
        'SavedSearch',
        'SearchDisplay',
        'MessageTemplate',
        'Navigation',
        'OptionValue',
        'Afform',
    ];

    public function name(): string
    {
        return 'managed-entity-metadata';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $extensionKey = $context->extensionKey();
        $seen = [];

        $notAList = static function (string $relative) use ($reporter): void {
            $reporter->fail("$relative: does not return a list of managed records");
        };
        foreach (ManagedFiles::records($context, $reporter, 'managed metadata unchecked', $notAList) as [$relative, $records]) {
            foreach ($records as $index => $record) {
                $label = "$relative record #$index";
                if (!is_array($record)) {
                    $reporter->fail("$label: is not an array");
                    continue;
                }

                $name = $record['name'] ?? null;
                foreach (['name', 'entity', 'params'] as $key) {
                    if (!isset($record[$key])) {
                        $reporter->fail("$label: missing required key '$key'");
                    }
                }
                if (isset($record['name']) && is_string($record['name'])) {
                    $label = "$relative record '{$record['name']}'";
                }

                $params = $record['params'] ?? null;
                if (isset($record['params']) && !is_array($params)) {
                    $reporter->fail("$label: 'params' is not an array");
                    $params = null;
                }
                if (is_array($params)) {
                    // mgd-php@1 and @2 set an empty params.version to '3'.
                    $defaulted = empty($params['version']);
                    $version = $defaulted ? 3 : $params['version'];
                    if (!in_array($version, self::VERSIONS, true)) {
                        $reporter->fail("$label: params version '" . Scalar::describe($version) . "' is not 3 or 4");
                    } elseif ((int) $version === 4 && !isset($params['values'])) {
                        $reporter->fail("$label: APIv4 params without 'values' — nothing would be written");
                    } elseif ((int) $version === 4 && !is_array($params['values'])) {
                        $reporter->fail("$label: APIv4 params 'values' is not an array");
                    } elseif ((int) $version === 3 && !$this->fileSuppressed($context, $relative)) {
                        // No line to anchor an inline ignore to; the escape is the
                        // file-wide `ckconform-ignore-file managed-entity-metadata`.
                        $reporter->warn("$label: managed record uses APIv3" . ($defaulted ? ' (no params.version, which mgd-php defaults to 3)' : '') . " — prefer version 4 with 'values' when the entity supports APIv4 save/update on the minimum supported core; otherwise document the compatibility exception");
                    }
                }

                if (isset($record['cleanup']) && !in_array($record['cleanup'], self::CLEANUP, true)) {
                    $reporter->fail("$label: cleanup '" . Scalar::describe($record['cleanup']) . "' is not one of always|never|unused");
                }
                if (isset($record['update']) && !in_array($record['update'], self::UPDATE, true)) {
                    $reporter->fail("$label: update '" . Scalar::describe($record['update']) . "' is not one of always|never|unmodified");
                }

                // A missing module is filled in by the mgd-php mixin; a wrong
                // one makes the next reconcile delete the record as orphaned.
                $module = $record['module'] ?? null;
                if (isset($record['module']) && $extensionKey !== null && $module !== $extensionKey) {
                    $reporter->fail("$label: module '" . Scalar::describe($module) . "' is not this extension's key '$extensionKey' — reconciliation will treat the record as orphaned and delete it");
                }

                if (!isset($record['cleanup'])
                    && is_string($record['entity'] ?? null)
                    && in_array($record['entity'], self::ADMIN_EDITABLE, true)
                ) {
                    $reporter->warn("$label: entity {$record['entity']} is admin-editable but no 'cleanup' is set — the default 'always' discards admin changes on uninstall");
                }

                if (is_string($name) && is_string($record['entity'] ?? null)) {
                    $identity = ($module ?? $extensionKey ?? '') . "\0" . $name . "\0" . $record['entity'];
                    if (isset($seen[$identity])) {
                        $reporter->fail("$label: duplicate managed identity (module, name, entity) — also defined in {$seen[$identity]}; only the last record survives");
                    } else {
                        $seen[$identity] = $relative;
                    }
                }
            }
        }
    }

    /** @var array<string, bool> */
    private array $fileSuppression = [];

    private function fileSuppressed(Context $context, string $relative): bool
    {
        return $this->fileSuppression[$relative] ??= Suppressions::of(
            (string) $context->read($relative)
        )->suppressed($this->name(), 0);
    }

}
