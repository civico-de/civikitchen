<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Files;
use CiviKitchen\Toolbelt\Repository\Policy;

final class MigrateCommand implements Command
{
    /**
     * phpcs sniff (standard.category.sniff, any message suffix stripped) => the mago rule that took it over.
     * name-boolean-arguments is missing on purpose: off in the baseline, a pragma for it would go unfulfilled.
     */
    private const PHPCS_TO_MAGO = [
        'CiviKitchen.Api.NoGenericVarOnActionParam' => 'civikitchen/no-generic-var-on-action-param',
        'CiviKitchen.Api.NoRequiredOnExternalAction' => 'civikitchen/no-required-on-external-action',
        'CiviKitchen.Extension.UseMixinsForStandardHooks' => 'civikitchen/use-mixins-for-standard-hooks',
        'CiviKitchen.Files.MaxFileLength' => 'civikitchen/max-file-length',
        'CiviKitchen.I18n.UseExtensionTs' => 'civikitchen/use-extension-ts',
        'CiviKitchen.Security.NoUnsafeUnserialize' => 'civikitchen/no-unsafe-unserialize',
        'CiviKitchen.Tests.NoTautologicalAssertion' => 'civikitchen/no-tautological-assertion',
        'Drupal.Scope.MethodScope' => 'civikitchen/explicit-visibility',
        'Drupal.Classes.PropertyDeclaration' => 'civikitchen/explicit-visibility',
        'Drupal.ControlStructures.InlineControlStructure' => 'block-statement',
        'Drupal.Semantics.FunctionAlias' => 'no-alias-function',
        'Drupal.Classes.UnusedUseStatement' => 'no-redundant-use',
        'SlevomatCodingStandard.TypeHints.DeclareStrictTypes' => 'strict-types',
    ];

    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    public function run(array $arguments): int
    {
        foreach ($arguments as $argument) {
            if (in_array($argument, ['-h', '--help'], true)) {
                echo $this->usage();
                return 0;
            }
            fwrite(STDERR, "ckmigrate: unknown argument: {$argument}\n" . $this->usage());
            return 2;
        }
        $repository = new Files($this->checkoutRoot, $this->runner);
        if (!$repository->isGitCheckout()) {
            fwrite(STDERR, "ckmigrate: not a git checkout - run it where git diff can show what changed.\n");
            return 2;
        }
        $templateCustom = (new Policy($this->checkoutRoot, $this->runner))->value('template_custom');
        $custom = array_map('trim', explode(',', explode(' -- ', $templateCustom, 2)[0]));

        $manual = [];
        $rewritten = 0;
        foreach ($repository->source(['php'], [], true, true) as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, 'phpcs:')) {
                continue;
            }
            [$migrated, $left] = $this->migrateComments($source);
            foreach ($left as $line) {
                $manual[] = "{$file}:{$line}: phpcs comment ckmigrate cannot rewrite";
            }
            if ($migrated !== $source) {
                file_put_contents($file, $migrated);
                $rewritten++;
                echo "rewrote phpcs comments: {$file}\n";
            }
        }
        foreach (['phpcs.xml.dist', 'phpcs.xml'] as $config) {
            if (!is_file($config)) {
                continue;
            }
            $settings = $this->portedSniffSettings($config);
            if ($settings === null) {
                $manual[] = "{$config}: not valid XML, left in place";
                continue;
            }
            unlink($config);
            echo "deleted {$config}\n";
            foreach ($settings as $setting) {
                $manual[] = "{$config} (deleted) {$setting}";
            }
        }
        if (array_intersect(['phpcs.xml', 'phpcs.xml.dist'], $custom) !== []) {
            $manual[] = 'civikitchen.yaml: drop the phpcs.xml.dist entry from policy.template_custom';
        }

        echo "ckmigrate: {$rewritten} file(s) rewritten. Review with git diff, then run cklint.\n";
        if ($manual === []) {
            return 0;
        }
        echo "Left for you (move a setting into mago.toml only if it still matters):\n";
        foreach ($manual as $item) {
            echo "  {$item}\n";
        }
        return 1;
    }

    /**
     * What the config set for a sniff that became a mago rule; path excludes of the
     * whole ruleset do not carry over, since mago reads only the PHP git tracks.
     *
     * @return list<string>|null null for a file that is not XML
     */
    private function portedSniffSettings(string $config): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $ruleset = simplexml_load_file($config);
        libxml_use_internal_errors($previous);
        if ($ruleset === false) {
            return null;
        }
        $settings = [];
        foreach ($ruleset->rule as $rule) {
            $reference = (string) $rule['ref'];
            foreach ($rule->exclude as $exclude) {
                $disabled = $this->magoRule((string) $exclude['name']);
                if ($disabled !== null) {
                    $settings[] = "turned off {$exclude['name']}: disable {$disabled} in mago.toml";
                }
            }
            $target = $this->magoRule($reference);
            if ($target === null) {
                continue;
            }
            foreach ($rule->children() as $child) {
                if ($child->getName() !== 'exclude') {
                    $settings[] = "configured {$reference} (now {$target}): " . trim((string) $child->asXML());
                }
            }
        }
        return $settings;
    }

    private function magoRule(string $code): ?string
    {
        return self::PHPCS_TO_MAGO[implode('.', array_slice(explode('.', $code), 0, 3))] ?? null;
    }

    /**
     * Turns each phpcs comment into its mago pragma or drops it.
     *
     * @return array{0: string, 1: list<int>} the new source and the lines left for a person
     */
    public function migrateComments(string $source): array
    {
        $tokens = token_get_all($source);
        $left = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) || !str_contains($token[1], 'phpcs:')) {
                continue;
            }
            $directive = $token[0] === T_COMMENT
                ? preg_match('~^(?://|#|/\*)\s*phpcs:(ignore|disable|enable|ignoreFile)\b\s*([^\n]*?)(?:\s+--\s*(.*?))?\s*(?:\*/)?$~', $token[1], $match)
                : 0;
            if ($directive !== 1) {
                $left[] = $token[2];
                continue;
            }
            $rules = [];
            foreach (preg_split('/\s*,\s*/', trim($match[2]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $code) {
                $rule = $this->magoRule($code);
                if ($rule !== null) {
                    $rules['lint:' . $rule] = true;
                }
            }
            if ($rules !== [] && $match[1] !== 'ignore') {
                // mago has no block form; a disabled region of a ported rule needs one pragma per finding.
                $left[] = $token[2];
                continue;
            }
            $block = str_starts_with($token[1], '/*');
            if ($rules !== []) {
                // A block comment can share its line with code, so it stays a block.
                $pragma = '@mago-expect ' . implode(',', array_keys($rules)) . (($match[3] ?? '') === '' ? '' : ' -- ' . $match[3]);
                $tokens[$index] = $block ? "/* {$pragma} */" : "// {$pragma}";
                continue;
            }
            $tokens[$index] = '';
            $this->dropLineIfEmpty($tokens, $index, $block);
        }
        return [implode('', array_map(static fn(array|string $token): string => is_array($token) ? $token[1] : $token, $tokens)), $left];
    }

    /**
     * Removes the line a dropped comment stood on alone, or the spaces before it after code.
     * A block comment between two tokens leaves one space.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function dropLineIfEmpty(array &$tokens, int $index, bool $block): void
    {
        $text = static function (int $at) use (&$tokens): string {
            return is_array($tokens[$at] ?? '') ? $tokens[$at][1] : (string) ($tokens[$at] ?? '');
        };
        // The whitespace before the comment, across comments dropped earlier on the same stretch.
        $before = [];
        for ($at = $index - 1; $at >= 0 && trim($text($at)) === ''; $at--) {
            $before[] = $at;
        }
        $previous = $at >= 0 ? $text($at) : "\n";
        $whitespace = implode('', array_map($text, array_reverse($before)));
        $lineStart = preg_match('/[\r\n][ \t]*$/', $previous . $whitespace) === 1;
        $next = $text($index + 1);
        $trimBefore = function () use (&$tokens, $before, $text): void {
            foreach ($before as $at) {
                $this->setText($tokens, $at, rtrim($text($at), " \t"));
                if ($text($at) !== '') {
                    return;
                }
            }
        };
        if ($lineStart && preg_match('/^(\r\n|\n|\r)/', $next, $newline) === 1) {
            $trimBefore();
            $rest = substr($next, strlen($newline[1]));
            // A blank line after the comment goes too where it would double one before it or open a block.
            if (preg_match('/(?:\{|\R[ \t]*)\R[ \t]*$/', $previous . $whitespace) === 1) {
                $rest = preg_replace('/^[ \t]*\R/', '', $rest, 1);
            }
            $this->setText($tokens, $index + 1, $rest);
        } elseif ($lineStart) {
            // Code follows on the comment's line: it keeps the line's indentation.
            $this->setText($tokens, $index + 1, ltrim($next, " \t"));
        } else {
            // The open tag's own whitespace is part of `<?php `, so only whitespace tokens shrink.
            $trimBefore();
            $joined = $previous . implode('', array_map($text, array_reverse($before)));
            // `return /* x */true` must not become `returntrue`, and a close tag keeps its space.
            $merges = $block && preg_match('/[\w\x80-\xff]$/', $joined) === 1 && preg_match('/^[\w\x80-\xff$\\\\]/', $next) === 1;
            $closeTag = str_starts_with($next, '?>') && preg_match('/\S$/', $joined) === 1;
            $tokens[$index] = $merges || $closeTag ? ' ' : '';
        }
    }

    /** @param array<int, array{0: int, 1: string, 2: int}|string> $tokens */
    private function setText(array &$tokens, int $index, string $text): void
    {
        if (!isset($tokens[$index])) {
            return;
        }
        $tokens[$index] = is_array($tokens[$index]) ? [$tokens[$index][0], $text, $tokens[$index][2]] : $text;
    }

    private function usage(): string
    {
        return <<<'TXT'
ckmigrate - move an extension repository off retired CiviKitchen tools.

  ckmigrate               apply every migration in the current git checkout

phpcs -> mago: a phpcs:ignore for a sniff that became a mago rule turns into
`// @mago-expect lint:<rule>` (reason kept); every other phpcs comment goes,
and phpcs.xml(.dist) is deleted. A pragma covers one finding where phpcs:ignore
covered the line. Exits 1 and lists what needs a person: a setting the deleted
config made for a ported sniff, a phpcs comment it cannot rewrite, a template_custom entry.
Review with git diff, then run cklint.

TXT;
    }
}
