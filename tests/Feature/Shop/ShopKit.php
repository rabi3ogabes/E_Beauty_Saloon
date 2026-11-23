<?php

namespace Tests\Feature\Shop;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\CRM\Models\Client;
use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Inventory\Models\BranchStock;
use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Domain\Shop\Models\Cart;
use App\Domain\Shop\Models\DeliveryZone;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

/** Shared fixtures for shop tests. */
class ShopKit
{
    public static function branch(Tenant $tenant): Branch
    {
        return asTenant($tenant, fn () => Branch::firstOrFail());
    }

    public static function variant(Tenant $tenant, array $attrs = [], int $stock = 10, ?Branch $branch = null): ProductVariant
    {
        return asTenant($tenant, function () use ($attrs, $stock, $branch) {
            $branch ??= Branch::firstOrFail();
            $slug = Str::slug(($attrs['name']['en'] ?? $attrs['name'] ?? 'Product').'-'.Str::random(4));
            $brand = isset($attrs['brand']) ? Brand::firstOrCreate(['slug' => Str::slug($attrs['brand'])], ['name' => $attrs['brand']]) : null;
            $category = isset($attrs['category']) ? ProductCategory::firstOrCreate(['slug' => $attrs['category']], ['name' => ['en' => ucfirst($attrs['category']), 'ar' => $attrs['category']]]) : null;
            $product = Product::create([
                'name' => $attrs['name'] ?? ['en' => 'Bond Shampoo', 'ar' => 'شامبو'],
                'slug' => $slug,
                'brand_id' => $brand?->id,
                'product_category_id' => $category?->id,
                'attributes' => $attrs['attributes'] ?? ['hair_type' => 'all', 'skin_type' => 'all'],
                'usage' => $attrs['usage'] ?? 'retail',
            ]);
            $variant = $product->variants()->create([
                'name' => $attrs['variant'] ?? ['en' => '250ml', 'ar' => '250 مل'],
                'sku' => $attrs['sku'] ?? 'SKU-'.Str::upper(Str::random(6)),
                'price' => $attrs['price'] ?? 10000,
                'sale_price' => $attrs['sale_price'] ?? null,
                'is_default' => true,
            ]);
            if ($stock > 0) {
                app(StockLedger::class)->move($branch->id, $variant, $stock, 'purchase', null, null, 'test stock');
            }

            return $variant->fresh('product');
        });
    }

    public static function zone(Tenant $tenant, array $attrs = []): DeliveryZone
    {
        return asTenant($tenant, fn () => DeliveryZone::create(array_merge([
            'branch_id' => Branch::firstOrFail()->id,
            'name' => ['en' => 'West Bay', 'ar' => 'الخليج الغربي'],
            'areas' => ['West Bay', 'Dafna'],
            'fee' => 1500,
            'min_order' => 5000,
            'free_shipping_threshold' => 30000,
            'same_day_enabled' => true,
            'same_day_cutoff' => '23:59',
            'same_day_fee' => 1000,
            'is_active' => true,
        ], $attrs)));
    }

    public static function client(Tenant $tenant, string $phone = '+97455550101', array $attrs = []): Client
    {
        return asTenant($tenant, fn () => Client::create(array_merge(['name' => 'Aisha Al-Kuwari', 'phone' => $phone, 'locale' => 'en'], $attrs)));
    }

    /** @param list<array{0: ProductVariant, 1: int}> $lines */
    public static function cart(Tenant $tenant, ?Client $client, array $lines, array $attrs = []): Cart
    {
        return asTenant($tenant, function () use ($client, $lines, $attrs) {
            $cart = Cart::create(array_merge(['token' => Str::random(40), 'client_id' => $client?->id, 'last_activity_at' => now()], $attrs));
            foreach ($lines as [$variant, $qty]) {
                $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => $qty]);
            }

            return $cart->fresh('items');
        });
    }

    public static function gateway(Tenant $tenant, string $driver = 'sadad'): TenantPaymentGateway
    {
        return asTenant($tenant, fn () => TenantPaymentGateway::create([
            'driver' => $driver, 'label' => strtoupper($driver), 'mode' => 'test', 'is_active' => true,
            'enabled_for' => ['shop'], 'credentials' => ['merchant_id' => 'm', 'secret_key' => 's'],
        ]));
    }

    public static function available(Tenant $tenant, ProductVariant $variant, ?Branch $branch = null): array
    {
        return asTenant($tenant, function () use ($variant, $branch) {
            $row = BranchStock::withoutGlobalScopes()->where('branch_id', ($branch ?? Branch::firstOrFail())->id)
                ->where('product_variant_id', $variant->id)->first();

            return ['qty' => (int) $row?->qty, 'reserved' => (int) $row?->reserved];
        });
    }
}
