<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Models;

use Asignua\FilamentScheduledReports\Enums\RunStatus;
use Asignua\FilamentScheduledReports\Support\UnescapedJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $scheduled_report_id
 * @property RunStatus $status
 * @property bool $manual
 * @property int|null $rows
 * @property int|null $size_bytes
 * @property string|null $delivery
 * @property int|null $recipients_count
 * @property string|null $reason
 * @property list<string> $warnings
 * @property string|null $file_id
 * @property string|null $started_at UTC
 * @property string|null $finished_at UTC
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class ScheduledReportRun extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('filament-scheduled-reports.tables.runs', 'scheduled_report_runs');
    }

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'manual' => 'boolean',
            'warnings' => UnescapedJson::class,
        ];
    }

    /**
     * @return BelongsTo<ScheduledReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(ScheduledReport::class, 'scheduled_report_id');
    }
}
