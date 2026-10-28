<?php

/**
 * The steps after a seed snapshot is loaded and its dates are shifted, in one
 * CiviCRM boot: `cv scr --user=admin seed-after-restore.php`.
 *
 * Runs pending extension upgrades, rebuilds triggers and caches and reconciles
 * managed entities, invalidates every smart group cache, refreshes every
 * SearchKit DB-entity display, then runs the profile's after_restore scripts
 * (CK_SEED_AFTER_RESTORE, one path per line) in this same process.
 */

$started = microtime(TRUE);
$step = static function (string $name, callable $work) use (&$started): void {
  $work();
  printf("[ck seed]     %-22s %5.1f s\n", $name, microtime(TRUE) - $started);
  $started = microtime(TRUE);
};

$step('extension upgrades', static function (): void {
  if (!CRM_Extension_Upgrades::hasPending()) {
    return;
  }
  $runner = new CRM_Queue_Runner([
    'title' => 'Extension upgrades after a seed restore',
    'queue' => CRM_Extension_Upgrades::createQueue(),
    'errorMode' => CRM_Queue_Runner::ERROR_ABORT,
  ]);
  $result = $runner->runAll();
  if ($result !== TRUE) {
    throw new RuntimeException('extension upgrades failed: ' . json_encode($result));
  }
});

$step('triggers and caches', static function (): void {
  Civi::rebuild(['*' => TRUE, 'sessions' => FALSE])->execute();
});

$step('smart groups', static function (): void {
  $ids = \Civi\Api4\Group::get(FALSE)
    ->addSelect('id')
    ->addClause('OR', ['saved_search_id', 'IS NOT NULL'], ['children', 'IS NOT NULL'])
    ->execute()->column('id');
  if ($ids) {
    CRM_Contact_BAO_GroupContactCache::invalidateGroupContactCache($ids);
  }
});

$step('SearchKit entities', static function (): void {
  if (!CRM_Extension_System::singleton()->getMapper()->isActiveModule('search_kit')) {
    return;
  }
  $displays = \Civi\Api4\SearchDisplay::get(FALSE)
    ->addSelect('name')
    ->addWhere('type', '=', 'entity')
    ->execute();
  foreach ($displays as $display) {
    civicrm_api4('SK_' . $display['name'], 'refresh', ['checkPermissions' => FALSE]);
  }
});

foreach (array_filter(explode("\n", (string) getenv('CK_SEED_AFTER_RESTORE'))) as $script) {
  $step('script ' . basename($script), static function () use ($script): void {
    require $script;
  });
}
