<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use Asignua\FilamentScheduledReports\Support\Unsubscribe;
use Illuminate\Console\Command;

/**
 * Tests only (tests/Databases): one contender for an unsubscribe, run as its own process. It waits for the barrier file so
 * that every contender fires at the same moment.
 */
class UnsubscribeProbe extends Command
{
    protected $signature = 'workbench:unsubscribe {ulid} {key} {barrier}';

    protected $description = 'Unsubscribe one recipient once the barrier file exists.';

    public function handle(): int
    {
        $deadline = microtime(true) + 30;

        while (!file_exists((string) $this->argument('barrier')) && microtime(true) < $deadline) {
            usleep(200);
        }

        $this->output->write(Unsubscribe::apply((string) $this->argument('ulid'), (string) $this->argument('key')) ? 'REMOVED' : 'UNCHANGED');

        return self::SUCCESS;
    }
}
