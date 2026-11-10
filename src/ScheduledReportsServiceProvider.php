<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports;

use Asignua\FilamentScheduledReports\Commands\PruneCommand;
use Asignua\FilamentScheduledReports\Commands\RunCommand;
use Asignua\FilamentScheduledReports\Http\UnsubscribeController;
use Asignua\FilamentScheduledReports\Support\Access;
use Asignua\FilamentScheduledReports\Support\Unsubscribe;
use Asignua\FilamentXlsxExport\Support\QueuedExports;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ScheduledReportsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-scheduled-reports';

    public function configurePackage(Package $package): void
    {
        // Translations are read as `__('filament-scheduled-reports::filament-scheduled-reports.<key>')`.
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            ->hasMigration('2026_11_07_000000_create_scheduled_reports_tables')
            ->runsMigrations()
            ->hasCommands([RunCommand::class, PruneCommand::class]);
    }

    public function packageBooted(): void
    {
        $this->registerUnsubscribeRoutes();

        // A report file is the owner's data: every download re-asks whether the owner may still schedule that page.
        QueuedExports::checkDownloadsOf('scheduled-reports', static fn (Authenticatable $user, array $data): bool => Access::canDownload($user, $data));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (!(bool) config('filament-scheduled-reports.schedule.enabled', true)) {
                return;
            }

            // withoutOverlapping is not needed for correctness (the claim is atomic) but spares a second tick the query.
            $schedule->command('scheduled-reports:run')->everyMinute()->withoutOverlapping(5);

            $at = config('filament-scheduled-reports.schedule.prune_at', '03:40');
            $schedule->command('scheduled-reports:prune')->dailyAt(is_string($at) ? $at : '03:40');
        });
    }

    private function registerUnsubscribeRoutes(): void
    {
        if (!config('filament-scheduled-reports.unsubscribe.register_route', true)) {
            return;
        }

        $path = (string) config('filament-scheduled-reports.unsubscribe.path', 'scheduled-reports/unsubscribe/{report}/{recipient}');
        $middleware = (array) config('filament-scheduled-reports.unsubscribe.middleware', ['web']);

        Route::middleware($middleware)->group(function () use ($path): void {
            Route::get($path, [UnsubscribeController::class, 'show'])->name(Unsubscribe::ROUTE);

            // The signed URL is the credential, and a mail client's one-click POST cannot carry a CSRF token.
            Route::post($path, [UnsubscribeController::class, 'store'])
                ->withoutMiddleware([
                    // Laravel 13 renamed VerifyCsrfToken to PreventRequestForgery; the string is the Laravel 12 fallback.
                    class_exists(PreventRequestForgery::class) ? PreventRequestForgery::class : 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
                ])
                ->name(Unsubscribe::ROUTE_CONFIRM);
        });
    }
}
