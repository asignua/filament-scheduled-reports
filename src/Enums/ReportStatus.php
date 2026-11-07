<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Enums;

enum ReportStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
}
