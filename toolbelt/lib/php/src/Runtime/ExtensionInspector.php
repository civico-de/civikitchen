<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Runtime;

use Composer\Semver\Semver;
use RuntimeException;
use SimpleXMLElement;

final class ExtensionInspector
{
    public function __construct(private readonly string $composerAutoload)
    {
    }

    public function load(string $infoFile, string $expectedKey): SimpleXMLElement
    {
        return $this->parse(is_file($infoFile) ? (string) file_get_contents($infoFile) : '', $expectedKey);
    }

    public function parse(string $info, string $expectedKey): SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $xml = $info === '' ? false : simplexml_load_string($info);
        if (!$xml instanceof SimpleXMLElement || trim((string) $xml['key']) !== $expectedKey) {
            throw new RuntimeException("extension info.xml key does not match {$expectedKey}");
        }
        return $xml;
    }

    public function key(string $infoFile): string
    {
        libxml_use_internal_errors(true);
        $xml = is_file($infoFile) ? simplexml_load_file($infoFile) : false;
        return $xml instanceof SimpleXMLElement ? trim((string) $xml['key']) : '';
    }

    public function version(string $infoFile): string
    {
        libxml_use_internal_errors(true);
        $xml = is_file($infoFile) ? simplexml_load_file($infoFile) : false;
        return $xml instanceof SimpleXMLElement ? trim((string) $xml->version) : '';
    }

    /** @return list<string> */
    public function requirements(string $infoFile): array
    {
        libxml_use_internal_errors(true);
        $xml = is_file($infoFile) ? simplexml_load_file($infoFile) : false;
        if (!$xml instanceof SimpleXMLElement) {
            return [];
        }
        $requirements = [];
        foreach ($xml->requires->ext ?? [] as $extension) {
            $key = trim((string) $extension);
            if ($key !== '') {
                $requirements[] = $key;
            }
        }
        return $requirements;
    }

    public function assertVersion(SimpleXMLElement $xml, string $constraint): void
    {
        if ($constraint === '') {
            return;
        }
        if (!class_exists(Semver::class)) {
            if (!is_file($this->composerAutoload)) {
                throw new RuntimeException('Composer version-constraint validator is unavailable');
            }
            require_once $this->composerAutoload;
        }
        $version = trim((string) $xml->version);
        if ($version === '' || !Semver::satisfies($version, $constraint)) {
            $actual = $version === '' ? 'is missing' : "{$version} does not satisfy {$constraint}";
            throw new RuntimeException("extension version {$actual}");
        }
    }
}
