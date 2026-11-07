<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Tests\Feature;

use Asignua\FilamentScheduledReports\Tests\TestCase;
use Filament\Tables\Contracts\HasTable;
use Livewire\Livewire;
use Workbench\App\Filament\Resources\Orders\Pages\ListOrders;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;

use function Livewire\trigger;

class SpikeTest extends TestCase
{
    public function test_fresh_component_builds_the_tables_query(): void
    {
        $acme = Customer::query()->create(['name' => 'Acme']);
        $other = Customer::query()->create(['name' => 'Other']);
        Order::query()->create(['name' => 'Bolt', 'status' => 'new', 'amount' => 5, 'customer_id' => $acme->id]);
        Order::query()->create(['name' => 'Boat', 'status' => 'new', 'amount' => 50, 'customer_id' => $acme->id]);
        Order::query()->create(['name' => 'Bolt2', 'status' => 'shipped', 'amount' => 7, 'customer_id' => $acme->id]);
        Order::query()->create(['name' => 'Bolt3', 'status' => 'new', 'amount' => 9, 'customer_id' => $other->id]);
        Order::query()->create(['name' => 'Nut', 'status' => 'new', 'amount' => 1, 'customer_id' => $acme->id]);

        // Reference: what the real, mounted table produces.
        $mounted = Livewire::test(ListOrders::class)
            ->set('tableFilters.status.value', 'new')
            ->set('tableFilters.customer.value', (string) $acme->id)
            ->set('tableSearch', 'Bo')
            ->set('tableSort', 'amount:desc');
        $expected = $mounted->instance()->getFilteredSortedTableQuery()->pluck('name')->all();
        $this->assertSame(['Boat', 'Bolt'], $expected);

        // A "worker": a brand new component, no HTTP request, no snapshot.
        $component = app('livewire')->new(ListOrders::class);
        trigger('mount', $component, [], null, null, []);
        $component->tableFilters = ['status' => ['value' => 'new'], 'customer' => ['value' => (string) $acme->id]];
        $component->tableSearch = 'Bo';
        $component->tableSort = 'amount:desc';

        $this->assertInstanceOf(HasTable::class, $component);
        $this->assertSame($expected, $component->getFilteredSortedTableQuery()->pluck('name')->all());
        dump($component->getFilteredSortedTableQuery()->toRawSql());
    }
}
