<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\ImageBootstraps;
use CiviKitchen\Ckconform\Reporter;

/**
 * A copy of a bootstrap the template once managed and the image now ships, or a
 * phpunit/phpstan config still naming one. A bootstrap the repo wrote is its own.
 */
final class RetiredTemplateCopyCheck implements Check
{
    public function name(): string
    {
        return 'retired-template-copy';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $rootFiles = $context->isGitRepo()
            ? array_values(array_filter($context->trackedFiles(), static fn (string $file): bool => !str_contains($file, '/')))
            : array_values(array_filter(scandir($context->path('.')) ?: [], static fn (string $file): bool => is_file($context->path($file))));
        [$copies, $references] = ImageBootstraps::scan($rootFiles, $context->readShipped(...));

        foreach ($copies as $path) {
            $reporter->fail(sprintf(
                '%s is a copy of the civikitchen bootstrap the image ships as %s — delete it'
                . ' (ckinit --update does, once nothing names it)',
                $path,
                ImageBootstraps::RETIRED[$path][0],
            ));
        }
        foreach ($references as [$file, $line, $path]) {
            // A copy naming another copy goes with it.
            if (!in_array($file, $copies, true)) {
                $reporter->failAt($file, $line, sprintf('%s:%d: %s', $file, $line, $this->advice($path)));
            }
        }
    }

    private function advice(string $path): string
    {
        $image = ImageBootstraps::RETIRED[$path][0];

        return match ($path) {
            ImageBootstraps::PHPSTAN_COPY => "names {$path}, the retired template copy — list {$image} under bootstrapFiles"
                . " instead, and drop {$path} from excludePaths where listed",
            'tests/phpunit/bootstrap.php' => "bootstrap=\"{$path}\" names the retired template copy — set bootstrap=\"{$image}\";"
                . ' repository setup goes into tests/phpunit/bootstrap.local.php',
            default => "requires the retired template copy {$path} — require {$image} instead",
        };
    }
}
