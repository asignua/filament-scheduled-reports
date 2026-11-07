<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Finds a user by key through the guard's own user provider, so it works for whatever user model,
 * key type and provider the application uses.
 */
final class Owners
{
    public static function find(string $guard, string $id): ?Authenticatable
    {
        $provider = config("auth.guards.{$guard}.provider");

        return Auth::createUserProvider(is_string($provider) ? $provider : null)?->retrieveById($id);
    }
}
