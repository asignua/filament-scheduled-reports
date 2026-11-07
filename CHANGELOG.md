# Changelog

All notable changes to `asignua/filament-scheduled-reports` are documented here.

## Unreleased

- First version: «Schedule report» header action for tables with an `XlsxExportAction`, daily / weekly / monthly schedules in the owner's time zone, atomic claim, owner-bound runs, mail delivery with attachment or sign-in link, unsubscribe, history, pause, run now, duplicate, ten languages.
- Security: a **data-scope hook** for the worker, `->beforeRun(fn (Authenticatable $owner, Panel $panel, ScheduledReport $report) => …)`, and `->requireScopeHook()` that refuses to schedule (and pauses stored reports) without it. The README now says up front that panel middleware, `ServingFilament` and a second panel's `bootUsing()` do not run in the worker, and no longer promises «never more».
- Security: the export action must be visible, **not disabled** and **authorized** (button, save and every run); the page must still be on the panel; a stored tab that no longer exists, or a page with no tabs left, pauses the report instead of widening its rows.
- Security: a **manager's pause is binding**: the owner cannot Run now, Resume or Duplicate it, and a run queued before the pause (or before any pause, for a scheduled run) is skipped in `execute()`. New pause reason `manager_paused`.
- Security: per-owner limits (`max_reports_per_owner`, `max_recipients`, `max_concurrent_per_owner`); Duplicate keeps `last_manual_run_at`; the run job's timeout stays under the connection's `retry_after`.
- Security: report files carry a scope, so the download route re-checks the `scheduleReport` gate and the page, and a time limit (`file_retention_hours`) that holds even when the export's retention is 0; `scheduled-reports:prune` removes the files of the runs it deletes. Needs the `asignua/filament-xlsx-export` release with `QueuedExports::checkDownloadsOf()`.
- Security: markdown in a report name, a person's name or a filter value is escaped in the mail (it was a live link); `url_root` comes from `app.url` / `->urlRoot()` (or the panel's configured domain), not from the request's Host; a failed run stores the exception class and logs the message instead of storing SQL or host names; unsubscribing is serialized with a row lock (a lost update could bring someone back).
