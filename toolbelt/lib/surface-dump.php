<?php

/**
 * Prints the site's API, route, and Afform surface as deterministic JSON with
 * the permissions each declares. Run inside a CiviCRM container via `cv scr`.
 */

$out = [
  'api4' => [],
  'api3' => [],
  'routes' => [],
  'afform' => [],
];

foreach (\Civi\Api4\Entity::get(FALSE)->addSelect('name')->execute() as $entity) {
  $entityName = (string) ($entity['name'] ?? '');
  if ($entityName === '') {
    continue;
  }
  try {
    $actions = civicrm_api4($entityName, 'getActions', ['checkPermissions' => FALSE]);
  }
  catch (\Throwable $e) {
    continue;
  }
  foreach ($actions as $action) {
    $actionName = (string) ($action['name'] ?? '');
    if ($actionName === '') {
      continue;
    }
    try {
      $perms = \Civi\API\Request::create($entityName, $actionName, ['version' => 4])->getPermissions();
    }
    catch (\Throwable $e) {
      $perms = NULL;
    }
    $out['api4'][] = [
      'entity' => $entityName,
      'action' => $actionName,
      'permissions' => $perms,
    ];
  }
}

if (!function_exists('_civicrm_api3_permissions')) {
  $permissionsFile = stream_resolve_include_path('CRM/Core/DAO/permissions.php');
  if (is_string($permissionsFile) && is_file($permissionsFile)) {
    require_once $permissionsFile;
  }
}
try {
  $api3Entities = civicrm_api3('Entity', 'get', [])['values'] ?? [];
}
catch (\Throwable $e) {
  $api3Entities = [];
}
foreach ($api3Entities as $entity) {
  $entityName = (string) $entity;
  if ($entityName === '') {
    continue;
  }
  try {
    $actions = civicrm_api3($entityName, 'getactions', [])['values'] ?? [];
  }
  catch (\Throwable $e) {
    continue;
  }
  foreach ($actions as $action) {
    $actionName = (string) $action;
    if ($actionName === '') {
      continue;
    }
    $params = ['version' => 3];
    try {
      $perms = function_exists('_civicrm_api3_permissions')
        ? _civicrm_api3_permissions($entityName, $actionName, $params)
        : NULL;
    }
    catch (\Throwable $e) {
      $perms = NULL;
    }
    $out['api3'][] = [
      'entity' => $entityName,
      'action' => $actionName,
      'permissions' => $perms,
    ];
  }
}

foreach (\CRM_Core_Menu::items() as $path => $item) {
  $out['routes'][] = [
    'path' => (string) $path,
    'access' => $item['access_arguments'] ?? NULL,
    'callback' => is_string($item['page_callback'] ?? NULL) ? $item['page_callback'] : NULL,
  ];
}

if (class_exists('\Civi\Api4\Afform')) {
  try {
    $forms = \Civi\Api4\Afform::get(FALSE)
      ->addSelect('name', 'server_route', 'permission', 'is_public')
      ->execute();
    foreach ($forms as $form) {
      $out['afform'][] = [
        'name' => (string) ($form['name'] ?? ''),
        'server_route' => $form['server_route'] ?? NULL,
        'permission' => $form['permission'] ?? NULL,
        'is_public' => (bool) ($form['is_public'] ?? FALSE),
      ];
    }
  }
  catch (\Throwable $e) {
    // Afform may be present on disk while disabled in the database.
  }
}

$byEntityAction = static fn (array $a, array $b): int => [$a['entity'], $a['action']] <=> [$b['entity'], $b['action']];
usort($out['api4'], $byEntityAction);
usort($out['api3'], $byEntityAction);
usort($out['routes'], static fn (array $a, array $b): int => $a['path'] <=> $b['path']);
usort($out['afform'], static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
