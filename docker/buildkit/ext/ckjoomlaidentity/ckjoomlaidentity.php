<?php

/**
 * On headless requests authx sets the CiviCRM contact but Joomla's identity
 * stays guest; on the first permission check, load the contact's Joomla user.
 */

function ckjoomlaidentity_civicrm_config(&$config) {
  if (!defined('CIVICRM_UF') || CIVICRM_UF !== 'Joomla') {
    return;
  }
  // High priority so the identity is in place before any later listener (or the
  // core check) evaluates the permission.
  \Civi::dispatcher()->addListener('hook_civicrm_permission_check', 'ckjoomlaidentity_sync_joomla_identity', 1000);
}

/**
 * Make Joomla's current identity match the logged-in CiviCRM contact.
 */
function ckjoomlaidentity_sync_joomla_identity($e) {
  if (!class_exists('\Joomla\CMS\Factory', FALSE)) {
    return;
  }
  $contactId = \CRM_Core_Session::getLoggedInContactID();
  if (!$contactId) {
    return;
  }
  $ufId = \CRM_Core_BAO_UFMatch::getUFId($contactId);
  if (!$ufId) {
    return;
  }
  $app = \Joomla\CMS\Factory::getApplication();
  $identity = $app->getIdentity();
  if ($identity && (int) $identity->id === (int) $ufId) {
    // Already the right user — nothing to do (keeps this cheap on repeat checks).
    return;
  }
  try {
    $user = \Joomla\CMS\Factory::getContainer()
      ->get(\Joomla\CMS\User\UserFactoryInterface::class)
      ->loadUserById((int) $ufId);
    $app->loadIdentity($user);
  }
  catch (\Throwable $ex) {
    // Best-effort: leave the identity as-is and let CiviCRM surface its own
    // permission error rather than this one.
  }
}
