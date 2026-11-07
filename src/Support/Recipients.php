<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

use Asignua\FilamentScheduledReports\Models\ScheduledReport;
use Asignua\FilamentScheduledReports\ScheduledReportsPlugin;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Turns the stored recipient list into the people a mail may go to right now.
 *
 * The list is data the owner chose; whether each entry may still receive is decided again at every
 * send: a user taken out of the owner's picker, a user who lost the panel, an outside address once
 * the domain list shrank. Whoever is dropped is named in the run's warnings, never silently skipped.
 */
final class Recipients
{
    /**
     * @return list<array{type: string, id?: string, email?: string}>
     */
    public static function onlyOwner(Authenticatable $owner): array
    {
        return [['type' => 'user', 'id' => (string) $owner->getAuthIdentifier()]];
    }

    /**
     * The stable key of a stored recipient, used in the unsubscribe link.
     *
     * @param array{type: string, id?: string, email?: string} $recipient
     */
    public static function key(array $recipient): string
    {
        if (($recipient['type'] ?? '') === 'user') {
            return 'u-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($recipient['id'] ?? ''));
        }

        return 'e-'.sha1(strtolower((string) ($recipient['email'] ?? '')));
    }

    /**
     * @return array{send: list<array{key: string, email: string, name: string}>, dropped: list<string>}
     */
    public static function resolve(ScheduledReport $report, Authenticatable $owner, ?ScheduledReportsPlugin $plugin, ?Panel $panel): array
    {
        $provider = Auth::guard($report->guard)->getProvider();
        $ownerId = (string) $owner->getAuthIdentifier();
        $allowed = array_map('strval', array_keys($plugin?->recipientOptions($owner) ?? []));
        $send = [];
        $dropped = [];

        foreach ($report->recipients as $recipient) {
            $key = self::key($recipient);

            if (($recipient['type'] ?? '') === 'user') {
                $id = (string) ($recipient['id'] ?? '');
                $user = $id === $ownerId ? $owner : self::user($provider, $id);

                if ($user === null || ($id !== $ownerId && !in_array($id, $allowed, true))
                    || ($user instanceof FilamentUser && $panel !== null && !$user->canAccessPanel($panel))) {
                    $dropped[] = 'recipient_dropped:user:'.$id;

                    continue;
                }

                $email = self::email($user);

                if ($email === null) {
                    $dropped[] = 'recipient_dropped:user:'.$id;

                    continue;
                }

                $send[] = ['key' => $key, 'email' => $email, 'name' => self::name($user)];

                continue;
            }

            $email = strtolower(trim((string) ($recipient['email'] ?? '')));

            if ($plugin === null || !$plugin->allowsExternalRecipients() || filter_var($email, FILTER_VALIDATE_EMAIL) === false || !$plugin->isDomainAllowed($email)) {
                $dropped[] = 'recipient_dropped:email:'.$email;

                continue;
            }

            $send[] = ['key' => $key, 'email' => $email, 'name' => $email];
        }

        return ['send' => $send, 'dropped' => $dropped];
    }

    public static function email(Authenticatable $user): ?string
    {
        $email = $user instanceof Model ? $user->getAttribute('email') : null;

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    public static function name(Authenticatable $user): string
    {
        if ($user instanceof \Filament\Models\Contracts\HasName) {
            return $user->getFilamentName();
        }

        $name = $user instanceof Model ? $user->getAttribute('name') : null;

        return is_string($name) && $name !== '' ? $name : (string) self::email($user);
    }

    private static function user(UserProvider $provider, string $id): ?Authenticatable
    {
        return $provider->retrieveById($id);
    }
}
