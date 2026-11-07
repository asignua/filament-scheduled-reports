<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Enums;

enum Frequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return __('filament-scheduled-reports::filament-scheduled-reports.frequency_'.$this->value);
    }
}
