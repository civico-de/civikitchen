<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A compose file in a subdirectory without an explicit project name: all
 * `.docker/` stacks resolve to project "docker" and share containers and volumes.
 */
final class ComposeProjectNameCheck implements Check
{
    public function name(): string
    {
        return 'compose-project-name';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        $named = [];
        foreach ($context->composeFiles() as $file) {
            $named[$file] = preg_match('/^name:[ \t]*\S/m', $context->read($file) ?? '') === 1;
        }

        $unnamed = [];
        foreach ($context->composeFiles() as $file) {
            // A root-level file derives the repo's own, unique directory name.
            if (!str_contains($file, '/')) {
                continue;
            }
            // [ \t] rather than \s: \s matches the newline, so a bare "name:"
            // would be satisfied by the first word of the NEXT line.
            if (!$named[$file] && !$this->mergedIntoNamedBase($file, $named)) {
                $unnamed[] = $file;
            }
        }

        if ($unnamed === []) {
            return;
        }

        sort($unnamed);
        $reporter->fail(
            'compose file without an explicit project name: ' . implode(', ', $unnamed)
            . ' — compose falls back to the directory name, so every stack kept in .docker/'
            . ' shares one project'
        );
    }

    /**
     * Compose merges a `compose.override.y(a)ml` into the base file beside it.
     *
     * @param array<string, bool> $named
     */
    private function mergedIntoNamedBase(string $file, array $named): bool
    {
        if (preg_match('/^(?:docker-)?compose\.override\.ya?ml$/', basename($file)) !== 1) {
            return false;
        }
        foreach (['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'] as $base) {
            if ($named[dirname($file) . '/' . $base] ?? false) {
                return true;
            }
        }

        return false;
    }
}
