<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Inventory\Models\BranchStock;
use App\Domain\POS\Actions\OpenShift;
use App\Domain\POS\Models\Register;
use App\Domain\Team\Models\CommissionRule;
use App\Domain\Team\Models\Staff;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * A ready-to-sell salon: 3 services, 2 products (10 in stock each), 2 staff with commission rules,
 * a client, a register with an open shift (float 200.00). Tax 5% inclusive unless overridden.
 */
function posSalon(array $branchOverrides = [], array $modules = []): object
{
    $tenant = createTenant('maison', 1, $modules);

    return asTenant($tenant, function () use ($tenant, $branchOverrides) {
        $branch = Branch::firstOrFail();
        $branch->update(array_merge(['tax_percent' => 5, 'tax_inclusive' => true], $branchOverrides));

        $services = collect([['Blow-dry', 15000], ['Gel manicure', 18000], ['Brow threading', 6000]])->map(fn ($s) => Service::create([
            'name' => ['en' => $s[0], 'ar' => $s[0]], 'slug' => Str::slug($s[0]), 'price' => $s[1], 'duration_minutes' => 45,
        ]));
        $products = collect([['Elixir Oil', 9500], ['Bond Shampoo', 12000]])->map(function ($p) use ($branch) {
            $product = Product::create(['name' => ['en' => $p[0], 'ar' => $p[0]], 'slug' => Str::slug($p[0])]);
            $variant = $product->variants()->create(['name' => ['en' => '100ml'], 'sku' => Str::upper(Str::random(8)), 'barcode' => (string) random_int(1000000000, 9999999999), 'price' => $p[1]]);
            app(StockLedger::class)->move($branch->id, $variant, 10, 'purchase');

            return $variant;
        });
        $layla = Staff::create(['name' => ['en' => 'Layla'], 'is_active' => true]);
        $mariam = Staff::create(['name' => ['en' => 'Mariam'], 'is_active' => true]);
        $layla->branches()->attach($branch->id);
        $mariam->branches()->attach($branch->id);

        // Default: 10% services, 5% products. Layla (staff-specific): 20% services. Specific service rule for Mariam: fixed 10.00
        CommissionRule::create(['applies_to' => 'service', 'type' => 'percent', 'value' => 1000]);
        CommissionRule::create(['applies_to' => 'product', 'type' => 'percent', 'value' => 500]);
        CommissionRule::create(['staff_id' => $layla->id, 'applies_to' => 'service', 'type' => 'percent', 'value' => 2000]);

        $client = Client::create(['name' => 'Noura Al-Kuwari', 'phone' => '55001234', 'email' => 'noura@example.com']);
        $register = Register::create(['branch_id' => $branch->id, 'name' => 'Front desk', 'code' => 'FD']);
        $owner = $tenant->owner;
        $shift = app(OpenShift::class)->handle($register, $owner, 20000);

        return (object) compact('tenant', 'branch', 'services', 'products', 'layla', 'mariam', 'client', 'register', 'shift', 'owner');
    });
}

/** Run as the tenant with the given user signed in (and branch restrictions applied like the middleware does). */
function posActingAs(Tenant $tenant, $user): void
{
    asTenant($tenant);
    test()->actingAs($user);
    app(TenantContext::class)->restrictToBranches($user->restrictedBranchIds());
}

function saleKey(): string
{
    return (string) Str::ulid();
}

function variantStock(ProductVariant $variant, int $branchId): int
{
    return (int) BranchStock::withoutGlobalScopes()->where('branch_id', $branchId)->where('product_variant_id', $variant->id)->value('qty');
}
