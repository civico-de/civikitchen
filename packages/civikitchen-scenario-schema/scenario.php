#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Composer\Semver\VersionParser;

$validator = dirname(__DIR__) . '/civicrm-profile-schema/validate.php';
if (!is_file($validator)) $validator = dirname(__DIR__) . '/profile-schema/validate.php';
if (!is_file($validator)) throw new RuntimeException('scenario validator dependency is missing');
require_once $validator;

/** @return array<string, mixed> */
function ck_scenario_parse_yaml(string $file): array {
  static $yamlLoaded = FALSE;
  if (!$yamlLoaded) {
    $yamlAutoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($yamlAutoload)) {
      throw new RuntimeException('scenario YAML parser dependency is missing; run composer install --working-dir=packages/civikitchen-scenario-schema');
    }
    require_once $yamlAutoload;
    $yamlLoaded = TRUE;
  }
  if (strtolower((string) pathinfo($file, PATHINFO_EXTENSION)) !== 'yaml') {
    throw new RuntimeException("scenario file must use the canonical .yaml extension: {$file}");
  }
  if (!is_file($file)) throw new RuntimeException("scenario file not found: {$file}");
  $size = filesize($file);
  if ($size === FALSE || $size > 1024 * 1024) {
    throw new RuntimeException('scenario YAML exceeds the 1 MiB configuration limit');
  }
  $source = (string) file_get_contents($file);
  $first = ltrim($source)[0] ?? '';
  if ($first === '{' || $first === '[') {
    throw new RuntimeException('scenario must use YAML mapping syntax, not JSON syntax in a .yaml file');
  }
  try {
    $document = Yaml::parse($source, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
  }
  catch (ParseException $e) {
    throw new RuntimeException("invalid scenario YAML: {$e->getMessage()}", 0, $e);
  }
  if (!is_array($document) || array_is_list($document)) {
    throw new RuntimeException('scenario YAML root must be a mapping');
  }
  return $document;
}

/** @param array<string, mixed> $document */
function ck_scenario_dump_yaml(array $document): string {
  return Yaml::dump($document, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK) . "\n";
}

/** @return array<string, mixed> */
function ck_config_load(string $file): array {
  if (!is_file($file)) throw new RuntimeException("scenario file not found: {$file}");
  $document = ck_scenario_parse_yaml($file);
  $schemaFile = __DIR__ . '/scenario.schema.json';
  $schema = json_decode((string) file_get_contents($schemaFile), TRUE, 512, JSON_THROW_ON_ERROR);
  $documentObject = json_decode(json_encode($document, JSON_THROW_ON_ERROR), FALSE, 512, JSON_THROW_ON_ERROR);
  $errors = (new CkProfileSchemaValidator($schema))->validate($documentObject);
  if ($errors !== []) throw new RuntimeException(implode("\n", $errors));
  $sourceKeys = [];
  foreach ($document['policy']['extension_sources'] ?? [] as $source) {
    if (isset($sourceKeys[$source['key']])) {
      throw new RuntimeException('$.policy.extension_sources: repeated key ' . $source['key']);
    }
    $sourceKeys[$source['key']] = TRUE;
    try {
      (new VersionParser())->parseConstraints($source['version']);
    }
    catch (UnexpectedValueException $e) {
      throw new RuntimeException('$.policy.extension_sources: invalid Composer version constraint for ' . $source['key'] . ': ' . $source['version'], 0, $e);
    }
  }
  // A patch, variant or digest pin of the same image is the same database.
  $database = $document['policy']['database'] ?? NULL;
  $scenarioDatabase = $document['scenario']['database']['image'] ?? NULL;
  if (is_string($database) && is_string($scenarioDatabase)
    && preg_match('/^' . preg_quote($database, '/') . '([.@-]|$)/', $scenarioDatabase) !== 1) {
    throw new RuntimeException("policy.database is {$database}, but scenario.database.image is {$scenarioDatabase}: name the same image in both");
  }
  return $document;
}

/** @return array<string, mixed> */
function ck_scenario_load(string $file): array {
  $document = ck_config_load($file);
  if (!isset($document['scenario']) || !is_array($document['scenario'])) {
    throw new RuntimeException('$.scenario: is required for scenario commands');
  }
  $scenario = $document['scenario'];
  $scenario += [
    'http_port' => 8080,
    'locales' => [],
    'profiles' => [],
    'profile_paths' => [],
    'trust_external_extension' => FALSE,
    'trust_external_profiles' => FALSE,
    'credentials_output' => 'file',
    'allow_secret_logging' => FALSE,
    'checks' => ['conform', 'lint', 'format', 'compatibility', 'javascript', 'test'],
  ];
  $scenario += ['site_url' => 'http://localhost:' . $scenario['http_port']];
  if (in_array($scenario['credentials_output'], ['log', 'both'], TRUE) && !$scenario['allow_secret_logging']) {
    throw new RuntimeException('$.scenario.allow_secret_logging: must be true when credentials_output writes secrets to logs');
  }
  ck_scenario_validate_site_url($scenario['site_url'], $scenario['http_port']);
  $scenario['extension'] += ['writable' => FALSE];
  if (isset($scenario['default_locale']) && !in_array($scenario['default_locale'], $scenario['locales'], TRUE)) {
    throw new RuntimeException('$.default_locale: must also appear in $.locales');
  }
  $base = dirname((string) realpath($file));
  $scenario['extension']['path'] = ck_scenario_path($base, $scenario['extension']['path'], 'extension.path');
  $insideConfigRoot = $scenario['extension']['path'] === $base || str_starts_with($scenario['extension']['path'], $base . '/');
  if (!$insideConfigRoot && !$scenario['trust_external_extension']) {
    throw new RuntimeException('$.scenario.trust_external_extension: must be true when extension.path resolves outside the config directory');
  }
  $info = $scenario['extension']['path'] . '/info.xml';
  if (!is_file($info)) throw new RuntimeException('$.extension.path: directory has no info.xml');
  $xml = simplexml_load_file($info);
  if ($xml === FALSE || trim((string) ($xml['key'] ?? '')) !== $scenario['extension']['key']) {
    throw new RuntimeException('$.extension.key: does not match info.xml <extension key>');
  }
  foreach ($scenario['profile_paths'] as $index => $path) {
    $scenario['profile_paths'][$index] = ck_scenario_path($base, $path, "profile_paths[{$index}]");
  }
  if ($scenario['profile_paths'] !== [] && !$scenario['trust_external_profiles']) {
    throw new RuntimeException('$.trust_external_profiles: must be true when profile_paths are mounted; external profiles are trusted executable code');
  }
  return $scenario;
}

function ck_scenario_validate_site_url(string $url, int $httpPort): void {
  $parts = parse_url($url);
  if ($parts === FALSE || ($parts['scheme'] ?? '') !== 'http' || empty($parts['host'])) {
    throw new RuntimeException('$.site_url: must be an absolute plain-HTTP base URL');
  }
  if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
    throw new RuntimeException('$.site_url: credentials, query strings, and fragments are not valid in a site base URL');
  }
  if (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/') {
    throw new RuntimeException('$.site_url: subdirectory paths are not supported');
  }
  $rawHost = strtolower((string) $parts['host']);
  if (preg_match('/^[a-z0-9._-]+$/', $rawHost) !== 1) {
    throw new RuntimeException('$.site_url: host must be a plain DNS name or IPv4 address');
  }
  // A final dot is only DNS' absolute-name notation. Normalize it before the
  // loopback check so localhost. cannot evade the published-port contract.
  $host = rtrim($rawHost, '.');
  if ($host === '') {
    throw new RuntimeException('$.site_url: host must be a plain DNS name or IPv4 address');
  }
  $isIpv4Loopback = preg_match('/^127(?:\.[0-9]{1,3}){3}$/', $host) === 1;
  if ($host === 'localhost' || $isIpv4Loopback) {
    $urlPort = (int) ($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80));
    if ($urlPort !== $httpPort) {
      throw new RuntimeException("$.site_url: loopback port {$urlPort} does not match $.http_port {$httpPort}");
    }
  }
}

function ck_scenario_path(string $base, string $path, string $field): string {
  $candidate = str_starts_with($path, '/') ? $path : $base . '/' . $path;
  $resolved = realpath($candidate);
  if ($resolved === FALSE || !is_dir($resolved)) throw new RuntimeException("$.{$field}: directory not found: {$path}");
  return $resolved;
}

/** @param array<string, mixed> $scenario @return array<string, mixed> */
function ck_scenario_compose(array $scenario): array {
  $environment = [
    'CIVICRM_AUTO_INSTALL' => '1',
    'CIVICRM_DB_HOST' => 'db',
    // A disposable scenario uses the DB admin so the isolated headless scratch
    // DB can be created safely without a generated grant file.
    'CIVICRM_DB_USER' => 'root',
    'CIVICRM_DB_PASSWORD' => 'root',
    'CIVIKITCHEN_SITE_URL' => $scenario['site_url'],
    'CIVIKITCHEN_LOCALES' => implode(',', $scenario['locales']),
    'CIVIKITCHEN_DEFAULT_LOCALE' => $scenario['default_locale'] ?? '',
    'CIVIKITCHEN_PROFILE' => implode(',', $scenario['profiles']),
    'CK_CREDENTIALS_OUTPUT' => $scenario['credentials_output'],
    'CK_CREDENTIALS_FILE' => '/tmp/civikitchen-api-credentials.txt',
    'CIVIKITCHEN_TRUST_EXTERNAL_PROFILES' => $scenario['trust_external_profiles'] ? '1' : '0',
    'CIVIKITCHEN_EXTENSION_PATH' => '/civikitchen-extension',
    'CIVIKITCHEN_EXTENSION_KEY' => $scenario['extension']['key'],
    'CIVIKITCHEN_ENABLE_EXTENSIONS' => $scenario['extension']['key'],
  ];
  if ($scenario['profiles'] !== []) {
    // Standalone profiles seed as `admin`; buildkit flavors already provide
    // that CMS account and ignore this standalone-only knob.
    $environment['CIVIKITCHEN_DEMO_USER'] = 'admin';
  }
  $volumes = [[
    'type' => 'bind',
    'source' => $scenario['extension']['path'],
    'target' => '/civikitchen-extension',
    'read_only' => !$scenario['extension']['writable'],
  ]];
  $containerProfilePaths = [];
  foreach ($scenario['profile_paths'] as $index => $path) {
    $target = "/civikitchen-profiles/{$index}";
    $volumes[] = [
      'type' => 'bind',
      'source' => $path,
      'target' => $target,
      'read_only' => TRUE,
    ];
    $containerProfilePaths[] = $target;
  }
  $environment['CIVIKITCHEN_PROFILE_PATH'] = implode(':', $containerProfilePaths);

  return [
    'name' => $scenario['name'],
    'services' => [
      'app' => [
        'image' => $scenario['image'],
        'ports' => [$scenario['http_port'] . ':80'],
        'environment' => $environment,
        'depends_on' => ['db' => ['condition' => 'service_healthy']],
        'volumes' => $volumes,
      ],
      'db' => [
        'image' => $scenario['database']['image'],
        'environment' => [
          'MYSQL_ROOT_PASSWORD' => 'root',
          'MYSQL_DATABASE' => 'civicrm',
        ],
        // MariaDB 11+ images ship only mariadb-admin, MySQL only mysqladmin.
        'healthcheck' => [
          'test' => ['CMD-SHELL', 'mariadb-admin ping -h 127.0.0.1 -proot --silent || mysqladmin ping -h 127.0.0.1 -proot --silent'],
          'interval' => '5s',
          'timeout' => '5s',
          'retries' => 30,
        ],
        'volumes' => ['db-data:/var/lib/mysql'],
      ],
    ],
    'volumes' => ['db-data' => NULL],
  ];
}

/** @param array<string, mixed> $scenario @return list<string> */
function ck_scenario_commands(array $scenario): array {
  $commands = [];
  foreach ($scenario['checks'] as $check) {
    $commands[] = match ($check) {
      'lint' => 'ck lint --all',
      'format' => 'ck format --check',
      default => 'ck ' . $check,
    };
  }
  return $commands;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  $command = $argv[1] ?? 'help';
  $file = $argv[2] ?? 'civikitchen.yaml';
  if (in_array($command, ['help', '-h', '--help'], TRUE)) {
    echo "ck scenario validate|plan|compose|commands [file=civikitchen.yaml]\n";
    echo "ck scenario materialize [file=civikitchen.yaml] [output=civikitchen.compose.json]\n";
    exit(0);
  }
  try {
    if ($command === 'validate') {
      $document = ck_config_load($file);
      if (isset($document['scenario'])) ck_scenario_load($file);
      echo "{$file}: valid CiviKitchen configuration\n";
      exit(0);
    }
    $scenario = ck_scenario_load($file);
    if ($command === 'materialize') {
      $target = $argv[3] ?? 'civikitchen.compose.json';
      $body = json_encode(ck_scenario_compose($scenario), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
      if (file_put_contents($target, $body, LOCK_EX) === FALSE) throw new RuntimeException("cannot write {$target}");
      echo "{$target}: wrote Docker Compose model\n";
      exit(0);
    }
    $output = match ($command) {
      'plan' => json_encode($scenario, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
      'compose' => json_encode(ck_scenario_compose($scenario), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
      'commands' => implode("\n", ck_scenario_commands($scenario)) . "\n",
      default => throw new RuntimeException("unknown scenario command: {$command}"),
    };
    echo $output;
  }
  catch (Throwable $e) {
    fwrite(STDERR, "ckscenario: {$e->getMessage()}\n");
    exit(1);
  }
}
