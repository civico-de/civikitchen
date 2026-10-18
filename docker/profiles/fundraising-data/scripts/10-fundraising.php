<?php
// Seed the fundraising world: the NGO itself, two campaigns, donors with a
// year of varied contribution history, pledges with installment schedules,
// and recurring donors. Runs via `cv scr` with CiviCRM booted. Idempotent:
// skips entirely when the NGO org already exists.

require_once __DIR__ . '/../../lib.php';

use Civi\Api4\Campaign;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;

if (ck_seed_org_exists('Spendenwerk Musterstadt e.V.')) {
  echo "fundraising already seeded, skipping\n";
  return;
}

$orgId = ck_seed_org('Spendenwerk Musterstadt e.V.', 'spenden@spendenwerk.example.de', ['Stiftungsallee 8', '48143', 'Münster']);
echo "created Spendenwerk Musterstadt e.V. (contact {$orgId})\n";

$campaigns = [
  ['jahresend2026', 'Jahresendkampagne 2026', 'Year-end appeal', '-2 months', '+1 month'],
  ['vereinsheim', 'Neues Vereinsheim', 'Capital campaign for the new clubhouse', '-8 months', '+10 months'],
];
$campaignIds = [];
foreach ($campaigns as [$name, $title, $desc, $start, $end]) {
  $c = Campaign::create(FALSE)
    ->addValue('name', $name)
    ->addValue('title', $title)
    ->addValue('description', $desc)
    ->addValue('campaign_type_id:name', 'Direct Mail')
    ->addValue('status_id:name', 'In Progress')
    ->addValue('start_date', date('Y-m-d', strtotime($start)))
    ->addValue('end_date', date('Y-m-d', strtotime($end)))
    ->addValue('is_active', TRUE)
    ->execute()->first();
  $campaignIds[$name] = $c['id'];
}
echo "created 2 campaigns\n";

ck_seed_group('grossspender', 'Großspender');
ck_seed_group('dauerspender', 'Dauerspender');

// [first, last, street, plz, city] — donors with varied giving patterns below.
$donors = [
  ['Elke', 'Brandt', 'Akazienweg 3', '50823', 'Köln'],
  ['Holger', 'Voigt', 'Parkstraße 21', '22607', 'Hamburg'],
  ['Renate', 'Seidel', 'Blumenstraße 9', '04177', 'Leipzig'],
  ['Norbert', 'Engel', 'Im Winkel 5', '79098', 'Freiburg'],
  ['Christa', 'Horn', 'Steinweg 44', '35037', 'Marburg'],
  ['Volker', 'Pohl', 'Lessingstraße 2', '07743', 'Jena'],
  ['Angelika', 'Busch', 'Fasanenweg 18', '70565', 'Stuttgart'],
  ['Reinhard', 'Sauer', 'Domplatz 1', '48143', 'Münster'],
  ['Marion', 'Arnold', 'Brunnenstraße 30', '10119', 'Berlin'],
  ['Gerhard', 'Pfeiffer', 'Kapellenweg 7', '93049', 'Regensburg'],
  ['Silke', 'Bergmann', 'Erlenweg 12', '33602', 'Bielefeld'],
  ['Detlef', 'Kuhn', 'Schlossstraße 16', '14059', 'Berlin'],
  ['Ute', 'Franke', 'Mozartstraße 4', '76133', 'Karlsruhe'],
  ['Bernd', 'Albrecht', 'Hafenstraße 23', '18055', 'Rostock'],
  ['Iris', 'Schubert', 'Weinbergweg 6', '97070', 'Würzburg'],
  ['Axel', 'Winkler', 'Friedensplatz 11', '44135', 'Dortmund'],
  ['Carola', 'Vogel', 'Birkenstraße 27', '01097', 'Dresden'],
  ['Helmut', 'Otto', 'Gartenfeldstraße 8', '55118', 'Mainz'],
];

$amounts = [25.00, 50.00, 100.00, 250.00, 75.00, 500.00];
$donorIds = [];
$contribs = 0;
foreach ($donors as $i => [$first, $last, $street, $plz, $city]) {
  $cid = ck_seed_individual($first, $last, ck_seed_email($first, $last, 'spenden.example.de'), [$street, $plz, $city]);
  $donorIds[] = $cid;

  // 1-4 donations over the past 14 months; every third donor gives to a
  // campaign; amounts cycle so reports/charts have spread.
  $n = 1 + ($i % 4);
  for ($d = 0; $d < $n; $d++) {
    $create = Contribution::create(FALSE)
      ->addValue('contact_id', $cid)
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('total_amount', $amounts[($i + $d) % count($amounts)])
      ->addValue('currency', 'EUR')
      ->addValue('receive_date', date('Y-m-d', strtotime('-' . (1 + (($i * 3 + $d * 5) % 14)) . ' months')))
      ->addValue('contribution_status_id:name', 'Completed')
      ->addValue('source', 'Spende');
    if ($i % 3 === 0) {
      $create->addValue('campaign_id', $campaignIds[$d % 2 === 0 ? 'jahresend2026' : 'vereinsheim']);
    }
    $create->execute();
    $contribs++;
  }

  if ($i % 6 === 0) {
    ck_seed_group_contact($cid, 'grossspender');
  }
}
echo 'created ' . count($donorIds) . " donors with {$contribs} contributions\n";

// Recurring donors: an active monthly ContributionRecur plus this month's
// installment, so the recurring-contributions screens have live data.
$recur = 0;
foreach (array_slice($donorIds, 0, 6) as $cid) {
  $r = ContributionRecur::create(FALSE)
    ->addValue('contact_id', $cid)
    ->addValue('amount', 15.00)
    ->addValue('currency', 'EUR')
    ->addValue('frequency_unit', 'month')
    ->addValue('frequency_interval', 1)
    ->addValue('start_date', date('Y-m-d', strtotime('-6 months')))
    ->addValue('contribution_status_id:name', 'In Progress')
    ->addValue('financial_type_id:name', 'Donation')
    ->execute()->first();
  Contribution::create(FALSE)
    ->addValue('contact_id', $cid)
    ->addValue('contribution_recur_id', $r['id'])
    ->addValue('financial_type_id:name', 'Donation')
    ->addValue('total_amount', 15.00)
    ->addValue('currency', 'EUR')
    ->addValue('receive_date', date('Y-m-d', strtotime('-1 week')))
    ->addValue('contribution_status_id:name', 'Completed')
    ->addValue('source', 'Dauerspende')
    ->execute();
  ck_seed_group_contact($cid, 'dauerspender');
  $recur++;
}
echo "created {$recur} recurring donors\n";

// Pledges via API3 — its BAO builds the installment schedule (PledgePayments),
// which the API4 entity does not do.
$pledges = 0;
foreach (array_slice($donorIds, 6, 4) as $cid) {
  civicrm_api3('Pledge', 'create', [
    'contact_id' => $cid,
    'amount' => 600.00,
    'original_installment_amount' => 50.00,
    'currency' => 'EUR',
    'installments' => 12,
    'frequency_unit' => 'month',
    'frequency_interval' => 1,
    'frequency_day' => 1,
    'start_date' => date('Y-m-d', strtotime('-2 months')),
    'create_date' => date('Y-m-d', strtotime('-2 months')),
    'financial_type_id' => 1,
    'status_id' => 'In Progress',
    'check_permissions' => 0,
  ]);
  $pledges++;
}
echo "created {$pledges} pledges\n";
