<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Tests\Feature;

use Asignua\FilamentScheduledReports\Enums\RunStatus;
use Asignua\FilamentScheduledReports\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The whole path with nothing faked: the scheduler command pushes a job onto a database queue, and a
 * separate `queue:work` process, with no HTTP request, no session and no Livewire snapshot, builds the
 * page from stored plain state, runs the table's own query as the owner, writes the workbook and sends
 * the mail (through the log mailer, so the message can be read back).
 */
class WorkerEndToEndTest extends TestCase
{
    private string $database;

    private string $log;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->database ??= sys_get_temp_dir().'/sr-worker-'.bin2hex(random_bytes(6)).'.sqlite';
        touch($this->database);

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', $this->database);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database.table', 'jobs');
    }

    /**
     * The worker is another process, so it sees only committed rows: a real file database instead of a
     * transaction that is rolled back.
     */
    protected function refreshTestDatabase(): void
    {
        $this->artisan('migrate');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The worker writes to the real local disk, so this process must read from it too (the base class fakes it).
        config(['filesystems.disks.local.root' => storage_path('app/private')]);
        app('filesystem')->forgetDisk('local');
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('filament-xlsx-exports');

        parent::tearDown();

        @unlink($this->database);
    }

    public function test_a_queue_worker_process_builds_and_mails_the_report(): void
    {
        $acme = $this->seedOrders();
        $report = $this->makeReport([
            'name' => 'Acme new orders',
            'state' => [
                'version' => 1,
                'filters' => ['status' => ['value' => 'new'], 'customer' => ['value' => (string) $acme->id]],
                'search' => 'Bo',
                'column_searches' => [],
                'sort' => 'amount:desc',
                'tab' => null,
                'export' => ['columns' => ['name', 'amount']],
            ],
        ]);

        $this->artisan('scheduled-reports:run')->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->count(), 'The scheduler command did not queue the job.');
        $this->assertSame(0, $report->runs()->where('status', RunStatus::Success)->count(), 'Nothing may run in this process.');

        $this->log = dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel/storage/logs/laravel.log';
        @unlink($this->log);

        $output = $this->worker();

        $run = $report->runs()->firstOrFail();
        $this->assertSame(RunStatus::Success, $run->status, 'Worker output: '.$output);
        $this->assertSame(2, $run->rows);
        $this->assertSame('attached', $run->delivery);
        $this->assertSame(0, DB::table('jobs')->count(), 'The worker left jobs behind: '.$output);
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'A job failed: '.(string) DB::table('failed_jobs')->value('exception'));

        // The rows the saved filters select, in the saved order.
        $names = array_values(array_filter(array_column($this->workbookRows($run), 0), static fn (mixed $cell): bool => in_array($cell, ['Boat', 'Bolt', 'Bolt2', 'Bolt3', 'Nut'], true)));
        $this->assertSame(['Boat', 'Bolt'], $names);

        $mail = (string) file_get_contents($this->log);
        $this->assertStringContainsString('Subject: Report: Acme new orders', $mail);
        $this->assertMatchesRegularExpression('/filename=.*\.xlsx/', $mail);
        $this->assertStringContainsString('List-Unsubscribe:', $mail);
    }

    public function test_a_worker_process_pauses_a_report_whose_owner_lost_access(): void
    {
        $this->seedOrders();
        $report = $this->makeReport();
        auth()->user()->update(['is_active' => false]);

        $this->artisan('scheduled-reports:run')->assertSuccessful();
        $this->worker();

        $this->assertSame(RunStatus::Paused, $report->runs()->firstOrFail()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function worker(): string
    {
        $env = [
            ...getenv(),
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->database,
            'QUEUE_CONNECTION' => 'database',
            'MAIL_MAILER' => 'log',
            'MAIL_FROM_ADDRESS' => 'reports@example.test',
            'LOG_CHANNEL' => 'single',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ];
        $pipes = [];
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/testbench', 'queue:work', 'database', '--stop-when-empty', '--tries=1'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
        $this->assertIsResource($process);

        $output = trim((string) stream_get_contents($pipes[1])).trim((string) stream_get_contents($pipes[2]));
        proc_close($process);

        return $output;
    }
}
