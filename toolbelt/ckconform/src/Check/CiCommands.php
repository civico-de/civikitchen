<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Context;

/**
 * What the CI jobs judging this extension actually run: their workflow and
 * GitLab pipeline text without comments, the gates behind `ck` subcommands and the shared CI,
 * the npm and composer scripts they invoke by name, and the repo's own shell scripts they invoke
 * by path, followed transitively.
 */
final class CiCommands
{
    /** The tools each `ck ci` gate runs (toolbelt/lib/php/src/Cli/CiCommand.php). */
    private const CI_GATES = [
        'cklint' => 'cklint',
        'ckconform' => 'ckconform',
        'ckcivix' => 'ckcivix',
        'ckfmt' => 'ckfmt',
        'ckcoverage' => 'ckcoverage phpunit',
        // Runs only with a second config, see selectedGates().
        'phpunit-extra' => 'phpunit',
        'phpstan' => 'phpstan',
        // Analyses the tests with phpstan-tests.neon(.dist), never phpstan.neon.dist.
        'phpstan-tests' => 'phpstan-tests',
        'ckanalyze' => 'ckanalyze',
        'ckcompat' => 'ckcompat',
        'ckdeps' => 'ckdeps',
        'cktaint' => 'cktaint',
        'cksmarty' => 'cksmarty',
        'ckeslint' => 'ckeslint',
    ];

    /** Tools a `ck <subcommand>` runs beyond its own `ck<subcommand>` binary. */
    private const CK_EXTRAS = ['coverage' => 'phpunit', 'test' => 'ckphpunit phpunit', 'phpunit' => 'ckphpunit phpunit'];

    /** Package-manager flags that take the next word as their value. */
    private const VALUE_FLAGS = ['--prefix', '-C', '--cwd', '--dir', '-w', '--workspace', '--filter', '-d', '--working-dir'];

    /** Comment-free workflow text plus everything it runs indirectly; '' without a scoped workflow. */
    public static function reachable(Context $context): string
    {
        $text = '';
        foreach ($context->ciTexts() as $body) {
            $text .= self::withoutComments($body) . "\n";
        }
        if ($text === '') {
            return '';
        }
        if ($context->runsSharedCi()) {
            $text .= implode(' ', self::CI_GATES) . "\n";
        }
        // The shared GitLab pipeline's e2e job (ci/gitlab/extension-ci.yml), run when a playwright config exists.
        if ($context->includesSharedGitlabCi()) {
            $text .= "npm run test:e2e\n";
        }

        return self::expandCk(self::withScripts($context, $text));
    }

    /** Whether $token appears as a command word, not inside a longer name, a dotfile or a directory path. */
    public static function runs(string $text, string $token): bool
    {
        return preg_match('/(?<![\w.-])' . preg_quote($token, '/') . '(?![\w\/-]|\.(?!phar\b))/', $text) === 1;
    }

    /**
     * Workflow text with YAML comments removed, so a step a comment describes
     * never counts as one that runs. Line-based: a '#' inside a quoted value
     * is dropped too, which errs toward reporting a missing runner.
     */
    public static function withoutComments(string $yaml): string
    {
        $out = [];
        foreach (explode("\n", $yaml) as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $out[] = preg_replace('/\s+#.*$/', '', $line) ?? $line;
        }

        return implode("\n", $out);
    }

    /** `ck ci` becomes the gates it runs (its --only/--skip lists dropped), `ck <x>` gains `ck<x>`. */
    private static function expandCk(string $text): string
    {
        $text = preg_replace_callback(
            '/(?<![\w.-])ck[ \t]+([a-z][\w-]*)([^\n;&|()<>]*)/',
            static fn (array $call): string => $call[1] === 'ci'
                ? implode(' ', self::selectedGates($call[2], str_contains($text, 'CK_EXTRA_PHPUNIT_CONFIG')))
                : 'ck' . $call[1] . ' ' . (self::CK_EXTRAS[$call[1]] ?? '') . $call[2],
            $text,
        ) ?? $text;

        // ckphpunit (`ck test`) and ckcoverage run phpunit, also called by path — but not `tests/ckphpunit`.
        // The path may hold a `$(…)` or backtick expression; a YAML flow list may name the binary.
        return preg_replace_callback(
            '/(?<![^\s"\'=;&|(\[{,`])((?:\$\([^()\n]*\)|`[^`\n]*`|[^\s;&|()`])*\/)?(ckphpunit|ckcoverage)(?![\w\/.-])/',
            static fn (array $call): string => $call[1] === '' || self::isBinaryDirectory($call[1])
                ? $call[1] . $call[2] . ' phpunit'
                : $call[0],
            $text,
        ) ?? $text;
    }

    /** `…/bin/`, `…/sbin/`, `…/.bin/`, `/`, `~/`, `./`, or a directory taken from a variable or expression. */
    private static function isBinaryDirectory(string $path): bool
    {
        $parts = explode('/', rtrim($path, '/'));
        $last = trim(end($parts), '"\'');

        return in_array($last, ['bin', 'sbin', '.bin', '', '~', '.', '..'], true) || strpbrk($last, '$})`') !== false;
    }

    /** @return array<string, string> the gates `ck ci <arguments>` runs after --only/--skip */
    private static function selectedGates(string $arguments, bool $extraFromEnvironment): array
    {
        // As CiCommand: the union of every --only list (all gates without one), minus every --skip.
        $lists = ['only' => [], 'skip' => []];
        $unknownOnly = false;
        preg_match_all('/--(only|skip)(?:=|[ \t]+)(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))/', $arguments, $options, PREG_SET_ORDER);
        foreach ($options as $match) {
            $names = array_filter(array_map('trim', explode(',', $match[2] . ($match[3] ?? '') . ($match[4] ?? ''))));
            // CiCommand rejects an unknown gate, so one here is shell expansion (`$X`, backticks, `{a,b}`):
            // an --only list may then add any gate, a --skip list skips none we can name.
            if (array_diff($names, array_keys(self::CI_GATES)) !== []) {
                $unknownOnly = $unknownOnly || $match[1] === 'only';
                continue;
            }
            $lists[$match[1]] += array_flip($names);
        }
        $selected = $lists['only'] === [] || $unknownOnly ? self::CI_GATES : array_intersect_key(self::CI_GATES, $lists['only']);
        $gates = array_diff_key($selected, $lists['skip']);
        // As CiCommand: the gate runs `phpunit -c FILE` only when the flag or CK_EXTRA_PHPUNIT_CONFIG names FILE.
        $hasExtra = preg_match('/--extra-phpunit-config(?:=|[ \t]+)(["\']?)([^\s"\']+)\1/', $arguments, $extra) === 1;
        if (isset($gates['phpunit-extra']) && $hasExtra) {
            $gates['phpunit-extra'] = 'phpunit -c ' . $extra[2];
        } elseif (!$extraFromEnvironment) {
            unset($gates['phpunit-extra']);
        }

        return $gates;
    }

    private static function withScripts(Context $context, string $text): string
    {
        $tables = [
            'npm' => self::scripts($context, 'package.json'),
            'composer' => self::scripts($context, 'composer.json'),
        ];
        $shellScripts = self::shellScripts($context);
        $seen = [];
        // A script's own calls name their siblings relative to its directory, the workflow's from the root.
        for ($pending = ['' => $text]; $pending !== [];) {
            $found = [];
            foreach ($pending as $directory => $body) {
                foreach (self::invocations($body) as [$manager, $name]) {
                    if (isset($seen[$manager][$name]) || !isset($tables[$manager][$name])) {
                        continue;
                    }
                    $seen[$manager][$name] = true;
                    $found[''] = ($found[''] ?? '') . implode("\n", $tables[$manager][$name]) . "\n";
                }
                foreach ($shellScripts as $script) {
                    if (isset($seen['sh'][$script]) || !self::namesScript($body, $script, $directory)) {
                        continue;
                    }
                    $seen['sh'][$script] = true;
                    $scriptDirectory = dirname($script) === '.' ? '' : dirname($script);
                    $found[$scriptDirectory] = ($found[$scriptDirectory] ?? '')
                        . self::withoutComments($context->read($script) ?? '') . "\n";
                }
            }
            $text .= implode("\n", $found);
            $pending = $found;
        }

        return $text;
    }

    /** @return list<string> tracked shell scripts: a `.sh` file or one with a shell shebang, outside vendor/ */
    private static function shellScripts(Context $context): array
    {
        $scripts = [];
        foreach ($context->trackedFiles() as $file) {
            if (str_starts_with($file, 'vendor/') || str_contains($file, '/vendor/') || str_contains($file, 'node_modules/')) {
                continue;
            }
            $isScript = str_ends_with($file, '.sh')
                || preg_match('/^#![^\n]*\b(?:ba|z|da|k)?sh\b/', (string) substr($context->read($file) ?? '', 0, 64)) === 1;
            if ($isScript) {
                $scripts[] = $file;
            }
        }

        return $scripts;
    }

    /** Whether $text names $script by its repo path, or by its path relative to $directory. */
    private static function namesScript(string $text, string $script, string $directory): bool
    {
        $paths = [$script];
        if ($directory !== '' && str_starts_with($script, $directory . '/')) {
            $paths[] = substr($script, strlen($directory) + 1);
        }
        foreach ($paths as $path) {
            if (preg_match('/(?<![\w.-])(?:\.\/)?' . preg_quote($path, '/') . '(?![\w\/.-])/', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{0: 'npm'|'composer', 1: string}> script invocations: `npm test`, `yarn run x`, `composer x`, `@x` */
    private static function invocations(string $text): array
    {
        $found = [];
        preg_match_all('/(?<![\w.\/-])(npm|yarn|pnpm|bun|composer)(?:\.phar)?((?:[ \t]+[^\s;&|]+)*)/', $text, $calls, PREG_SET_ORDER);
        foreach ($calls as [, $manager, $arguments]) {
            $words = preg_split('/[ \t]+/', trim($arguments)) ?: [];
            for ($i = 0, $count = count($words); $i < $count; $i++) {
                $word = trim($words[$i], '"\'');
                if (in_array($word, self::VALUE_FLAGS, true)) {
                    $i++;
                } elseif ($word !== '' && !str_starts_with($word, '-') && $word !== 'run' && $word !== 'run-script') {
                    $found[] = [$manager === 'composer' ? 'composer' : 'npm', $word];
                    break;
                }
            }
        }
        preg_match_all('/(?<![\w@])@([A-Za-z0-9:_-]+)/', $text, $references);
        foreach ($references[1] as $name) {
            $found[] = ['composer', $name];
        }

        return $found;
    }

    /** @return array<string, list<string>> script name => command lines, over every tracked manifest of that name */
    private static function scripts(Context $context, string $manifest): array
    {
        $table = [];
        foreach ($context->tracked($manifest, Context::outsideNodeModules(...)) as $file) {
            if (str_starts_with($file, 'vendor/') || str_contains($file, '/vendor/')) {
                continue;
            }
            $scripts = $context->json($file)['scripts'] ?? null;
            foreach (is_array($scripts) ? $scripts : [] as $name => $body) {
                foreach (is_array($body) ? $body : [$body] as $line) {
                    if (is_string($line)) {
                        $table[(string) $name][] = $line;
                    }
                }
            }
        }

        return $table;
    }
}
