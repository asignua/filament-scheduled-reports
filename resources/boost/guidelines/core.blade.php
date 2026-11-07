## Filament Scheduled Reports (asignua/filament-scheduled-reports)

- Register the plugin on the panel: `->plugin(Asignua\FilamentScheduledReports\ScheduledReportsPlugin::make())`; `->recipients(fn ($owner) => [id => label])` adds colleagues, `->allowExternalRecipients()->allowedDomains([...])` allows outside addresses (off by default).
- Add `ScheduleReportAction::make()` beside an `XlsxExportAction` on a table page. It inherits the export's columns and options.
- Define the gates `scheduleReport` (user, page class) and `manageScheduledReports` (user). Both are denied until defined: no button, no runs.
- The worker does NOT run panel middleware / `ServingFilament` / `bootUsing()`: re-apply the owner's data scope with `->beforeRun(fn ($owner, $panel, $report) => …)`; `->requireScopeHook()` refuses to schedule without it.
- Runs act as the owner and rebuild the page from stored plain data (no Livewire snapshot); lost access pauses the report. Panels with tenancy are not supported.
- Needs a queue worker and `schedule:run`; `scheduled-reports:run` is registered every minute.
