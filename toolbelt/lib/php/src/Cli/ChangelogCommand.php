<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Changelog\Changelog;

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
        if ($file === '' || !in_array($operation, ['lint', 'release', 'section'], true)
            || count($arguments) !== ($operation === 'lint' ? 0 : 1)) {
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

    private function usage(): string
    {
        return <<<'TXT'
usage: ckchangelog [--file <path>] lint
       ckchangelog [--file <path>] release <version>
       ckchangelog [--file <path>] section <version>

  lint      CHANGELOG.md follows the grammar below
  release   lint, plus <version> has a section dated today or earlier and
            [Unreleased] is empty
  section   print <version>'s section body, the release notes

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
