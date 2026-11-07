<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Commands;

use Asignua\FilamentScheduledReports\Models\ScheduledReportRun;
use Asignua\FilamentXlsxExport\Support\QueuedExports;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'scheduled-reports:prune';

    protected $description = 'Delete report history older than filament-scheduled-reports.history_days.';

    public function handle(): int
    {
        $days = max(1, (int) config('filament-scheduled-reports.history_days', 90));
        $old = ScheduledReportRun::query()->where('created_at', '<', now()->subDays($days));

        // The files of the runs that go: the export's own prune may be switched off or set to "never".
        $disk = QueuedExports::disk();

        foreach ((clone $old)->whereNotNull('file_id')->pluck('file_id') as $id) {
            $disk->delete([QueuedExports::filePath((string) $id), QueuedExports::metaPath((string) $id)]);
        }

        $deleted = $old->delete();

        $this->info("{$deleted} run(s) deleted.");

        return self::SUCCESS;
    }
}
