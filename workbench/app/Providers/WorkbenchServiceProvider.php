<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\User;

/**
 * Settings the workbench needs when it runs without the PHPUnit TestCase (the `testbench` console and
 * `testbench serve`), where nothing else points the auth provider at the workbench's user model.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config(['auth.providers.users.model' => User::class]);
    }

    public function boot(): void
    {
        // Both gates are denied until the application defines them; the workbench lets everybody schedule
        // and one address manage everything, which is what the browser walk-through shows.
        Gate::define('scheduleReport', fn (User $user, ?string $page = null): bool => true);
        Gate::define('manageScheduledReports', fn (User $user): bool => $user->email === 'admin@example.test');
    }
}
