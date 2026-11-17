<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\PhpSource;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\SmartySource;

/**
 * `{ts}` in an extension template or `.mgd.php` body that no
 * `{crmScope extensionKey='…'}` or `domain` attribute ties to this extension,
 * judged per file; a foreign key fails, a Smarty-variable key is accepted.
 */
final class CrmScopeCheck implements Check
{
    public function name(): string
    {
        return 'crm-scope';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $key = $context->extensionKey();
        if ($key === null) {
            // No info.xml key: nothing to compare a wrapper against.
            return;
        }

        foreach ($this->sources($context) as $relative) {
            $source = $context->read($relative);
            if ($source === null || !str_contains($source, '{ts')) {
                continue;
            }
            if (str_ends_with($relative, '.php')) {
                // Only the string literals are template bodies; a comment naming the tags is prose.
                $source = PhpSource::withoutComments($source);
            }
            foreach ($this->findings($source, $key) as $reason => $lines) {
                $where = count($lines) === 1
                    ? 'line ' . $lines[0]
                    : 'lines ' . implode(', ', $lines);
                $reporter->fail(
                    "$relative: $where: $reason — {ts} resolves in core's translation domain, "
                    . "so this extension's own .po is never consulted and the string stays untranslated"
                );
            }
        }
    }

    /**
     * Templates the extension owns, plus `.mgd.php` files that may carry a
     * message-template body.
     *
     * @return list<string>
     */
    private function sources(Context $context): array
    {
        return $context->sourceFiles(
            '',
            ['.tpl', '.mgd.php'],
            static fn (string $file): bool => str_ends_with($file, '.mgd.php')
                || str_starts_with($file, 'templates/'),
        );
    }

    /**
     * Uncovered `{ts}` blocks grouped by reason, one line per reason.
     *
     * @return array<string, list<int>> reason => 1-based line numbers
     */
    private function findings(string $source, string $key): array
    {
        $source = SmartySource::blankOutInertRegions($source);

        /** @var list<array{offset: int, kind: string, key: string|null, dynamic: bool}> $events */
        $events = [];
        foreach ($this->matches('/\{crmScope\b([^}]*)\}/i', $source) as [$offset, $groups]) {
            [$scopeKey, $dynamic] = $this->scopeKey($groups[1] ?? '');
            $events[] = ['offset' => $offset, 'kind' => 'open', 'key' => $scopeKey, 'dynamic' => $dynamic];
        }
        foreach ($this->matches('#\{/crmScope\}#i', $source) as [$offset]) {
            $events[] = ['offset' => $offset, 'kind' => 'close', 'key' => null, 'dynamic' => false];
        }
        foreach ($this->matches('/\{ts(?=[\s}])([^}]*)\}/', $source) as [$offset, $groups]) {
            [$domain, $dynamic] = $this->attributeValue($groups[1] ?? '', 'domain');
            $events[] = [
                'offset' => $offset,
                'kind' => 'ts',
                'key' => $domain,
                'dynamic' => $dynamic,
                'hasDomain' => $domain !== null || $dynamic,
            ];
        }
        usort($events, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        /** @var list<array{key: string|null, dynamic: bool}> $stack */
        $stack = [];
        $findings = [];
        foreach ($events as $event) {
            if ($event['kind'] === 'open') {
                $stack[] = ['key' => $event['key'], 'dynamic' => $event['dynamic']];
                continue;
            }
            if ($event['kind'] === 'close') {
                array_pop($stack);
                continue;
            }

            if (($event['hasDomain'] ?? false) === true) {
                // An explicit domain attribute wins over any {crmScope}.
                if ($event['dynamic'] || $event['key'] === $key) {
                    continue;
                }
                $reason = "its domain=\"{$event['key']}\" is not this extension's key '$key', "
                    . 'and an explicit domain wins over any {crmScope}';
            }
            else {
                $reason = $this->reason($stack === [] ? null : $stack[count($stack) - 1], $key);
            }
            if ($reason === null) {
                continue;
            }
            $findings[$reason] ??= [];
            $findings[$reason][] = substr_count($source, "\n", 0, $event['offset']) + 1;
        }

        return $findings;
    }

    /**
     * @param array{key: string|null, dynamic: bool}|null $scope
     */
    private function reason(?array $scope, string $key): ?string
    {
        if ($scope === null) {
            return "no {crmScope extensionKey='$key'} around it";
        }
        if ($scope['dynamic']) {
            return null;
        }
        if ($scope['key'] === null || $scope['key'] === '') {
            return "the enclosing {crmScope} names no extensionKey (expected '$key')";
        }
        if ($scope['key'] !== $key) {
            return "the enclosing {crmScope} names '{$scope['key']}', not this extension's key '$key'";
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: bool} literal key (or null), and whether
     *                                        it is a Smarty expression
     */
    private function scopeKey(string $attributes): array
    {
        return $this->attributeValue($attributes, 'extensionKey');
    }

    /**
     * @return array{0: string|null, 1: bool} literal value (or null), and
     *                                        whether it is a Smarty expression
     */
    private function attributeValue(string $attributes, string $name): array
    {
        // Inside a .mgd.php the quotes arrive escaped: extensionKey=\'greeter\'.
        $attributes = str_replace(['\\\'', '\\"'], ["'", '"'], $attributes);

        if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*(?:\'([^\']*)\'|"([^"]*)"|(\S+))/i', $attributes, $match) !== 1) {
            return [null, false];
        }
        if (($match[3] ?? '') !== '') {
            $bare = $match[3];

            return str_contains($bare, '$') ? [null, true] : [$bare, false];
        }
        $quoted = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');

        return str_contains($quoted, '$') ? [null, true] : [$quoted, false];
    }

    /**
     * @return list<array{0: int, 1: array<int, string>}> offset, capture groups
     */
    private function matches(string $pattern, string $source): array
    {
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return [];
        }
        $found = [];
        foreach ($matches as $set) {
            $groups = [];
            foreach ($set as $index => $capture) {
                $groups[$index] = $capture[0];
            }
            $found[] = [(int) $set[0][1], $groups];
        }

        return $found;
    }
}
