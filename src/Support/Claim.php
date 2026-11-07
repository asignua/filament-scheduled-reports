<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

use Illuminate\Database\ConnectionInterface;

/**
 * The atomic step that makes a report run once: `UPDATE ... WHERE next_run_at = <what I read>`.
 *
 * Every scheduler tick, every worker box and every overlapping cron may find the same due row; the
 * database lets exactly one of those updates change it. The winner has moved `next_run_at` forward in
 * the same statement, so nobody else can claim the same run, and a crash after the claim loses one run
 * instead of repeating it. The comparison is on the raw string the row held, so no driver or timezone
 * conversion can make the two sides disagree.
 */
final class Claim
{
    public static function advance(ConnectionInterface $db, string $table, int $id, string $expected, ?string $next): bool
    {
        return $db->table($table)
            ->where('id', $id)
            ->where('status', 'active')
            ->where('next_run_at', $expected)
            ->update(['next_run_at' => $next, 'last_run_at' => now()->utc()->format('Y-m-d H:i:s')]) === 1;
    }
}
