<?php

use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Shop\Actions\AssignDriver;
use App\Domain\Shop\Actions\ConfirmOrder;
use App\Domain\Shop\Actions\PlaceOrder;
use App\Domain\Shop\Models\Cart;
use App\Domain\Shop\Models\Driver;
use App\Domain\Shop\Models\Order;
use App\Domain\Shop\Models\ProductReview;
use App\Domain\Shop\Models\ShopCoupon;
use App\Domain\Shop\Models\StaffPick;
use App\Domain\Team\Models\Staff;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Shop\ShopKit;

beforeEach(fn () => MessagingManager::fake());

it('module disabled → /shop 403', function () {
    $tenant = createTenant('essential');
    $this->get(tenantUrl($tenant, 'shop'))->assertForbidden();
    $this->get(tenantUrl($tenant, 'shop/cart'))->assertForbidden();
    $this->getJson(tenantUrl($tenant, 'shop/search/suggest?q=sh'))->assertForbidden();
});

it('renders the storefront with filters, sorting and SEO', function () {
    $tenant = createTenant();
    ShopKit::variant($tenant, ['name' => ['en' => 'Curl Cream', 'ar' => 'كريم الشعر المجعد'], 'brand' => 'Oribe', 'category' => 'hair-care', 'attributes' => ['hair_type' => 'curly'], 'price' => 9000]);
    ShopKit::variant($tenant, ['name' => ['en' => 'Face Oil', 'ar' => 'زيت الوجه'], 'brand' => 'La Mer', 'category' => 'skin', 'attributes' => ['skin_type' => 'dry'], 'price' => 30000]);
    ShopKit::variant($tenant, ['name' => ['en' => 'Pro Bleach', 'ar' => 'مبيض'], 'usage' => 'professional']);

    $this->get(tenantUrl($tenant, 'shop'))->assertOk()->assertSee('Curl Cream')->assertSee('Face Oil')->assertDontSee('Pro Bleach')
        ->assertSee('application/ld+json', false);
    $this->get(tenantUrl($tenant, 'shop?hair_type=curly'))->assertOk()->assertSee('Curl Cream')->assertDontSee('Face Oil');
    $this->get(tenantUrl($tenant, 'shop?brand[]=la-mer'))->assertOk()->assertSee('Face Oil')->assertDontSee('Curl Cream');
    $this->get(tenantUrl($tenant, 'shop?min=200'))->assertOk()->assertSee('Face Oil')->assertDontSee('Curl Cream');
    $this->get(tenantUrl($tenant, 'shop/category/skin'))->assertOk()->assertSee('Face Oil')->assertDontSee('Curl Cream');
    $this->get(tenantUrl($tenant, 'shop?sort=price_desc'))->assertOk()->assertSeeInOrder(['Face Oil', 'Curl Cream']);
    $this->getJson(tenantUrl($tenant, 'shop?partial=1&skin_type=dry'))->assertOk()->assertJsonPath('total', 1);
    $this->get(tenantUrl($tenant, 'shop?lang=ar'))->assertOk()->assertSee('dir="rtl"', false)->assertSee('كريم الشعر المجعد');
});

it('renders a product page with availability, services, reviews and stylist picks', function () {
    $tenant = createTenant();
    $variant = ShopKit::variant($tenant, ['name' => ['en' => 'Gel Top Coat', 'ar' => 'طبقة جل'], 'category' => 'nails', 'brand' => 'OPI'], stock: 6);
    $client = ShopKit::client($tenant);
    asTenant($tenant, function () use ($variant, $client) {
        $cat = ServiceCategory::create(['name' => ['en' => 'Nails', 'ar' => 'الأظافر'], 'slug' => 'nails']);
        $service = Service::create(['service_category_id' => $cat->id, 'name' => ['en' => 'Gel manicure', 'ar' => 'مانيكير جل'], 'slug' => 'gel-manicure', 'price' => 18000]);
        $staff = Staff::create(['name' => ['en' => 'Mariam Khalil', 'ar' => 'مريم خليل']]);
        $staff->branches()->attach(Branch::first()->id);
        $pick = ShopKit::variant(tenant(), ['name' => ['en' => 'Cuticle Oil', 'ar' => 'زيت الأظافر']]);
        StaffPick::create(['staff_id' => $staff->id, 'product_id' => $pick->product_id]);
        $booking = Booking::create(['branch_id' => Branch::first()->id, 'client_id' => $client->id, 'reference' => 'LX1234', 'status' => 'completed', 'starts_at' => now()->subWeek(), 'ends_at' => now()->subWeek()->addHour()]);
        $booking->items()->create(['service_id' => $service->id, 'staff_id' => $staff->id, 'starts_at' => now()->subWeek(), 'ends_at' => now()->subWeek()->addHour(), 'price' => 18000, 'duration_minutes' => 60]);
        ProductReview::create(['product_id' => $variant->product_id, 'author_name' => 'Noura', 'rating' => 5, 'title' => 'Glassy finish']);
    });

    $url = tenantUrl($tenant, 'shop/p/'.$variant->product->slug);
    $this->get($url)->assertOk()
        ->assertSee('Gel Top Coat')->assertSee('"@type":"Product"', false)->assertSee('AggregateRating')
        ->assertSee('Gel manicure')->assertSee('Glassy finish')->assertSee('Main')
        ->assertDontSee('Cuticle Oil');

    $this->actingAs($client, 'client')->get($url)->assertOk()->assertSee('Mariam Khalil')->assertSee('Cuticle Oil');
});

it('adds to the cart, applies a coupon and toggles the wishlist through the JSON API', function () {
    $tenant = createTenant();
    $variant = ShopKit::variant($tenant, ['price' => 10000], stock: 3);
    asTenant($tenant, fn () => ShopCoupon::create(['code' => 'glow10', 'type' => 'percent', 'value' => 1000]));
    $client = ShopKit::client($tenant);
    $this->actingAs($client, 'client');

    $this->postJson(tenantUrl($tenant, 'shop/cart/items'), ['variant_id' => $variant->id, 'qty' => 2])->assertOk()->assertJsonPath('cart.count', 2)->assertJsonPath('cart.subtotal', 20000);
    $this->postJson(tenantUrl($tenant, 'shop/cart/items'), ['variant_id' => $variant->id, 'qty' => 5])->assertStatus(422);
    $this->postJson(tenantUrl($tenant, 'shop/cart/coupon'), ['code' => 'GLOW10'])->assertOk()->assertJsonPath('cart.discount', 2000)->assertJsonPath('cart.total', 18000);
    $this->postJson(tenantUrl($tenant, 'shop/cart/gift'), ['gift_wrap' => true, 'gift_message' => 'For Mama'])->assertOk()->assertJsonPath('cart.gift_wrap_fee', 2500);
    $item = $this->getJson(tenantUrl($tenant, 'shop/cart'))->json('items.0.id');
    $this->patchJson(tenantUrl($tenant, 'shop/cart/items/'.$item), ['qty' => 1])->assertOk()->assertJsonPath('cart.count', 1);

    $this->postJson(tenantUrl($tenant, 'shop/wishlist/'.$variant->product_id))->assertOk()->assertJsonPath('wishlisted', true);
    $this->get(tenantUrl($tenant, 'shop/wishlist'))->assertOk()->assertSee($variant->product->name);
    $this->get(tenantUrl($tenant, 'shop/cart'))->assertOk()->assertSee($variant->product->name);
});

it('checks out a guest by inline phone OTP and places a COD pickup order over HTTP', function () {
    $tenant = createTenant();
    $variant = ShopKit::variant($tenant, ['price' => 15000], stock: 3);
    $branch = ShopKit::branch($tenant);

    $this->postJson(tenantUrl($tenant, 'shop/cart/items'), ['variant_id' => $variant->id])->assertOk();
    $cart = asTenant($tenant, fn () => Cart::latest('id')->first());
    $this->withCookie('shop_cart', $cart->token);

    $this->get(tenantUrl($tenant, 'shop/checkout'))->assertOk()->assertSee(__('shop.checkout.title'));
    $this->postJson(tenantUrl($tenant, 'shop/checkout'), ['fulfilment' => 'pickup', 'branch_id' => $branch->id, 'customer_name' => 'Dana', 'payment_method' => 'cod'])
        ->assertStatus(422)->assertJsonPath('message', __('shop.errors.verify_phone'));

    $this->postJson(tenantUrl($tenant, 'shop/checkout/otp'), ['phone' => '5555 2020'])->assertOk()->assertJsonPath('phone', '+97455552020');
    $this->postJson(tenantUrl($tenant, 'shop/checkout/otp/verify'), ['phone' => '+97455552020', 'code' => '123456', 'name' => 'Dana'])->assertOk();
    $this->assertAuthenticated('client');

    $this->postJson(tenantUrl($tenant, 'shop/checkout/quote'), ['fulfilment' => 'pickup', 'branch_id' => $branch->id])->assertOk()->assertJsonPath('total', 15000);
    $res = $this->postJson(tenantUrl($tenant, 'shop/checkout'), ['fulfilment' => 'pickup', 'branch_id' => $branch->id, 'customer_name' => 'Dana', 'payment_method' => 'cod'])->assertOk();

    $order = asTenant($tenant, fn () => Order::latest('id')->first());
    expect($order->customer_phone)->toBe('+97455552020')->and($res->json('redirect'))->toContain($order->public_token);
    $this->get(tenantUrl($tenant, 'shop/order/'.$order->public_token))->assertOk()->assertSee($order->number);
    $this->get(tenantUrl($tenant, 'account/orders'))->assertOk()->assertSee($order->number);
    $this->get(tenantUrl($tenant, 'account/orders?order='.$order->number))->assertOk()->assertSee(__('shop.account.reorder'));
});

it('lets drivers deliver from their signed link and collect COD', function () {
    $tenant = createTenant();
    $variant = ShopKit::variant($tenant, ['price' => 10000], stock: 3);
    $zone = ShopKit::zone($tenant);
    $client = ShopKit::client($tenant);
    $cart = ShopKit::cart($tenant, $client, [[$variant, 1]]);
    [$order, $driver] = asTenant($tenant, function () use ($cart, $zone, $client) {
        $driver = Driver::create(['name' => 'Ravi', 'phone' => '+97455551111']);
        $order = app(PlaceOrder::class)->handle($cart, ['fulfilment' => 'delivery', 'zone_id' => $zone->id, 'area' => 'West Bay', 'payment_method' => 'cod'], $client);
        app(ConfirmOrder::class)->handle($order);
        app(AssignDriver::class)->handle($order, $driver);

        return [$order, $driver];
    });
    $link = asTenant($tenant, fn () => $driver->signedUrl());
    expect($link)->toContain($tenant->slug.'.')->toContain('signature=');
    $link = tenantUrl($tenant, ltrim(parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY), '/'));

    $this->get(tenantUrl($tenant, 'driver/'.$driver->id))->assertForbidden(); // unsigned
    $this->get($link)->assertOk()->assertSee($order->number)->assertSee('wa.me');

    $sign = fn ($o) => tenantUrl($tenant, ltrim(URL::signedRoute('shop.driver.delivered', ['driver' => $driver->id, 'order' => $o->number], absolute: false), '/'));
    $this->post($sign($order), ['step' => 'out'])->assertRedirect()->assertSessionHasNoErrors();
    $this->post($sign($order), ['step' => 'delivered', 'cod_method' => 'card_terminal'])->assertRedirect()->assertSessionHasNoErrors();

    $order->refresh();
    expect($order->status->value)->toBe('delivered')->and($order->cod_method)->toBe('card_terminal')->and($order->paid)->toBe($order->total);
});

it('renders packing slip and invoice PDFs in Arabic and English for staff', function () {
    $tenant = createTenant();
    $variant = ShopKit::variant($tenant, stock: 3);
    $client = ShopKit::client($tenant);
    $cart = ShopKit::cart($tenant, $client, [[$variant, 1]]);
    $order = asTenant($tenant, fn () => app(PlaceOrder::class)->handle($cart, ['fulfilment' => 'pickup', 'branch_id' => ShopKit::branch($tenant)->id, 'payment_method' => 'cod', 'gift_wrap' => true, 'gift_message' => 'مبروك'], $client));

    $this->get(tenantUrl($tenant, "shop/admin/orders/{$order->number}/invoice"))->assertRedirect();
    $this->actingAs($tenant->owner);
    foreach (['packing-slip', 'invoice'] as $doc) {
        foreach (['en', 'ar'] as $lang) {
            $res = $this->get(tenantUrl($tenant, "shop/admin/orders/{$order->number}/{$doc}?lang={$lang}"))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            expect(substr($res->getContent(), 0, 4))->toBe('%PDF');
        }
    }
});
