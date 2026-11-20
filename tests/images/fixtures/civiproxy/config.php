<?php

// Upstream defaults with the stub target and one mapped API key.
require __DIR__ . '/config.dist.php';

$proxy_base = 'http://localhost';
$target_civicrm = 'http://target:8000';
$target_rest4 = $target_civicrm . '/civicrm/ajax/api4/';
$api_key_map = ['proxy-key' => 'target-key'];
$authx_external_flow = ['legacyrest'];
$authx_internal_flow = 'header';
$rest_allowed_actions = ['all' => ['Contact' => ['get' => ['limit' => 'int']]]];
