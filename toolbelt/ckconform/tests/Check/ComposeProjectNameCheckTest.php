<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\ComposeProjectNameCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class ComposeProjectNameCheckTest extends CheckTestCase
{
    public function testSilentWithoutAComposeFile(): void
    {
        $this->assertSilent($this->run_(new ComposeProjectNameCheck(), $this->repo([], git: true)));
    }

    /** The estate-wide case: every stack in .docker/ resolving to "docker". */
    public function testAComposeFileWithoutANameFails(): void
    {
        $context = $this->repo([
            '.docker/docker-compose.ci.yml' => "services:\n  app:\n    image: x:1\n",
        ], git: true);
        $this->assertFails($this->run_(new ComposeProjectNameCheck(), $context), 'without an explicit project name');
    }

    public function testAnExplicitNamePasses(): void
    {
        $context = $this->repo([
            '.docker/docker-compose.ci.yml' => "name: thing-ci\n\nservices:\n  app:\n    image: x:1\n",
        ], git: true);
        $this->assertPasses($this->run_(new ComposeProjectNameCheck(), $context));
    }

    /** An empty or commented-out key is not a name. */
    public function testAnEmptyOrCommentedNameDoesNotCount(): void
    {
        foreach (["name:\n", "# name: thing\n"] as $header) {
            $context = $this->repo([
                '.docker/compose.yaml' => $header . "services:\n  app:\n    image: x:1\n",
            ], git: true);
            $this->assertFails($this->run_(new ComposeProjectNameCheck(), $context));
        }
    }

    public function testEveryUnnamedFileIsNamedInTheMessage(): void
    {
        $context = $this->repo([
            '.docker/docker-compose.ci.yml' => "services:\n  a:\n    image: x:1\n",
            '.docker/docker-compose.yml' => "services:\n  b:\n    image: x:1\n",
        ], git: true);
        $reporter = $this->run_(new ComposeProjectNameCheck(), $context);
        $message = implode("\n", $reporter->messages('FAIL'));
        self::assertStringContainsString('docker-compose.ci.yml', $message);
        self::assertStringContainsString('docker-compose.yml', $message);
    }

    /**
     * A root-level compose file derives the repo's own, unique directory name.
     */
    public function testARootLevelComposeFileNeedsNoExplicitName(): void
    {
        $context = $this->repo([
            'docker-compose.yml' => "services:\n  app:\n    image: x:1\n",
        ], git: true);
        $this->assertPasses($this->run_(new ComposeProjectNameCheck(), $context));
    }

    /** Compose merges compose.override.yml into the base file beside it, name included. */
    public function testAnOverrideBesideANamedBaseFileIsNamed(): void
    {
        $context = $this->repo([
            '.docker/compose.yml' => "name: myext\nservices:\n  app:\n    image: mariadb:11.4\n",
            '.docker/compose.override.yml' => "services:\n  app:\n    ports: ['8080:80']\n",
        ], git: true);
        $this->assertSilent($this->run_(new ComposeProjectNameCheck(), $context));
    }

    public function testAnOverrideBesideAnUnnamedBaseFileIsNotNamed(): void
    {
        $context = $this->repo([
            '.docker/compose.yml' => "services:\n  app:\n    image: mariadb:11.4\n",
            '.docker/compose.override.yml' => "services:\n  app:\n    ports: ['8080:80']\n",
        ], git: true);
        $this->assertFails($this->run_(new ComposeProjectNameCheck(), $context), '.docker/compose.override.yml, .docker/compose.yml');
    }
}
