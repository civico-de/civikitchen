<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Seed;

use DateTimeImmutable;
use RuntimeException;

/**
 * The calendar unit a seed relies on: which period a snapshot belongs to, how
 * long it stays valid, and where a generator may place a record so that a
 * forward date shift inside the validity window keeps it in its period.
 */
final class Calendar
{
    public const UNITS = ['year', 'month', 'none'];

    /** Days of the shortest period, so a record clamped to a period's first day survives the shift. */
    private const SHORTEST = ['year' => 365, 'month' => 28];

    public function __construct(public readonly string $unit)
    {
        if (!in_array($unit, self::UNITS, true)) {
            throw new RuntimeException("unknown calendar unit '{$unit}' (year, month, none)");
        }
    }

    public static function day(string $date): DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new RuntimeException("not a Y-m-d date: '{$date}'");
        }
        return $day;
    }

    /** The day in force: CK_SEED_TODAY when set, else the container's date. */
    public static function today(): DateTimeImmutable
    {
        $override = getenv('CK_SEED_TODAY');
        return self::day(is_string($override) && $override !== '' ? $override : date('Y-m-d'));
    }

    public static function days(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400);
    }

    /** '2026' for year, '2026-10' for month, '' for none. */
    public function period(DateTimeImmutable $day): string
    {
        return match ($this->unit) {
            'year' => $day->format('Y'),
            'month' => $day->format('Y-m'),
            default => '',
        };
    }

    public function start(DateTimeImmutable $day, int $ago = 0): DateTimeImmutable
    {
        return match ($this->unit) {
            'year' => $day->setDate((int) $day->format('Y') - $ago, 1, 1),
            'month' => $day->setDate((int) $day->format('Y'), (int) $day->format('n') - $ago, 1),
            default => throw new RuntimeException('calendar unit none has no periods'),
        };
    }

    public function end(DateTimeImmutable $day, int $ago = 0): DateTimeImmutable
    {
        $next = match ($this->unit) {
            'year' => $this->start($day, $ago)->modify('+1 year'),
            'month' => $this->start($day, $ago)->modify('+1 month'),
            default => throw new RuntimeException('calendar unit none has no periods'),
        };
        return $next->modify('-1 day');
    }

    /**
     * The last day a snapshot generated on $generated may be restored: the end of
     * its period, or earlier where a period is shorter than the one before.
     */
    public function validUntil(DateTimeImmutable $generated): ?DateTimeImmutable
    {
        if ($this->unit === 'none') {
            return null;
        }
        $shortest = $generated->modify('+' . (self::SHORTEST[$this->unit] - 1) . ' days');
        $end = $this->end($generated);
        return $shortest < $end ? $shortest : $end;
    }

    public function covers(DateTimeImmutable $generated, DateTimeImmutable $today): bool
    {
        $until = $this->validUntil($generated);
        return $today >= $generated && ($until === null || $today <= $until);
    }

    /**
     * The latest day a record of the period $ago periods back may fall on, so
     * that a shift by up to the validity window keeps it in that period.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable} first and last allowed day
     */
    public function window(DateTimeImmutable $today, int $ago): array
    {
        $start = $this->start($today, $ago);
        if ($ago === 0) {
            return [$start, $today];
        }
        $slack = self::days($today, $this->validUntil($today) ?? $today);
        $latest = $this->end($today, $ago)->modify("-{$slack} days");
        return [$start, $latest < $start ? $start : $latest];
    }
}
