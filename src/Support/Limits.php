<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

use Asignua\FilamentScheduledReports\Models\ScheduledReport;

/**
 * The per-owner caps from the config, in one place so the action, the resource and the scheduler agree.
 */
final class Limits
{
    public static function maxReports(): int
    {
        return max(0, (int) config('filament-scheduled-reports.max_reports_per_owner', 20));
    }

    public static function maxRecipients(): int
    {
        return max(0, (int) config('filament-scheduled-reports.max_recipients', 25));
    }

    public static function maxConcurrent(): int
    {
        $own = config('filament-scheduled-reports.max_concurrent_per_owner');

        return max(0, (int) (is_numeric($own) ? $own : config('filament-xlsx-export.queue.max_per_user', 2)));
    }

    public static function reportsReached(string $guard, string $ownerId): bool
    {
        $max = self::maxReports();

        return $max > 0 && ScheduledReport::query()->where('guard', $guard)->where('owner_id', $ownerId)->count() >= $max;
    }

    public static function tooManyRecipients(int $count): bool
    {
        $max = self::maxRecipients();

        return $max > 0 && $count > $max;
    }
}
