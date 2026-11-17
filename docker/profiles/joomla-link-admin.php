<?php
// Link the Joomla admin to a contact, which civibuild's install skips: without
// it `cv --user=admin` fails with "Failed to determine contact ID".
if (CIVICRM_UF !== 'Joomla') {
  return;
}

$uid = (int) \Joomla\CMS\User\UserHelper::getUserId('admin');
if (!$uid) {
  fwrite(STDERR, "joomla-link-admin: no Joomla 'admin' user found\n");
  return;
}
if (\Civi\Api4\UFMatch::get(FALSE)->addWhere('uf_id', '=', $uid)->execute()->first()) {
  echo "joomla-link-admin: admin (uf_id={$uid}) already linked\n";
  return;
}

$jUser = \Joomla\CMS\Factory::getContainer()
  ->get(\Joomla\CMS\User\UserFactoryInterface::class)
  ->loadUserById($uid);
\CRM_Core_BAO_UFMatch::synchronizeUFMatch($jUser, $uid, $jUser->email, 'Joomla');
$cid = \Civi\Api4\UFMatch::get(FALSE)->addWhere('uf_id', '=', $uid)->addSelect('contact_id')->execute()->first()['contact_id'] ?? '?';
echo "joomla-link-admin: linked admin (uf_id={$uid}) -> contact {$cid}\n";
