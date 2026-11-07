<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $reports = (string) config('filament-scheduled-reports.tables.reports', 'scheduled_reports');
        $runs = (string) config('filament-scheduled-reports.tables.runs', 'scheduled_report_runs');

        Schema::create($reports, function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            // A string: the host's user key may be an int, a uuid or a ulid.
            $table->string('owner_id', 64);
            $table->string('guard', 64);
            $table->string('panel', 64);
            $table->string('page');
            $table->string('action', 64);
            $table->string('name');
            $table->string('frequency', 16);
            $table->string('time', 5);
            $table->json('days')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->string('timezone', 64);
            $table->string('locale', 16)->nullable();
            $table->string('url_root')->nullable();
            $table->json('state');
            $table->json('summary')->nullable();
            $table->json('recipients');
            $table->boolean('skip_empty')->default(false);
            $table->string('status', 16)->default('active');
            $table->string('paused_reason', 32)->nullable();
            $table->dateTime('next_run_at')->nullable();
            $table->dateTime('last_run_at')->nullable();
            $table->dateTime('last_manual_run_at')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamps();

            $table->index(['status', 'next_run_at']);
            $table->index(['owner_id', 'guard']);
        });

        Schema::create($runs, function (Blueprint $table) use ($reports): void {
            $table->id();
            $table->foreignId('scheduled_report_id')->constrained($reports)->cascadeOnDelete();
            $table->string('status', 16);
            $table->boolean('manual')->default(false);
            $table->unsignedInteger('rows')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('delivery', 16)->nullable();
            $table->unsignedSmallInteger('recipients_count')->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('warnings')->nullable();
            $table->string('file_id', 26)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['scheduled_report_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('filament-scheduled-reports.tables.runs', 'scheduled_report_runs'));
        Schema::dropIfExists((string) config('filament-scheduled-reports.tables.reports', 'scheduled_reports'));
    }
};
