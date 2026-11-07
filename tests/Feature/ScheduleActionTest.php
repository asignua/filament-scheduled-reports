<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Tests\Feature;

use Asignua\FilamentScheduledReports\Enums\Frequency;
use Asignua\FilamentScheduledReports\Enums\ReportStatus;
use Asignua\FilamentScheduledReports\Models\ScheduledReport;
use Asignua\FilamentScheduledReports\ScheduledReportsPlugin;
use Asignua\FilamentScheduledReports\Support\ScheduleFields;
use Asignua\FilamentScheduledReports\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Livewire\trigger;

use Workbench\App\Filament\Resources\Orders\Pages\ListOrders;
use Workbench\App\Models\User;

class ScheduleActionTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'name' => 'Monday orders',
            'frequency' => 'weekly',
            'days' => [1],
            'time' => '09:00',
            'timezone' => 'Europe/Kyiv',
            'recipients' => [(string) auth()->id()],
            'skip_empty' => true,
            'export' => ['columns' => ['name', 'amount']],
            ...$overrides,
        ];
    }

    public function test_the_button_is_there_for_an_allowed_user(): void
    {
        Livewire::test(ListOrders::class)->assertActionVisible('scheduleReport');
    }

    public function test_it_saves_the_filters_search_sort_columns_and_schedule_as_plain_data(): void
    {
        $this->seedOrders();

        Livewire::test(ListOrders::class)
            ->set('tableFilters.status.value', 'new')
            ->set('tableSearch', 'Bo')
            ->set('tableSort', 'amount:desc')
            ->callAction('scheduleReport', $this->form())
            ->assertHasNoActionErrors();

        /** @var ScheduledReport $report */
        $report = ScheduledReport::query()->firstOrFail();

        $this->assertSame((string) auth()->id(), $report->owner_id);
        $this->assertSame('admin', $report->panel);
        $this->assertSame(ListOrders::class, $report->page);
        $this->assertSame('xlsxExport', $report->action);
        $this->assertSame(Frequency::Weekly, $report->frequency);
        $this->assertSame([1], $report->days);
        $this->assertSame('Europe/Kyiv', $report->timezone);
        $this->assertSame('new', $report->state['filters']['status']['value']);
        $this->assertSame('Bo', $report->state['search']);
        $this->assertSame('amount:desc', $report->state['sort']);
        $this->assertSame(['name', 'amount'], $report->state['export']['columns']);
        $this->assertTrue($report->skip_empty);
        $this->assertSame(ReportStatus::Active, $report->status);
        $this->assertNotNull($report->next_run_at);
        $this->assertNotEmpty($report->summary);
        // Plain data only: no Livewire snapshot, no class instances.
        $this->assertStringNotContainsString('snapshot', json_encode($report->state, JSON_THROW_ON_ERROR));
    }

    public function test_the_stored_json_keeps_non_ascii_text_readable(): void
    {
        $this->seedOrders();

        Livewire::test(ListOrders::class)
            ->set('tableSearch', 'Кава')
            ->callAction('scheduleReport', $this->form(['name' => 'Звіт']));

        $raw = (string) ScheduledReport::query()->toBase()->value('state');

        $this->assertStringContainsString('Кава', $raw);
        $this->assertStringNotContainsString('\\u041a', $raw);
    }

    public function test_a_recipient_the_owner_is_not_offered_is_refused(): void
    {
        $stranger = User::factory()->create(['is_active' => false]); // the workbench offers only active users

        Livewire::test(ListOrders::class)
            ->callAction('scheduleReport', $this->form(['recipients' => [(string) auth()->id(), (string) $stranger->id]]))
            ->assertHasActionErrors();

        $this->assertSame(0, ScheduledReport::query()->count());
    }

    public function test_recipients_beyond_the_offered_ones_are_dropped_even_if_validation_is_bypassed(): void
    {
        $stranger = User::factory()->create(['is_active' => false]);
        $plugin = ScheduledReportsPlugin::forPanel(Filament::getPanel('admin'));

        $recipients = ScheduleFields::recipientsFrom(['recipients' => [(string) auth()->id(), (string) $stranger->id], 'external' => ['x@example.com']], $plugin, auth()->user());

        $this->assertSame([['type' => 'user', 'id' => (string) auth()->id()]], $recipients);
    }

    public function test_external_addresses_cannot_be_submitted_while_the_panel_does_not_allow_them(): void
    {
        Livewire::test(ListOrders::class)
            ->callAction('scheduleReport', $this->form(['external' => ['boss@example.com']]));

        $this->assertSame([['type' => 'user', 'id' => (string) auth()->id()]], ScheduledReport::query()->firstOrFail()->recipients);
    }

    public function test_external_addresses_are_checked_against_the_allowed_domains(): void
    {
        ScheduledReportsPlugin::forPanel(Filament::getPanel('admin'))->allowExternalRecipients()->allowedDomains(['example.com']);

        Livewire::test(ListOrders::class)
            ->callAction('scheduleReport', $this->form(['external' => ['boss@example.com', 'spy@evil.test']]))
            ->assertHasActionErrors();

        $this->assertSame(0, ScheduledReport::query()->count());

        Livewire::test(ListOrders::class)
            ->callAction('scheduleReport', $this->form(['external' => ['boss@example.com']]))
            ->assertHasNoActionErrors();

        $this->assertContains(['type' => 'email', 'email' => 'boss@example.com'], ScheduledReport::query()->firstOrFail()->recipients);
    }

    public function test_the_button_is_hidden_when_the_gate_says_no(): void
    {
        Gate::define('scheduleReport', fn (User $user, ?string $page = null): bool => false);

        Livewire::test(ListOrders::class)->assertActionHidden('scheduleReport');
    }

    public function test_the_gate_gets_the_page_so_it_can_allow_some_pages_only(): void
    {
        Gate::define('scheduleReport', fn (User $user, ?string $page = null): bool => $page === 'Other\\Page');

        Livewire::test(ListOrders::class)->assertActionHidden('scheduleReport');
    }

    public function test_a_tenant_panel_has_no_action(): void
    {
        Filament::setCurrentPanel('tenant');

        // Built without rendering: the tenant panel has no routes for the workbench resource, and the
        // question is only whether the action is offered.
        $page = app('livewire')->new(ListOrders::class);
        trigger('mount', $page, [], null, null, []);

        $action = collect($page->getCachedHeaderActions())->first(fn ($action): bool => $action->getName() === 'scheduleReport');

        $this->assertNotNull($action);
        $this->assertFalse($action->isVisible());
    }

    public function test_a_submit_after_the_gate_was_closed_saves_nothing(): void
    {
        $component = Livewire::test(ListOrders::class)->mountAction('scheduleReport');
        Gate::define('scheduleReport', fn (User $user, ?string $page = null): bool => false);

        $component->callMountedAction();

        $this->assertSame(0, ScheduledReport::query()->count());
    }

    public function test_the_modal_says_recipients_see_the_owners_data(): void
    {
        Livewire::test(ListOrders::class)
            ->mountAction('scheduleReport')
            ->assertMountedActionModalSee(__('filament-scheduled-reports::filament-scheduled-reports.visibility_title'));
    }
}
