<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Changelog\Changelog;
use CiviKitchen\Toolbelt\Repository\Files;

/** ckchangelog: the CHANGELOG.md grammar as a lint, a release gate and the release notes. */
final class ChangelogCommand implements Command
{
    public function run(array $arguments): int
    {
        $file = Changelog::FILE;
        $index = array_search('--file', $arguments, true);
        if ($index !== false) {
            $file = (string) ($arguments[$index + 1] ?? '');
            array_splice($arguments, $index, 2);
        }
        $operation = array_shift($arguments) ?? '';
        if (in_array($operation, ['-h', '--help'], true)) {
            echo $this->usage();
            return 0;
        }
        if ($file === '' || !in_array($operation, ['lint', 'release', 'section', 'published'], true)
            || count($arguments) !== (in_array($operation, ['lint', 'published'], true) ? 0 : 1)) {
            fwrite(STDERR, $this->usage());
            return 2;
        }
        $changelog = Changelog::read($file);

        if ($operation === 'section') {
            $body = $changelog->section($arguments[0]);
            if ($body === null) {
                fwrite(STDERR, 'ckchangelog: no section for version ' . Changelog::version($arguments[0]) . " in {$file}\n");
                return 1;
            }
            echo $body, "\n";
            return 0;
        }
        if ($operation === 'published') {
            return $this->published($changelog, $file);
        }

        $errors = $operation === 'release'
            ? $changelog->releaseErrors($arguments[0], gmdate('Y-m-d'))
            : $changelog->errors();
        if ($errors !== []) {
            fwrite(STDERR, implode("\n", $errors) . "\nckchangelog: the format is Keep a Changelog 1.1.0 — see `ckchangelog --help`\n");
            return 1;
        }
        echo $operation === 'release'
            ? 'ckchangelog: ' . Changelog::version($arguments[0]) . " is documented\n"
            : "ckchangelog: {$file} is clean\n";
        return 0;
    }

    /** Each tagged version's section must read as it did in the tag: its release notes were cut from it. */
    private function published(Changelog $changelog, string $file): int
    {
        if (str_starts_with($file, '/')) {
            fwrite(STDERR, "ckchangelog: published reads the file from the tags, so --file must be relative\n");
            return 2;
        }
        $git = new Files((string) getcwd());
        if (!$git->isGitCheckout()) {
            fwrite(STDERR, "ckchangelog: published needs a git checkout with its tags\n");
            return 2;
        }
        $changed = [];
        foreach ($changelog->versions() as $version) {
            $tag = "v{$version}";
            if ($git->git(['rev-parse', '-q', '--verify', "refs/tags/{$tag}"])['status'] !== 0) {
                continue;
            }
            $shown = $git->git(['show', "{$tag}:./{$file}"]);
            // A changelog written after the tag never carried this section into a release.
            $then = $shown['status'] === 0 ? (new Changelog("{$tag}:{$file}", $shown['output']))->section($version) : null;
            if ($then !== null && $then !== $changelog->section($version)) {
                $changed[] = "{$file}: [{$version}] differs from its text in {$tag}; new entries belong under [Unreleased]";
            }
        }
        if ($changed !== []) {
            fwrite(STDERR, implode("\n", $changed) . "\n");
            return 1;
        }
        echo "ckchangelog: every tagged section in {$file} is unchanged\n";
        return 0;
    }

    private function usage(): string
    {
        return <<<'TXT'
usage: ckchangelog [--file <path>] lint
       ckchangelog [--file <path>] release <version>
       ckchangelog [--file <path>] section <version>
       ckchangelog [--file <path>] published

  lint      CHANGELOG.md follows the grammar below
  release   lint, plus <version> has a section dated today or earlier and
            [Unreleased] is empty
  section   print <version>'s section body, the release notes
  published every version with a v<version> tag has the section text it had
            in that tag; a section the tag's changelog lacks is not checked

Grammar (Keep a Changelog 1.1.0, nothing else is tolerated):
  <prose>                     free text before the first "## " heading
  ## [Unreleased]             exactly once, and the first "## " heading
  ## [<semver>] - YYYY-MM-DD  one per released version, descending SemVer
  ### <Section>               Added, Changed, Deprecated, Removed, Fixed or
                              Security; at least one per version
  - <bullet>                  at least one per "### " section; wrapped lines
                              continue with two spaces
  [<semver>]: <url>           one link reference per heading, Unreleased
                              included once a version exists; the references
                              close the file

TXT;
    }
}
