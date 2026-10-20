<?php

declare(strict_types=1);

// The profile helpers against recording APIv4/APIv3 stubs: what lib.php and
// seed-lib.php hand to core, and where the period buckets put records.

namespace Civi\Api4 {
  final class Call {
    /** @var list<array{string, string, array<string, mixed>}> */
    public static array $log = [];
    private static int $id = 0;

    /** @var array<string, mixed> */
    private array $values = [];

    public function __construct(private readonly string $entity, private readonly string $action) {
    }

    public function __call(string $method, array $arguments): self {
      match ($method) {
        'addValue' => $this->values[$arguments[0]] = $arguments[1],
        'addWhere' => $this->values['where:' . $arguments[0]] = $arguments[2],
        'setContributionValues' => $this->values += $arguments[0],
        'addLineItem' => $this->values['line_items'][] = $arguments[0],
        default => throw new \LogicException("unexpected {$method}"),
      };
      return $this;
    }

    public function execute(): self {
      self::$log[] = [$this->entity, $this->action, $this->values];
      return $this;
    }

    /** @return array{id: int} */
    public function single(): array {
      return ['id' => ++self::$id];
    }

    /** @return array{id: int} */
    public function first(): array {
      return $this->single();
    }
  }

  abstract class Entity {
    public static function create(bool $check): Call {
      return new Call((new \ReflectionClass(static::class))->getShortName(), 'create');
    }

    public static function update(bool $check): Call {
      return new Call((new \ReflectionClass(static::class))->getShortName(), 'update');
    }
  }

  final class Contact extends Entity {}
  final class Email extends Entity {}
  final class Address extends Entity {}
  final class Order extends Entity {}
  final class Contribution extends Entity {}
}

namespace {
  use Civi\Api4\Call;

  function civicrm_api3(string $entity, string $action, array $params): array {
    Call::$log[] = [$entity, $action, $params];
    return ['is_error' => 0];
  }

  function seed_lib_expect(bool $condition, string $message): void {
    if (!$condition) {
      fwrite(STDERR, "FAIL: {$message}\n");
      exit(1);
    }
  }

  putenv('CK_SEED_TODAY');
  require dirname(__DIR__, 2) . '/docker/profiles/seed-lib.php';

  try {
    ck_seed_today();
    seed_lib_expect(FALSE, 'a script without CK_SEED_TODAY must not fall back to the wall clock');
  }
  catch (RuntimeException $e) {
  }

  // Address.country_id:name matches civicrm_country.iso_code; a country name
  // matches nothing and leaves the address without a country.
  ck_seed_individual('Erika', 'Muster', 'erika@example.org', ['Weg 1', '12345', 'Musterstadt']);
  ck_seed_org('Sample e.V.', NULL, ['Platz 2', '12345', 'Musterstadt']);
  $countries = array_column(array_map(static fn(array $call): array => $call[2], array_filter(Call::$log,
    static fn(array $call): bool => $call[0] === 'Address')), 'country_id:name');
  seed_lib_expect($countries === ['DE', 'DE'], 'addresses name their country by ISO code, got ' . json_encode($countries));

  // Each status goes through Order (pending), then the steps core books it with.
  $expected = [
    'Pending' => [],
    'Completed' => ['Payment create 75'],
    'Partially paid' => ['Payment create 37.5'],
    'Refunded' => ['Payment create 75', 'Contribution update Refunded'],
    'Cancelled' => ['Contribution update Cancelled'],
    'Failed' => ['Contribution update Failed'],
  ];
  foreach ($expected as $status => $steps) {
    Call::$log = [];
    $id = ck_seed_contribution(7, 75.00, '2027-03-01 10:00:00', $status, 'Donation', ['source' => 'fixture']);
    [$order, $rest] = [array_shift(Call::$log), Call::$log];
    seed_lib_expect($order[0] === 'Order' && $order[2]['contribution_status_id:name'] === 'Pending'
      && $order[2]['source'] === 'fixture' && $order[2]['line_items'][0]['line_total'] === 75.00, "{$status}: a pending order first");
    $seen = array_map(static fn(array $call): string => "{$call[0]} {$call[1]} "
      . ($call[0] === 'Payment' ? $call[2]['total_amount'] : $call[2]['contribution_status_id:name']), $rest);
    seed_lib_expect($seen === $steps, "{$status}: " . json_encode($seen));
    foreach ($rest as $call) {
      seed_lib_expect($call[0] !== 'Payment' || ($call[2]['is_send_contribution_notification'] === 0 && $call[2]['contribution_id'] === $id),
        "{$status}: payments send no receipt");
      seed_lib_expect($call[0] !== 'Contribution' || ($call[2]['cancel_date'] === '2027-03-01 10:00:00' && $call[2]['where:id'] === $id),
        "{$status}: a status change dates its cancellation");
    }
  }

  // The same seed draws the same dates, and every bucket holds its period on
  // a generation day at the start, middle and end of a year.
  foreach (['2027-01-02', '2027-06-30', '2027-12-30'] as $today) {
    putenv("CK_SEED_TODAY={$today}");
    seed_lib_expect(ck_seed_today()->format('Y-m-d') === $today, 'CK_SEED_TODAY is the generation day');
    $draw = static function (): array {
      $rng = ck_seed_rng(5);
      $dates = [];
      for ($i = 0; $i < 200; $i++) {
        $dates[] = [$i % 2, ck_seed_date_in($rng, 'year', $i % 2)];
      }
      return $dates;
    };
    $dates = $draw();
    seed_lib_expect($dates === $draw(), 'one seed, one set of dates');
    $year = (int) substr($today, 0, 4);
    foreach ($dates as [$ago, $date]) {
      seed_lib_expect((int) substr($date, 0, 4) === $year - $ago, "{$today}: {$date} belongs to {$ago} years back");
      seed_lib_expect($ago === 1 || substr($date, 0, 10) <= $today, "{$today}: {$date} is no later than today");
      $hour = (int) substr($date, 11, 2);
      seed_lib_expect($hour >= 8 && $hour <= 18, "{$date} is at a working hour");
    }
  }

  echo "seed-lib: ok\n";
}
