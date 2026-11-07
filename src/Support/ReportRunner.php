<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

use Asignua\FilamentScheduledReports\Enums\PauseReason;
use Asignua\FilamentScheduledReports\Enums\ReportStatus;
use Asignua\FilamentScheduledReports\Enums\RunStatus;
use Asignua\FilamentScheduledReports\Mail\ProblemMail;
use Asignua\FilamentScheduledReports\Mail\ReportMail;
use Asignua\FilamentScheduledReports\Models\ScheduledReport;
use Asignua\FilamentScheduledReports\Models\ScheduledReportRun;
use Asignua\FilamentScheduledReports\ScheduledReportsPlugin;
use Asignua\FilamentXlsxExport\Support\ExportTooLarge;
use Asignua\FilamentXlsxExport\Support\QueuedExports;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Component;

use function Livewire\trigger;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Builds and delivers one run, as the report's owner.
 *
 * The rule that shapes everything here: a report must not carry more than its owner sees in the panel
 * right now. The run acts as the owner (guard, panel, locale, `canAccessPanel`), re-checks the gate,
 * the page's own access and the action's visibility, and builds the query on a page component made
 * fresh from stored plain state. If any of that no longer holds the report pauses and no file is made.
 */
final class ReportRunner
{
    public static function execute(int $reportId, int $runId): void
    {
        $report = ScheduledReport::query()->find($reportId);
        $run = ScheduledReportRun::query()->find($runId);

        if ($report === null || $run === null) {
            return;
        }

        // A run can sit in the queue for a while. Whoever paused the report in the meantime meant it to stop:
        // a scheduled run is dropped once the report is not active, any run once a manager paused it.
        if ($report->isLockedByManager() || (!$run->manual && !$report->isActive())) {
            $run->update(['status' => RunStatus::Skipped, 'reason' => $report->isLockedByManager() ? 'skipped:paused_by_manager' : 'skipped:not_active', 'finished_at' => self::stamp()]);

            return;
        }

        $run->update(['status' => RunStatus::Running, 'started_at' => self::stamp()]);

        try {
            self::attempt($report, $run);
        } catch (Throwable $exception) {
            self::recordFailure($reportId, $runId, $exception);

            throw $exception;
        }
    }

    private static function attempt(ScheduledReport $report, ScheduledReportRun $run): void
    {
        try {
            $panel = Filament::getPanel($report->panel);
        } catch (Throwable) {
            $panel = null;
        }

        if ($panel === null) {
            self::pause($report, $run, PauseReason::TargetGone, 'panel');

            return;
        }

        if ($panel->hasTenancy()) {
            self::pause($report, $run, PauseReason::TenancyUnsupported, 'panel', self::owner($report));

            return;
        }

        $plugin = ScheduledReportsPlugin::forPanel($panel);

        if ($plugin === null) {
            self::pause($report, $run, PauseReason::TargetGone, 'plugin');

            return;
        }

        $payload = [
            'guard' => $report->guard,
            'user' => $report->owner_id,
            'panel' => $report->panel,
            'locale' => $report->locale,
        ];

        QueuedExports::within($payload, function (?Authenticatable $owner) use ($report, $run, $panel, $plugin, $payload): void {
            if ($owner === null) {
                self::pause($report, $run, PauseReason::OwnerLostAccess, 'owner');

                return;
            }

            self::build($report, $run, $owner, $panel, $plugin, $payload);
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function build(ScheduledReport $report, ScheduledReportRun $run, Authenticatable $owner, Panel $panel, ScheduledReportsPlugin $plugin, array $payload): void
    {
        if (!Access::canSchedule($owner, $report->page)) {
            self::pause($report, $run, PauseReason::OwnerLostAccess, 'gate');

            return;
        }

        if (!$plugin->scopeHookSatisfied()) {
            self::pause($report, $run, PauseReason::ScopeHookMissing, 'hook', $owner);

            return;
        }

        $page = $report->page;

        if (!class_exists($page) || !is_subclass_of($page, Component::class) || !is_subclass_of($page, HasTable::class) || !Access::pageOnPanel($panel, $page)) {
            self::pause($report, $run, PauseReason::TargetGone, 'page', $owner);

            return;
        }

        // The panel's middleware did not run in this worker: the host re-applies the owner's data scope here, before any query.
        $plugin->runBeforeRun($owner, $panel, $report);

        if (method_exists($page, 'canAccess') && !$page::canAccess()) {
            self::pause($report, $run, PauseReason::OwnerLostAccess, 'page');

            return;
        }

        try {
            $component = self::fresh($page);
        } catch (HttpExceptionInterface $exception) {
            // The page's own authorization (a resource policy) said no.
            self::pause($report, $run, in_array($exception->getStatusCode(), [403, 404], true) ? PauseReason::OwnerLostAccess : PauseReason::TargetGone, 'page');

            return;
        }

        $missing = ReportState::apply($component, $report->state);

        if ($missing !== null) {
            self::pause($report, $run, PauseReason::TargetGone, $missing, $owner);

            return;
        }

        $action = ExportForm::action($component, $report->action);

        if ($action === null || !method_exists($action, 'queuedExport')) {
            self::pause($report, $run, PauseReason::TargetGone, 'action', $owner);

            return;
        }

        if (!ExportForm::usable($action)) {
            self::pause($report, $run, PauseReason::OwnerLostAccess, 'action');

            return;
        }

        $warnings = [];
        $data = is_array($report->state['export'] ?? null) ? $report->state['export'] : [];
        $available = ExportForm::columnOptions($action, Schema::make($component instanceof HasSchemas ? $component : null));

        if ($available !== null && is_array($data['columns'] ?? null)) {
            $kept = array_values(array_filter($data['columns'], static fn (mixed $column): bool => is_string($column) && array_key_exists($column, $available)));
            $removed = array_values(array_diff(array_map('strval', $data['columns']), $kept));

            if ($kept === []) {
                self::pause($report, $run, PauseReason::TargetGone, 'columns', $owner);

                return;
            }

            if ($removed !== []) {
                $warnings[] = 'columns_removed:'.implode(',', $removed);
            }

            $data['columns'] = $kept;
        }

        $query = $component->getFilteredSortedTableQuery();

        if ($query === null) {
            self::pause($report, $run, PauseReason::TargetGone, 'query', $owner);

            return;
        }

        $temporary = QueuedExports::temporaryFile();

        try {
            /** @var array{file_name: string, rows: int} $result */
            $result = $action->queuedExport($component, $query, $data, $temporary);

            if ($result['rows'] === 0 && $report->skip_empty) {
                self::finish($report, $run, RunStatus::Skipped, ['warnings' => $warnings, 'rows' => 0]);

                return;
            }

            $id = strtolower((string) Str::ulid());
            // The scope makes the download route ask this plugin before it streams the file, and the time limit
            // holds even when the export's own retention is "never".
            QueuedExports::store($id, $temporary, $result['file_name'], $result['rows'], [
                ...$payload,
                'scope' => 'scheduled-reports',
                'scope_data' => ['page' => $report->page, 'panel' => $report->panel],
                'keep_hours' => max(1, (int) config('filament-scheduled-reports.file_retention_hours', 168)),
            ]);
        } catch (ExportTooLarge $exception) {
            self::fail($report, $run, RunStatus::TooLarge, 'too_large:'.$exception->count.':'.$exception->limit, $owner);

            return;
        } finally {
            @unlink($temporary);
        }

        self::deliver($report, $run, $owner, $panel, $plugin, $id, $result, $warnings);
    }

    /**
     * @param array{file_name: string, rows: int} $result
     * @param list<string>                        $warnings
     */
    private static function deliver(ScheduledReport $report, ScheduledReportRun $run, Authenticatable $owner, Panel $panel, ScheduledReportsPlugin $plugin, string $id, array $result, array $warnings): void
    {
        $resolved = Recipients::resolve($report, $owner, $plugin, $panel);
        $warnings = [...$warnings, ...$resolved['dropped']];
        $disk = QueuedExports::disk();
        $size = (int) $disk->size(QueuedExports::filePath($id));
        $limit = max(0, (int) config('filament-scheduled-reports.max_attachment_mb', 10)) * 1024 * 1024;
        $oversize = $size > $limit;
        $diskName = is_string(config('filament-xlsx-export.queue.disk')) ? (string) config('filament-xlsx-export.queue.disk') : 'local';
        $ownerKey = Recipients::key(Recipients::onlyOwner($owner)[0]);
        $send = $resolved['send'];

        $told = [];

        if ($oversize) {
            // The download link needs a sign-in as the owner, so it is only useful to the owner; everybody
            // else is named in the history, and told in a short mail, instead of getting a link that cannot work
            // (or a file that is too big).
            $ownerEntry = self::ownerEntry($owner, $ownerKey);
            $others = array_filter($send, static fn (array $entry): bool => $entry['key'] !== $ownerKey);

            if ($others !== []) {
                $warnings[] = 'oversize_not_sent:'.count($others);
            }

            $told = array_values($others);
            $send = $ownerEntry === null ? [] : [$ownerEntry];
        }

        if ($send === []) {
            self::pause($report, $run, PauseReason::NoRecipients, 'recipients', $owner);
            $disk->delete([QueuedExports::filePath($id), QueuedExports::metaPath($id)]);

            return;
        }

        foreach ($told as $entry) {
            Mail::to($entry['email'])->locale($report->locale)->queue(new ProblemMail(
                $report->name,
                __('filament-scheduled-reports::filament-scheduled-reports.mail_oversize_title', ['name' => $report->name]),
                __('filament-scheduled-reports::filament-scheduled-reports.mail_oversize_body', ['name' => $report->name, 'size' => number_format($size / 1048576, 1), 'limit' => number_format($limit / 1048576), 'owner' => Recipients::name($owner)]),
                Unsubscribe::url($report, $entry['key']),
            ));
        }

        $notes = array_values(array_filter($warnings, static fn (string $warning): bool => str_starts_with($warning, 'columns_removed:')));

        foreach ($send as $entry) {
            $link = $oversize ? QueuedExports::url($id, $report->url_root, max(1, (int) config('filament-scheduled-reports.file_retention_hours', 168))) : null;

            Mail::to($entry['email'])->locale($report->locale)->queue(new ReportMail(
                $report->name,
                $entry['name'],
                $result['file_name'],
                $result['rows'],
                $report->summary,
                Unsubscribe::url($report, $entry['key']),
                $diskName,
                QueuedExports::filePath($id),
                $link,
                $notes,
            ));
        }

        self::finish($report, $run, RunStatus::Success, [
            'rows' => $result['rows'],
            'size_bytes' => $size,
            'delivery' => $oversize ? 'link' : 'attached',
            'recipients_count' => count($send),
            'file_id' => $id,
            'warnings' => $warnings,
        ]);

        self::resetFailures($report);
    }

    /**
     * @return array{key: string, email: string, name: string}|null
     */
    private static function ownerEntry(Authenticatable $owner, string $key): ?array
    {
        $email = Recipients::email($owner);

        return $email === null ? null : ['key' => $key, 'email' => $email, 'name' => Recipients::name($owner)];
    }

    /**
     * A page built the way Livewire builds it for a first visit, with no HTTP request behind it. The
     * `mount` event runs `boot()`, the trait boots and `mount()` itself, which is what makes a Filament
     * page build its table.
     *
     * @param class-string<Component&HasTable> $page
     *
     * @return Component&HasTable
     */
    private static function fresh(string $page): Component
    {
        $component = app('livewire')->new($page);

        trigger('mount', $component, [], null, null, []);

        /** @var Component&HasTable $component */
        return $component;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function finish(ScheduledReport $report, ScheduledReportRun $run, RunStatus $status, array $values = []): void
    {
        $run->update([...$values, 'status' => $status, 'finished_at' => self::stamp()]);
        $report->update(['last_run_at' => self::stamp()]);
    }

    private static function pause(ScheduledReport $report, ScheduledReportRun $run, PauseReason $reason, string $detail, ?Authenticatable $notify = null): void
    {
        $report->update(['status' => ReportStatus::Paused, 'paused_reason' => $reason, 'next_run_at' => null]);
        $run->update(['status' => RunStatus::Paused, 'reason' => $reason->value.':'.$detail, 'finished_at' => self::stamp()]);

        // Somebody who lost access is not written to (the admin sees the pause in the resource); everybody
        // else is, because a report that stops quietly is the failure this plugin exists to prevent.
        if ($notify !== null) {
            Notifier::tell($notify, $report, __('filament-scheduled-reports::filament-scheduled-reports.notify_paused', ['reason' => $reason->label()]), __('filament-scheduled-reports::filament-scheduled-reports.notify_paused_detail', ['detail' => Reasons::text($reason->value.':'.$detail)]));
        }
    }

    private static function fail(ScheduledReport $report, ScheduledReportRun $run, RunStatus $status, string $reason, ?Authenticatable $notify): void
    {
        $run->update(['status' => $status, 'reason' => mb_substr($reason, 0, 500), 'finished_at' => self::stamp()]);
        $report->update(['last_run_at' => self::stamp()]);

        $paused = self::countFailure($report);

        if ($notify === null) {
            return;
        }

        if ($status === RunStatus::TooLarge) {
            Notifier::tell($notify, $report, __('filament-scheduled-reports::filament-scheduled-reports.notify_too_large'), Reasons::text($reason));
        }

        if ($paused) {
            Notifier::tell($notify, $report, __('filament-scheduled-reports::filament-scheduled-reports.notify_paused', ['reason' => PauseReason::Failed->label()]), __('filament-scheduled-reports::filament-scheduled-reports.notify_failed_detail', ['count' => (int) config('filament-scheduled-reports.max_failures', 3)]));
        }
    }

    /**
     * Records a failed run that no catch block of ours saw (an exception, or a worker killed on timeout).
     * Safe to call twice for one run.
     */
    public static function recordFailure(int $reportId, int $runId, Throwable $exception): void
    {
        $report = ScheduledReport::query()->find($reportId);
        $run = ScheduledReportRun::query()->find($runId);

        if ($report === null || $run === null || !in_array($run->status, [RunStatus::Queued, RunStatus::Running], true)) {
            return;
        }

        // The message can carry SQL, hosts and paths: it goes to the log, the history gets the class only.
        Log::error("filament-scheduled-reports: run {$runId} of report {$reportId} failed: ".$exception->getMessage(), ['exception' => $exception]);

        self::fail($report, $run, RunStatus::Failed, self::reasonFor($exception), self::owner($report));
    }

    public static function reasonFor(Throwable $exception): string
    {
        return 'exception:'.class_basename($exception);
    }

    /**
     * Counts one more failure in a row, atomically, and pauses the report on the limit.
     *
     * @return bool whether this failure paused the report
     */
    private static function countFailure(ScheduledReport $report): bool
    {
        $report->newQuery()->whereKey($report->id)->increment('consecutive_failures');
        $report->refresh();

        if ($report->consecutive_failures < max(1, (int) config('filament-scheduled-reports.max_failures', 3)) || !$report->isActive()) {
            return false;
        }

        $report->update(['status' => ReportStatus::Paused, 'paused_reason' => PauseReason::Failed, 'next_run_at' => null]);

        return true;
    }

    private static function resetFailures(ScheduledReport $report): void
    {
        if ($report->consecutive_failures !== 0) {
            $report->update(['consecutive_failures' => 0]);
        }
    }

    private static function owner(ScheduledReport $report): ?Authenticatable
    {
        return Owners::find($report->guard, $report->owner_id);
    }

    private static function stamp(): string
    {
        return now()->utc()->format(Scheduler::FORMAT);
    }
}
