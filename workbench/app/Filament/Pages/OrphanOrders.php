<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Pages;

use Asignua\FilamentScheduledReports\Actions\ScheduleReportAction;
use Filament\Resources\Pages\ListRecords;
use Workbench\App\Filament\Resources\Orders\OrderResource;

/**
 * Tests only: a table page of the orders resource that is registered on no panel.
 */
class OrphanOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [OrderResource::exportAction(), ScheduleReportAction::make()];
    }
}
