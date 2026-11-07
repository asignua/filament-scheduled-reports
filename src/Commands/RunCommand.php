<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Commands;

use Asignua\FilamentScheduledReports\Support\Scheduler;
use Illuminate\Console\Command;

class RunCommand extends Command
{
    protected $signature = 'scheduled-reports:run';

    protected $description = 'Queue every scheduled report that is due (registered every minute by the plugin).';

    public function handle(): int
    {
        $this->info(Scheduler::dispatchDue().' report(s) queued.');

        return self::SUCCESS;
    }
}
