<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A route in xml/Menu/*.xml whose page_callback names an own CRM_<Shortname>_…
 * class this extension does not ship; foreign callbacks are passed over.
 */
final class DeclaredCallbackCheck implements Check
{
    public function name(): string
    {
        return 'declared-callback';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $files = $context->isGitRepo()
            ? $context->trackedUnder('xml/Menu', ['.xml'])
            : $context->findFiles('xml/Menu', ['.xml']);
        if ($files === []) {
            return;
        }

        $namespaces = ExtensionNamespace::all($context);

        foreach ($files as $relative) {
            $raw = $context->read($relative);
            if ($raw === null) {
                continue;
            }
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($raw);
            libxml_use_internal_errors($previous);
            if ($xml === false) {
                $reporter->fail("$relative: unparseable menu XML — CiviCRM cannot register any route from this file");
                continue;
            }

            foreach ($xml->item as $item) {
                $callback = trim((string) ($item->page_callback ?? ''));
                if ($callback === '') {
                    // A container entry (label + path only) has no callback.
                    continue;
                }
                $method = null;
                if (str_contains($callback, '::')) {
                    [$callback, $method] = explode('::', $callback, 2);
                    $method = trim($method);
                }
                $callback = ltrim(trim($callback), '\\');
                if (!ExtensionNamespace::isOwnClass($callback, $namespaces)) {
                    // Core or another extension: not resolvable without a boot.
                    continue;
                }

                $expected = str_replace('_', '/', $callback) . '.php';
                if (!$context->ships($expected)) {
                    $reporter->fail(
                        "$relative: page_callback $callback has no file $expected — the route 500s on first visit"
                    );
                    continue;
                }
                if ($method !== null && $method !== '') {
                    $source = $context->read($expected) ?? '';
                    // PHP method names are case-insensitive.
                    if (preg_match('/\bfunction\s+&?\s*' . preg_quote($method, '/') . '\s*\(/i', $source) !== 1) {
                        $reporter->fail(
                            "$relative: page_callback $callback::$method — $expected declares no function $method()"
                        );
                    }
                }
            }
        }
    }

}
