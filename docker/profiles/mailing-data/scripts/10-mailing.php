<?php
// Seed the mailing world: the sender org, segmented newsletter groups with
// subscribers, and a draft mailing addressed to the main newsletter group, so
// CiviMail's screens (and Mosaico, once a template is picked) start with real
// recipients. Runs via `cv scr` with CiviCRM booted. Idempotent: skips
// entirely when the sender org already exists.

require_once __DIR__ . '/../../lib.php';

use Civi\Api4\Group;
use Civi\Api4\Mailing;
use Civi\Api4\MailingGroup;

if (ck_seed_org_exists('Netzwerk Engagement e.V.')) {
  echo "mailing already seeded, skipping\n";
  return;
}

$orgId = ck_seed_org('Netzwerk Engagement e.V.', 'redaktion@netzwerk.example.de', ['Medienpark 2', '50670', 'Köln']);
echo "created Netzwerk Engagement e.V. (contact {$orgId})\n";

// Mailing lists must be of group_type "Mailing List" to be selectable as
// CiviMail recipients.
$lists = [
  ['nl_monatlich', 'Newsletter (monatlich)'],
  ['nl_projekte', 'Projekt-Updates'],
  ['presse', 'Presseverteiler'],
];
foreach ($lists as [$name, $title]) {
  Group::create(FALSE)
    ->addValue('name', $name)
    ->addValue('title', $title)
    ->addValue('group_type:name', ['Mailing List'])
    ->addValue('is_active', TRUE)
    ->execute();
}
echo 'created ' . count($lists) . " mailing lists\n";

// [first, last] — subscribers, spread across the lists below.
$subscribers = [
  ['Alexandra', 'Brand'], ['Benjamin', 'Ernst'], ['Carina', 'Fuchs'],
  ['David', 'Graf'], ['Eva', 'Heller'], ['Fabian', 'Janßen'],
  ['Greta', 'Kaiser'], ['Henrik', 'Lorenz'], ['Isabel', 'Marx'],
  ['Jonas', 'Naumann'], ['Klara', 'Oswald'], ['Leon', 'Paulsen'],
  ['Mara', 'Quandt'], ['Niklas', 'Reuter'], ['Olivia', 'Sander'],
  ['Paul', 'Thiel'], ['Quirin', 'Ulrich'], ['Rosa', 'Vetter'],
  ['Simon', 'Weiß'], ['Tessa', 'Ziegler'], ['Ulf', 'Adler'],
  ['Vera', 'Bachmann'], ['Wim', 'Cordes'], ['Xenia', 'Dietrich'],
  ['Yannick', 'Ebert'], ['Zoe', 'Falk'], ['Arne', 'Gerlach'],
  ['Britta', 'Hagen'], ['Carsten', 'Ilg'], ['Doris', 'Jahn'],
];

$count = 0;
foreach ($subscribers as $i => [$first, $last]) {
  $cid = ck_seed_individual($first, $last, ck_seed_email($first, $last, 'netzwerk.example.de'));
  // Everyone gets the monthly newsletter; every second contact the project
  // updates; every fifth the press list.
  ck_seed_group_contact($cid, 'nl_monatlich');
  if ($i % 2 === 0) {
    ck_seed_group_contact($cid, 'nl_projekte');
  }
  if ($i % 5 === 0) {
    ck_seed_group_contact($cid, 'presse');
  }
  $count++;
}
echo "created {$count} subscribers across the lists\n";

// A draft mailing (never scheduled, so nothing tries to send at boot).
$mailing = Mailing::create(FALSE)
  ->addValue('name', 'Newsletter Juni (Entwurf)')
  ->addValue('subject', 'Neues aus dem Netzwerk')
  ->addValue('from_name', 'Netzwerk Engagement e.V.')
  ->addValue('from_email', 'redaktion@netzwerk.example.de')
  ->addValue('body_html', '<h1>Neues aus dem Netzwerk</h1><p>Liebe Leserinnen und Leser,</p><p>hier die Themen des Monats. Diese Vorlage lässt sich im Mosaico-Editor weiter gestalten.</p><p>{action.unsubscribeUrl}</p>')
  ->addValue('body_text', "Neues aus dem Netzwerk\n\nLiebe Leserinnen und Leser,\nhier die Themen des Monats.\n\n{action.unsubscribeUrl}")
  ->execute()->first();
$groupId = Group::get(FALSE)->addWhere('name', '=', 'nl_monatlich')->execute()->first()['id'];
MailingGroup::create(FALSE)
  ->addValue('mailing_id', $mailing['id'])
  ->addValue('group_type', 'Include')
  ->addValue('entity_table', 'civicrm_group')
  ->addValue('entity_id', $groupId)
  ->execute();
echo "created draft mailing 'Newsletter Juni (Entwurf)'\n";
