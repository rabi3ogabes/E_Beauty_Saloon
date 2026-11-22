<?php

use App\Domain\Shop\Support\ArabicNormalizer;
use App\Domain\Shop\Support\ProductCatalog;
use Tests\Feature\Shop\ShopKit;

it('normalizes Arabic letters and strips tashkeel', function () {
    expect(ArabicNormalizer::normalize('شَامْبُو'))->toBe('شامبو')
        ->and(ArabicNormalizer::normalize('أحمر إضاءة آمنة'))->toBe('احمر اضاءه امنه')
        ->and(ArabicNormalizer::normalize('مستشفى'))->toBe('مستشفي')
        ->and(ArabicNormalizer::normalize('ـــكريـــم'))->toBe('كريم')
        ->and(ArabicNormalizer::normalize('Bond SHAMPOO No.4'))->toBe('bond shampoo no 4');
});

it('arabic search normalization finds شامبو when stored as شَامْبُو and English names', function () {
    $tenant = createTenant();
    $shampoo = ShopKit::variant($tenant, ['name' => ['en' => 'Gold Lust Shampoo', 'ar' => 'شَامْبُو جولد لاست'], 'brand' => 'Oribe', 'sku' => 'ORB-GL-250']);
    $mask = ShopKit::variant($tenant, ['name' => ['en' => 'Repair Mask', 'ar' => 'ماسك إصلاح الشعر'], 'brand' => 'Kérastase']);

    $find = fn (string $q) => asTenant($tenant, fn () => app(ProductCatalog::class)->search(['q' => $q])->pluck('products.id')->all());

    expect($find('شامبو'))->toBe([$shampoo->product_id])
        ->and($find('شامبوو'))->toBe([])
        ->and($find('gold shampoo'))->toBe([$shampoo->product_id])
        ->and($find('SHAMPOO'))->toBe([$shampoo->product_id])
        ->and($find('orb-gl'))->toBe([$shampoo->product_id]) // SKU
        ->and($find('oribe'))->toBe([$shampoo->product_id]) // brand
        ->and($find('اصلاح'))->toBe([$mask->product_id]) // إ normalized
        ->and($find('ماسك الشعر'))->toBe([$mask->product_id]);

    $this->getJson(tenantUrl($tenant, 'shop/search/suggest?q='.urlencode('شامبو')))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.name', 'Gold Lust Shampoo');
    $this->get(tenantUrl($tenant, 'shop?q='.urlencode('شامبو')))->assertOk()->assertSee('Gold Lust Shampoo')->assertDontSee('Repair Mask');
});

it('keeps the search index in sync and scoped per tenant', function () {
    $a = createTenant();
    $b = createTenant();
    $va = ShopKit::variant($a, ['name' => ['en' => 'Velvet Serum', 'ar' => 'سيروم']]);
    ShopKit::variant($b, ['name' => ['en' => 'Velvet Serum B', 'ar' => 'سيروم']]);

    $find = fn ($t, $q) => asTenant($t, fn () => app(ProductCatalog::class)->search(['q' => $q])->count());
    expect($find($a, 'velvet'))->toBe(1)->and($find($b, 'velvet'))->toBe(1);

    asTenant($a, fn () => $va->product->update(['name' => ['en' => 'Silk Serum', 'ar' => 'سيروم الحرير']]));
    expect($find($a, 'velvet'))->toBe(0)->and($find($a, 'الحرير'))->toBe(1);
});
