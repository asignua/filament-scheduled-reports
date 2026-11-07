<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

use Asignua\FilamentScheduledReports\Enums\PauseReason;
use Asignua\FilamentScheduledReports\Enums\ReportStatus;
use Asignua\FilamentScheduledReports\Models\ScheduledReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * The "unsubscribe" link in every mail: one signed URL per report and recipient. The signature is the
 * only credential (a recipient need not be able to sign in), so it is checked by the controller on
 * every request and a forged or edited link changes nothing.
 */
final class Unsubscribe
{
    public const string ROUTE = 'filament-scheduled-reports.unsubscribe';

    public const string ROUTE_CONFIRM = 'filament-scheduled-reports.unsubscribe.confirm';

    public static function url(ScheduledReport $report, string $key): string
    {
        $root = $report->url_root;
        $hasRoot = is_string($root) && $root !== '';

        // A worker has no request, so URLs would be built from APP_URL; the link should point at the
        // domain the owner scheduled the report from.
        if ($hasRoot) {
            URL::forceRootUrl($root);
        }

        try {
            return URL::signedRoute(self::ROUTE, ['report' => $report->ulid, 'recipient' => $key]);
        } finally {
            if ($hasRoot) {
                URL::forceRootUrl(null);
            }
        }
    }

    public static function valid(Request $request): bool
    {
        return $request->hasValidSignature();
    }

    /**
     * Takes the recipient off the list. Returns false when the report or the recipient is not there
     * (a second click, a deleted report): nothing to do, nothing to reveal.
     */
    public static function apply(string $ulid, string $key): bool
    {
        // Read, change and write under a row lock: two people unsubscribing at the same moment (a mail scanner and
        // a click, or two recipients of one send) would otherwise each write the list they read and bring one back.
        return DB::transaction(static function () use ($ulid, $key): bool {
            $report = ScheduledReport::query()->where('ulid', $ulid)->lockForUpdate()->first();

            if ($report === null) {
                return false;
            }

            $kept = array_values(array_filter($report->recipients, static fn (array $recipient): bool => Recipients::key($recipient) !== $key));

            if (count($kept) === count($report->recipients)) {
                return false;
            }

            $report->recipients = $kept;

            // A manager's pause binds: the owner must not be able to undo it by emptying the list and re-adding themselves.
            if ($kept === [] && !$report->isLockedByManager()) {
                $report->status = ReportStatus::Paused;
                $report->paused_reason = PauseReason::NoRecipients;
                $report->next_run_at = null;
            }

            $report->save();

            return true;
        });
    }
}
