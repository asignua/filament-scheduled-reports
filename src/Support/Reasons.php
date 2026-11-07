<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

use Illuminate\Support\Facades\Lang;

/**
 * The run history keeps short machine codes (`target_gone:filter:team`, `columns_removed:city,zip`) so that they
 * survive a change of language and can be tested; people get them as words here.
 */
final class Reasons
{
    private const string LANG = 'filament-scheduled-reports::filament-scheduled-reports.';

    public static function text(string $code): string
    {
        [$head, $rest] = array_pad(explode(':', $code, 2), 2, '');

        return match ($head) {
            'exception' => __(self::LANG.'code_exception', ['class' => $rest]),
            'too_large' => self::tooLarge($rest),
            'columns_removed' => __(self::LANG.'code_columns_removed', ['columns' => implode(', ', explode(',', $rest))]),
            'recipient_dropped' => self::dropped($rest),
            'oversize_not_sent' => __(self::LANG.'code_oversize_not_sent', ['count' => $rest]),
            'owner_lost_access', 'target_gone', 'tenancy_unsupported', 'no_recipients', 'scope_hook_missing', 'skipped', 'manager_paused', 'failed' => self::pause($head, $rest) ?? $code,
            default => $code,
        };
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    public static function texts(array $codes): array
    {
        return array_map(static fn (string $code): string => self::text($code), $codes);
    }

    private static function pause(string $head, string $rest): ?string
    {
        [$detail, $name] = array_pad(explode(':', $rest, 2), 2, '');
        $key = self::LANG.'code_'.$head.'_'.$detail;

        return Lang::has($key) ? (string) __($key, ['name' => $name]) : null;
    }

    private static function tooLarge(string $rest): string
    {
        [$count, $limit] = array_pad(explode(':', $rest, 2), 2, '');

        return __(self::LANG.'code_too_large', ['count' => is_numeric($count) ? number_format((int) $count) : $count, 'limit' => is_numeric($limit) ? number_format((int) $limit) : $limit]);
    }

    private static function dropped(string $rest): string
    {
        [$type, $who] = array_pad(explode(':', $rest, 2), 2, '');

        return $type === 'email'
            ? __(self::LANG.'code_recipient_dropped_email', ['email' => $who])
            : __(self::LANG.'code_recipient_dropped_user', ['id' => $who]);
    }
}
