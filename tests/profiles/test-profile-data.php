<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/toolbelt/lib/php/bootstrap.php';

use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Runtime\ProfileData;

$work = Directory::temporary('ck-profile-data');
register_shutdown_function(Directory::remove(...), $work);

$first = $work . '/first.json';
$second = $work . '/second.json';
file_put_contents($first, json_encode([
    'cms' => 'drupal10',
    'authx' => ['header_cred' => ['api_key', 'jwt']],
    'dependencies' => [
        ['name' => 'git-ext', 'repo' => 'https://example.org/ext.git', 'version' => 'abc', 'enable' => true],
        ['name' => 'skip-ext', 'registry' => true, 'skipUf' => ['Standalone'], 'skipUfReason' => 'fixture'],
    ],
    'apiUsers' => [['username' => 'api', 'role' => 'role', 'permissions' => ['one']]],
], JSON_THROW_ON_ERROR));
file_put_contents($second, json_encode([
    'dependencies' => [],
    'apiUsers' => [['username' => 'api2', 'role' => 'role', 'permissions' => ['two']]],
], JSON_THROW_ON_ERROR));

$profiles = new ProfileData();
assert($profiles->cms($first) === 'drupal10');
assert($profiles->authxPolicy($first, true) === 'api_key,jwt');
assert($profiles->hasApiUsers($first, true));
assert(count($profiles->dependencies($first, 'Standalone')) === 1);
assert($profiles->skipped($first, 'Standalone') === ['  SKIP skip-ext on Standalone: fixture']);

$merged = $work . '/merged.json';
$profiles->merge($merged, 'api_key,jwt', [$first, $second]);
$data = json_decode((string) file_get_contents($merged), true, 512, JSON_THROW_ON_ERROR);
assert($data['authx']['header_cred'] === ['api_key', 'jwt']);
assert($data['apiUsers'][0]['permissions'] === ['one', 'two']);
assert($data['apiUsers'][1]['permissions'] === ['one', 'two']);

// A set without API users resolves to an empty policy; the merged profile then
// carries no AuthX policy, so configure-api-users.php leaves AuthX alone.
$empty = $work . '/empty.json';
file_put_contents($empty, json_encode(['dependencies' => []], JSON_THROW_ON_ERROR));
$profiles->merge($merged, '', [$empty]);
$data = json_decode((string) file_get_contents($merged), true, 512, JSON_THROW_ON_ERROR);
assert(!array_key_exists('authx', $data));
assert($data['apiUsers'] === []);

$conflict = $work . '/conflict.json';
file_put_contents($conflict, json_encode([
    'dependencies' => [],
    'apiUsers' => [['username' => 'api', 'role' => 'different', 'permissions' => []]],
], JSON_THROW_ON_ERROR));
try {
    $profiles->merge($merged, '', [$first, $conflict]);
    throw new RuntimeException('conflicting roles were accepted');
} catch (RuntimeException $exception) {
    assert(str_contains($exception->getMessage(), 'conflicting roles'));
}

echo "profile data tests passed\n";
