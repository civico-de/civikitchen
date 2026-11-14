<?php
/**
 * CiviCRM permission grants through each CMS's native API, shared by
 * configure-api-users.php (API roles) and apply-content.php (anonymous visitors).
 * Loaded by `cv scr` scripts, so CiviCRM and the host CMS are booted.
 */

/**
 * CiviCRM permission → WordPress capability — the same mapping core applies
 * in CRM_Core_Permission_WordPress::check(): munge(strtolower($perm)).
 */
function ck_wp_cap(string $perm): string {
  return CRM_Utils_String::munge(strtolower($perm));
}

/**
 * Grant permissions to a Drupal role (created on demand). Unknown permission
 * names fail closed: extensions are enabled before this runs, so Drupal's
 * inventory is authoritative and a typo must not yield a marker-complete site.
 */
function ck_drupal_grant(string $roleId, array $perms, bool $exact = FALSE): void {
  $role = \Drupal\user\Entity\Role::load($roleId)
    ?: \Drupal\user\Entity\Role::create(['id' => $roleId, 'label' => $roleId]);
  $known = array_keys(\Drupal::service('user.permissions')->getPermissions());
  if ($exact) {
    foreach (array_diff($role->getPermissions(), $perms) as $stale) {
      $role->revokePermission($stale);
    }
  }
  foreach ($perms as $perm) {
    if (in_array($perm, $known, TRUE)) {
      $role->grantPermission($perm);
    }
    else {
      throw new \RuntimeException("unknown Drupal permission '{$perm}' for role '{$roleId}'");
    }
  }
  $role->save();
}

/** Fail unless Backdrop knows every permission (same check as ck_drupal_grant()). */
function ck_backdrop_require_known(string $roleName, array $perms): void {
  $known = array_keys(user_permission_get_modules());
  foreach ($perms as $perm) {
    if (!in_array($perm, $known, TRUE)) {
      throw new \RuntimeException("unknown Backdrop permission '{$perm}' for role '{$roleName}'");
    }
  }
}

/** Backdrop counterpart of ck_drupal_grant() with $exact: the role carries exactly $perms. */
function ck_backdrop_grant(string $roleName, array $perms): void {
  ck_backdrop_require_known($roleName, $perms);
  $role = user_role_load($roleName) ?: (object) ['name' => $roleName, 'label' => $roleName];
  $role->permissions = array_values(array_unique($perms));
  user_role_save($role);
}

/** The Joomla database. */
function ck_joomla_db(): \Joomla\Database\DatabaseInterface {
  return \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
}

/**
 * CRM_Core_Permission_Joomla checks permissions via $user->authorise(perm,
 * 'com_civicrm'), which needs a com_civicrm ACL asset. civibuild never
 * registers the component, so create the asset the way Joomla's installer
 * would. Idempotent.
 */
function ck_joomla_civicrm_asset(): void {
  $asset = new \Joomla\CMS\Table\Asset(ck_joomla_db());
  if ($asset->loadByName('com_civicrm')) {
    return;
  }
  $asset->name = 'com_civicrm';
  $asset->title = 'CiviCRM';
  $asset->setLocation((new \Joomla\CMS\Table\Asset(ck_joomla_db()))->getRootId(), 'last-child');
  $asset->rules = '{}';
  if (!$asset->check() || !$asset->store()) {
    throw new \RuntimeException('could not create the com_civicrm ACL asset: ' . $asset->getError());
  }
}

/**
 * Set the com_civicrm ACL rules of one Joomla usergroup: $perms are allowed,
 * and with $exact every other CiviCRM rule of the group is dropped first.
 */
function ck_joomla_grant(int $groupId, array $perms, bool $exact): void {
  ck_joomla_civicrm_asset();
  $db = ck_joomla_db();
  $where = $db->quoteName('name') . ' = ' . $db->quote('com_civicrm');
  $rules = json_decode($db->setQuery($db->getQuery(TRUE)->select('rules')->from('#__assets')->where($where))->loadResult() ?: '{}', TRUE);
  if ($exact) {
    foreach ($rules as &$groups) {
      if (is_array($groups)) unset($groups[$groupId]);
    }
    unset($groups);
  }
  $permissions = new \CRM_Core_Permission_Joomla();
  foreach ($perms as $perm) {
    $action = $permissions->translateJoomlaPermission($perm);
    if (is_array($action)) {
      $rules[$action[0]][$groupId] = 1;
    }
  }
  $db->setQuery($db->getQuery(TRUE)->update('#__assets')
    ->set($db->quoteName('rules') . ' = ' . $db->quote(json_encode($rules)))->where($where))->execute();
}

/**
 * Add CiviCRM permissions for anonymous visitors, keeping what they have:
 * Drupal's and Backdrop's anonymous role, the anonymous_user role CiviCRM
 * consults on WordPress, Joomla's guest usergroup, Standalone's everyone role.
 */
function ck_grant_anonymous(string $uf, array $perms): void {
  switch ($uf) {
    case 'Drupal8':
      ck_drupal_grant('anonymous', $perms);
      break;

    case 'Backdrop':
      ck_backdrop_require_known('anonymous', $perms);
      user_role_grant_permissions('anonymous', $perms);
      break;

    case 'WordPress':
      $role = get_role('anonymous_user') ?: add_role('anonymous_user', 'Anonymous User');
      foreach ($perms as $perm) {
        $role->add_cap(ck_wp_cap($perm));
      }
      break;

    case 'Joomla':
      $guest = (int) \Joomla\CMS\Component\ComponentHelper::getParams('com_users')->get('guest_usergroup', 1);
      ck_joomla_grant($guest, $perms, FALSE);
      break;

    case 'Standalone':
      $everyone = \Civi\Api4\Role::get(FALSE)->addSelect('id', 'permissions')->addWhere('name', '=', 'everyone')->execute()->single();
      \Civi\Api4\Role::update(FALSE)->addWhere('id', '=', $everyone['id'])
        ->addValue('permissions', array_values(array_unique([...$everyone['permissions'], ...$perms])))->execute();
      break;

    default:
      throw new \RuntimeException("no anonymous permission grant for user framework '{$uf}'");
  }
}
