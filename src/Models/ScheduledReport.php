<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Models;

use Asignua\FilamentScheduledReports\Enums\Frequency;
use Asignua\FilamentScheduledReports\Enums\PauseReason;
use Asignua\FilamentScheduledReports\Enums\ReportStatus;
use Asignua\FilamentScheduledReports\Support\UnescapedJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ulid
 * @property string $owner_id
 * @property string $guard
 * @property string $panel
 * @property class-string $page
 * @property string $action
 * @property string $name
 * @property Frequency $frequency
 * @property string $time
 * @property list<int>|null $days
 * @property int|null $day_of_month
 * @property string $timezone
 * @property string|null $locale
 * @property string|null $url_root
 * @property array<string, mixed> $state
 * @property list<string> $summary
 * @property list<array{type: string, id?: string, email?: string}> $recipients
 * @property bool $skip_empty
 * @property ReportStatus $status
 * @property PauseReason|null $paused_reason
 * @property string|null $next_run_at UTC, 'Y-m-d H:i:s'; never cast, the application's timezone must not shift it
 * @property string|null $last_run_at UTC
 * @property string|null $last_manual_run_at UTC
 * @property int $consecutive_failures
 */
class ScheduledReport extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('filament-scheduled-reports.tables.reports', 'scheduled_reports');
    }

    protected static function booted(): void
    {
        static::creating(function (self $report): void {
            $report->ulid ??= strtolower((string) Str::ulid());
        });

        // Not left to the foreign key: SQLite (and some hosts) run without constraint enforcement.
        static::deleting(function (self $report): void {
            $report->runs()->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'frequency' => Frequency::class,
            'status' => ReportStatus::class,
            'paused_reason' => PauseReason::class,
            'days' => UnescapedJson::class,
            'state' => UnescapedJson::class,
            'summary' => UnescapedJson::class,
            'recipients' => UnescapedJson::class,
            'skip_empty' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ScheduledReportRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ScheduledReportRun::class, 'scheduled_report_id');
    }

    public static function utc(?string $value): ?CarbonImmutable
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse($value, 'UTC');
    }

    public function nextRunAt(): ?CarbonImmutable
    {
        return self::utc($this->next_run_at);
    }

    public function isActive(): bool
    {
        return $this->status === ReportStatus::Active;
    }
}
