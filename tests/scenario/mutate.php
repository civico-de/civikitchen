#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/packages/civikitchen-scenario-schema/scenario.php';

$source = $argv[1] ?? throw new RuntimeException('source YAML required');
$target = $argv[2] ?? throw new RuntimeException('target YAML required');
$mutation = $argv[3] ?? throw new RuntimeException('mutation required');
$value = $argv[4] ?? NULL;
$document = ck_scenario_parse_yaml($source);
$scenario = &$document['scenario'];

switch ($mutation) {
  case 'invalid-default-locale':
    $scenario['default_locale'] = 'fr_FR';
    break;
  case 'derived-url':
    $scenario['extension']['path'] = realpath(dirname($source) . '/' . $scenario['extension']['path']);
    unset($scenario['site_url']);
    $scenario['http_port'] = 8290;
    break;
  case 'invalid-port':
    $scenario['http_port'] = 70000;
    break;
  case 'site-url':
    $scenario['site_url'] = $value;
    $scenario['http_port'] = 8080;
    break;
  case 'loopback-port':
    $scenario['site_url'] = "http://{$value}:9999";
    $scenario['http_port'] = 8080;
    break;
  case 'dotted-extension':
    $scenario['extension'] = ['key' => 'org.example.demo', 'path' => $value];
    break;
  case 'untrusted-profiles':
    $base = dirname($source);
    $scenario['extension']['path'] = realpath($base . '/' . $scenario['extension']['path']);
    foreach ($scenario['profile_paths'] as &$path) $path = realpath($base . '/' . $path);
    unset($path);
    $scenario['trust_external_profiles'] = FALSE;
    break;
  case 'database':
    $scenario['extension']['path'] = realpath(dirname($source) . '/' . $scenario['extension']['path']);
    $scenario['database']['image'] = $argv[5] ?? $scenario['database']['image'];
    $document['policy']['database'] = $value;
    break;
  case 'untrusted-extension':
    $scenario['trust_external_extension'] = FALSE;
    break;
  case 'secret-logging-without-consent':
    $scenario['credentials_output'] = 'log';
    unset($scenario['allow_secret_logging']);
    break;
  default:
    throw new RuntimeException("unknown mutation: {$mutation}");
}

if (file_put_contents($target, ck_scenario_dump_yaml($document), LOCK_EX) === FALSE) {
  throw new RuntimeException("cannot write {$target}");
}
