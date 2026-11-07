<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Tests\Feature;

use Asignua\FilamentScheduledReports\Enums\PauseReason;
use Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\ScheduledReportResource;
use Asignua\FilamentScheduledReports\Support\Access;
use Asignua\FilamentScheduledReports\Support\Scheduler;
use Asignua\FilamentScheduledReports\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Workbench\App\Filament\Resources\Orders\Pages\ListOrders;

/**
 * Both gates are denied until the application defines them: a plugin that can email data out of the
 * panel must not be switched on for everyone by being installed.
 */
class GatesUndefinedTest extends TestCase
{
    protected bool $defineScheduleGate = false;

    public function test_nobody_may_schedule_or_manage_without_the_gates(): void
    {
        $this->assertFalse(Access::canSchedule(auth()->user(), ListOrders::class));
        $this->assertFalse(Access::canManageAll(auth()->user()));
    }

    public function test_there_is_no_schedule_button(): void
    {
        Livewire::test(ListOrders::class)->assertActionHidden('scheduleReport');
    }

    public function test_the_resource_is_closed(): void
    {
        $this->assertFalse(ScheduledReportResource::canAccess());
    }

    public function test_a_stored_report_does_not_run(): void
    {
        Mail::fake();
        $this->seedOrders();
        $report = $this->makeReport();

        Scheduler::dispatchDue();

        $this->assertSame(PauseReason::OwnerLostAccess, $report->refresh()->paused_reason);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingQueued();
    }
}
