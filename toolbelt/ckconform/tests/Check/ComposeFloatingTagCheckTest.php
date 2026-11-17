<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\ComposeFloatingTagCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

/**
 * On 2026-07-06 maildev/maildev:latest became 3.0.0-rc.1, whose healthcheck
 * queries a route its own app answers with 404.
 */
final class ComposeFloatingTagCheckTest extends CheckTestCase
{
    public function testSilentWithoutAnyComposeFile(): void
    {
        $this->assertSilent($this->run_(new ComposeFloatingTagCheck(), $this->repo([], git: true)));
    }

    public function testFailsOnAnExplicitLatestTag(): void
    {
        $context = $this->repo([
            '.docker/docker-compose.yml' => "services:\n  mail:\n    image: maildev/maildev:latest\n",
        ], git: true);
        $this->assertFails($this->run_(new ComposeFloatingTagCheck(), $context), 'maildev/maildev:latest');
    }

    /** No tag is ':latest' spelled shorter. */
    public function testFailsOnAMissingTag(): void
    {
        $context = $this->repo([
            'compose.yaml' => "services:\n  db:\n    image: mariadb\n",
        ], git: true);
        $this->assertFails($this->run_(new ComposeFloatingTagCheck(), $context), 'mariadb');
    }

    public function testPassesOnAPinnedVersion(): void
    {
        $context = $this->repo([
            '.docker/docker-compose.yml' => "services:\n  mail:\n    image: maildev/maildev:2.2.1\n",
        ], git: true);
        $this->assertPasses($this->run_(new ComposeFloatingTagCheck(), $context));
    }

    public function testPassesOnADigest(): void
    {
        $context = $this->repo([
            'compose.yaml' => "services:\n  db:\n    image: mariadb@sha256:abc123\n",
        ], git: true);
        $this->assertPasses($this->run_(new ComposeFloatingTagCheck(), $context));
    }

    /**
     * A registry host carries a port, so the last colon is not always a tag
     * separator — 'registry:5000/thing' has no tag and must still be caught.
     */
    public function testARegistryPortIsNotMistakenForATag(): void
    {
        $context = $this->repo([
            'compose.yaml' => "services:\n  x:\n    image: registry:5000/thing\n",
        ], git: true);
        $this->assertFails($this->run_(new ComposeFloatingTagCheck(), $context), 'registry:5000/thing');
    }

    /**
     * The project's own image is referenced through an interpolated default and
     * is meant to track its tag — it is built from this very repo.
     */
    public function testAnInterpolatedImageIsNotFlagged(): void
    {
        $context = $this->repo([
            '.docker/docker-compose.yml'
                => "services:\n  app:\n    image: \${CIVIKITCHEN_IMAGE:-ghcr.io/civico-de/civikitchen:standalone}\n",
        ], git: true);
        $this->assertPasses($this->run_(new ComposeFloatingTagCheck(), $context));
    }

    public function testACommentedImageDoesNotCount(): void
    {
        $context = $this->repo([
            'compose.yaml' => "services:\n  x:\n    # image: mariadb:latest\n    image: mariadb:11.4\n",
        ], git: true);
        $this->assertPasses($this->run_(new ComposeFloatingTagCheck(), $context));
    }

    public function testEveryOffenderIsNamedUpToACap(): void
    {
        $context = $this->repo([
            'compose.yaml' => "services:\n  a:\n    image: a:latest\n  b:\n    image: b:latest\n"
                . "  c:\n    image: c:latest\n  d:\n    image: d:latest\n",
        ], git: true);
        $reporter = $this->run_(new ComposeFloatingTagCheck(), $context);
        $this->assertFails($reporter, '+1 more');
    }

    public function testQuotedLatestTagsFloat(): void
    {
        $context = $this->repo([
            '.docker/compose.yml' => "name: myext\nservices:\n  mail:\n    image: \"maildev/maildev:latest\"\n  app:\n    image: 'ghcr.io/example/app:latest'\n",
        ], git: true);
        $reporter = $this->run_(new ComposeFloatingTagCheck(), $context);
        $this->assertFails($reporter, '.docker/compose.yml:4 maildev/maildev:latest, .docker/compose.yml:6 ghcr.io/example/app:latest');
    }

    public function testAQuotedPinnedTagIsPinned(): void
    {
        $context = $this->repo([
            '.docker/compose.yml' => "name: myext\nservices:\n  db:\n    image: \"mariadb:11.4\"\n",
        ], git: true);
        $this->assertOk($this->run_(new ComposeFloatingTagCheck(), $context), 'every compose image is pinned');
    }

    /** `image:` beside `build:` names the image compose builds, not one it pulls. */
    public function testALocalBuildTagIsNoPull(): void
    {
        $context = $this->repo([
            '.docker/compose.yml' => "name: myext\nservices:\n  app:\n    build: .\n    image: myext-app\n  db:\n    image: mariadb:11.4\n",
        ], git: true);
        $this->assertOk($this->run_(new ComposeFloatingTagCheck(), $context), 'every compose image is pinned');
    }

    public function testABuildInOneServiceDoesNotExemptAnotherServicesPull(): void
    {
        $context = $this->repo([
            '.docker/compose.yml' => "name: myext\nservices:\n  app:\n    build: .\n    image: myext-app\n  db:\n    image: mariadb\n",
        ], git: true);
        $this->assertFails($this->run_(new ComposeFloatingTagCheck(), $context), '.docker/compose.yml:7 mariadb');
    }
}
