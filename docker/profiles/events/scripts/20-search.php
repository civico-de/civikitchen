<?php
// A SearchKit table of every event with its participant count, shown as an
// Afform page at civicrm/demo/events.

require_once __DIR__ . '/../../lib.php';

ck_seed_search_page('DemoEventRegistrations', 'Veranstaltungen und Anmeldungen', 'Event', [
  'select' => ['title', 'start_date', 'max_participants', 'COUNT(Event_Participant_event_id_01.id) AS COUNT_Event_Participant_event_id_01_id'],
  'join' => [['Participant AS Event_Participant_event_id_01', 'LEFT', ['id', '=', 'Event_Participant_event_id_01.event_id']]],
  'groupBy' => ['id'],
  'orderBy' => ['start_date' => 'DESC'],
], [
  'title' => 'Veranstaltung',
  'start_date' => 'Beginn',
  'max_participants' => 'Plätze',
  'COUNT_Event_Participant_event_id_01_id' => 'Anmeldungen',
], 'civicrm/demo/events');
echo "seeded the event registrations search page\n";
