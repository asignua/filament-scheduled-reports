<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Tests;

use Asignua\FilamentScheduledReports\Enums\Frequency;
use Asignua\FilamentScheduledReports\Enums\ReportStatus;
use Asignua\FilamentScheduledReports\Models\ScheduledReport;
use Asignua\FilamentScheduledReports\Models\ScheduledReportRun;
use Asignua\FilamentScheduledReports\ScheduledReportsServiceProvider;
use Asignua\FilamentXlsxExport\Support\QueuedExports;
use Asignua\FilamentXlsxExport\XlsxExportServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\LivewireServiceProvider;
use OpenSpout\Reader\XLSX\Reader;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Workbench\App\Filament\Resources\Orders\Pages\ListOrders;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;
use Workbench\App\Providers\AdminPanelProvider;
use Workbench\App\Providers\TenantPanelProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** Set to false in a test class that needs the application to have defined no gates at all. */
    protected bool $defineScheduleGate = true;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        if ($this->defineScheduleGate) {
            Gate::define('scheduleReport', fn (User $user, ?string $page = null): bool => true);
        }

        $this->actingAs(User::factory()->create(['timezone' => 'Europe/Kyiv']));
        Filament::setCurrentPanel('admin');
    }

    protected function getPackageProviders($app): array
    {
        return [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            XlsxExportServiceProvider::class,
            ScheduledReportsServiceProvider::class,
            AdminPanelProvider::class,
            TenantPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'reports@example.test', 'name' => 'Reports']);
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }

    /**
     * A saved report of the workbench's orders table, owned by the acting user, due in the past.
     *
     * @param array<string, mixed> $attributes
     */
    protected function makeReport(array $attributes = []): ScheduledReport
    {
        /** @var User $owner */
        $owner = $attributes['owner'] ?? auth()->user();
        unset($attributes['owner']);

        $report = new ScheduledReport([
            'owner_id' => (string) $owner->getKey(),
            'guard' => 'web',
            'panel' => 'admin',
            'page' => ListOrders::class,
            'action' => 'xlsxExport',
            'name' => 'Weekly orders',
            'frequency' => Frequency::Daily,
            'time' => '09:00',
            'days' => null,
            'day_of_month' => null,
            'timezone' => 'Europe/Kyiv',
            'locale' => 'en',
            'url_root' => 'http://localhost',
            'state' => ['version' => 1, 'filters' => [], 'search' => null, 'column_searches' => [], 'sort' => null, 'tab' => null, 'export' => []],
            'summary' => [],
            'recipients' => [['type' => 'user', 'id' => (string) $owner->getKey()]],
            'skip_empty' => false,
            'status' => ReportStatus::Active,
            ...$attributes,
        ]);
        $report->next_run_at ??= now()->subMinute()->utc()->format('Y-m-d H:i:s');
        $report->save();

        return $report;
    }

    /**
     * The cell values of the .xlsx a run produced, row by row, header included.
     *
     * @return list<list<mixed>>
     */
    protected function workbookRows(ScheduledReportRun $run): array
    {
        $this->assertNotNull($run->file_id, 'The run stored no file.');

        $reader = new Reader;
        $reader->open(Storage::disk('local')->path(QueuedExports::filePath($run->file_id)));
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(static fn ($cell) => $cell->getValue(), $row->getCells());
            }

            break;
        }

        $reader->close();

        return $rows;
    }

    /**
     * Customers and orders with known names, amounts and statuses.
     */
    protected function seedOrders(): Customer
    {
        $acme = Customer::query()->create(['name' => 'Acme']);
        $other = Customer::query()->create(['name' => 'Other']);

        Order::query()->create(['name' => 'Bolt', 'status' => 'new', 'amount' => 5, 'customer_id' => $acme->id]);
        Order::query()->create(['name' => 'Boat', 'status' => 'new', 'amount' => 50, 'customer_id' => $acme->id]);
        Order::query()->create(['name' => 'Bolt2', 'status' => 'shipped', 'amount' => 7, 'customer_id' => $acme->id]);
        Order::query()->create(['name' => 'Bolt3', 'status' => 'new', 'amount' => 9, 'customer_id' => $other->id]);
        Order::query()->create(['name' => 'Nut', 'status' => 'new', 'amount' => 1, 'customer_id' => $acme->id]);

        return $acme;
    }
}
