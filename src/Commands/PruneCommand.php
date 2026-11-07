<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Commands;

use Asignua\FilamentScheduledReports\Models\ScheduledReportRun;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'scheduled-reports:prune';

    protected $description = 'Delete report history older than filament-scheduled-reports.history_days.';

    public function handle(): int
    {
        $days = max(1, (int) config('filament-scheduled-reports.history_days', 90));
        $deleted = ScheduledReportRun::query()->where('created_at', '<', now()->subDays($days))->delete();

        $this->info("{$deleted} run(s) deleted.");

        return self::SUCCESS;
    }
}
