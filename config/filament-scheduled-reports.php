<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    |
    | No prefix by default; change a name here before the first migration if it collides with your own.
    |
    */
    'tables' => [
        'reports' => 'scheduled_reports',
        'runs' => 'scheduled_report_runs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    |
    | `scheduled-reports:run` is registered every minute on the application's scheduler (so the host
    | needs `schedule:run` in cron, as for any scheduled command); `scheduled-reports:prune` runs daily.
    | Set `enabled` to false to register them yourself.
    |
    */
    'schedule' => [
        'enabled' => true,
        'prune_at' => '03:40',
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | `max_attachment_mb`: a bigger file is not attached; the owner gets a link that needs a sign-in
    | as the owner (the link lives `file_retention_hours`, 7 days by default, whatever `xlsx-export`'s
    | `queue.retention_hours` is, even 0; it also needs the owner to still pass the `scheduleReport` gate). `mail_queue` and
    | `mail_connection` choose where the mails go; `run_queue` and `run_connection` where the report job goes
    | (null = the application's default).
    |
    */
    'max_attachment_mb' => 10,
    'file_retention_hours' => 168,
    'run_connection' => null,
    'run_queue' => null,
    'mail_connection' => null,
    'mail_queue' => null,

    /*
    |--------------------------------------------------------------------------
    | Failures and history
    |--------------------------------------------------------------------------
    |
    | After `max_failures` failed runs in a row the report is paused and the owner is told.
    | `history_days`: runs older than this are deleted by `scheduled-reports:prune`.
    | `manual_interval_seconds`: the shortest gap between two "Run now" clicks on one report.
    | `timeout`: the report job's timeout in seconds.
    |
    */
    'max_failures' => 3,
    'history_days' => 90,
    'manual_interval_seconds' => 60,
    'timeout' => 600,

    /*
    |--------------------------------------------------------------------------
    | Limits per owner
    |--------------------------------------------------------------------------
    |
    | `max_reports_per_owner`: saved reports one person may have (0 = no limit). `max_recipients`: people
    | and addresses one report may go to (0 = no limit). `max_concurrent_per_owner`: runs of one person
    | queued or running at once (0 = no limit; default: xlsx-export's `queue.max_per_user`). A due report
    | of an owner at the limit waits for the next minute; a "Run now" is refused.
    |
    */
    'max_reports_per_owner' => 20,
    'max_recipients' => 25,
    'max_concurrent_per_owner' => null,

    /*
    |--------------------------------------------------------------------------
    | Unsubscribe route
    |--------------------------------------------------------------------------
    */
    'unsubscribe' => [
        'register_route' => true,
        'path' => 'scheduled-reports/unsubscribe/{report}/{recipient}',
        'middleware' => ['web'],
    ],
];
