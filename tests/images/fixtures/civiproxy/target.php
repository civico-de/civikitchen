<?php

// Stub CiviCRM for the CiviProxy smoke test: echoes what the proxy forwarded.
header('Content-Type: application/json');
echo json_encode([
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'params' => json_decode($_GET['params'] ?? 'null', true),
]);
