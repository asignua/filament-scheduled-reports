<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->create(['name' => 'Emma Admin', 'email' => 'admin@example.test', 'password' => 'password', 'timezone' => 'Europe/Kyiv']);
        User::query()->create(['name' => 'Oleh Manager', 'email' => 'oleh@example.test', 'password' => 'password', 'timezone' => 'America/New_York']);
        User::query()->create(['name' => 'Nina Sales', 'email' => 'nina@example.test', 'password' => 'password']);

        $customers = collect(['Acme Corp', 'Globex', 'Initech', 'Umbrella'])->map(fn (string $name): Customer => Customer::query()->create(['name' => $name]));
        $names = ['Anvil', 'Bolt', 'Cable', 'Drill', 'Engine', 'Filter', 'Gasket', 'Hammer', 'Insulator', 'Jack', 'Knob', 'Lever'];

        foreach (range(1, 36) as $i) {
            Order::query()->create([
                'name' => $names[$i % count($names)].' #'.$i,
                'customer_id' => $customers[$i % 4]->id,
                'amount' => 20 + ($i * 37) % 480,
                'quantity' => 1 + $i % 9,
                'status' => $i % 3 === 0 ? 'shipped' : 'new',
                'paid' => $i % 4 !== 0,
                'placed_at' => now()->subDays($i),
            ]);
        }
    }
}
