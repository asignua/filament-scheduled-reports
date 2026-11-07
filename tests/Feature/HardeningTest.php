<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Tests\Feature;

use Asignua\FilamentScheduledReports\Enums\PauseReason;
use Asignua\FilamentScheduledReports\Enums\ReportStatus;
use Asignua\FilamentScheduledReports\Enums\RunStatus;
use Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\Pages\ListScheduledReports;
use Asignua\FilamentScheduledReports\Jobs\RunScheduledReportJob;
use Asignua\FilamentScheduledReports\Mail\ReportMail;
use Asignua\FilamentScheduledReports\Models\ScheduledReport;
use Asignua\FilamentScheduledReports\Models\ScheduledReportRun;
use Asignua\FilamentScheduledReports\ScheduledReportsPlugin;
use Asignua\FilamentScheduledReports\Support\ReportRunner;
use Asignua\FilamentScheduledReports\Support\Scheduler;
use Asignua\FilamentScheduledReports\Tests\TestCase;
use Asignua\FilamentXlsxExport\Support\QueuedExports;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Workbench\App\Filament\Pages\OrphanOrders;
use Workbench\App\Filament\Resources\Orders\OrderResource;
use Workbench\App\Filament\Resources\Orders\Pages\ListOrders;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;

class HardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    protected function tearDown(): void
    {
        OrderResource::$exportTweak = null;
        Order::clearBootedModels();

        parent::tearDown();
    }

    private function plugin(): ScheduledReportsPlugin
    {
        $plugin = ScheduledReportsPlugin::forPanel(Filament::getPanel('admin'));
        $this->assertNotNull($plugin);

        return $plugin;
    }

    private function runOnce(ScheduledReport $report): ScheduledReportRun
    {
        $this->assertSame(1, Scheduler::dispatchDue());

        return $report->runs()->latest('id')->firstOrFail();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'name' => 'Monday orders',
            'frequency' => 'daily',
            'time' => '09:00',
            'timezone' => 'Europe/Kyiv',
            'recipients' => [(string) auth()->id()],
            'export' => ['columns' => ['name', 'status']],
            ...$overrides,
        ];
    }

    // 1. the scope hook

    public function test_the_before_run_hook_re_applies_the_owners_data_scope_in_the_worker(): void
    {
        $this->seedOrders();
        $seen = [];
        $this->plugin()->beforeRun(function ($owner, $panel, $report) use (&$seen): void {
            $seen[] = [$owner->getAuthIdentifier(), $panel->getId(), $report->id];
            Order::addGlobalScope('mine', static fn ($query) => $query->where('status', 'shipped'));
        });
        $report = $this->makeReport(['state' => ['version' => 1, 'filters' => [], 'search' => null, 'column_searches' => [], 'sort' => null, 'tab' => null, 'export' => ['columns' => ['name', 'status']]]]);

        $run = $this->runOnce($report);

        $this->assertSame(RunStatus::Success, $run->status);
        $this->assertSame(1, $run->rows);
        $this->assertSame([[auth()->id(), 'admin', $report->id]], $seen);
    }

    public function test_a_panel_that_requires_the_hook_refuses_the_button_the_save_and_the_run(): void
    {
        $this->plugin()->requireScopeHook();

        Livewire::test(ListOrders::class)->assertActionHidden('scheduleReport');

        $this->plugin()->beforeRun(static fn () => null);
        Livewire::test(ListOrders::class)->assertActionVisible('scheduleReport');
    }

    public function test_a_stored_report_pauses_when_the_panel_now_requires_a_hook_it_lacks(): void
    {
        $this->seedOrders();
        $report = $this->makeReport();
        $this->plugin()->requireScopeHook();

        $run = $this->runOnce($report);

        $this->assertSame(PauseReason::ScopeHookMissing, $report->refresh()->paused_reason);
        $this->assertSame(RunStatus::Paused, $run->status);
        Mail::assertNotQueued(ReportMail::class);
    }

    public function test_saving_is_refused_server_side_without_a_required_hook(): void
    {
        $this->plugin()->requireScopeHook();

        try {
            Livewire::test(ListOrders::class)->callAction('scheduleReport', $this->form());
        } catch (\Throwable) {
        }

        $this->assertSame(0, ScheduledReport::query()->count());
    }

    // 2. disabled / unauthorized export action

    public function test_a_disabled_or_unauthorized_export_action_hides_the_button_and_stops_the_run(): void
    {
        $this->seedOrders();
        $report = $this->makeReport();
        OrderResource::$exportTweak = static fn ($action) => $action->disabled();

        Livewire::test(ListOrders::class)->assertActionHidden('scheduleReport');
        $run = $this->runOnce($report);

        $this->assertSame(PauseReason::OwnerLostAccess, $report->refresh()->paused_reason);
        $this->assertSame('owner_lost_access:action', $run->reason);

        OrderResource::$exportTweak = static fn ($action) => $action->authorize(false);
        Livewire::test(ListOrders::class)->assertActionHidden('scheduleReport');
    }

    // 3. a manager's pause is binding

    private function pausedByManager(): ScheduledReport
    {
        Gate::define('manageScheduledReports', fn (User $user): bool => $user->email === 'manager@example.test');
        $owner = auth()->user();
        $manager = User::factory()->create(['email' => 'manager@example.test']);
        $report = $this->makeReport();
        $this->actingAs($manager);
        Livewire::test(ListScheduledReports::class)->callTableAction('pause', $report);
        $this->actingAs($owner);

        return $report->refresh();
    }

    public function test_a_manager_pause_is_marked_and_the_owner_cannot_undo_it(): void
    {
        Queue::fake();
        $report = $this->pausedByManager();

        $this->assertSame(PauseReason::ManagerPaused, $report->paused_reason);
        Livewire::test(ListScheduledReports::class)
            ->assertTableActionHidden('resume', $report)
            ->assertTableActionHidden('runNow', $report)
            ->assertTableActionHidden('duplicate', $report);
        $this->assertNull(Scheduler::runNow($report));
        Queue::assertNothingPushed();
    }

    public function test_a_manager_can_resume_what_a_manager_paused(): void
    {
        $report = $this->pausedByManager();
        $this->actingAs(User::query()->where('email', 'manager@example.test')->firstOrFail());

        Livewire::test(ListScheduledReports::class)->callTableAction('resume', $report);

        $this->assertSame(ReportStatus::Active, $report->refresh()->status);
    }

    public function test_a_run_queued_before_the_pause_sends_nothing(): void
    {
        $this->seedOrders();
        $report = $this->makeReport();
        $run = $report->runs()->create(['status' => RunStatus::Queued, 'manual' => false, 'warnings' => []]);
        $report->update(['status' => ReportStatus::Paused, 'paused_reason' => PauseReason::Manual, 'next_run_at' => null]);

        ReportRunner::execute($report->id, $run->id);

        $this->assertSame(RunStatus::Skipped, $run->refresh()->status);
        Mail::assertNothingQueued();

        $manual = $report->runs()->create(['status' => RunStatus::Queued, 'manual' => true, 'warnings' => []]);
        $report->update(['paused_reason' => PauseReason::ManagerPaused]);
        ReportRunner::execute($report->id, $manual->id);

        $this->assertSame(RunStatus::Skipped, $manual->refresh()->status);
        Mail::assertNothingQueued();
    }

    // 4. limits

    public function test_a_duplicate_keeps_the_manual_run_stamp_and_the_report_count_is_capped(): void
    {
        $report = $this->makeReport(['last_manual_run_at' => now()->utc()->format('Y-m-d H:i:s')]);
        config(['filament-scheduled-reports.max_reports_per_owner' => 2]);

        Livewire::test(ListScheduledReports::class)->callTableAction('duplicate', $report);
        $copy = ScheduledReport::query()->whereKeyNot($report->id)->firstOrFail();
        $this->assertSame($report->last_manual_run_at, $copy->last_manual_run_at);

        Livewire::test(ListScheduledReports::class)->callTableAction('duplicate', $report);
        $this->assertSame(2, ScheduledReport::query()->count());

        Livewire::test(ListOrders::class)->callAction('scheduleReport', $this->form());
        $this->assertSame(2, ScheduledReport::query()->count());
    }

    public function test_recipients_are_capped(): void
    {
        config(['filament-scheduled-reports.max_recipients' => 2]);
        $ids = User::factory()->count(2)->create()->map(fn (User $user): string => (string) $user->id)->all();

        Livewire::test(ListOrders::class)
            ->callAction('scheduleReport', $this->form(['recipients' => [(string) auth()->id(), ...$ids]]))
            ->assertHasActionErrors();

        $this->assertSame(0, ScheduledReport::query()->count());
    }

    public function test_an_owner_has_at_most_n_runs_in_flight(): void
    {
        Queue::fake();
        config(['filament-scheduled-reports.max_concurrent_per_owner' => 1]);
        $first = $this->makeReport(['name' => 'A']);
        $second = $this->makeReport(['name' => 'B']);

        $this->assertNotNull(Scheduler::runNow($first));
        $this->assertNull(Scheduler::runNow($second));

        // A scheduled one waits for the next tick instead of piling on.
        $before = $second->refresh()->next_run_at;
        $this->assertSame(0, Scheduler::dispatchDue());
        $this->assertSame($before, $second->refresh()->next_run_at);
    }

    public function test_the_job_timeout_stays_under_the_connections_retry_after(): void
    {
        config(['queue.connections.slow' => ['driver' => 'sync', 'retry_after' => 100], 'filament-scheduled-reports.run_connection' => 'slow']);

        $this->assertSame(90, (new RunScheduledReportJob(1, 1))->timeout);
    }

    // 5. page on the panel; tab

    public function test_a_page_that_is_no_longer_on_the_panel_pauses_the_report(): void
    {
        $this->seedOrders();
        $report = $this->makeReport(['page' => OrphanOrders::class]);

        $run = $this->runOnce($report);

        $this->assertSame(PauseReason::TargetGone, $report->refresh()->paused_reason);
        $this->assertSame('target_gone:page', $run->reason);
    }

    public function test_a_stored_tab_when_all_tabs_are_gone_pauses_instead_of_widening_the_rows(): void
    {
        $this->seedOrders();
        $report = $this->makeReport(['state' => ['version' => 1, 'filters' => [], 'search' => null, 'column_searches' => [], 'sort' => null, 'tab' => 'shipped', 'export' => []]]);

        $run = $this->runOnce($report);

        $this->assertSame(PauseReason::TargetGone, $report->refresh()->paused_reason);
        $this->assertSame('target_gone:tab:shipped', $run->reason);
    }

    // 7. markdown and the host

    public function test_markdown_in_a_report_name_or_a_filter_line_does_not_become_a_link_in_the_mail(): void
    {
        $html = (new ReportMail('[open](http://evil.test)', '[Bob](http://evil2.test)', 'f.xlsx', 3, ['Customer: [x](http://evil3.test)'], 'http://localhost/unsub', 'local', 'p'))->render();

        foreach (['evil.test', 'evil2.test', 'evil3.test'] as $host) {
            $this->assertStringNotContainsString('href="http://'.$host, $html);
        }
        $this->assertStringContainsString('open', $html);
    }

    public function test_the_stored_url_root_is_the_configured_one_not_the_requests_host(): void
    {
        config(['app.url' => 'https://app.example.test']);

        Livewire::test(ListOrders::class)->callAction('scheduleReport', $this->form())->assertHasNoActionErrors();

        $this->assertSame('https://app.example.test', ScheduledReport::query()->firstOrFail()->url_root);
    }

    // 8. no raw exception text in the history

    public function test_a_failed_run_stores_the_class_and_logs_the_message(): void
    {
        Log::spy();
        $report = $this->makeReport();
        $run = $report->runs()->create(['status' => RunStatus::Running, 'manual' => false, 'warnings' => []]);

        ReportRunner::recordFailure($report->id, $run->id, new RuntimeException('SQLSTATE[HY000] [2002] connection to db.internal:3306 refused'));

        $run->refresh();
        $this->assertSame('exception:RuntimeException', $run->reason);
        $this->assertStringNotContainsString('db.internal', json_encode($run->toArray(), JSON_THROW_ON_ERROR));
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []): bool => str_contains(json_encode([$message, $context]) ?: '', 'db.internal'))->once();
    }

    public function test_a_dispatch_failure_stores_no_message_either(): void
    {
        config(['filament-scheduled-reports.run_connection' => 'nowhere']);
        $report = $this->makeReport();

        try {
            Scheduler::runNow($report);
            $this->fail('The dispatch should have thrown.');
        } catch (\Throwable) {
        }

        $this->assertStringStartsWith('exception:', (string) $report->runs()->firstOrFail()->reason);
    }

    // 6 (plugin side). the download is gated and short-lived

    public function test_the_download_of_a_report_file_is_gated_by_the_schedule_gate_and_the_page(): void
    {
        $this->seedOrders();
        config(['filament-scheduled-reports.max_attachment_mb' => 0]);
        $report = $this->makeReport();

        $run = $this->runOnce($report);
        $mail = null;
        Mail::assertQueued(ReportMail::class, function (ReportMail $queued) use (&$mail): bool {
            $mail = $queued;

            return true;
        });
        $this->assertNotNull($mail->link);
        $this->get($mail->link)->assertOk();

        Gate::define('scheduleReport', fn (User $user, ?string $page = null): bool => false);
        $this->get($mail->link)->assertRedirect();

        $meta = QueuedExports::meta((string) $run->file_id);
        $this->assertSame('scheduled-reports', $meta['scope']);
        $this->assertGreaterThan(now()->getTimestamp(), $meta['keep_until']);
    }

    public function test_a_report_file_is_pruned_even_when_the_export_retention_is_zero(): void
    {
        $this->seedOrders();
        config(['filament-xlsx-export.queue.retention_hours' => 0, 'filament-scheduled-reports.file_retention_hours' => 2]);
        $run = $this->runOnce($this->makeReport());
        $this->assertTrue(QueuedExports::disk()->exists(QueuedExports::filePath((string) $run->file_id)));

        $this->travel(3)->hours();
        $this->artisan('xlsx-export:prune')->assertSuccessful();

        $this->assertFalse(QueuedExports::disk()->exists(QueuedExports::filePath((string) $run->file_id)));
    }
}
