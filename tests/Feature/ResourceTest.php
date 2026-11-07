<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Tests\Feature;

use Asignua\FilamentScheduledReports\Enums\PauseReason;
use Asignua\FilamentScheduledReports\Enums\ReportStatus;
use Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\Pages\EditScheduledReport;
use Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\Pages\ListScheduledReports;
use Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\ScheduledReportResource;
use Asignua\FilamentScheduledReports\Jobs\RunScheduledReportJob;
use Asignua\FilamentScheduledReports\Models\ScheduledReport;
use Asignua\FilamentScheduledReports\Support\Scheduler;
use Asignua\FilamentScheduledReports\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Workbench\App\Models\User;

class ResourceTest extends TestCase
{
    public function test_a_person_sees_only_their_own_reports(): void
    {
        $mine = $this->makeReport(['name' => 'Mine']);
        $other = User::factory()->create();
        $theirs = $this->makeReport(['name' => 'Theirs', 'owner' => $other]);

        Livewire::test(ListScheduledReports::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_a_manager_sees_everybodys_reports_but_edits_only_their_own(): void
    {
        Gate::define('manageScheduledReports', fn (User $user): bool => true);
        $mine = $this->makeReport(['name' => 'Mine']);
        $theirs = $this->makeReport(['name' => 'Theirs', 'owner' => User::factory()->create()]);

        Livewire::test(ListScheduledReports::class)->assertCanSeeTableRecords([$mine, $theirs]);

        $this->assertTrue(ScheduledReportResource::canEdit($mine));
        $this->assertFalse(ScheduledReportResource::canEdit($theirs));
        $this->assertTrue(ScheduledReportResource::canDelete($theirs));
    }

    public function test_someone_else_cannot_open_a_report_by_its_url(): void
    {
        $theirs = $this->makeReport(['owner' => User::factory()->create()]);

        Livewire::test(EditScheduledReport::class, ['record' => $theirs->getKey()])->assertNotFound();
    }

    public function test_pause_and_resume(): void
    {
        $report = $this->makeReport();

        Livewire::test(ListScheduledReports::class)->callTableAction('pause', $report);

        $report->refresh();
        $this->assertSame(ReportStatus::Paused, $report->status);
        $this->assertSame(PauseReason::Manual, $report->paused_reason);
        $this->assertNull($report->next_run_at);

        Livewire::test(ListScheduledReports::class)->callTableAction('resume', $report);

        $report->refresh();
        $this->assertSame(ReportStatus::Active, $report->status);
        $this->assertNull($report->paused_reason);
        $this->assertTrue($report->nextRunAt()->isFuture());
    }

    public function test_run_now_queues_one_run_and_a_second_click_is_refused(): void
    {
        Queue::fake();
        $report = $this->makeReport();

        Livewire::test(ListScheduledReports::class)
            ->callTableAction('runNow', $report)
            ->callTableAction('runNow', $report);

        Queue::assertPushed(RunScheduledReportJob::class, 1);
        $this->assertSame(1, $report->runs()->where('manual', true)->count());
    }

    public function test_run_now_does_not_touch_the_schedule(): void
    {
        Queue::fake();
        $report = $this->makeReport(['next_run_at' => '2030-01-01 10:00:00']);

        Scheduler::runNow($report);

        $this->assertSame('2030-01-01 10:00:00', $report->refresh()->next_run_at);
    }

    public function test_duplicate_makes_an_active_copy(): void
    {
        $report = $this->makeReport(['status' => ReportStatus::Paused, 'paused_reason' => PauseReason::Manual]);

        Livewire::test(ListScheduledReports::class)->callTableAction('duplicate', $report);

        $this->assertSame(2, ScheduledReport::query()->count());
        $copy = ScheduledReport::query()->whereKeyNot($report->id)->firstOrFail();
        $this->assertSame(ReportStatus::Active, $copy->status);
        $this->assertNotSame($report->ulid, $copy->ulid);
        $this->assertTrue($copy->nextRunAt()->isFuture());
    }

    public function test_delete_removes_the_report_and_its_history(): void
    {
        $report = $this->makeReport();
        $report->runs()->create(['status' => 'success']);

        Livewire::test(ListScheduledReports::class)->callTableAction('delete', $report);

        $this->assertSame(0, ScheduledReport::query()->count());
        $this->assertSame(0, \Asignua\FilamentScheduledReports\Models\ScheduledReportRun::query()->count());
    }

    public function test_the_history_lists_the_runs_of_the_report(): void
    {
        $report = $this->makeReport();
        $run = $report->runs()->create(['status' => 'success', 'rows' => 7]);

        Livewire::test(\Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\RelationManagers\RunsRelationManager::class, ['ownerRecord' => $report, 'pageClass' => EditScheduledReport::class])
            ->assertCanSeeTableRecords([$run]);
    }

    public function test_editing_the_schedule_recomputes_the_next_run(): void
    {
        $report = $this->makeReport(['next_run_at' => '2030-01-01 10:00:00']);

        Livewire::test(EditScheduledReport::class, ['record' => $report->getKey()])
            ->fillForm(['time' => '15:30', 'recipients' => [(string) auth()->id()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $report->refresh();
        $this->assertSame('15:30', $report->time);
        $this->assertNotSame('2030-01-01 10:00:00', $report->next_run_at);
    }
}
