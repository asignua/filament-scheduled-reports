<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ScheduledReportsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-scheduled-reports';

    public function configurePackage(Package $package): void
    {
        // Translations live in resources/lang/<locale>/filament-scheduled-reports.php and are read as
        // `__('filament-scheduled-reports::filament-scheduled-reports.<key>')`. Publish tag: `filament-scheduled-reports-translations`.
        $package->name(static::$name)
            ->hasTranslations()
            ->hasViews();

        // Add a config file only when the plugin really has options: create config/filament-scheduled-reports.php and
        // chain `->hasConfigFile()` here (publish tag `filament-scheduled-reports-config`). Prefer fluent setters on the Plugin.
    }
}
