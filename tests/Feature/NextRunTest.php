<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Tests\Feature;

use Asignua\FilamentScheduledReports\Enums\Frequency;
use Asignua\FilamentScheduledReports\Support\NextRun;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NextRunTest extends TestCase
{
    private function daily(string $after, string $time, string $zone): string
    {
        return NextRun::after(CarbonImmutable::parse($after, 'UTC'), Frequency::Daily, $time, null, null, $zone)->utc()->format('Y-m-d H:i');
    }

    public function test_a_daily_report_fires_today_when_the_time_has_not_come_yet(): void
    {
        // 08:00 Kyiv (UTC+2 in January) is 06:00 UTC; the report is at 09:00 Kyiv = 07:00 UTC.
        $this->assertSame('2027-01-15 07:00', $this->daily('2027-01-15 06:00:00', '09:00', 'Europe/Kyiv'));
    }

    public function test_it_is_strictly_after_the_given_instant(): void
    {
        $this->assertSame('2027-01-16 07:00', $this->daily('2027-01-15 07:00:00', '09:00', 'Europe/Kyiv'));
    }

    public function test_kyiv_spring_forward_gap_moves_to_the_first_valid_minute(): void
    {
        // 2027-03-28: at 03:00 Kyiv the clocks jump to 04:00, so 03:30 does not exist. The first valid
        // minute is 04:00 EEST = 01:00 UTC. (A naive "add the gap" would give 04:30.)
        $this->assertSame('2027-03-28 01:00', $this->daily('2027-03-27 20:00:00', '03:30', 'Europe/Kyiv'));
    }

    public function test_kyiv_fall_back_overlap_uses_the_first_occurrence(): void
    {
        // 2026-10-25: 04:00 EEST becomes 03:00 EET, so 03:30 happens twice. The first is 03:30 EEST = 00:30 UTC.
        $this->assertSame('2026-10-25 00:30', $this->daily('2026-10-24 12:00:00', '03:30', 'Europe/Kyiv'));
    }

    public function test_a_report_in_the_overlap_fires_once_that_day_not_twice(): void
    {
        $first = NextRun::after(CarbonImmutable::parse('2026-10-24 12:00:00', 'UTC'), Frequency::Daily, '03:30', null, null, 'Europe/Kyiv');
        $second = NextRun::after($first, Frequency::Daily, '03:30', null, null, 'Europe/Kyiv');

        $this->assertSame('2026-10-26 01:30', $second->utc()->format('Y-m-d H:i'));
    }

    public function test_new_york_spring_forward_gap(): void
    {
        // 2027-03-14: 02:00 EST jumps to 03:00 EDT; 02:30 does not exist -> 03:00 EDT = 07:00 UTC.
        $this->assertSame('2027-03-14 07:00', $this->daily('2027-03-13 12:00:00', '02:30', 'America/New_York'));
    }

    public function test_new_york_fall_back_overlap(): void
    {
        // 2026-11-01: 02:00 EDT becomes 01:00 EST, so 01:30 happens twice; the first is 01:30 EDT = 05:30 UTC.
        $this->assertSame('2026-11-01 05:30', $this->daily('2026-10-31 12:00:00', '01:30', 'America/New_York'));
    }

    public function test_a_normal_time_across_the_transition_keeps_its_wall_clock(): void
    {
        // 09:00 in Kyiv is 07:00 UTC before the October change and 07:00 UTC... no: 09:00 EET is 07:00 UTC, 09:00 EEST is 06:00 UTC.
        $this->assertSame('2026-10-24 06:00', $this->daily('2026-10-23 12:00:00', '09:00', 'Europe/Kyiv'));
        $this->assertSame('2026-10-26 07:00', $this->daily('2026-10-25 12:00:00', '09:00', 'Europe/Kyiv'));
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function monthEnds(): array
    {
        return [
            'february, common year' => ['2027-02-01 00:00:00', 31, '2027-02-28'],
            'february, leap year' => ['2028-02-01 00:00:00', 31, '2028-02-29'],
            'april has 30 days' => ['2027-04-01 00:00:00', 31, '2027-04-30'],
            'the 30th in february' => ['2027-02-01 00:00:00', 30, '2027-02-28'],
            'the 15th is the 15th' => ['2027-02-01 00:00:00', 15, '2027-02-15'],
        ];
    }

    #[DataProvider('monthEnds')]
    public function test_day_of_month_beyond_the_month_means_its_last_day(string $after, int $day, string $expectedDate): void
    {
        $next = NextRun::after(CarbonImmutable::parse($after, 'UTC'), Frequency::Monthly, '12:00', null, $day, 'UTC');

        $this->assertSame($expectedDate, $next->format('Y-m-d'));
    }

    public function test_the_31st_skips_to_the_next_month_once_the_last_day_has_passed(): void
    {
        $next = NextRun::after(CarbonImmutable::parse('2027-02-28 12:00:00', 'UTC'), Frequency::Monthly, '12:00', null, 31, 'UTC');

        $this->assertSame('2027-03-31', $next->format('Y-m-d'));
    }

    public function test_weekly_picks_the_next_chosen_weekday(): void
    {
        // 2027-01-15 is a Friday. Monday (1) and Thursday (4): the next is Monday 2027-01-18.
        $next = NextRun::after(CarbonImmutable::parse('2027-01-15 12:00:00', 'UTC'), Frequency::Weekly, '09:00', [1, 4], null, 'UTC');

        $this->assertSame('2027-01-18 09:00', $next->format('Y-m-d H:i'));
    }

    public function test_the_weekday_is_the_owners_not_utcs(): void
    {
        // 23:30 UTC on Sunday is already Monday 02:30 in Kyiv; a Monday 09:00 Kyiv report is due that morning.
        $next = NextRun::after(CarbonImmutable::parse('2027-01-17 23:30:00', 'UTC'), Frequency::Weekly, '09:00', [1], null, 'Europe/Kyiv');

        $this->assertSame('2027-01-18 07:00', $next->utc()->format('Y-m-d H:i'));
    }

    public function test_an_invalid_time_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        NextRun::after(CarbonImmutable::now(), Frequency::Daily, '25:00', null, null, 'UTC');
    }
}
