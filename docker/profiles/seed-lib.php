<?php
// Helpers for profile scripts whose data is stored as a snapshot (profile.json
// "snapshot"): dates from CK_SEED_TODAY, a seeded RNG, period buckets that
// survive the restore's date shift, and contributions through Order + Payment.
// Include from scripts/<file>.php with: require_once __DIR__ . '/../../seed-lib.php';
// An extension's own profile includes /opt/civikitchen/docker/profiles/seed-lib.php.

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../../toolbelt/lib/php/src/Seed/Calendar.php';

use Civi\Api4\Contribution;
use Civi\Api4\Order;
use CiviKitchen\Toolbelt\Seed\Calendar;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The generation day. A snapshot script never reads the wall clock: the
 * restore shifts dates by the days between this day and the restore day.
 */
function ck_seed_today(): DateTimeImmutable {
  $today = getenv('CK_SEED_TODAY');
  if (!is_string($today) || $today === '') {
    throw new RuntimeException('CK_SEED_TODAY is not set; the profile driver sets it for profile scripts');
  }
  return Calendar::day($today);
}

/**
 * A deterministic random source, so one input always yields the same data.
 */
function ck_seed_rng(int $seed = 1): Randomizer {
  return new Randomizer(new Mt19937($seed));
}

/**
 * A datetime in the period $ago periods before today's ('year' or 'month'),
 * placed so the restore's forward shift keeps it in that period.
 */
function ck_seed_date_in(Randomizer $rng, string $unit, int $ago): string {
  [$first, $last] = (new Calendar($unit))->window(ck_seed_today(), $ago);
  return ck_seed_time($rng, $first->modify('+' . $rng->getInt(0, Calendar::days($first, $last)) . ' days'));
}

/**
 * A datetime on $day at a working hour.
 */
function ck_seed_time(Randomizer $rng, DateTimeImmutable $day): string {
  return $day->setTime($rng->getInt(8, 18), $rng->getInt(0, 59))->format('Y-m-d H:i:s');
}

/**
 * Create a contribution the way the back office books one: Order (pending),
 * then Payment, then the status change for Refunded, Cancelled and Failed, so
 * core writes every financial row. Receipts stay off. Returns the id.
 *
 * @param string $status Completed, Pending, Partially paid, Refunded, Cancelled or Failed
 * @param array $values further contribution fields (source, campaign_id, contribution_recur_id, ...)
 */
function ck_seed_contribution(int $contactId, float $amount, string $date, string $status, string $financialType = 'Donation', array $values = []): int {
  $id = (int) Order::create(FALSE)
    ->setContributionValues([
      'contact_id' => $contactId,
      'financial_type_id:name' => $financialType,
      'receive_date' => $date,
      'contribution_status_id:name' => 'Pending',
    ] + $values)
    ->addLineItem([
      'line_total' => $amount,
      'unit_price' => $amount,
      'qty' => 1,
      'financial_type_id:name' => $financialType,
      'entity_table' => 'civicrm_contribution',
    ])
    ->execute()->single()['id'];
  $pay = static fn(float $total) => civicrm_api3('Payment', 'create', [
    'contribution_id' => $id,
    'total_amount' => $total,
    'trxn_date' => $date,
    'payment_instrument_id' => 'EFT',
    'is_send_contribution_notification' => 0,
  ]);
  $setStatus = static fn(string $to) => Contribution::update(FALSE)
    ->addWhere('id', '=', $id)
    ->addValue('contribution_status_id:name', $to)
    ->addValue('cancel_date', $date)
    ->execute();
  match ($status) {
    'Pending' => NULL,
    'Completed' => $pay($amount),
    'Partially paid' => $pay(round($amount / 2, 2)),
    'Refunded' => [$pay($amount), $setStatus('Refunded')],
    'Cancelled', 'Failed' => $setStatus($status),
  };
  return $id;
}
