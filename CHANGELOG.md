# Changelog

All notable changes to `asignua/filament-scheduled-reports` are documented here.

## v1.1.0 - 2026-11-10

- Requires Filament ^5.11.2; replaced deprecated Filament API calls: Requires Filament ^5.11.2; replaced deprecated Filament API in the workbench (BaseFilter::schema() → form()).

## v1.0.1 - 2026-11-09

- Security: on Filament 5.11.2+ the edit page opened for users the resource meant to refuse (a manager could open another person's report: the page asks `getEditAuthorizationResponse()` there, no longer `canEdit()`). The owner-only edit rule, and the view / delete / create rules, now live in the resource's `get…AuthorizationResponse()` methods, which `canX()` follows. HTTP tests cover the edit page for the owner, a manager and a stranger.

## v1.0.0 - 2026-11-09

- «Schedule report» header action for tables with an `XlsxExportAction`: saves filters, search, sort and the export's columns, plus a schedule (daily, weekdays, day of the month, in the owner's time zone) and recipients. Exactly-once runs, owner-bound, mail with the file or a sign-in link, unsubscribe, history, pause, run now, duplicate, ten languages.
- **Data scope in the worker (read this):** panel middleware, `ServingFilament` and `bootUsing()` do not run in the queue worker. Re-apply your scope with `ScheduledReportsPlugin::make()->beforeRun(fn (Authenticatable $owner, Panel $panel, ScheduledReport $report) => …)`, and add `->requireScopeHook()` to refuse scheduling (and pause stored reports) without it.
- Gates deny by default: `scheduleReport` and `manageScheduledReports`; the export action must be visible, enabled and authorized at save, every run and every download.
- A manager's pause is binding; per-owner limits; report files expire (`file_retention_hours`); mail content is escaped.
- Requirements: PHP 8.3+, Laravel 12 or 13, Filament 5, `asignua/filament-xlsx-export` ^1.2, a queue worker and the scheduler.
- No tenant panels in v1: there is no button, and a stored report on such a panel pauses.
- Known limitations: history times are shown in UTC.
