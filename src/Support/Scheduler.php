<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

use Asignua\FilamentScheduledReports\Enums\RunStatus;
use Asignua\FilamentScheduledReports\Jobs\RunScheduledReportJob;
use Asignua\FilamentScheduledReports\Models\ScheduledReport;
use Asignua\FilamentScheduledReports\Models\ScheduledReportRun;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Finds the reports that are due, wins the right to run each one, and queues the job.
 */
final class Scheduler
{
    public const string FORMAT = 'Y-m-d H:i:s';

    public static function nextFor(ScheduledReport $report, ?CarbonInterface $after = null): string
    {
        return NextRun::after(
            $after ?? now(),
            $report->frequency,
            $report->time,
            $report->days,
            $report->day_of_month,
            $report->timezone,
        )->utc()->format(self::FORMAT);
    }

    /**
     * Queues every due report once. A report that was due several times during downtime still runs once:
     * its next time is worked out from now, not from the missed time, so there is no burst of catch-up mails.
     *
     * @return int how many runs this call started
     */
    public static function dispatchDue(?CarbonInterface $now = null): int
    {
        $now = ($now ?? now())->copy()->utc();
        $started = 0;

        /** @var Builder<ScheduledReport> $due */
        $due = ScheduledReport::query()
            ->where('status', 'active')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now->format(self::FORMAT));

        $due->orderBy('id')->each(function (ScheduledReport $report) use ($now, &$started): void {
            $expected = $report->getRawOriginal('next_run_at');

            if (!is_string($expected)) {
                return;
            }

            $won = Claim::advance(DB::connection($report->getConnectionName()), $report->getTable(), $report->id, $expected, self::nextFor($report, $now));

            if ($won) {
                self::start($report, false);
                $started++;
            }
        });

        return $started;
    }

    /**
     * "Run now". Does not touch the schedule. At most one click per `manual_interval_seconds`, decided
     * by one atomic UPDATE so a double click or two tabs cannot queue two.
     */
    public static function runNow(ScheduledReport $report): ?ScheduledReportRun
    {
        $interval = max(0, (int) config('filament-scheduled-reports.manual_interval_seconds', 60));
        $now = now()->utc();

        $won = DB::connection($report->getConnectionName())->table($report->getTable())
            ->where('id', $report->id)
            ->where(static fn ($query) => $query->whereNull('last_manual_run_at')->orWhere('last_manual_run_at', '<=', $now->copy()->subSeconds($interval)->format(self::FORMAT)))
            ->update(['last_manual_run_at' => $now->format(self::FORMAT)]) === 1;

        return $won ? self::start($report, true) : null;
    }

    private static function start(ScheduledReport $report, bool $manual): ScheduledReportRun
    {
        $run = ScheduledReportRun::query()->create([
            'scheduled_report_id' => $report->id,
            'status' => RunStatus::Queued,
            'manual' => $manual,
            'warnings' => [],
        ]);

        try {
            RunScheduledReportJob::dispatch($report->id, $run->id);
        } catch (Throwable $exception) {
            $run->update(['status' => RunStatus::Failed, 'reason' => mb_substr($exception->getMessage(), 0, 500), 'finished_at' => now()->utc()->format(self::FORMAT)]);

            throw $exception;
        }

        return $run;
    }
}
