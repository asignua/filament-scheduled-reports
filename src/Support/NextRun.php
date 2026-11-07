<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

use Asignua\FilamentScheduledReports\Enums\Frequency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Works out when a report is due next. The schedule is written in the owner's wall-clock time
 * ("Mondays at 09:00 Kyiv"), the answer is an instant in UTC.
 *
 * Wall-clock time is not a function onto instants twice a year, so the two awkward cases are decided
 * here, not left to PHP's silent adjustments: a time that does not exist (the hour skipped when the
 * clocks go forward) becomes the first minute that does exist, and a time that happens twice (the hour
 * repeated when they go back) is the first of the two. Either way a report fires once a day, never twice.
 */
final class NextRun
{
    /** Far enough for a monthly report on the 31st to find a month that has one, with room to spare. */
    private const int HORIZON_DAYS = 800;

    /**
     * The first due instant strictly after `$after`.
     *
     * @param list<int>|null $days ISO weekdays (1 = Monday ... 7 = Sunday), for the weekly frequency
     */
    public static function after(CarbonInterface $after, Frequency $frequency, string $time, ?array $days, ?int $dayOfMonth, string $timezone): CarbonImmutable
    {
        [$hour, $minute] = self::split($time);
        $zone = new DateTimeZone($timezone);
        $afterUtc = CarbonImmutable::instance($after)->utc();
        $local = $afterUtc->setTimezone($zone);

        // A plain calendar walk: dates are added in UTC so that a DST change cannot skip or repeat a day.
        $date = CarbonImmutable::create($local->year, $local->month, $local->day, 0, 0, 0, 'UTC') ?? throw new \LogicException('Cannot build the start date.');

        for ($i = 0; $i <= self::HORIZON_DAYS; $i++) {
            $day = $date->addDays($i);

            if (!self::matches($day, $frequency, $days, $dayOfMonth)) {
                continue;
            }

            $instant = self::instant($day->year, $day->month, $day->day, $hour, $minute, $zone);

            if ($instant->greaterThan($afterUtc)) {
                return $instant;
            }
        }

        // Unreachable for a valid schedule (every frequency matches within a year); a loud failure
        // is better than a report that silently never runs.
        throw new \InvalidArgumentException('The schedule never fires.');
    }

    /**
     * @param list<int>|null $days
     */
    private static function matches(CarbonImmutable $day, Frequency $frequency, ?array $days, ?int $dayOfMonth): bool
    {
        return match ($frequency) {
            Frequency::Daily => true,
            Frequency::Weekly => in_array($day->isoWeekday(), $days ?? [], true),
            // The 31st in a 28-day month is the month's last day, not the 3rd of the next one.
            Frequency::Monthly => $day->day === min(max(1, $dayOfMonth ?? 1), $day->daysInMonth),
        };
    }

    /**
     * @return array{int, int}
     */
    private static function split(string $time): array
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $parts) !== 1) {
            throw new \InvalidArgumentException("Invalid time [{$time}], expected HH:MM.");
        }

        return [(int) $parts[1], (int) $parts[2]];
    }

    private static function instant(int $year, int $month, int $day, int $hour, int $minute, DateTimeZone $zone): CarbonImmutable
    {
        $wall = gmmktime($hour, $minute, 0, $month, $day, $year);

        // A wall time inside the gap has no instant: move on to the first minute that has one.
        for ($step = 0; $step <= 24 * 60; $step++) {
            $found = self::earliest($wall + $step * 60, $zone);

            if ($found !== null) {
                return CarbonImmutable::createFromTimestampUTC($found);
            }
        }

        throw new \InvalidArgumentException('No valid instant found for the wall time.');
    }

    /**
     * The earliest UTC timestamp whose local wall clock reads `$wall` (a timestamp taken as if it
     * were UTC), or null when no instant does.
     */
    private static function earliest(int $wall, DateTimeZone $zone): ?int
    {
        // The offsets in force a day either side cover both sides of any transition near `$wall`.
        $offsets = [
            $zone->getOffset(new DateTimeImmutable('@'.($wall - 86400))),
            $zone->getOffset(new DateTimeImmutable('@'.($wall + 86400))),
        ];

        $valid = [];

        foreach (array_unique($offsets) as $offset) {
            $utc = $wall - $offset;

            // The candidate holds only if the zone really is on that offset at that instant.
            if ($zone->getOffset(new DateTimeImmutable('@'.$utc)) === $offset) {
                $valid[] = $utc;
            }
        }

        return $valid === [] ? null : min($valid);
    }
}
