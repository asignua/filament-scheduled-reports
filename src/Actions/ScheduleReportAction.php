<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Actions;

use Asignua\FilamentScheduledReports\Enums\Frequency;
use Asignua\FilamentScheduledReports\Enums\ReportStatus;
use Asignua\FilamentScheduledReports\Models\ScheduledReport;
use Asignua\FilamentScheduledReports\ScheduledReportsPlugin;
use Asignua\FilamentScheduledReports\Support\Access;
use Asignua\FilamentScheduledReports\Support\ExportForm;
use Asignua\FilamentScheduledReports\Support\ReportState;
use Asignua\FilamentScheduledReports\Support\ScheduleFields;
use Asignua\FilamentScheduledReports\Support\Scheduler;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * "Schedule report": a header action that saves the table as it is now (filters, search, sort, the
 * export's columns and options) together with a schedule and recipients.
 *
 * It exists only where a report could really run later: on a page (not a relation manager, which needs
 * a parent record), on a panel without tenancy (the background export cannot carry a tenant), where the
 * page has an export action to inherit from, and for a user the `scheduleReport` gate lets in.
 */
class ScheduleReportAction extends Action
{
    protected string $exportActionName = 'xlsxExport';

    public static function getDefaultName(): ?string
    {
        return 'scheduleReport';
    }

    /**
     * The name of the XlsxExportAction to inherit from (default `xlsxExport`).
     */
    public function exportAction(string $name): static
    {
        $this->exportActionName = $name;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament-scheduled-reports::filament-scheduled-reports.schedule'));
        $this->modalHeading(__('filament-scheduled-reports::filament-scheduled-reports.schedule_heading'));
        $this->modalSubmitActionLabel(__('filament-scheduled-reports::filament-scheduled-reports.schedule_submit'));
        $this->icon(Heroicon::OutlinedCalendarDays);
        $this->color('gray');
        $this->modalWidth('2xl');

        $this->visible(fn (mixed $livewire): bool => $this->mayBeUsedOn($livewire));
        $this->schema(fn (HasTable $livewire, Schema $schema): array => $this->scheduleSchema($livewire, $schema));
        $this->fillForm(fn (HasTable $livewire): array => $this->defaults($livewire, Schema::make($livewire instanceof HasSchemas && $livewire instanceof Component ? $livewire : null)));
        $this->action(fn (array $data, HasTable $livewire) => $this->save($livewire, $data));
    }

    private function mayBeUsedOn(mixed $livewire): bool
    {
        $panel = Filament::getCurrentPanel();

        if (!$livewire instanceof Page || !$livewire instanceof HasTable || $panel === null || $panel->hasTenancy()) {
            return false;
        }

        if (ScheduledReportsPlugin::forPanel($panel) === null || !Access::canSchedule($this->user($panel), $livewire::class)) {
            return false;
        }

        $export = ExportForm::action($livewire, $this->exportActionName);

        return $export !== null && (!method_exists($export, 'isVisible') || $export->isVisible());
    }

    private function user(?\Filament\Panel $panel = null): ?Authenticatable
    {
        $panel ??= Filament::getCurrentPanel();

        return Auth::guard($panel?->getAuthGuard() ?? Auth::getDefaultDriver())->user();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(HasTable $livewire, Schema $schema): array
    {
        $export = ExportForm::action($livewire, $this->exportActionName);
        $user = $this->user();
        $timezone = $user !== null && method_exists($user, 'getAttribute') ? $user->getAttribute('timezone') : null;
        $timezone = is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : (string) config('app.timezone');

        return [
            'name' => Str::title((string) $livewire->getTable()->getPluralModelLabel()),
            'frequency' => Frequency::Weekly->value,
            'days' => [1],
            'day_of_month' => 1,
            'time' => '09:00',
            'timezone' => $timezone,
            'recipients' => $user === null ? [] : [(string) $user->getAuthIdentifier()],
            'external' => [],
            'skip_empty' => false,
            'export' => $export === null ? [] : ExportForm::defaults($export, $schema),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function scheduleSchema(HasTable $livewire, Schema $schema): array
    {
        $panel = Filament::getCurrentPanel();
        $plugin = ScheduledReportsPlugin::forPanel($panel);
        $user = $this->user();
        $lang = 'filament-scheduled-reports::filament-scheduled-reports.';
        $components = [
            Callout::make(__($lang.'visibility_title'))
                ->description(__($lang.'visibility_body'))
                ->warning(),
        ];

        $summary = ReportState::summary($livewire);
        $components[] = Text::make(__($lang.'will_send'));
        $components[] = $summary === []
            ? Text::make(__($lang.'no_filters'))->color('gray')
            : UnorderedList::make(array_map(static fn (string $line): Text => Text::make($line), $summary));

        $components = [...$components, ...($user === null ? [] : ScheduleFields::make($plugin, $user))];

        $export = ExportForm::action($livewire, $this->exportActionName);

        if ($export !== null) {
            $options = ExportForm::components($export, $schema);

            if ($options !== []) {
                $components[] = Group::make($options)->statePath('export');
            }
        }

        return $components;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function save(HasTable $livewire, array $data): void
    {
        $panel = Filament::getCurrentPanel();
        $user = $this->user($panel);
        $plugin = ScheduledReportsPlugin::forPanel($panel);

        // The button was visible when the modal opened; the request that submits it is checked again.
        abort_unless($panel !== null && $user !== null && $plugin !== null && $livewire instanceof Page && !$panel->hasTenancy() && Access::canSchedule($user, $livewire::class), 403);

        $recipients = ScheduleFields::recipientsFrom($data, $plugin, $user);

        abort_if($recipients === [], 422);

        $frequency = Frequency::from((string) $data['frequency']);
        $days = $frequency === Frequency::Weekly ? array_values(array_map('intval', (array) ($data['days'] ?? []))) : null;

        $report = new ScheduledReport([
            'owner_id' => (string) $user->getAuthIdentifier(),
            'guard' => $panel->getAuthGuard(),
            'panel' => $panel->getId(),
            'page' => $livewire::class,
            'action' => $this->exportActionName,
            'name' => (string) $data['name'],
            'frequency' => $frequency,
            'time' => (string) $data['time'],
            'days' => $days,
            'day_of_month' => $frequency === Frequency::Monthly ? (int) $data['day_of_month'] : null,
            'timezone' => (string) $data['timezone'],
            'locale' => app()->getLocale(),
            'url_root' => request()->root(),
            'state' => [...ReportState::capture($livewire), 'export' => is_array($data['export'] ?? null) ? $data['export'] : []],
            'summary' => ReportState::summary($livewire),
            'recipients' => $recipients,
            'skip_empty' => (bool) ($data['skip_empty'] ?? false),
            'status' => ReportStatus::Active,
        ]);
        $report->next_run_at = Scheduler::nextFor($report);
        $report->save();

        Notification::make()
            ->success()
            ->title(__('filament-scheduled-reports::filament-scheduled-reports.scheduled_title'))
            ->body(__('filament-scheduled-reports::filament-scheduled-reports.scheduled_body', [
                'when' => $report->nextRunAt()?->setTimezone($report->timezone)->isoFormat('LLLL').' ('.$report->timezone.')',
            ]))
            ->send();
    }
}
