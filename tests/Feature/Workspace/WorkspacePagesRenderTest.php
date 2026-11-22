<?php

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
