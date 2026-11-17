<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\WorkflowPermissionsCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class WorkflowPermissionsCheckTest extends CheckTestCase
{
    public function testSilentWithoutAnyWorkflow(): void
    {
        $this->assertSilent($this->run_(new WorkflowPermissionsCheck(), $this->repo([])));
    }

    public function testWarnsWhenNoTopLevelPermissionsBlock(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "name: CI\non: push\njobs:\n  build:\n    runs-on: ubuntu-latest\n",
        ]);
        $reporter = $this->run_(new WorkflowPermissionsCheck(), $context);
        $this->assertWarns(
            $reporter,
            ".github/workflows/ci.yml declares no 'permissions:' block — the job token inherits the repo default"
        );
    }

    public function testSilentWhenTopLevelPermissionsIsDeclared(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "name: CI\npermissions:\n  contents: read\njobs:\n  build:\n    runs-on: ubuntu-latest\n",
        ]);
        $this->assertSilent($this->run_(new WorkflowPermissionsCheck(), $context));
    }

    /**
     * A job-level `permissions:` is indented and does not satisfy the column-0 match.
     */
    public function testJobLevelIndentedPermissionsDoesNotCount(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "name: CI\njobs:\n  build:\n    permissions:\n      contents: read\n    runs-on: ubuntu-latest\n",
        ]);
        $reporter = $this->run_(new WorkflowPermissionsCheck(), $context);
        $this->assertWarns($reporter, "declares no 'permissions:' block");
    }
}
