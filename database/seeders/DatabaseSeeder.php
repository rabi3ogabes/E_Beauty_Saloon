<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlatformSeeder::class);
        $this->call(DemoTenantSeeder::class);

        // Module demo data: database/seeders/Demo/*Seeder.php, ordered by static $order.
        collect(glob(database_path('seeders/Demo/*Seeder.php')))
            ->map(fn ($f) => 'Database\\Seeders\\Demo\\'.basename($f, '.php'))
            ->sortBy(fn ($c) => property_exists($c, 'order') ? $c::$order : 50)
            ->each(fn ($c) => $this->call($c));
    }
}
