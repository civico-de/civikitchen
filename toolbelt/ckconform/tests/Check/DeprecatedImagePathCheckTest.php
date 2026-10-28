<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\DeprecatedImagePathCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class DeprecatedImagePathCheckTest extends CheckTestCase
{
    public function testSilentOnTheTemplatesPhpstanConfig(): void
    {
        $neon = (string) file_get_contents(dirname(__DIR__, 4) . '/scaffold/template/extension/phpstan.neon.dist');
        self::assertStringContainsString('/opt/civikitchen/toolbelt/phpstan-config/', $neon);
        $this->assertSilent($this->run_(new DeprecatedImagePathCheck(), $this->repo(['phpstan.neon.dist' => $neon], git: true)));
    }

    public function testSilentOnPathsThatDidNotMove(): void
    {
        $context = $this->repo([
            'Makefile' => "mutate:\n\tphp /opt/civikitchen-infection/vendor/bin/infection\n",
            '.github/workflows/ci.yml' => "run: ls /opt/civikitchen/toolbelt/psalm/stubs\n",
        ], git: true);
        $this->assertSilent($this->run_(new DeprecatedImagePathCheck(), $context));
    }

    public function testSilentOnProse(): void
    {
        $context = $this->repo([
            'CHANGELOG.md' => "- Include /opt/civikitchen/toolbelt/phpstan-config instead of /opt/civikitchen-phpstan-config.\n",
        ], git: true);
        $this->assertSilent($this->run_(new DeprecatedImagePathCheck(), $context));
    }

    public function testWarnsOnTheV1PhpstanIncludeWithItsReplacement(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => "includes:\n\t- /opt/civikitchen-phpstan-config/civicrm-disallowed.neon\n",
        ], git: true);
        $reporter = $this->run_(new DeprecatedImagePathCheck(), $context);
        self::assertSame([
            'phpstan.neon.dist:2: /opt/civikitchen-phpstan-config is deprecated and goes away in civikitchen v2 — use /opt/civikitchen/toolbelt/phpstan-config',
        ], $reporter->messages('warn'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function movedPaths(): iterable
    {
        yield 'engine root' => ['/opt/civikitchen-phpstan/vendor/phpstan/phpstan-strict-rules/rules.neon', '/opt/civikitchen'];
        yield 'phpstan root' => ['/opt/civikitchen/toolbelt/phpstan-root/vendor/phpstan/phpstan-strict-rules/rules.neon', '/opt/civikitchen'];
        yield 'phpstan extension' => ['php /opt/civikitchen-phpstan-ext/tools/gen-route-catalog.php .', '/opt/civikitchen/toolbelt/phpstan'];
        yield 'psalm stubs' => ['<file name="/opt/civikitchen-psalm/stubs/Guzzle.php" />', '/opt/civikitchen/toolbelt/psalm'];
        yield 'deps config' => ["'/opt/civikitchen-composer-deps.php'", '/opt/civikitchen/toolbelt/lib/composer-deps.php'];
    }

    /** @dataProvider movedPaths */
    public function testNamesTheReplacementForEachMovedPath(string $line, string $replacement): void
    {
        $reporter = $this->run_(new DeprecatedImagePathCheck(), $this->repo(['tools/run.sh' => $line . "\n"], git: true));
        $this->assertWarns($reporter, 'use ' . $replacement);
    }

    public function testWarnsOnAProfileMountedBelowTheV1ProfileDirectory(): void
    {
        $context = $this->repo([
            'compose.yml' => "services:\n  civicrm:\n    volumes:\n      - .:/usr/local/share/civikitchen/profiles/kurs:ro\n",
        ], git: true);
        self::assertSame([
            'compose.yml:4: /usr/local/share/civikitchen/profiles is deprecated and goes away in civikitchen v2 — use /opt/civikitchen/docker/profiles',
        ], $this->run_(new DeprecatedImagePathCheck(), $context)->messages('warn'));
    }

    public function testSilentOnTheRuntimeFilesThatStayInPlace(): void
    {
        $context = $this->repo([
            'compose.yml' => "command: sh /usr/local/share/civikitchen/provision.sh\n",
        ], git: true);
        $this->assertSilent($this->run_(new DeprecatedImagePathCheck(), $context));
    }

    public function testReportsTheFirstHitPerFile(): void
    {
        $context = $this->repo([
            'rector.php' => "<?php\n// /opt/civikitchen-rector/rector.php\n// /opt/civikitchen-rector/rules\n",
        ], git: true);
        $this->assertSame(1, $this->run_(new DeprecatedImagePathCheck(), $context)->warnings());
    }

    public function testReadsTheRepositoryWorkflowsOfAMonorepoExtension(): void
    {
        $context = $this->monorepoExtension(
            ['.github/workflows/ci.yml' => "run: php /opt/civikitchen-phpstan-ext/tools/gen-route-catalog.php .\n"],
            [],
        );
        $this->assertWarns($this->run_(new DeprecatedImagePathCheck(), $context), '../.github/workflows/ci.yml:1:');
    }

    public function testAYamlCommentIsProse(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "jobs:\n  psalm:\n    # formerly /opt/civikitchen-psalm/bin/psalm\n    run: psalm\n",
        ], git: true);
        $this->assertSilent($this->run_(new DeprecatedImagePathCheck(), $context));
    }

    public function testAPathAfterAHashInsideAYamlValueStillCounts(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "jobs:\n  psalm:\n    run: /opt/civikitchen-psalm/bin/psalm --tag=#1 # run psalm\n",
        ], git: true);
        $this->assertWarns($this->run_(new DeprecatedImagePathCheck(), $context), 'ci.yml:3');
    }
}
