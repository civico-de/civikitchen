<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;
use CiviKitchen\Ckconform\Suppressions;
use CiviKitchen\Toolbelt\Filesystem\Directory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds a throwaway extension directory per test and runs a single check
 * against it.
 */
abstract class CheckTestCase extends TestCase
{
    private ?string $dir = null;

    /**
     * Suppressions share instances per contents across a run; reset them per
     * test so a consumed ignore does not carry over.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Suppressions::reset();
    }

    protected function tearDown(): void
    {
        Suppressions::reset();
        if ($this->dir !== null) {
            Directory::remove($this->dir);
        }
        $this->dir = null;
        parent::tearDown();
    }

    /**
     * @param array<string, string> $files Repo-relative path => contents.
     *                                     An 'info.xml' is supplied unless given.
     */
    protected function repo(array $files, bool $git = false): Context
    {
        $this->dir = Directory::temporary('ckconform');

        $files['info.xml'] ??= $this->infoXml();
        foreach ($files as $path => $contents) {
            if ($path === '__policy_fixture') {
                $path = 'civikitchen.yaml';
                $contents = $this->policyFixture($contents);
            }
            $full = $this->dir . '/' . $path;
            $parent = dirname($full);
            if (!is_dir($parent)) {
                mkdir($parent, 0777, true);
            }
            file_put_contents($full, $contents);
        }

        if ($git) {
            $this->gitInit();
        }

        return new Context($this->dir, $this->coreDir());
    }

    /**
     * A monorepo holding the extension under inspection and optionally a
     * neighbour; $rootFiles, write(), gitCommit() and gitTag() use its root.
     *
     * @param array<string, string> $rootFiles
     * @param array<string, string> $extensionFiles
     * @param array<string, string> $neighbourFiles
     */
    protected function monorepoExtension(
        array $rootFiles,
        array $extensionFiles,
        array $neighbourFiles = [],
        string $directory = 'example',
        string $neighbourDirectory = 'base',
    ): Context {
        $this->dir = Directory::temporary('ckconform');
        mkdir($this->dir . '/' . $directory, 0777, true);
        $extensionFiles['info.xml'] ??= $this->infoXml();
        $trees = ['' => $rootFiles, $directory . '/' => $extensionFiles];
        if ($neighbourFiles !== []) {
            $neighbourFiles['info.xml'] ??= $this->infoXml(key: 'base');
            $trees[$neighbourDirectory . '/'] = $neighbourFiles;
        }
        foreach ($trees as $prefix => $files) {
            foreach ($files as $path => $contents) {
                $full = $this->dir . '/' . $prefix . $path;
                if (!is_dir(dirname($full))) {
                    mkdir(dirname($full), 0777, true);
                }
                file_put_contents($full, $contents);
            }
        }
        $this->gitInit();

        return new Context($this->dir . '/' . $directory, $this->coreDir());
    }

    /**
     * An extension one level below the layout the monorepo rules cover
     * (`extensions/example`), for the tests that pin what happens outside it.
     *
     * @param array<string, string> $rootFiles
     */
    protected function nestedExtension(array $rootFiles): Context
    {
        $this->dir = Directory::temporary('ckconform');
        mkdir($this->dir . '/extensions/example', 0777, true);
        file_put_contents($this->dir . '/extensions/example/info.xml', $this->infoXml());
        foreach ($rootFiles as $path => $contents) {
            $full = $this->dir . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $contents);
        }
        $this->gitInit();

        return new Context($this->dir . '/extensions/example', $this->coreDir());
    }

    /** Compact fixture adapter; production accepts YAML only. */
    protected function policyFixture(string $lines): string
    {
        $policy = [];
        foreach (explode("\n", $lines) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            [$plain, $reason] = array_pad(explode(' -- ', $value, 2), 2, '');
            $list = array_values(array_filter(array_map('trim', explode(',', $plain)), static fn ($v) => $v !== ''));
            switch ($key) {
                case 'min_coverage': $policy['coverage']['minimum'] = ctype_digit($plain) ? (int) $plain : $plain; break;
                case 'tests': $policy['tests'] = ['mode' => $plain, 'reason' => $reason]; break;
                case 'ignore_checks': $policy['ignore_checks'] = ['checks' => $list, 'reason' => $reason]; break;
                case 'vendor': $policy['vendor'] = ['mode' => $plain, 'reason' => $reason]; break;
                case 'known_hooks': $policy['known_hooks'] = $list; break;
                case 'known_api4_entities': $policy['known_api4_entities'] = ['entities' => $list, 'reason' => $reason]; break;
                case 'optional_extensions': $policy['optional_extensions'] = ['extensions' => $list, 'reason' => $reason]; break;
                case 'bundles': $policy['bundles'] = ['mode' => $plain, 'reason' => $reason]; break;
                case 'deploy_hygiene':
                case 'deploy_hygiene': $policy['deploy_hygiene'] = ['paths' => $list, 'reason' => $reason]; break;
                case 'lfs_exempt': $policy['lfs_exempt'] = ['paths' => $list, 'reason' => $reason]; break;
                case 'vendored_paths': $policy['vendored_paths'][] = ['path' => $plain, 'reason' => $reason]; break;
                case 'smarty_skip_templates': $policy['smarty_skip_templates'][] = ['template' => $plain, 'reason' => $reason]; break;
                case 'release': $policy['release'] = ['mode' => $plain, 'reason' => $reason]; break;
                case 'untagged_versions': $policy['untagged_versions'][] = ['version' => $plain, 'reason' => $reason]; break;
                case 'civicrm_floor_reasons': $policy['civicrm_floor_reasons'][] = ['version' => $plain, 'reason' => $reason]; break;
                case 'max_unreleased_days': $policy[$key] = ctype_digit($plain) ? (int) $plain : $plain; break;
                case 'mutation_min_msi': $policy['mutation']['minimum_msi'] = ctype_digit($plain) ? (int) $plain : $plain; break;
                case 'mutation_min_covered_msi': $policy['mutation']['minimum_covered_msi'] = ctype_digit($plain) ? (int) $plain : $plain; break;
                case 'mutation_paths': $policy['mutation']['paths'] = $list; break;
                case 'dist_exclude': $policy['dist']['exclude'] = $list; break;
                case 'dist_include': $policy['dist']['include'] = array_map(static fn ($path) => ['path' => $path, 'reason' => $reason], $list); break;
                case 'lifecycle_log_ignore': $policy['lifecycle']['log_ignore'][] = ['pattern' => $plain, 'reason' => $reason]; break;
                case 'template_custom': $policy['template_custom'] = ['paths' => $list, 'reason' => $reason]; break;
                case 'extension_source':
                    [$sourceKey, $url] = array_pad(explode('@', $plain, 2), 2, '');
                    $policy['extension_sources'][] = ['key' => $sourceKey, 'version' => '^1.0', 'url' => $url, 'sha256' => str_repeat('a', 64), 'reason' => $reason];
                    break;
                default: $policy[$key] = $value; break;
            }
        }
        return Yaml::dump(['version' => 1, 'policy' => $policy], 8, 2) . "\n";
    }

    /** A minimal but valid info.xml. */
    protected function infoXml(
        string $key = 'fixture',
        string $license = 'Proprietary',
        string $compatibility = '6.10',
        string $extra = '',
    ): string {
        return <<<XML
            <?xml version="1.0"?>
            <extension key="{$key}" type="module">
              <file>{$key}</file>
              <name>Fixture</name>
              <license>{$license}</license>
              <compatibility>
                <ver>{$compatibility}</ver>
              </compatibility>
            {$extra}
            </extension>
            XML;
    }

    protected function run_(Check $check, Context $context): Reporter
    {
        $reporter = new Reporter();
        $reporter->suppressWith($context);
        $reporter->setRule($check->name());
        $check->run($context, $reporter);

        return $reporter;
    }

    protected function assertFails(Reporter $reporter, string $needle = ''): void
    {
        $failures = $reporter->messages('FAIL');
        self::assertNotSame([], $failures, 'expected a FAIL, got: ' . $reporter->render());
        if ($needle !== '') {
            self::assertStringContainsString($needle, implode("\n", $failures));
        }
    }

    protected function assertPasses(Reporter $reporter): void
    {
        self::assertSame(0, $reporter->failures(), 'expected no FAIL, got: ' . $reporter->render());
    }

    protected function assertWarns(Reporter $reporter, string $needle = ''): void
    {
        $warnings = $reporter->messages('warn');
        self::assertNotSame([], $warnings, 'expected a warn, got: ' . $reporter->render());
        if ($needle !== '') {
            self::assertStringContainsString($needle, implode("\n", $warnings));
        }
    }

    protected function assertOk(Reporter $reporter, string $needle = ''): void
    {
        self::assertSame([], array_filter($reporter->results(), static fn (array $r): bool => $r['level'] !== 'ok'),
            'expected only ok, got: ' . $reporter->render());
        self::assertStringContainsString($needle, implode("\n", $reporter->messages('ok')));
    }

    protected function assertSilent(Reporter $reporter): void
    {
        self::assertSame([], $reporter->results(), 'expected no output, got: ' . $reporter->render());
    }

    protected function coreDir(): ?string
    {
        return null;
    }

    /**
     * Without git's background maintenance: its detached lock file aborts the
     * recursive delete in teardown.
     */
    private function gitInit(): void
    {
        $this->git('init -q');
        foreach (['maintenance.auto false', 'gc.auto 0', 'gc.autoDetach false'] as $setting) {
            $this->git('config ' . $setting);
        }
        $this->git('add -A');
    }

    /** Git insulated from global config, e.g. a personal gitignore listing `.env`. */
    protected function git(string $args, string $env = ''): void
    {
        exec(
            'GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null ' . $env . 'git -C '
            . escapeshellarg((string) $this->dir) . ' ' . $args . ' 2>/dev/null'
        );
    }

    /**
     * Stage everything and commit it, optionally dated for the age-based rules.
     *
     * @param string $date an ISO 8601 timestamp — git rejects approxidate
     *                     ('5 days ago') in the date environment variables
     */
    protected function gitCommit(string $message, string $date = ''): void
    {
        $env = $date === ''
            ? ''
            : 'GIT_AUTHOR_DATE=' . escapeshellarg($date) . ' GIT_COMMITTER_DATE=' . escapeshellarg($date) . ' ';
        $this->git('add -A');
        $this->git(
            '-c user.name=ckconform -c user.email=ckconform@example.invalid commit -q -m '
            . escapeshellarg($message),
            $env,
        );
    }

    protected function gitTag(string $tag): void
    {
        $this->git('tag ' . escapeshellarg($tag));
    }

    /**
     * Make the fixture look like a truncated clone (`.git/shallow`).
     */
    protected function gitShallow(): void
    {
        file_put_contents($this->dir . '/.git/shallow', '');
    }

    /** The fixture repository's root, for a test that needs an absolute path in a file it writes. */
    protected function fixtureRoot(): string
    {
        return (string) $this->dir;
    }

    /** Write a file into the fixture after repo() built it. */
    protected function write(string $path, string $contents): void
    {
        $full = $this->dir . '/' . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }
        file_put_contents($full, $contents);
    }
}
