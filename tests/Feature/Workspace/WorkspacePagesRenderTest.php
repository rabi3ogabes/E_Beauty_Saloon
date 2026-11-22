<?php

use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Actions\AddClientNote;
use App\Domain\CRM\Models\Client;
use App\Domain\Inventory\Actions\CreatePurchaseOrder;
use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Inventory\Actions\TransferStock;
use App\Domain\Team\Models\Staff;
use App\Domain\Team\Models\TimeOff;
use App\Domain\Workspace\Actions\SavePaymentGateway;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->tenant = createTenant(branches: 3);
    $this->tenant->forceFill(['onboarded_at' => now()])->save();
    Filament::setCurrentPanel('dashboard');
    asTenant($this->tenant);
    $this->actingAs($this->tenant->owner);
});

it('renders every workspace page for the owner', function (string $path) {
    $this->get(tenantUrl($this->tenant, $path))->assertOk();
})->with([
    'branches' => 'dashboard/branches',
    'branches create' => 'dashboard/branches/create',
    'staff' => 'dashboard/staff',
    'staff create' => 'dashboard/staff/create',
    'time off' => 'dashboard/time-off',
    'team logins' => 'dashboard/team-logins',
    'roles' => 'dashboard/roles',
    'service categories' => 'dashboard/service-categories',
    'services' => 'dashboard/services',
    'services create' => 'dashboard/services/create',
    'product categories' => 'dashboard/product-categories',
    'brands' => 'dashboard/brands',
    'products' => 'dashboard/products',
    'products create' => 'dashboard/products/create',
    'stock' => 'dashboard/stock',
    'stock movements' => 'dashboard/stock-movements',
    'suppliers' => 'dashboard/suppliers',
    'purchase orders' => 'dashboard/purchase-orders',
    'purchase orders create' => 'dashboard/purchase-orders/create',
    'stock transfers' => 'dashboard/stock-transfers',
    'payment methods' => 'dashboard/payment-methods',
    'payment methods create' => 'dashboard/payment-methods/create',
    'clients' => 'dashboard/clients',
    'clients create' => 'dashboard/clients/create',
    'branding' => 'dashboard/branding',
    'business settings' => 'dashboard/business-settings',
    'domains' => 'dashboard/domains',
    'security log' => 'dashboard/security-log',
    'billing' => 'dashboard/billing',
    'onboarding' => 'dashboard/welcome',
]);

it('renders record pages (edit / view) with data, in English and Arabic', function (string $locale) {
    require_once __DIR__.'/WorkspaceHelpers.php';
    $branch = ws_main_branch();
    $service = Service::create(['name' => ['en' => 'Balayage', 'ar' => 'بالياج'], 'slug' => 'balayage', 'duration_minutes' => 180, 'price' => 95000]);
    $service->branches()->attach($branch->id, ['price' => 99000, 'is_active' => true]);
    $variant = ws_variant('R-1');
    app(StockLedger::class)->move($branch->id, $variant->id, 2, 'purchase');
    $staff = Staff::create(['name' => ['en' => 'Lina', 'ar' => 'لينا']]);
    $staff->branches()->attach($branch);
    $client = Client::create(['name' => 'Noor', 'phone' => '55001122', 'allergies' => 'Latex']);
    app(AddClientNote::class)->handle($client, 'VIP');
    $po = app(CreatePurchaseOrder::class)->handle($branch->id, null, [['product_variant_id' => $variant->id, 'qty' => 3, 'unit_cost' => 1000]]);
    $gateway = app(SavePaymentGateway::class)->handle(null, ['driver' => 'stripe', 'credentials' => ['secret_key' => 'sk_live_secret']]);
    app(TransferStock::class)->handle($branch->id, ws_branch('pearl')->id, [$variant->id => 1]);
    TimeOff::create(['staff_id' => $staff->id, 'starts_at' => now(), 'ends_at' => now()->addDay()]);

    foreach (['dashboard/branches/'.$branch->id.'/edit', 'dashboard/staff/'.$staff->id.'/edit', 'dashboard/services/'.$service->id.'/edit',
        'dashboard/products/'.$variant->product_id.'/edit', 'dashboard/clients/'.$client->id, 'dashboard/clients/'.$client->id.'/edit',
        'dashboard/purchase-orders/'.$po->id, 'dashboard/payment-methods/'.$gateway->id.'/edit', 'dashboard/stock', 'dashboard/stock-movements',
        'dashboard/stock-transfers', 'dashboard/services', 'dashboard/products', 'dashboard/staff', 'dashboard/clients', 'dashboard/time-off',
        'dashboard/team-logins', 'dashboard/payment-methods', 'dashboard/security-log', 'dashboard/roles', 'dashboard/branding', 'dashboard/welcome'] as $path) {
        $this->withSession(['locale' => $locale])->get(tenantUrl($this->tenant, $path))->assertOk();
    }
})->with(['en', 'ar']);
