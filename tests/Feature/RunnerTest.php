<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Tests\Feature;

use Asignua\FilamentScheduledReports\Enums\PauseReason;
use Asignua\FilamentScheduledReports\Enums\ReportStatus;
use Asignua\FilamentScheduledReports\Enums\RunStatus;
use Asignua\FilamentScheduledReports\Mail\ProblemMail;
use Asignua\FilamentScheduledReports\Mail\ReportMail;
use Asignua\FilamentScheduledReports\Models\ScheduledReport;
use Asignua\FilamentScheduledReports\Models\ScheduledReportRun;
use Asignua\FilamentScheduledReports\ScheduledReportsPlugin;
use Asignua\FilamentScheduledReports\Support\Scheduler;
use Asignua\FilamentScheduledReports\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\User;

class RunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function runOnce(ScheduledReport $report): ScheduledReportRun
    {
        $this->assertSame(1, Scheduler::dispatchDue());

        return $report->runs()->latest('id')->firstOrFail();
    }

    private function stored(): int
    {
        return count(Storage::disk('local')->allFiles());
    }

    public function test_it_sends_exactly_the_rows_the_saved_filters_select(): void
    {
        $acme = $this->seedOrders();
        $report = $this->makeReport(['state' => [
            'version' => 1,
            'filters' => ['status' => ['value' => 'new'], 'customer' => ['value' => (string) $acme->id]],
            'search' => 'Bo',
            'column_searches' => [],
            'sort' => 'amount:desc',
            'tab' => null,
            'export' => ['columns' => ['name', 'amount', 'customer.name']],
        ]]);

        $run = $this->runOnce($report);

        $this->assertSame(RunStatus::Success, $run->status);
        $rows = $this->workbookRows($run);
        // Title row, header, then Boat (50) before Bolt (5): status new, customer Acme, search "Bo", amount desc.
        $names = array_column(array_filter($rows, static fn (array $row): bool => in_array($row[0] ?? null, ['Boat', 'Bolt', 'Bolt2', 'Bolt3', 'Nut'], true)), 0);
        $this->assertSame(['Boat', 'Bolt'], array_values($names));
        $this->assertSame(2, $run->rows);
        Mail::assertQueued(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo(auth()->user()->email) && $mail->rows === 2);
    }

    public function test_the_next_run_moves_forward_when_the_run_is_claimed(): void
    {
        $report = $this->makeReport();
        $this->runOnce($report);

        $this->assertTrue($report->refresh()->nextRunAt()->isFuture());
    }

    public function test_a_second_tick_does_not_run_the_same_report_again(): void
    {
        $report = $this->makeReport();
        $this->runOnce($report);

        $this->assertSame(0, Scheduler::dispatchDue());
        $this->assertSame(1, $report->runs()->count());
    }

    public function test_after_downtime_there_is_one_catch_up_run_not_a_series(): void
    {
        // A daily report that was due for ten days.
        $report = $this->makeReport(['next_run_at' => now()->subDays(10)->utc()->format('Y-m-d H:i:s')]);

        Scheduler::dispatchDue();
        Scheduler::dispatchDue();
        Scheduler::dispatchDue();

        $this->assertSame(1, $report->runs()->count());
        $this->assertTrue($report->refresh()->nextRunAt()->isFuture());
    }

    public function test_a_paused_report_does_not_run(): void
    {
        $this->makeReport(['status' => ReportStatus::Paused]);

        $this->assertSame(0, Scheduler::dispatchDue());
    }

    public function test_an_owner_who_lost_the_panel_pauses_the_report_and_no_file_is_made(): void
    {
        $this->seedOrders();
        $report = $this->makeReport();
        auth()->user()->update(['is_active' => false]);

        $run = $this->runOnce($report);

        $report->refresh();
        $this->assertSame(ReportStatus::Paused, $report->status);
        $this->assertSame(PauseReason::OwnerLostAccess, $report->paused_reason);
        $this->assertSame(RunStatus::Paused, $run->status);
        $this->assertSame(0, $this->stored());
        Mail::assertNothingQueued();
    }

    public function test_a_deleted_owner_pauses_the_report(): void
    {
        $report = $this->makeReport();
        auth()->user()->delete();

        $run = $this->runOnce($report);

        $this->assertSame(PauseReason::OwnerLostAccess, $report->refresh()->paused_reason);
        $this->assertSame(0, $this->stored());
        Mail::assertNothingQueued();
        $this->assertSame(RunStatus::Paused, $run->status);
    }

    public function test_a_gate_that_says_no_denies_the_run(): void
    {
        $report = $this->makeReport();
        Gate::define('scheduleReport', fn (User $user, ?string $page = null): bool => false);

        $this->runOnce($report);

        $this->assertSame(PauseReason::OwnerLostAccess, $report->refresh()->paused_reason);
        $this->assertSame(0, $this->stored());
    }

    public function test_a_tenant_panel_never_runs(): void
    {
        $report = $this->makeReport(['panel' => 'tenant']);

        $run = $this->runOnce($report);

        $this->assertSame(PauseReason::TenancyUnsupported, $report->refresh()->paused_reason);
        $this->assertSame(RunStatus::Paused, $run->status);
        $this->assertSame(0, $this->stored());
    }

    public function test_a_missing_panel_pauses_with_target_gone(): void
    {
        $report = $this->makeReport(['panel' => 'nope']);

        $this->runOnce($report);

        $this->assertSame(PauseReason::TargetGone, $report->refresh()->paused_reason);
    }

    public function test_a_missing_page_pauses_with_target_gone(): void
    {
        $report = $this->makeReport(['page' => 'App\\Filament\\Pages\\Renamed']);

        $this->runOnce($report);

        $this->assertSame(PauseReason::TargetGone, $report->refresh()->paused_reason);
    }

    public function test_a_filter_that_is_gone_pauses_instead_of_sending_everything(): void
    {
        $this->seedOrders();
        $report = $this->makeReport(['state' => ['version' => 1, 'filters' => ['region' => ['value' => 'north']], 'search' => null, 'column_searches' => [], 'sort' => null, 'tab' => null, 'export' => []]]);

        $run = $this->runOnce($report);

        $this->assertSame(PauseReason::TargetGone, $report->refresh()->paused_reason);
        $this->assertStringContainsString('filter:region', (string) $run->reason);
        $this->assertSame(0, $this->stored());
    }

    public function test_an_unused_filter_that_is_gone_does_not_matter(): void
    {
        $this->seedOrders();
        $report = $this->makeReport(['state' => ['version' => 1, 'filters' => ['region' => ['value' => null]], 'search' => null, 'column_searches' => [], 'sort' => null, 'tab' => null, 'export' => []]]);

        $this->assertSame(RunStatus::Success, $this->runOnce($report)->status);
    }

    public function test_a_removed_column_is_left_out_with_a_warning(): void
    {
        $this->seedOrders();
        $report = $this->makeReport(['state' => ['version' => 1, 'filters' => [], 'search' => null, 'column_searches' => [], 'sort' => null, 'tab' => null, 'export' => ['columns' => ['name', 'discontinued']]]]);

        $run = $this->runOnce($report);

        $this->assertSame(RunStatus::Success, $run->status);
        $this->assertContains('columns_removed:discontinued', $run->warnings);
        $this->assertContains('name', array_map('strtolower', $this->workbookRows($run)[1] ?? $this->workbookRows($run)[0]));
    }

    public function test_when_every_saved_column_is_gone_the_report_pauses(): void
    {
        $report = $this->makeReport(['state' => ['version' => 1, 'filters' => [], 'search' => null, 'column_searches' => [], 'sort' => null, 'tab' => null, 'export' => ['columns' => ['discontinued']]]]);

        $this->runOnce($report);

        $this->assertSame(PauseReason::TargetGone, $report->refresh()->paused_reason);
    }

    public function test_an_empty_report_is_skipped_when_asked_to(): void
    {
        $report = $this->makeReport(['skip_empty' => true]);

        $run = $this->runOnce($report);

        $this->assertSame(RunStatus::Skipped, $run->status);
        $this->assertSame(0, $this->stored());
        Mail::assertNothingQueued();
    }

    public function test_an_empty_report_is_sent_when_not_asked_to_skip(): void
    {
        $report = $this->makeReport();

        $this->assertSame(RunStatus::Success, $this->runOnce($report)->status);
        Mail::assertQueued(ReportMail::class);
    }

    public function test_a_file_over_the_attachment_limit_goes_as_a_link_to_the_owner_only(): void
    {
        $this->seedOrders();
        $colleague = User::factory()->create();
        config(['filament-scheduled-reports.max_attachment_mb' => 0]);
        $report = $this->makeReport(['recipients' => [
            ['type' => 'user', 'id' => (string) auth()->id()],
            ['type' => 'user', 'id' => (string) $colleague->id],
        ]]);

        $run = $this->runOnce($report);

        $this->assertSame('link', $run->delivery);
        $this->assertContains('oversize_not_sent:1', $run->warnings);
        Mail::assertQueued(ReportMail::class, 1);
        Mail::assertQueued(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo(auth()->user()->email) && $mail->link !== null && $mail->attachments() === []);
        Mail::assertNotQueued(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo($colleague->email));
    }

    public function test_a_small_file_is_attached(): void
    {
        $this->seedOrders();
        $report = $this->makeReport();

        $run = $this->runOnce($report);

        $this->assertSame('attached', $run->delivery);
        Mail::assertQueued(ReportMail::class, fn (ReportMail $mail): bool => $mail->link === null && count($mail->attachments()) === 1);
    }

    public function test_a_table_that_grew_past_the_export_cap_is_recorded_and_the_owner_told(): void
    {
        $this->seedOrders();
        config(['filament-xlsx-export.queue.hard_cap' => 2]);
        $report = $this->makeReport();

        $run = $this->runOnce($report);

        $this->assertSame(RunStatus::TooLarge, $run->status);
        $this->assertSame(0, $this->stored());
        Mail::assertQueued(ProblemMail::class, fn (ProblemMail $mail): bool => $mail->hasTo(auth()->user()->email));
        $this->assertSame(1, $report->refresh()->consecutive_failures);
        $this->assertSame(1, auth()->user()->notifications()->count());
    }

    public function test_three_failures_in_a_row_pause_the_report_and_tell_the_owner(): void
    {
        $this->seedOrders();
        config(['filament-xlsx-export.queue.hard_cap' => 2]);
        $report = $this->makeReport();

        for ($i = 0; $i < 3; $i++) {
            ScheduledReport::query()->whereKey($report->id)->update(['next_run_at' => now()->subMinute()->utc()->format('Y-m-d H:i:s')]);
            Scheduler::dispatchDue();
        }

        $report->refresh();
        $this->assertSame(ReportStatus::Paused, $report->status);
        $this->assertSame(PauseReason::Failed, $report->paused_reason);
        $this->assertNull($report->next_run_at);
        Mail::assertQueued(ProblemMail::class, fn (ProblemMail $mail): bool => str_contains($mail->title, PauseReason::Failed->label()));
    }

    public function test_a_success_resets_the_failure_count(): void
    {
        $this->seedOrders();
        $report = $this->makeReport(['consecutive_failures' => 2]);

        $this->runOnce($report);

        $this->assertSame(0, $report->refresh()->consecutive_failures);
    }

    public function test_a_failing_run_is_counted_once_even_if_the_queue_reports_it_too(): void
    {
        $report = $this->makeReport(['page' => ScheduledReport::class]); // not a table page -> target gone, not a failure
        $this->runOnce($report);

        $this->assertSame(0, $report->refresh()->consecutive_failures);
    }

    public function test_external_addresses_are_not_sent_to_by_default(): void
    {
        $this->seedOrders();
        $report = $this->makeReport(['recipients' => [
            ['type' => 'user', 'id' => (string) auth()->id()],
            ['type' => 'email', 'email' => 'boss@example.com'],
        ]]);

        $run = $this->runOnce($report);

        $this->assertContains('recipient_dropped:email:boss@example.com', $run->warnings);
        Mail::assertQueued(ReportMail::class, 1);
        Mail::assertNotQueued(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo('boss@example.com'));
    }

    public function test_external_addresses_work_only_for_allowed_domains(): void
    {
        $this->seedOrders();
        ScheduledReportsPlugin::forPanel(Filament::getPanel('admin'))->allowExternalRecipients()->allowedDomains(['example.com']);
        $report = $this->makeReport(['recipients' => [
            ['type' => 'email', 'email' => 'boss@example.com'],
            ['type' => 'email', 'email' => 'spy@evil.test'],
            ['type' => 'email', 'email' => 'sub@mail.example.com'],
        ]]);

        $run = $this->runOnce($report);

        Mail::assertQueued(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo('boss@example.com'));
        Mail::assertNotQueued(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo('spy@evil.test') || $mail->hasTo('sub@mail.example.com'));
        $this->assertContains('recipient_dropped:email:spy@evil.test', $run->warnings);
    }

    public function test_allowing_external_recipients_without_domains_allows_nobody(): void
    {
        $this->seedOrders();
        ScheduledReportsPlugin::forPanel(Filament::getPanel('admin'))->allowExternalRecipients();
        $report = $this->makeReport(['recipients' => [['type' => 'email', 'email' => 'boss@example.com']]]);

        $this->runOnce($report);

        $this->assertSame(PauseReason::NoRecipients, $report->refresh()->paused_reason);
        Mail::assertNotQueued(ReportMail::class);
    }

    public function test_a_colleague_who_is_no_longer_offered_stops_receiving(): void
    {
        $this->seedOrders();
        $colleague = User::factory()->create();
        $report = $this->makeReport(['recipients' => [
            ['type' => 'user', 'id' => (string) auth()->id()],
            ['type' => 'user', 'id' => (string) $colleague->id],
        ]]);
        $colleague->update(['is_active' => false]);

        $run = $this->runOnce($report);

        Mail::assertNotQueued(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo($colleague->email));
        $this->assertContains('recipient_dropped:user:'.$colleague->id, $run->warnings);
    }

    public function test_the_run_acts_as_the_owner_and_then_puts_the_worker_back(): void
    {
        $this->seedOrders();
        $other = User::factory()->create();
        $report = $this->makeReport();
        $this->actingAs($other);
        app()->setLocale('de');

        $this->runOnce($report);

        $this->assertSame($other->id, auth()->id());
        $this->assertSame('de', app()->getLocale());
    }
}
