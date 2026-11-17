<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\MonorepoRequiresMountedCheck;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class MonorepoRequiresMountedCheckTest extends CheckTestCase
{
    public function testFailsWhenTheSameRepositoryDependencyIsNotMounted(): void
    {
        $context = $this->dependent("    volumes:\n      - ../..:/var/www/html/ext/fixture\n");
        $this->assertFails(
            $this->run_(new MonorepoRequiresMountedCheck(), $context),
            'base is this repository\'s base/ but the CI compose file .docker/docker-compose.ci.yml does not mount it into the app service at /var/www/html/ext/base',
        );
    }

    public function testFailsWhenTheMountPointsSomewhereElse(): void
    {
        $context = $this->dependent("    volumes:\n      - .:/var/www/html/ext/base\n");
        $this->assertFails(
            $this->run_(new MonorepoRequiresMountedCheck(), $context),
            'is mounted from',
        );
    }

    public function testPassesWithTheNeighbourMounted(): void
    {
        $context = $this->dependent("    volumes:\n      - ../../base:/var/www/html/ext/base\n");
        $this->assertPasses($this->run_(new MonorepoRequiresMountedCheck(), $context));
    }

    public function testPassesWithTheLongVolumeSyntax(): void
    {
        $context = $this->dependent(
            "    volumes:\n      - type: bind\n        source: ../../base\n"
            . "        target: /var/www/html/ext/base\n"
        );
        $this->assertPasses($this->run_(new MonorepoRequiresMountedCheck(), $context));
    }

    public function testSilentWhenNoDependencyLivesInThisRepository(): void
    {
        $context = $this->monorepoExtension([], [], ['Civi/Neighbour.php' => '<?php']);
        $this->assertSilent($this->run_(new MonorepoRequiresMountedCheck(), $context));
    }

    public function testSilentInASingleExtensionRepo(): void
    {
        $context = $this->repo([
            'info.xml' => $this->requiring(),
            '.docker/docker-compose.ci.yml' => "name: fixture\nservices:\n  app:\n    image: ck\n",
        ], git: true);
        $this->assertSilent($this->run_(new MonorepoRequiresMountedCheck(), $context));
    }

    public function testFailsUnevaluatedWhenTheStackDoesNotParse(): void
    {
        $context = $this->dependent("    volumes:\n      - x\n     - y\n");
        $this->assertFails(
            $this->run_(new MonorepoRequiresMountedCheck(), $context),
            'not evaluated: .docker/docker-compose.ci.yml does not parse as YAML',
        );
    }

    public function testWarnsUnevaluatedWhenTheOnlyMountSourceIsInterpolated(): void
    {
        foreach (['$BASE', '${BASE}', '${BASE:-../../base}'] as $source) {
            $reporter = $this->run_(
                new MonorepoRequiresMountedCheck(),
                $this->dependent("    volumes:\n      - {$source}:/var/www/html/ext/base\n"),
            );
            self::assertSame(0, $reporter->failures(), $source);
            $this->assertWarns($reporter, 'monorepo-requires-mounted not evaluated: the mount of /var/www/html/ext/base');
        }
    }

    public function testALiteralMountBesideAnInterpolatedOneIsEvaluated(): void
    {
        $context = $this->dependent(
            "    volumes:\n      - \${BASE}:/var/www/html/ext/base\n      - ../../base:/var/www/html/ext/base\n"
        );
        $this->assertPasses($this->run_(new MonorepoRequiresMountedCheck(), $context));
    }

    public function testPassesWhenTheMountUsesTheKey(): void
    {
        $context = $this->dotted("      - ../../base:/var/www/html/ext/de.civico.ckmonobase\n");
        $this->assertPasses($this->run_(new MonorepoRequiresMountedCheck(), $context));
    }

    public function testFailsWhenTheMountUsesTheFileNameInsteadOfTheKey(): void
    {
        $reporter = $this->run_(
            new MonorepoRequiresMountedCheck(),
            $this->dotted("      - ../../base:/var/www/html/ext/ckmonobase\n"),
        );
        $this->assertFails($reporter, 'does not mount it into the app service at /var/www/html/ext/de.civico.ckmonobase');
        $this->assertFails($reporter, 'looked up by its key');
    }

    public function testPassesWithAnAbsoluteVolumeSource(): void
    {
        $context = $this->dependent("    volumes:\n      - ../../base:/var/www/html/ext/base\n");
        $this->write(
            'example/.docker/docker-compose.ci.yml',
            "name: fixture\nservices:\n  app:\n    image: ck\n    volumes:\n      - "
            . $this->fixtureRoot() . "/base:/var/www/html/ext/base\n",
        );
        $this->assertPasses($this->run_(new MonorepoRequiresMountedCheck(), $context));
    }

    public function testFailsWhenTheMountIsOnlyInAnotherService(): void
    {
        $context = $this->monorepoExtension(
            [],
            [
                'info.xml' => $this->requiring(),
                '.docker/docker-compose.ci.yml' => "name: fixture\nservices:\n  app:\n    image: ck\n"
                    . "  worker:\n    image: ck\n    volumes:\n      - ../../base:/var/www/html/ext/base\n",
            ],
            ['Civi/Neighbour.php' => '<?php'],
        );
        $this->assertFails(
            $this->run_(new MonorepoRequiresMountedCheck(), $context),
            'does not mount it into the app service at /var/www/html/ext/base',
        );
    }

    public function testFailsWhenTheRepositoryShipsNoStack(): void
    {
        $context = $this->monorepoExtension(
            [],
            ['info.xml' => $this->requiring()],
            ['Civi/Neighbour.php' => '<?php'],
        );
        $this->assertFails(
            $this->run_(new MonorepoRequiresMountedCheck(), $context),
            'base is this repository\'s base/ but the CI compose file .docker/docker-compose.ci.yml does not mount it into the app service'
            . ' at /var/www/html/ext/base',
        );
    }

    public function testFailsWhenOnlyTheDevComposeFileMountsTheNeighbour(): void
    {
        $context = $this->dependent('');
        $this->write(
            'example/.docker/docker-compose.yml',
            "name: fixture\nservices:\n  app:\n    image: ck\n    volumes:\n      - ../../base:/var/www/html/ext/base\n",
        );
        $this->assertFails(
            $this->run_(new MonorepoRequiresMountedCheck(), $context),
            'the CI compose file .docker/docker-compose.ci.yml does not mount it',
        );
    }

    public function testJudgesTheComposeFileTheCallerPasses(): void
    {
        $stack = "name: fixture\nservices:\n  app:\n    image: ck\n    volumes:\n      - ../../base:/var/www/html/ext/base\n";
        $context = $this->monorepoExtension(
            ['.github/workflows/ci.yml' => $this->caller("      compose_file: ci/stack.yml\n")],
            ['info.xml' => $this->requiring(), 'ci/stack.yml' => $stack],
            ['Civi/Neighbour.php' => '<?php'],
        );
        $this->assertPasses($this->run_(new MonorepoRequiresMountedCheck(), $context));
    }

    public function testFailsUnevaluatedWhenTheCallerPassesAnExpression(): void
    {
        $context = $this->monorepoExtension(
            ['.github/workflows/ci.yml' => $this->caller("      compose_file: \${{ vars.STACK }}\n")],
            ['info.xml' => $this->requiring()],
            ['Civi/Neighbour.php' => '<?php'],
        );
        $this->assertFails(
            $this->run_(new MonorepoRequiresMountedCheck(), $context),
            'ci.yml:example sets compose_file',
        );
    }

    private function caller(string $with): string
    {
        return "name: CI\non: push\njobs:\n  example:\n"
            . "    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1\n"
            . "    with:\n      working_directory: example\n" . $with;
    }

    /**
     * A dotted key differs from its `<file>` (`de.civico.ckmonobase` ships as
     * `ckmonobase`); a dependency is looked up under its key.
     */
    private function dotted(string $volume): Context
    {
        return $this->monorepoExtension(
            [],
            [
                'info.xml' => $this->infoXml(
                    extra: "  <requires>\n    <ext>de.civico.ckmonobase</ext>\n  </requires>",
                ),
                '.docker/docker-compose.ci.yml' => "name: fixture\nservices:\n  app:\n    image: ck\n"
                    . "    volumes:\n" . $volume,
            ],
            [
                'info.xml' => str_replace(
                    '<file>de.civico.ckmonobase</file>',
                    '<file>ckmonobase</file>',
                    $this->infoXml(key: 'de.civico.ckmonobase'),
                ),
                'Civi/Neighbour.php' => '<?php',
            ],
        );
    }

    /** The extension requires the neighbour `base`; its stack mounts whatever is given. */
    private function dependent(string $volumes): Context
    {
        return $this->monorepoExtension(
            [],
            [
                'info.xml' => $this->requiring(),
                '.docker/docker-compose.ci.yml' => "name: fixture\nservices:\n  app:\n    image: ck\n" . $volumes,
            ],
            ['Civi/Neighbour.php' => '<?php'],
        );
    }

    private function requiring(): string
    {
        return $this->infoXml(extra: "  <requires>\n    <ext>base</ext>\n  </requires>");
    }
}
