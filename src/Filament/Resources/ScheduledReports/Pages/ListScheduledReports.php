<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\Pages;

use Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\ScheduledReportResource;
use Filament\Resources\Pages\ListRecords;

class ListScheduledReports extends ListRecords
{
    protected static string $resource = ScheduledReportResource::class;
}
