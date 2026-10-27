<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

use CiviKitchen\Toolbelt\Filesystem\Directory;

/**
 * A throwaway fake CiviCRM core on disk for checks that read core's Civi/Api4
 * tree. The using test writes its own entity files under makeCore()'s
 * Civi/Api4; creation and removal live here, so the removal only ever reaches
 * the directory this trait itself created.
 */
trait FakeCoreTrait
{
    private ?string $core = null;

    protected function tearDown(): void
    {
        if ($this->core !== null) {
            Directory::remove($this->core);
        }
        $this->core = null;
        parent::tearDown();
    }

    protected function coreDir(): ?string
    {
        return $this->core;
    }

    /** Creates the core skeleton (with an empty Civi/Api4) and returns its root. */
    private function makeCore(): string
    {
        $this->core = Directory::temporary('ckconform-core');
        mkdir($this->core . '/Civi/Api4', 0777, true);

        return $this->core;
    }
}
