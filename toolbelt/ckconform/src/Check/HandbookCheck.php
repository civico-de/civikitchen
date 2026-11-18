<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * An open-source extension ships a manual in docs/handbook/ (or the directory its info.xml tag
 * handbook:dir=<name> names), publishes it through the shared docs workflow and links the site
 * from info.xml.
 */
final class HandbookCheck implements Check
{
    public const DIR = 'docs/handbook';

    public const TAG = 'handbook:dir=';

    /** The manual directory before ADR-0020. */
    public const OLD_DIR = 'handbook';

    public const SHARED_DOCS = 'extension-docs.yml';

    public function name(): string
    {
        return 'handbook';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $license = $context->infoLicense();
        // A repo that never releases is never installed, so nobody reads its handbook.
        if ($license === '' || strcasecmp($license, 'Proprietary') === 0 || $context->policyValue('release') !== null) {
            return;
        }
        $dir = $this->dir($context);
        if ($dir === null) {
            $reporter->fail(
                'info.xml must name exactly one handbook directory inside the extension with '
                . self::TAG . '<name>, as the handbook extension and the docs build read it'
            );

            return;
        }
        $index = $dir . '/index.md';
        if (!$context->exists($index)) {
            $moved = $dir === self::DIR && $context->exists(self::OLD_DIR . '/index.md')
                ? ' — the manual moved from handbook/ to docs/handbook/: git mv handbook docs/handbook'
                : ' — an open-source extension ships a handbook for its users; see civikitchen\'s '
                    . 'docs/reusable-workflows.md#documentation-site';
            $reporter->fail("info.xml declares {$license}, but there is no {$index}{$moved}");

            return;
        }
        if ($context->isMonorepo() || $context->policyValue('ci') === 'gitlab') {
            return;
        }
        if ($context->jobsCalling(self::SHARED_DOCS) === []) {
            $reporter->fail(
                "{$dir}/ is not published: no workflow calls " . self::SHARED_DOCS
                . ' — add the docs caller from civikitchen\'s docs/reusable-workflows.md#documentation-site'
            );

            return;
        }
        $documentation = $context->infoXml()?->xpath('urls/url[@desc="Documentation"]') ?: [];
        $host = (string) parse_url(trim((string) ($documentation[0] ?? '')), PHP_URL_HOST);
        if (!str_ends_with($host, '.github.io')) {
            $reporter->fail(
                'info.xml does not link the published handbook: set <url desc="Documentation"> to its site, '
                . 'https://<owner>.github.io/<repository>/, which the extension list shows'
            );
        }
    }

    /** The directory the handbook extension reads: the one the tag names, else docs/handbook; null for a bad tag. */
    private function dir(Context $context): ?string
    {
        $declared = [];
        foreach ($context->infoXml()?->xpath('tags/tag') ?: [] as $tag) {
            $text = trim((string) $tag);
            if (str_starts_with($text, self::TAG)) {
                $declared[] = trim(substr($text, strlen(self::TAG)), '/');
            }
        }
        if ($declared === []) {
            return self::DIR;
        }
        $segments = explode('/', $declared[0]);
        if (count($declared) > 1 || array_intersect(['..', '.', ''], $segments) !== []) {
            return null;
        }

        return $declared[0];
    }
}
