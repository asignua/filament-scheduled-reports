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
    | as the owner (the link lives as long as `xlsx-export`'s `queue.retention_hours`). `mail_queue` and
    | `mail_connection` choose where the mails go; `run_queue` and `run_connection` where the report job goes
    | (null = the application's default).
    |
    */
    'max_attachment_mb' => 10,
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
    | Unsubscribe route
    |--------------------------------------------------------------------------
    */
    'unsubscribe' => [
        'register_route' => true,
        'path' => 'scheduled-reports/unsubscribe/{report}/{recipient}',
        'middleware' => ['web'],
    ],
];
