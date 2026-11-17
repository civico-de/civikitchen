<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A phpunit config without a real coverage include list (<coverage> or legacy
 * <filter><whitelist>; PHPUnit 9 ignores PHPUnit 10's <source>), parsed as XML.
 */
final class CoverageSectionCheck implements Check
{
    public function name(): string
    {
        return 'coverage-section';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->hasShippedUnder('tests/phpunit')) {
            return;
        }

        // PHPUnit reads phpunit.xml before phpunit.xml.dist, so a shipped one is judged alone.
        [$verdict, $source] = $this->coverage(
            $context->readShipped('phpunit.xml') ?? $context->readShipped('phpunit.xml.dist'),
        );
        if ($verdict === 'sources') {
            $reporter->ok('phpunit config declares coverage sources');

            return;
        }

        $reporter->fail(match ($verdict) {
            'malformed' => 'phpunit config is not well-formed XML',
            'empty' => 'phpunit config\'s <coverage> lists no sources',
            default => 'phpunit config has no <coverage> section',
        } . ($source ? ' (PHPUnit 9 ignores <source>: list the sources in <coverage><include>)' : '')
            . ' — coverage runs measure nothing');
    }

    /**
     * 'sources', 'empty' for a section without an include list, 'malformed',
     * or 'none'; and whether PHPUnit 10's <source> is there.
     *
     * @return array{string, bool}
     */
    private function coverage(?string $xml): array
    {
        if ($xml === null || trim($xml) === '') {
            return ['none', false];
        }

        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        if ($parsed === false) {
            return ['malformed', false];
        }
        $source = ($parsed->xpath('/*/source') ?: []) !== [];
        $entries = '/*/coverage/include/*[self::directory or self::file] | /*/filter/whitelist/*[self::directory or self::file]';
        if (($parsed->xpath($entries) ?: []) !== []) {
            return ['sources', $source];
        }

        return [($parsed->xpath('/*/coverage | /*/filter/whitelist') ?: []) !== [] ? 'empty' : 'none', $source];
    }
}
