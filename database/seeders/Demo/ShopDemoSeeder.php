<?php

namespace Database\Seeders\Demo;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Payments\Actions\SettlePayment;
use App\Domain\Payments\Gateways\GatewayManager;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shop\Actions\AssignDriver;
use App\Domain\Shop\Actions\CancelOrder;
use App\Domain\Shop\Actions\ConfirmOrder;
use App\Domain\Shop\Actions\MarkDelivered;
use App\Domain\Shop\Actions\MarkOutForDelivery;
use App\Domain\Shop\Actions\MarkPreparing;
use App\Domain\Shop\Actions\MarkReadyForPickup;
use App\Domain\Shop\Actions\PlaceOrder;
use App\Domain\Shop\Actions\ReturnOrder;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Cart;
use App\Domain\Shop\Models\DeliveryZone;
use App\Domain\Shop\Models\Driver;
use App\Domain\Shop\Models\Order;
use App\Domain\Shop\Models\ProductReview;
use App\Domain\Shop\Models\ShopCoupon;
use App\Domain\Shop\Models\StaffPick;
use App\Domain\Shop\Support\FulfilmentPlanner;
use App\Domain\Shop\Support\SearchIndexer;
use App\Domain\Shop\Support\ShopSettings;
use App\Domain\Team\Models\Staff;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Exceptions\DomainException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shop demo for "Maison Lumière": Doha delivery zones, drivers, coupons, stylist picks, reviews
 * and ~60 orders over the last 45 days in mixed statuses — placed and progressed through the real
 * lifecycle Actions so stock, payments, history and loyalty are consistent.
 */
class ShopDemoSeeder extends Seeder
{
    public static int $order = 40;

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'lumiere')->first();
        if (! $tenant || ! $tenant->hasModule('shop')) {
            return;
        }

        app(TenantContext::class)->run($tenant, function () use ($tenant) {
            if (DeliveryZone::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
                return;
            }
            MessagingManager::fake();
            GatewayManager::fake();
            try {
                $this->seed($tenant);
            } finally {
                Carbon::setTestNow();
                MessagingManager::fake(false);
                GatewayManager::fake(false);
            }
        });
    }

    protected function seed(Tenant $tenant): void
    {
        mt_srand(2026);
        $branches = Branch::orderBy('sort')->get();
        $westBay = $branches->firstWhere('slug', 'west-bay') ?? $branches->first();
        $pearl = $branches->firstWhere('slug', 'the-pearl') ?? $branches->last();

        ShopSettings::save(['cod_enabled' => true, 'cod_limit' => 300000, 'cod_otp_required' => false, 'gift_wrap_price' => 2500, 'same_day_cutoff' => '15:00'], $tenant);

        // Delivery zones — Doha
        $zones = collect([
            [$westBay, 'West Bay', 'الخليج الغربي', ['West Bay', 'Dafna', 'Diplomatic Area', 'Corniche'], 1000, 10000, 25000, true, '16:00', 1500, [[25.3150, 51.5150], [25.3450, 51.5150], [25.3450, 51.5450], [25.3150, 51.5450]]],
            [$pearl, 'The Pearl', 'اللؤلؤة', ['The Pearl', 'Porto Arabia', 'Qanat Quartier', 'Viva Bahriya'], 1000, 10000, 25000, true, '16:00', 1500, [[25.3600, 51.5350], [25.3850, 51.5350], [25.3850, 51.5600], [25.3600, 51.5600]]],
            [$pearl, 'Lusail', 'لوسيل', ['Lusail', 'Marina District', 'Fox Hills', 'Energy City'], 1500, 15000, 35000, true, '14:00', 2000, null],
            [$westBay, 'Al Waab', 'الوعب', ['Al Waab', 'Aspire Zone', 'Al Aziziya', 'Baaya'], 2000, 15000, 40000, false, null, 0, null],
            [$westBay, 'Al Sadd', 'السد', ['Al Sadd', 'Bin Mahmoud', 'Fereej Bin Omran', 'Musheireb'], 1500, 10000, 30000, true, '15:00', 1500, null],
            [$westBay, 'Al Rayyan', 'الريان', ['Al Rayyan', 'Muaither', 'Al Gharrafa'], 2500, 20000, 50000, false, null, 0, null],
        ])->map(fn ($z, $i) => DeliveryZone::create([
            'branch_id' => $z[0]->id, 'name' => ['en' => $z[1], 'ar' => $z[2]], 'areas' => $z[3], 'fee' => $z[4], 'min_order' => $z[5],
            'free_shipping_threshold' => $z[6], 'same_day_enabled' => $z[7], 'same_day_cutoff' => $z[8], 'same_day_fee' => $z[9],
            'polygon' => $z[10], 'eta_hours' => $z[7] ? 24 : 48, 'is_active' => true, 'sort' => $i,
        ]));

        $ravi = Driver::create(['name' => 'Ravi Kumar', 'phone' => '+97455770011', 'branch_id' => $westBay->id, 'partner' => 'internal']);
        $joel = Driver::create(['name' => 'Joel Santos', 'phone' => '+97455770022', 'branch_id' => $pearl->id, 'partner' => 'internal']);
        Driver::create(['name' => 'Doha Express Courier', 'phone' => '+97444990000', 'partner' => 'manual']);
        $drivers = collect([$ravi, $joel]);

        ShopCoupon::create(['code' => 'WELCOME10', 'type' => 'percent', 'value' => 1000, 'max_uses_per_client' => 1, 'is_active' => true]);
        ShopCoupon::create(['code' => 'GLOW50', 'type' => 'fixed', 'value' => 5000, 'min_order' => 30000, 'max_uses' => 200, 'expires_at' => now()->addMonths(2), 'is_active' => true]);
        ShopCoupon::create(['code' => 'FREEDELIVERY', 'type' => 'fixed', 'value' => 0, 'free_shipping' => true, 'is_active' => true]);

        // Stylist picks + "used in these services" mapping by matching categories.
        $products = Product::with(['category', 'variants'])->get();
        $map = ['hair' => ['hair-care', 'styling'], 'nails' => ['nails'], 'skin' => ['skin'], 'makeup' => ['makeup'], 'lashes' => ['makeup'], 'spa' => ['skin']];
        foreach (Staff::withoutGlobalScopes()->where('tenant_id', $tenant->id)->with('services.category')->get() as $staff) {
            $cats = $staff->services->pluck('category.slug')->filter()->unique()->flatMap(fn ($c) => $map[$c] ?? [])->unique();
            $products->filter(fn ($p) => $cats->contains($p->category?->slug))->shuffle()->take(4)->values()
                ->each(fn ($p, $i) => StaffPick::create(['staff_id' => $staff->id, 'product_id' => $p->id, 'sort' => $i]));
        }
        foreach (Service::with('category')->get() as $service) {
            $products->filter(fn ($p) => in_array($p->category?->slug, $map[$service->category?->slug] ?? [], true))->shuffle()->take(2)
                ->each(fn ($p) => DB::table('shop_product_service')->insertOrIgnore(['tenant_id' => $tenant->id, 'product_id' => $p->id, 'service_id' => $service->id]));
        }

        // Reviews
        $names = ['Noura A.', 'Aisha K.', 'Maryam S.', 'Emily R.', 'Hessa M.', 'Priya S.', 'Lina H.', 'Reem F.'];
        $quotes = [
            ['Holy grail', 'My hair has never felt this soft. The team recommended it after my balayage.'],
            ['Worth every riyal', 'Arrived the same afternoon, beautifully wrapped.'],
            ['رائع', 'منتج ممتاز والتوصيل كان سريعاً جداً.'],
            ['Salon finish at home', 'Lasts all week, exactly like after my appointment.'],
            ['Lovely scent', 'Subtle and luxurious — I use it every evening.'],
        ];
        foreach ($products->shuffle()->take(18) as $p) {
            foreach (range(1, mt_rand(1, 3)) as $_) {
                [$title, $body] = $quotes[array_rand($quotes)];
                ProductReview::create(['product_id' => $p->id, 'author_name' => $names[array_rand($names)], 'rating' => mt_rand(4, 5), 'title' => $title, 'body' => $body, 'created_at' => now()->subDays(mt_rand(1, 60))]);
            }
        }

        // Make sure demo orders don't empty the shelves.
        $ledger = app(StockLedger::class);
        foreach (ProductVariant::all() as $v) {
            foreach ($branches as $b) {
                $ledger->move($b->id, $v, 15, 'purchase', null, "shop-demo:{$v->id}:{$b->id}", 'Shop demo restock');
            }
        }

        $this->seedOrders($tenant, $zones, $drivers, $branches);
        $this->seedAbandonedCarts($tenant);

        app(SearchIndexer::class)->reindexTenant($tenant->id);
    }

    protected function seedOrders(Tenant $tenant, $zones, $drivers, $branches): void
    {
        $clients = Client::inRandomOrder()->limit(45)->get();
        $variants = ProductVariant::with('product')->get()->filter(fn ($v) => $v->product?->usage !== 'professional')->values();
        $place = app(PlaceOrder::class);
        Carbon::setTestNow();
        $base = Carbon::now('Asia/Qatar')->toImmutable();

        for ($i = 0; $i < 60; $i++) {
            $daysAgo = (int) round(45 * pow(mt_rand(0, 1000) / 1000, 1.8)); // weighted towards recent orders
            $at = $base->subDays($daysAgo)->setTime(mt_rand(9, 21), mt_rand(0, 59));
            if ($at->gt($base)) {
                $at = $base->subMinutes(mt_rand(20, 180));
            }
            $at = Carbon::instance($at->utc()->toDateTime());
            Carbon::setTestNow($at);

            $client = $clients[$i % $clients->count()];
            $fulfilment = ['delivery', 'delivery', 'delivery', 'pickup', 'pickup', 'same_day'][mt_rand(0, 5)];
            $zone = $zones->random();
            if ($fulfilment === 'same_day' && ! $zone->same_day_enabled) {
                $fulfilment = 'delivery';
            }
            $method = ['cod', 'cod', 'cod', 'online', 'online', 'online', 'cod'][mt_rand(0, 6)];

            $cart = Cart::create(['token' => Str::random(40), 'client_id' => $client->id, 'last_activity_at' => $at, 'gift_wrap' => mt_rand(1, 6) === 1,
                'gift_message' => mt_rand(1, 8) === 1 ? 'Happy birthday habibti ✦' : null, 'coupon_code' => mt_rand(1, 7) === 1 ? 'GLOW50' : null]);
            foreach ($variants->random(mt_rand(1, 3)) as $v) {
                $cart->items()->create(['product_variant_id' => $v->id, 'qty' => mt_rand(1, 2)]);
            }
            $cart->load('items');

            $planner = app(FulfilmentPlanner::class);
            $slot = $fulfilment === 'same_day' ? ($planner->sameDaySlots($zone)[0] ?? null) : null;
            if ($fulfilment === 'same_day' && ! $slot) {
                $fulfilment = 'delivery';
            }
            $area = $zone->areas[array_rand($zone->areas)];

            $data = [
                'fulfilment' => $fulfilment,
                'branch_id' => $branches->random()->id,
                'zone_id' => $zone->id, 'area' => $area, 'street' => 'Street '.mt_rand(800, 990), 'building' => 'Building '.mt_rand(1, 80), 'unit' => 'Apt '.mt_rand(1, 40),
                'delivery_slot' => $slot,
                'payment_method' => $method, 'cod_method' => mt_rand(0, 1) ? 'cash' : 'card_terminal',
                'customer_name' => $client->name, 'customer_email' => $client->email,
            ];
            $order = null;
            foreach ([$data, ['coupon_code' => null, 'fulfilment' => 'pickup', 'payment_method' => 'online'] + $data] as $attempt => $try) {
                try {
                    if ($attempt > 0) {
                        $cart->update(['coupon_code' => null]);
                    }
                    $order = $place->handle($cart->fresh('items'), $try, $client);
                    break;
                } catch (DomainException) {
                    // e.g. below the zone minimum / coupon minimum → retry as a simple pickup
                }
            }
            if (! $order) {
                continue;
            }

            $this->progress($order, $daysAgo, $drivers);
        }
        Carbon::setTestNow();
    }

    /** Advance an order through the lifecycle according to its age. */
    protected function progress(Order $order, int $daysAgo, $drivers): void
    {
        $step = function (int $minutes) {
            Carbon::setTestNow(now()->addMinutes($minutes));
        };
        $roll = mt_rand(1, 100);

        // Online: payment result first (5% fail → cancelled & released).
        if ($order->payment_method === 'online' && $order->status === OrderStatus::Pending) {
            $payment = Payment::where('payable_type', $order->getMorphClass())->where('payable_id', $order->id)->where('status', 'pending')->first();
            $step(3);
            if ($payment && ($roll <= 5 && $daysAgo > 0)) {
                app(SettlePayment::class)->fail($payment, 'declined');

                return;
            }
            if ($payment) {
                if ($daysAgo <= 1 && $roll <= 25) {
                    return; // still awaiting payment
                }
                app(SettlePayment::class)->succeed($payment, 'demo_'.$order->number);
            }
        }

        $order = $order->fresh();
        $target = match (true) {
            $daysAgo <= 1 => [OrderStatus::Pending, OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::OutForDelivery][mt_rand(0, 4)],
            $daysAgo <= 4 => [OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::OutForDelivery, OrderStatus::Delivered][mt_rand(0, 3)],
            $roll <= 7 => OrderStatus::Cancelled,
            $roll <= 11 => OrderStatus::Returned,
            default => OrderStatus::Delivered,
        };
        if ($target === OrderStatus::OutForDelivery && $order->isPickup()) {
            $target = OrderStatus::ReadyForPickup;
        }

        try {
            if ($target === OrderStatus::Pending) {
                return;
            }
            if ($target === OrderStatus::Cancelled) {
                $step(mt_rand(20, 240));
                app(CancelOrder::class)->handle($order, ['client', 'salon', 'out_of_stock'][mt_rand(0, 2)]);

                return;
            }
            if ($order->status === OrderStatus::Pending) {
                $step(mt_rand(10, 90));
                app(ConfirmOrder::class)->handle($order->fresh());
            }
            if ($target === OrderStatus::Confirmed) {
                return;
            }
            $step(mt_rand(30, 120));
            app(MarkPreparing::class)->handle($order->fresh());
            if ($target === OrderStatus::Preparing) {
                return;
            }
            $step(mt_rand(30, 180));
            if ($order->isPickup()) {
                app(MarkReadyForPickup::class)->handle($order->fresh());
                if ($target === OrderStatus::ReadyForPickup) {
                    return;
                }
                $step(mt_rand(60, 60 * 24));
            } else {
                $driver = $drivers->random();
                app(AssignDriver::class)->handle($order->fresh(), $driver);
                app(MarkOutForDelivery::class)->handle($order->fresh());
                if ($target === OrderStatus::OutForDelivery) {
                    return;
                }
                $step(mt_rand(30, 150));
            }
            app(MarkDelivered::class)->handle($order->fresh(), $order->cod_method);
            if ($target === OrderStatus::Returned) {
                $step(60 * 24 * mt_rand(1, 5));
                app(ReturnOrder::class)->handle($order->fresh(), asStoreCredit: (bool) mt_rand(0, 1));
            }
        } catch (DomainException) {
            // leave the order where it is
        }
    }

    protected function seedAbandonedCarts(Tenant $tenant): void
    {
        $variants = ProductVariant::inRandomOrder()->limit(10)->get();
        foreach (Client::inRandomOrder()->limit(6)->get() as $i => $client) {
            $cart = Cart::create(['token' => Str::random(40), 'client_id' => $client->id, 'phone' => $client->phone, 'last_activity_at' => now()->subHours(3 + $i * 5)]);
            $cart->items()->create(['product_variant_id' => $variants[$i]->id, 'qty' => 1]);
        }
        // Browsing-only carts (no phone): count towards conversion, never "abandoned".
        foreach (range(1, 40) as $i) {
            Cart::create(['token' => Str::random(40), 'last_activity_at' => now()->subDays(mt_rand(0, 30)), 'created_at' => now()->subDays(mt_rand(0, 30))]);
        }
    }
}
