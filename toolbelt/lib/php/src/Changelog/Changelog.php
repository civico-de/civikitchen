<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Changelog;

use CiviKitchen\Toolbelt\Version\SemVer;

/**
 * The one CHANGELOG.md grammar (Keep a Changelog 1.1.0), deliberately strict:
 * anything it does not spell out is an error, not a tolerated variant.
 *
 *   <prose>                     free text before the first "## " heading
 *   ## [Unreleased]             exactly once, and the first "## " heading
 *   ## [<semver>] - YYYY-MM-DD  one per released version, descending SemVer
 *   ### <Section>               only Added, Changed, Deprecated, Removed,
 *                               Fixed, Security; at least one per version
 *   - <bullet>                  at least one per "### " section; wrapped lines
 *                               continue with two spaces
 *   [<semver>]: <url>           one link reference per heading, Unreleased
 *                               included once a version exists; the references
 *                               close the file, nothing but them follows
 */
final class Changelog
{
    public const FILE = 'CHANGELOG.md';

    private const SECTIONS = ['Added', 'Changed', 'Deprecated', 'Removed', 'Fixed', 'Security'];

    /** @var list<string> */
    private array $lines;

    /** @var array<string, string> version => date, in file order */
    private array $versions = [];

    private int $unreleasedEntries = 0;

    /** @var list<string> */
    private array $errors = [];

    public function __construct(private readonly string $name, string $content)
    {
        $this->lines = preg_split('/\R/', $content) ?: [];
        $this->parse();
    }

    public static function read(string $path): self
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("{$path} does not exist or is unreadable");
        }

        return new self($path, $content);
    }

    /** @return list<string> grammar violations, each "<file>:<line>: <message>" */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<string> the released versions, in file order */
    public function versions(): array
    {
        return array_map('strval', array_keys($this->versions));
    }

    /**
     * Grammar violations plus what blocks releasing $version on $today
     * (YYYY-MM-DD): no section for it, a future date, entries left in Unreleased.
     *
     * @return list<string>
     */
    public function releaseErrors(string $version, string $today): array
    {
        $version = self::version($version);
        $errors = $this->errors;
        $where = "{$this->name}:" . count($this->lines);
        if (!isset($this->versions[$version])) {
            $errors[] = "{$where}: version {$version} has no changelog section";
        } elseif ($this->versions[$version] > $today) {
            $errors[] = "{$where}: version {$version} is dated {$this->versions[$version]}, in the future";
        }
        if ($this->unreleasedEntries > 0) {
            $errors[] = "{$where}: [Unreleased] still has entries — move them under [{$version}]";
        }

        return $errors;
    }

    /** The body of $version's section without its heading, null when there is none. */
    public function section(string $version): ?string
    {
        $want = '## [' . self::version($version) . '] - ';
        $body = null;
        foreach ($this->lines as $line) {
            if ($body === null) {
                if (str_starts_with($line, $want)) {
                    $body = [];
                }
                continue;
            }
            if (str_starts_with($line, '## ') || preg_match('/^\[[^\]]+\]: /', $line) === 1) {
                break;
            }
            $body[] = $line;
        }
        $text = $body === null ? '' : trim(implode("\n", $body), "\n");

        return trim($text) === '' ? null : $text;
    }

    /** Strips a leading v and refuses anything but a SemVer version. */
    public static function version(string $version): string
    {
        $version = str_starts_with($version, 'v') ? substr($version, 1) : $version;
        if (!SemVer::valid($version)) {
            throw new \RuntimeException("'{$version}' is not a SemVer version");
        }

        return $version;
    }

    private function parse(): void
    {
        $unreleased = 0;
        $seenHeading = false;
        $inUnreleased = false;
        $version = null;
        $versionSections = 0;
        $versionEntries = 0;
        $section = null;
        $sectionEntries = 0;
        $references = [];
        $inReferences = false;
        $closeSection = function (int $line) use (&$section, &$sectionEntries): void {
            if ($section !== null && $sectionEntries === 0) {
                $this->error($line, "### {$section} has no entries");
            }
            $section = null;
            $sectionEntries = 0;
        };
        $closeVersion = function (int $line) use (&$version, &$versionSections, &$versionEntries, $closeSection): void {
            if ($version !== null && $versionSections === 0) {
                $this->error($line, "version {$version} has no ### section");
            }
            if ($version !== null && $versionEntries === 0) {
                $this->error($line, "version {$version} has no entries");
            }
            $closeSection($line);
            $version = null;
            $versionSections = 0;
            $versionEntries = 0;
        };

        foreach ($this->lines as $index => $line) {
            $number = $index + 1;
            if ($inReferences && trim($line) !== '' && preg_match('/^\[[^\]]+\]: \S/', $line) !== 1) {
                $this->error($number, "content after the link references: {$line}");
                continue;
            }
            if (str_starts_with($line, '## ')) {
                $closeVersion($number);
                if ($line === '## [Unreleased]') {
                    if ($seenHeading) {
                        $this->error($number, '[Unreleased] must be the first ## heading');
                    }
                    $unreleased++;
                    $inUnreleased = true;
                    $seenHeading = true;
                    continue;
                }
                $inUnreleased = false;
                $seenHeading = true;
                if (preg_match('/^## \[([^\]]+)\] - ((\d{4})-(\d{2})-(\d{2}))$/', $line, $match) !== 1 || !SemVer::valid($match[1])
                    || !checkdate((int) $match[4], (int) $match[5], (int) $match[3])) {
                    $this->error($number, "bad version heading: {$line}");
                    continue;
                }
                if (isset($this->versions[$match[1]])) {
                    $this->error($number, "duplicate version heading {$match[1]}");
                } else {
                    $previous = array_key_last($this->versions);
                    if ($previous !== null && SemVer::compare((string) $previous, $match[1]) <= 0) {
                        $this->error($number, "versions out of descending order: {$previous} before {$match[1]}");
                    }
                    $this->versions[$match[1]] = $match[2];
                }
                $version = $match[1];
                continue;
            }
            if (str_starts_with($line, '### ')) {
                $closeSection($number);
                $name = substr($line, 4);
                if (!in_array($name, self::SECTIONS, true)) {
                    $this->error($number, "unknown subsection: {$name}");
                }
                if ($version === null && !$inUnreleased) {
                    $this->error($number, '### outside any version section');
                }
                $versionSections++;
                $section = $name;
                continue;
            }
            if (str_starts_with($line, '- ')) {
                if ($section === null) {
                    $this->error($number, 'entry outside a ### section');
                }
                $versionEntries++;
                $sectionEntries++;
                if ($inUnreleased) {
                    $this->unreleasedEntries++;
                }
                continue;
            }
            if (preg_match('/^\[([^\]]+)\]: \S/', $line, $match) === 1) {
                if (isset($references[$match[1]])) {
                    $this->error($number, "duplicate link reference [{$match[1]}]");
                }
                $references[$match[1]] = true;
                $inReferences = true;
                continue;
            }
            if ($seenHeading && trim($line) !== '' && !(str_starts_with($line, '  ') && $sectionEntries > 0)) {
                $this->error($number, "unexpected line: {$line}");
            }
        }
        $end = count($this->lines);
        $closeVersion($end);

        if ($unreleased !== 1) {
            $this->error($end, "expected exactly one \"## [Unreleased]\" heading, found {$unreleased}");
        }
        foreach (array_keys($this->versions) as $known) {
            if (!isset($references[(string) $known])) {
                $this->error($end, "no link reference for [{$known}]");
            }
        }
        if ($this->versions !== [] && !isset($references['Unreleased'])) {
            $this->error($end, 'no link reference for [Unreleased]');
        }
    }

    private function error(int $line, string $message): void
    {
        $this->errors[] = "{$this->name}:{$line}: {$message}";
    }
}
