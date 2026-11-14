<?php
// A SearchKit table of the memberships with type and status, shown as an
// Afform page at civicrm/demo/members.

require_once __DIR__ . '/../../lib.php';

ck_seed_search_page('DemoMembers', 'Mitglieder', 'Membership', [
  'select' => ['contact_id.display_name', 'membership_type_id:label', 'status_id:label', 'join_date', 'end_date'],
  'orderBy' => ['join_date' => 'ASC'],
], [
  'contact_id.display_name' => 'Mitglied',
  'membership_type_id:label' => 'Art',
  'status_id:label' => 'Status',
  'join_date' => 'Eintritt',
  'end_date' => 'Ende',
], 'civicrm/demo/members');
echo "seeded the members search page\n";
