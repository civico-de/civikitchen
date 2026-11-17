<?php
// Discover-install CiviCRM's Joomla component and plugins, after
// `extension:discover`. script.civicrm.php throws on civibuild's path layout
// after the component is registered, so that throw is caught.
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;

$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

// Only com_civicrm and its plugins match "civicrm" in #__extensions.
$eids = $db->setQuery(
  $db->getQuery(TRUE)
    ->select('extension_id')
    ->from('#__extensions')
    ->where($db->quoteName('element') . ' LIKE ' . $db->quote('%civicrm%'))
)->loadColumn();

if (!$eids) {
  fwrite(STDERR, "joomla-enable-plugins: no civicrm extensions discovered — did 'extension:discover' run?\n");
  return;
}

foreach ($eids as $eid) {
  try {
    (new Installer())->discover_install((int) $eid);
  }
  catch (\Throwable $e) {
    // Expected for com_civicrm (see above); the component is registered by now.
    fwrite(STDERR, "joomla-enable-plugins: discover_install({$eid}): {$e->getMessage()}\n");
  }
}

// The failed install script rolls state back to -1, at which option=com_civicrm
// 404s; force enabled=1 and state=0.
$db->setQuery(
  $db->getQuery(TRUE)
    ->update('#__extensions')
    ->set([$db->quoteName('enabled') . ' = 1', $db->quoteName('state') . ' = 0'])
    ->where($db->quoteName('element') . ' LIKE ' . $db->quote('%civicrm%'))
)->execute();

echo 'joomla-enable-plugins: registered+enabled CiviCRM Joomla extensions [' . implode(',', $eids) . "]\n";
