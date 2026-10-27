<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Filesystem\Directory;

$root = dirname(__DIR__, 2);
require $root . '/toolbelt/lib/php/bootstrap.php';
$work = Directory::temporary('civikitchen-coverage-gate');
mkdir($work . '/src', 0700);
file_put_contents($work . '/src/One.php', '<?php echo 1;');
file_put_contents($work . '/src/Two.php', '<?php echo 2;');

register_shutdown_function(Directory::remove(...), $work);

/** @param list<array{name:string,total:int,covered:int}> $files */
$clover = static function (array $files, int $total, int $covered) use ($work): string {
    $body = '';
    foreach ($files as $file) {
        $name = htmlspecialchars($work . '/src/' . $file['name'], ENT_XML1);
        $body .= "<file name=\"{$name}\"><metrics statements=\"{$file['total']}\" coveredstatements=\"{$file['covered']}\"/></file>";
    }
    return "<?xml version=\"1.0\"?><coverage><project>{$body}<metrics statements=\"{$total}\" coveredstatements=\"{$covered}\"/></project></coverage>";
};

$run = static function (string $xml, string $minimum) use ($root, $work): int {
    $report = $work . '/report.xml';
    file_put_contents($report, $xml);
    $process = proc_open([
        PHP_BINARY,
        $root . '/tests/shared-php/assert-coverage.php',
        $report,
        $minimum,
        $work . '/src',
    ], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    return is_resource($process) ? proc_close($process) : 99;
};

$both = [
    ['name' => 'One.php', 'total' => 1, 'covered' => 1],
    ['name' => 'Two.php', 'total' => 1, 'covered' => 1],
];
if ($run($clover($both, 2, 2), '100') !== 0) {
    throw new RuntimeException('exact coverage boundary should pass');
}
$zero = $both;
$zero[1]['covered'] = 0;
if ($run($clover($zero, 2, 1), '0') !== 1) {
    throw new RuntimeException('zero-covered executable file should fail');
}
if ($run($clover([$both[0]], 1, 1), '0') !== 2) {
    throw new RuntimeException('source file missing from report should be an invalid report');
}
if ($run($clover($both, 2, 1), '51') !== 1) {
    throw new RuntimeException('coverage below floor should fail');
}
if ($run('<not-clover/>', '0') !== 2) {
    throw new RuntimeException('malformed Clover report should be invalid');
}
echo "shared PHP coverage gate tests passed\n";
