<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\RelationManagers;

use Asignua\FilamentScheduledReports\Models\ScheduledReportRun;
use Asignua\FilamentScheduledReports\Support\Reasons;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;

/**
 * The history of a report: when, how many rows, how big, what happened and why.
 */
class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('filament-scheduled-reports::filament-scheduled-reports.history');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $lang = 'filament-scheduled-reports::filament-scheduled-reports.';

        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('created_at')->label(__($lang.'when'))->dateTime()->sortable(),
                TextColumn::make('status')
                    ->label(__($lang.'status'))
                    ->badge()
                    ->state(fn (ScheduledReportRun $record): string => $record->status->label().($record->manual ? ' ('.__($lang.'manual').')' : ''))
                    ->color(fn (ScheduledReportRun $record): string => $record->status->color()),
                TextColumn::make('rows')->label(__($lang.'rows'))->numeric()->alignEnd()->placeholder('—'),
                TextColumn::make('size_bytes')->label(__($lang.'size'))->alignEnd()->placeholder('—')
                    ->state(fn (ScheduledReportRun $record): ?string => $record->size_bytes === null ? null : number_format($record->size_bytes / 1024, 1).' KB'),
                TextColumn::make('delivery')
                    ->label(__($lang.'delivery'))
                    ->state(fn (ScheduledReportRun $record): ?string => $record->delivery === null ? null : (Lang::has($lang.'delivery_'.$record->delivery) ? (string) __($lang.'delivery_'.$record->delivery) : $record->delivery))
                    ->placeholder('—'),
                TextColumn::make('reason')
                    ->label(__($lang.'reason'))
                    ->state(fn (ScheduledReportRun $record): ?string => collect(Reasons::texts(array_values(array_filter([$record->reason, ...$record->warnings]))))->implode('; ') ?: null)
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->defaultSort('id', 'desc');
    }
}
