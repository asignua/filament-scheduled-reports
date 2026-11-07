<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Enums;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';

    /**
     * The table was empty and the report asked not to send an empty file.
     */
    case Skipped = 'skipped';

    case Failed = 'failed';

    /**
     * The table grew past the export's row cap.
     */
    case TooLarge = 'too_large';

    /**
     * The report was paused instead of running (lost access, target gone, ...).
     */
    case Paused = 'paused';

    public function label(): string
    {
        return __('filament-scheduled-reports::filament-scheduled-reports.run_'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Success => 'success',
            self::Failed, self::TooLarge => 'danger',
            self::Paused, self::Skipped => 'warning',
            default => 'gray',
        };
    }
}
