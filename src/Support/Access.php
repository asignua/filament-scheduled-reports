<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * The two gates, both denied until the application defines them: a plugin that can email data out
 * of the panel must not switch itself on for everyone.
 *
 * - `scheduleReport` (user, page class): may this user schedule a report of this page?
 * - `manageScheduledReports` (user): may this user see and pause everybody's reports?
 */
final class Access
{
    public const string SCHEDULE = 'scheduleReport';

    public const string MANAGE = 'manageScheduledReports';

    public static function canSchedule(?Authenticatable $user, ?string $page = null): bool
    {
        if ($user === null || !Gate::has(self::SCHEDULE)) {
            return false;
        }

        return Gate::forUser($user)->allows(self::SCHEDULE, [$page]);
    }

    public static function canManageAll(?Authenticatable $user): bool
    {
        if ($user === null || !Gate::has(self::MANAGE)) {
            return false;
        }

        return Gate::forUser($user)->allows(self::MANAGE);
    }
}
