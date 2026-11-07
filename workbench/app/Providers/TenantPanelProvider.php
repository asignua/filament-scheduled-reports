<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Asignua\FilamentScheduledReports\ScheduledReportsPlugin;
use Filament\Panel;
use Filament\PanelProvider;
use Workbench\App\Models\Team;

/**
 * A panel with tenancy: the background export cannot carry a tenant, so reports must refuse it.
 */
class TenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('tenant')
            ->path('tenant')
            ->tenant(Team::class)
            ->plugin(ScheduledReportsPlugin::make());
    }
}
