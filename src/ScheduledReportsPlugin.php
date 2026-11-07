<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports;

use Filament\Contracts\Plugin;
use Filament\Panel;

class ScheduledReportsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'asignua-filament-scheduled-reports';
    }

    public function register(Panel $panel): void
    {
        // Register resources, pages, widgets, render hooks on the panel here.
    }

    public function boot(Panel $panel): void
    {
        // Runs when the panel is served.
    }
}
