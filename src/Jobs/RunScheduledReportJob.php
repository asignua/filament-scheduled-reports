<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Jobs;

use Asignua\FilamentScheduledReports\Support\ReportRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * One run of one report. One try: a report that failed halfway is not retried into a second mail;
 * the next scheduled time (or "Run now") builds it again from scratch.
 */
final class RunScheduledReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly int $reportId, public readonly int $runId)
    {
        $this->timeout = max(30, (int) config('filament-scheduled-reports.timeout', 600));

        $connection = config('filament-scheduled-reports.run_connection');
        $queue = config('filament-scheduled-reports.run_queue');

        if (is_string($connection)) {
            $this->onConnection($connection);
        }

        if (is_string($queue)) {
            $this->onQueue($queue);
        }
    }

    public function handle(): void
    {
        ReportRunner::execute($this->reportId, $this->runId);
    }

    /**
     * Also reached when the worker kills the job on timeout, which no catch block inside `handle()` sees.
     */
    public function failed(Throwable $exception): void
    {
        ReportRunner::recordFailure($this->reportId, $this->runId, $exception);
    }
}
