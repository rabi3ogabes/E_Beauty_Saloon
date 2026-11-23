<?php

use Tests\Feature\Shop\ShopKit;

it('serves the shop catalog, fulfilment options and a token cart to the mobile app', function () {
    $tenant = createTenant();
    $v = ShopKit::variant($tenant, ['name' => ['en' => 'Bond Oil', 'ar' => 'زيت الرابطة'], 'category' => 'hair-care', 'price' => 12000], stock: 4);
    ShopKit::zone($tenant);
    $headers = ['X-Tenant' => $tenant->slug];

    $this->getJson(centralUrl('api/v1/shop/products?q='.urlencode('زيت')), $headers)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Bond Oil');
    $this->getJson(centralUrl('api/v1/shop/products/'.$v->product->slug), $headers)->assertOk()->assertJsonPath('data.variants.0.price', 12000);
    $this->getJson(centralUrl('api/v1/shop/categories'), $headers)->assertOk()->assertJsonPath('data.0.slug', 'hair-care');
    $this->getJson(centralUrl('api/v1/shop/fulfilment'), $headers)->assertOk()->assertJsonCount(1, 'zones')->assertJsonCount(1, 'pickup_branches');

    $token = $this->postJson(centralUrl('api/v1/shop/cart/items'), ['variant_id' => $v->id, 'qty' => 2], $headers)->assertOk()->assertJsonPath('count', 2)->json('token');
    $this->getJson(centralUrl('api/v1/shop/cart'), $headers + ['X-Cart-Token' => $token])->assertOk()->assertJsonPath('subtotal', 24000);
    $this->postJson(centralUrl('api/v1/shop/cart/items'), ['variant_id' => $v->id, 'qty' => 9], $headers + ['X-Cart-Token' => $token])->assertStatus(422);

    $this->getJson(centralUrl('api/v1/shop/orders'), $headers)->assertUnauthorized();
    $client = ShopKit::client($tenant);
    $this->withToken($client->createToken('app')->plainTextToken)->getJson(centralUrl('api/v1/shop/orders'), $headers)->assertOk()->assertJsonCount(0, 'data');

    $essential = createTenant('essential');
    $this->getJson(centralUrl('api/v1/shop/products'), ['X-Tenant' => $essential->slug])->assertForbidden();
});
