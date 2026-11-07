<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports;

use Asignua\FilamentScheduledReports\Enums\PauseReason;
use Asignua\FilamentScheduledReports\Enums\ReportStatus;
use Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\Pages\EditScheduledReport;
use Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\Pages\ListScheduledReports;
use Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\RelationManagers\RunsRelationManager;
use Asignua\FilamentScheduledReports\Models\ScheduledReport;
use Asignua\FilamentScheduledReports\ScheduledReportsPlugin;
use Asignua\FilamentScheduledReports\Support\Access;
use Asignua\FilamentScheduledReports\Support\Limits;
use Asignua\FilamentScheduledReports\Support\Owners;
use Asignua\FilamentScheduledReports\Support\ScheduleFields;
use Asignua\FilamentScheduledReports\Support\Scheduler;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * "Scheduled reports": a person's own reports; everybody's for whoever passes `manageScheduledReports`.
 * What a report may do never depends on who opens this page, only on its owner (see `ReportRunner`).
 */
class ScheduledReportResource extends Resource
{
    protected static ?string $model = ScheduledReport::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $slug = 'scheduled-reports';

    public static function getModelLabel(): string
    {
        return __('filament-scheduled-reports::filament-scheduled-reports.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-scheduled-reports::filament-scheduled-reports.plural');
    }

    public static function getNavigationLabel(): string
    {
        return self::getPluralModelLabel();
    }

    private static function user(): ?Authenticatable
    {
        $panel = Filament::getCurrentPanel();

        return Auth::guard($panel?->getAuthGuard() ?? Auth::getDefaultDriver())->user();
    }

    public static function canAccess(): bool
    {
        $user = self::user();

        return Access::canSchedule($user) || Access::canManageAll($user);
    }

    public static function canCreate(): bool
    {
        // Reports are made from a table's own "Schedule report" button, where the filters are.
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof ScheduledReport && self::owns($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof ScheduledReport && (self::owns($record) || Access::canManageAll(self::user()));
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof ScheduledReport && (self::owns($record) || Access::canManageAll(self::user()));
    }

    private static function owns(ScheduledReport $report): bool
    {
        $user = self::user();

        return $user !== null && $report->owner_id === (string) $user->getAuthIdentifier() && $report->guard === Filament::getCurrentPanel()?->getAuthGuard();
    }

    /**
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = self::user();

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if (Access::canManageAll($user)) {
            return $query;
        }

        return $query->where('owner_id', (string) $user->getAuthIdentifier())->where('guard', Filament::getCurrentPanel()?->getAuthGuard());
    }

    public static function form(Schema $schema): Schema
    {
        $user = self::user();
        $plugin = ScheduledReportsPlugin::forPanel(Filament::getCurrentPanel());

        return $schema->components([
            Text::make(fn (?ScheduledReport $record): string => __('filament-scheduled-reports::filament-scheduled-reports.will_send')),
            UnorderedList::make(fn (?ScheduledReport $record): array => array_map(static fn (string $line): Text => Text::make($line), $record->summary ?? [])),
            ...($user === null ? [] : ScheduleFields::make($plugin, $user)),
        ]);
    }

    public static function table(Table $table): Table
    {
        $lang = 'filament-scheduled-reports::filament-scheduled-reports.';

        return $table
            ->columns([
                TextColumn::make('name')->label(__($lang.'name'))->searchable()->sortable(),
                TextColumn::make('owner_id')
                    ->label(__($lang.'owner'))
                    ->visible(fn (): bool => Access::canManageAll(self::user()))
                    ->state(fn (ScheduledReport $record): string => (string) (Owners::find($record->guard, $record->owner_id)?->getAttribute('name') ?? '#'.$record->owner_id)),
                TextColumn::make('frequency')
                    ->label(__($lang.'frequency'))
                    ->state(fn (ScheduledReport $record): string => $record->frequency->label().' · '.$record->time.' · '.$record->timezone),
                TextColumn::make('status')
                    ->label(__($lang.'status'))
                    ->badge()
                    ->state(fn (ScheduledReport $record): string => $record->status === ReportStatus::Active ? __($lang.'status_active') : __($lang.'status_paused').($record->paused_reason instanceof PauseReason ? ': '.$record->paused_reason->label() : ''))
                    ->color(fn (ScheduledReport $record): string => $record->status === ReportStatus::Active ? 'success' : 'warning'),
                TextColumn::make('next_run_at')
                    ->label(__($lang.'next_run'))
                    ->state(fn (ScheduledReport $record): ?string => $record->nextRunAt()?->setTimezone($record->timezone)->isoFormat('lll'))
                    ->placeholder('—'),
                TextColumn::make('last_run_at')
                    ->label(__($lang.'last_run'))
                    ->state(fn (ScheduledReport $record): ?string => ScheduledReport::utc($record->last_run_at)?->setTimezone($record->timezone)->isoFormat('lll'))
                    ->placeholder('—'),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('runNow')
                        ->label(__($lang.'run_now'))
                        ->icon(Heroicon::OutlinedPlay)
                        ->visible(fn (ScheduledReport $record): bool => self::owns($record) && !$record->isLockedByManager())
                        ->action(function (ScheduledReport $record) use ($lang): void {
                            $run = Scheduler::runNow($record);

                            Notification::make()
                                ->title($run === null ? __($lang.'run_too_soon') : __($lang.'run_started'))
                                ->color($run === null ? 'warning' : 'success')
                                ->send();
                        }),
                    Action::make('pause')
                        ->label(__($lang.'pause'))
                        ->icon(Heroicon::OutlinedPause)
                        ->visible(fn (ScheduledReport $record): bool => $record->isActive() && (self::owns($record) || Access::canManageAll(self::user())))
                        ->action(fn (ScheduledReport $record) => $record->update(['status' => ReportStatus::Paused, 'paused_reason' => self::owns($record) ? PauseReason::Manual : PauseReason::ManagerPaused, 'next_run_at' => null])),
                    Action::make('resume')
                        ->label(__($lang.'resume'))
                        ->icon(Heroicon::OutlinedPlayPause)
                        ->visible(fn (ScheduledReport $record): bool => !$record->isActive() && (Access::canManageAll(self::user()) || (self::owns($record) && !$record->isLockedByManager())))
                        ->action(function (ScheduledReport $record) use ($lang): void {
                            if ($record->recipients === []) {
                                Notification::make()->warning()->title(__($lang.'resume_no_recipients'))->send();

                                return;
                            }

                            $record->status = ReportStatus::Active;
                            $record->paused_reason = null;
                            $record->consecutive_failures = 0;
                            $record->next_run_at = Scheduler::nextFor($record);
                            $record->save();
                        }),
                    Action::make('duplicate')
                        ->label(__($lang.'duplicate'))
                        ->icon(Heroicon::OutlinedDocumentDuplicate)
                        ->visible(fn (ScheduledReport $record): bool => self::owns($record) && !$record->isLockedByManager())
                        ->action(function (ScheduledReport $record): void {
                            if (Limits::reportsReached($record->guard, $record->owner_id)) {
                                Notification::make()->danger()->title(__('filament-scheduled-reports::filament-scheduled-reports.limit_reached'))->send();

                                return;
                            }

                            // `last_manual_run_at` is kept: a copy must not be a way round the "Run now" interval.
                            $copy = $record->replicate(['ulid', 'last_run_at', 'consecutive_failures']);
                            $copy->ulid = strtolower((string) Str::ulid());
                            $copy->name = $record->name.' ('.__('filament-scheduled-reports::filament-scheduled-reports.copy').')';
                            $copy->status = ReportStatus::Active;
                            $copy->paused_reason = null;
                            $copy->consecutive_failures = 0;
                            $copy->next_run_at = Scheduler::nextFor($copy);
                            $copy->save();
                        }),
                    DeleteAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [RunsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScheduledReports::route('/'),
            'edit' => EditScheduledReport::route('/{record}/edit'),
        ];
    }
}
