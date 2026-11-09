<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Tests\Feature;

use Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\ScheduledReportResource;
use Asignua\FilamentScheduledReports\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\User;

/**
 * A real HTTP GET of the edit page. Filament 5.11.2 stopped asking `canEdit()` on the page (it asks
 * `getEditAuthorizationResponse()`), so a rule that lives only in a `canEdit()` override opens somebody else's report.
 */
class PageAuthorizationTest extends TestCase
{
    public function test_a_manager_cannot_open_the_edit_page_of_somebody_elses_report(): void
    {
        Gate::define('manageScheduledReports', fn (User $user): bool => true);
        $theirs = $this->makeReport(['owner' => User::factory()->create()]);

        $this->get(ScheduledReportResource::getUrl('edit', ['record' => $theirs]))->assertForbidden();
    }

    public function test_the_owner_opens_the_edit_page_of_their_own_report(): void
    {
        $mine = $this->makeReport();

        $this->get(ScheduledReportResource::getUrl('edit', ['record' => $mine]))->assertOk();
    }

    public function test_a_manager_opens_the_edit_page_of_their_own_report(): void
    {
        Gate::define('manageScheduledReports', fn (User $user): bool => true);
        $mine = $this->makeReport();

        $this->get(ScheduledReportResource::getUrl('edit', ['record' => $mine]))->assertOk();
    }

    public function test_somebody_without_the_manage_gate_gets_nothing_of_another_persons_report(): void
    {
        $theirs = $this->makeReport(['owner' => User::factory()->create()]);

        $this->get(ScheduledReportResource::getUrl('edit', ['record' => $theirs]))->assertNotFound();
    }

    public function test_the_list_opens_for_a_person_who_may_schedule(): void
    {
        $this->get(ScheduledReportResource::getUrl('index'))->assertOk();
    }

    public function test_the_resource_rules_are_one_rule(): void
    {
        Gate::define('manageScheduledReports', fn (User $user): bool => true);
        $theirs = $this->makeReport(['owner' => User::factory()->create()]);

        $this->assertFalse(ScheduledReportResource::canEdit($theirs));
        $this->assertFalse(ScheduledReportResource::getEditAuthorizationResponse($theirs)->allowed());
        $this->assertTrue(ScheduledReportResource::getViewAuthorizationResponse($theirs)->allowed());
        $this->assertTrue(ScheduledReportResource::getDeleteAuthorizationResponse($theirs)->allowed());
        $this->assertFalse(ScheduledReportResource::getCreateAuthorizationResponse()->allowed());
    }
}
