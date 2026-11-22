<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Tenancy\Models\Tenant;
use Filament\Facades\Filament;

/** Shared helpers for the owner-workspace tests (prefixed to avoid clashing with other modules). */
function ws_owner_session($test, Tenant $tenant): void
{
    Filament::setCurrentPanel('dashboard');
    asTenant($tenant);
    $test->actingAs($tenant->owner);
}

function ws_branch(string $slug, ?string $en = null): Branch
{
    return Branch::create(['name' => ['en' => $en ?? ucfirst($slug), 'ar' => 'فرع'], 'slug' => $slug, 'opening_hours' => Branch::defaultHours()]);
}

function ws_variant(string $sku = 'SKU-1', int $cost = 5000, int $price = 12000): ProductVariant
{
    $product = Product::create(['name' => ['en' => 'Shampoo '.$sku], 'slug' => 'shampoo-'.strtolower($sku), 'usage' => 'retail']);

    return ProductVariant::create(['product_id' => $product->id, 'name' => ['en' => '250ml'], 'sku' => $sku, 'cost' => $cost, 'price' => $price, 'is_default' => true]);
}

function ws_main_branch(): Branch
{
    return Branch::query()->withoutGlobalScope('allowed_branches')->where('slug', 'main')->firstOrFail();
}
