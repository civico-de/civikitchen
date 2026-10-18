<?php
// Seed the events world: the host org, past + upcoming events, and
// participants in varied statuses (Registered / Attended / Cancelled), so the
// event dashboards, participant searches, and reports all have data. Runs via
// `cv scr` with CiviCRM booted. Idempotent: skips entirely when the host org
// already exists.

require_once __DIR__ . '/../../lib.php';

use Civi\Api4\Event;
use Civi\Api4\Participant;

if (ck_seed_org_exists('Bildungswerk Musterstadt e.V.')) {
  echo "events already seeded, skipping\n";
  return;
}

$orgId = ck_seed_org('Bildungswerk Musterstadt e.V.', 'veranstaltungen@bildungswerk.example.de', ['Seminarstraße 14', '69117', 'Heidelberg']);
echo "created Bildungswerk Musterstadt e.V. (contact {$orgId})\n";

// [title, event type, start offset, duration days, max participants, public]
$events = [
  ['Fachkonferenz Engagement 2026', 'Conference', '+6 weeks', 2, 120, TRUE],
  ['Sommerfest 2026', 'Fundraiser', '+3 months', 1, 200, TRUE],
  ['Workshop Öffentlichkeitsarbeit', 'Workshop', '+2 weeks', 1, 25, TRUE],
  ['Workshop Fördermittel', 'Workshop', '-6 weeks', 1, 25, TRUE],
  ['Online-Seminar Datenschutz', 'Meeting', '-3 months', 1, 80, TRUE],
  ['Vorstandsklausur', 'Meeting', '-2 weeks', 2, 12, FALSE],
];
$eventIds = [];
foreach ($events as [$title, $type, $start, $days, $max, $public]) {
  $startDate = date('Y-m-d 10:00:00', strtotime($start));
  $e = Event::create(FALSE)
    ->addValue('title', $title)
    ->addValue('event_type_id:name', $type)
    ->addValue('start_date', $startDate)
    ->addValue('end_date', date('Y-m-d 17:00:00', strtotime($start . " +{$days} days")))
    ->addValue('max_participants', $max)
    ->addValue('is_public', $public)
    ->addValue('is_active', TRUE)
    ->addValue('is_online_registration', TRUE)
    ->addValue('summary', $title)
    ->execute()->first();
  $eventIds[] = $e['id'];
}
echo 'created ' . count($eventIds) . " events\n";

// [first, last, street, plz, city]
$people = [
  ['Tobias', 'Lindner', 'Ginsterweg 2', '69115', 'Heidelberg'],
  ['Nadine', 'Haas', 'Römerstraße 31', '68161', 'Mannheim'],
  ['Florian', 'Kraft', 'Turmgasse 7', '74072', 'Heilbronn'],
  ['Melanie', 'Stein', 'Bachstraße 19', '76829', 'Landau'],
  ['Christian', 'Berger', 'Hirschgraben 4', '60311', 'Frankfurt am Main'],
  ['Stefanie', 'Roth', 'Quellenweg 8', '64283', 'Darmstadt'],
  ['Daniel', 'Frank', 'Kastanienallee 25', '67059', 'Ludwigshafen'],
  ['Annika', 'Beck', 'Sonnenhang 3', '69469', 'Weinheim'],
  ['Patrick', 'Herrmann', 'Mittelgasse 12', '69412', 'Eberbach'],
  ['Vanessa', 'Schuster', 'Talstraße 28', '74821', 'Mosbach'],
  ['Markus', 'Simon', 'Pfarrgasse 1', '69251', 'Gaiberg'],
  ['Lena', 'Böhm', 'Neckarstaden 16', '69117', 'Heidelberg'],
  ['Oliver', 'Jung', 'Wieblinger Weg 5', '69123', 'Heidelberg'],
  ['Franziska', 'Keller', 'Bergheimer Straße 44', '69115', 'Heidelberg'],
  ['Sebastian', 'Walter', 'Handschuhsheimer Landstraße 9', '69120', 'Heidelberg'],
  ['Jana', 'Peters', 'Eppelheimer Straße 13', '69115', 'Heidelberg'],
  ['Matthias', 'König', 'Friedrich-Ebert-Anlage 22', '69117', 'Heidelberg'],
  ['Sandra', 'Huber', 'Rohrbacher Straße 57', '69115', 'Heidelberg'],
];

$statuses = ['Registered', 'Registered', 'Attended', 'Registered', 'Cancelled'];
$participants = 0;
foreach ($people as $i => [$first, $last, $street, $plz, $city]) {
  $cid = ck_seed_individual($first, $last, ck_seed_email($first, $last, 'bildungswerk.example.de'), [$street, $plz, $city]);
  // Each person attends 1-3 events; past events get Attended/Cancelled mix,
  // upcoming events get Registered.
  $n = 1 + ($i % 3);
  for ($p = 0; $p < $n; $p++) {
    $eventIdx = ($i + $p * 2) % count($eventIds);
    $isPast = in_array($eventIdx, [3, 4, 5], TRUE);
    $status = $isPast ? $statuses[($i + $p) % count($statuses)] : 'Registered';
    Participant::create(FALSE)
      ->addValue('event_id', $eventIds[$eventIdx])
      ->addValue('contact_id', $cid)
      ->addValue('status_id:name', $status)
      ->addValue('role_id:name', 'Attendee')
      ->addValue('register_date', date('Y-m-d', strtotime('-' . (1 + $i % 8) . ' weeks')))
      ->execute();
    $participants++;
  }
}
echo 'created ' . count($people) . " contacts with {$participants} participant records\n";
