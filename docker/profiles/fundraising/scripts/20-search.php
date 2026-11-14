<?php
// A SearchKit table of donors with their completed donations (membership fees
// excluded), shown as an Afform
// page at civicrm/demo/donors.

require_once __DIR__ . '/../../lib.php';

ck_seed_search_page('DemoDonors', 'Spender:innen', 'Contribution', [
  'select' => ['contact_id.display_name', 'SUM(total_amount) AS SUM_total_amount', 'COUNT(id) AS COUNT_id', 'MAX(receive_date) AS MAX_receive_date'],
  'where' => [['contribution_status_id:name', '=', 'Completed'], ['financial_type_id:name', '=', 'Donation']],
  'groupBy' => ['contact_id', 'contact_id.display_name'],
  'orderBy' => ['SUM_total_amount' => 'DESC'],
], [
  'contact_id.display_name' => 'Kontakt',
  'SUM_total_amount' => 'Summe',
  'COUNT_id' => 'Zuwendungen',
  'MAX_receive_date' => 'Letzte Zuwendung',
], 'civicrm/demo/donors');
echo "seeded the donors search page\n";
