<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

use Asignua\FilamentScheduledReports\Mail\ProblemMail;
use Asignua\FilamentScheduledReports\Models\ScheduledReport;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the owner something went wrong. Mail always (a panel need not have database notifications),
 * a panel notification as well only when the panel has them switched on.
 */
final class Notifier
{
    public static function tell(?Authenticatable $owner, ScheduledReport $report, string $title, string $detail): void
    {
        if ($owner === null) {
            return;
        }

        $email = Recipients::email($owner);

        if ($email !== null) {
            Mail::to($email)->queue(new ProblemMail($report->name, $title, $detail));
        }

        try {
            $panel = Filament::getPanel($report->panel);

            if ($panel?->hasDatabaseNotifications()) {
                Notification::make()->warning()->title($title)->body($report->name.': '.$detail)->sendToDatabase($owner);
            }
        } catch (Throwable) {
            // The mail has gone; a missing panel is the very thing that may be being reported.
        }
    }
}
