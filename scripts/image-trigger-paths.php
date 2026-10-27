<?php

// Prints the push trigger paths of build-dev-images.yml, one per line.
require __DIR__ . '/../packages/civikitchen-scenario-schema/vendor/autoload.php';

$workflow = Symfony\Component\Yaml\Yaml::parseFile($argv[1] ?? __DIR__ . '/../.github/workflows/build-dev-images.yml');
$paths = $workflow['on']['push']['paths'] ?? [];
if (!is_array($paths) || $paths === []) {
    fwrite(STDERR, "no on.push.paths in the workflow\n");
    exit(1);
}
echo implode("\n", $paths), "\n";
