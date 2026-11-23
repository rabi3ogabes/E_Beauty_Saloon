<?php

use App\Domain\POS\Models\Commission;
use App\Domain\POS\Models\Register;
use App\Domain\POS\Models\Sale;
use App\Domain\POS\Models\Shift;
use App\Domain\Tenancy\Models\Tenant;
use Database\Seeders\Demo\PosDemoSeeder;
use Database\Seeders\DemoTenantSeeder;

it('seeds the POS demo for Maison Lumière without the booking demo', function () {
    $this->seed(DemoTenantSeeder::class);
    $this->seed(PosDemoSeeder::class);
    $this->seed(PosDemoSeeder::class); // idempotent

    asTenant(Tenant::where('slug', 'lumiere')->firstOrFail(), function () {
        expect(Register::count())->toBe(2)
            ->and(Sale::count())->toBe(150)
            ->and(Sale::where('completed_at', '<', now()->subDays(20))->exists())->toBeTrue()
            ->and(Commission::count())->toBeGreaterThan(100)
            ->and(Shift::where('status', 'open')->count())->toBe(2);
    });
});
