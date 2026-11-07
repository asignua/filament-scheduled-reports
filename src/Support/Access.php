<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Resources\Pages\Page as ResourcePage;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Throwable;

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

    /**
     * Whether `$page` is still one of the panel's own pages (a resource page counts when the resource is
     * registered and still lists the page).
     */
    public static function pageOnPanel(Panel $panel, string $page): bool
    {
        if (is_subclass_of($page, ResourcePage::class)) {
            $resource = $page::getResource();

            if (!in_array($resource, $panel->getResources(), true)) {
                return false;
            }

            foreach ($resource::getPages() as $registration) {
                if ($registration->getPage() === $page) {
                    return true;
                }
            }

            return false;
        }

        return in_array($page, $panel->getPages(), true);
    }

    /**
     * The check the download route runs before it hands a stored report file out: the same questions a run
     * asks, because the link outlives the run.
     *
     * @param array<string, mixed> $data `page` and `panel`, put in the file's sidecar by the runner
     */
    public static function canDownload(Authenticatable $user, array $data): bool
    {
        $page = $data['page'] ?? null;
        $panelId = $data['panel'] ?? null;

        if (!is_string($page) || !is_string($panelId) || !self::canSchedule($user, $page)) {
            return false;
        }

        $previous = Filament::getCurrentPanel();

        try {
            $panel = Filament::getPanel($panelId);

            if ($panel === null || !self::pageOnPanel($panel, $page)) {
                return false;
            }

            Filament::setCurrentPanel($panel);

            return !method_exists($page, 'canAccess') || $page::canAccess();
        } catch (Throwable) {
            return false;
        } finally {
            Filament::setCurrentPanel($previous);
        }
    }

    public static function canManageAll(?Authenticatable $user): bool
    {
        if ($user === null || !Gate::has(self::MANAGE)) {
            return false;
        }

        return Gate::forUser($user)->allows(self::MANAGE);
    }
}
