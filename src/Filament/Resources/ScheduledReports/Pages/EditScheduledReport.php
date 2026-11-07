<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\Pages;

use Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\ScheduledReportResource;
use Asignua\FilamentScheduledReports\Models\ScheduledReport;
use Asignua\FilamentScheduledReports\ScheduledReportsPlugin;
use Asignua\FilamentScheduledReports\Support\ScheduleFields;
use Asignua\FilamentScheduledReports\Support\Scheduler;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditScheduledReport extends EditRecord
{
    protected static string $resource = ScheduledReportResource::class;

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $report = $this->getRecord();

        if (!$report instanceof ScheduledReport) {
            return $data;
        }

        $data['recipients'] = array_values(array_map(
            static fn (array $recipient): string => (string) $recipient['id'],
            array_filter($report->recipients, static fn (array $recipient): bool => $recipient['type'] === 'user'),
        ));
        $data['external'] = array_values(array_map(
            static fn (array $recipient): string => (string) $recipient['email'],
            array_filter($report->recipients, static fn (array $recipient): bool => $recipient['type'] === 'email'),
        ));
        $data['frequency'] = $report->frequency->value;
        $data['days'] = $report->days ?? [];

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (!$record instanceof ScheduledReport) {
            return $record;
        }

        $panel = Filament::getCurrentPanel();
        $user = Auth::guard($panel?->getAuthGuard() ?? Auth::getDefaultDriver())->user();
        $plugin = ScheduledReportsPlugin::forPanel($panel);

        abort_if($user === null, 403);

        $recipients = ScheduleFields::recipientsFrom($data, $plugin, $user);

        // Unknown or forbidden recipients are dropped by `recipientsFrom`; a list that ends up empty is not saved.
        abort_if($recipients === [], 422);

        $record->fill([
            'name' => (string) $data['name'],
            'frequency' => $data['frequency'],
            'time' => (string) $data['time'],
            'days' => $data['frequency'] === 'weekly' ? array_values(array_map('intval', (array) ($data['days'] ?? []))) : null,
            'day_of_month' => $data['frequency'] === 'monthly' ? (int) $data['day_of_month'] : null,
            'timezone' => (string) $data['timezone'],
            'recipients' => $recipients,
            'skip_empty' => (bool) ($data['skip_empty'] ?? false),
        ]);

        if ($record->isActive()) {
            $record->next_run_at = Scheduler::nextFor($record);
        }

        $record->save();

        return $record;
    }
}
