<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — Salon shop (module "shop"): carts, wishlists, orders, delivery, coupons, reviews.
 * Stock itself lives in branch_stock / stock_movements (shared with POS through StockLedger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete(); // fulfilling branch
            $table->json('name');
            $table->json('areas')->nullable(); // ["West Bay","Dafna"] — matched against the address area
            $table->json('polygon')->nullable(); // [[lat,lng],...] — matched against the map pin
            $table->unsignedBigInteger('fee')->default(0);
            $table->unsignedBigInteger('min_order')->default(0);
            $table->unsignedBigInteger('free_shipping_threshold')->nullable(); // null = never free
            $table->boolean('same_day_enabled')->default(false);
            $table->string('same_day_cutoff', 5)->nullable(); // "14:00" branch local time; null = shop setting
            $table->unsignedBigInteger('same_day_fee')->default(0);
            $table->unsignedSmallInteger('eta_hours')->default(24); // standard delivery estimate
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40)->nullable(); // Home / Office
            $table->string('recipient_name')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('area')->nullable();
            $table->string('street')->nullable();
            $table->string('building')->nullable();
            $table->string('unit', 40)->nullable();
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('notes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // staff login
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->string('partner', 30)->default('internal'); // internal|manual|<courier key>
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shop_coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('code', 40);
            $table->string('type', 10)->default('percent'); // percent|fixed
            $table->unsignedBigInteger('value'); // basis points for percent (1500 = 15%), minor units for fixed
            $table->unsignedBigInteger('min_order')->default(0);
            $table->unsignedBigInteger('max_discount')->nullable(); // cap for percent coupons
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_client')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->boolean('free_shipping')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('token', 64)->unique(); // guest cookie
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete(); // preferred fulfilment branch
            $table->string('phone', 20)->nullable(); // captured at checkout for abandoned-cart recovery
            $table->string('coupon_code', 40)->nullable();
            $table->boolean('gift_wrap')->default(false);
            $table->string('gift_message', 300)->nullable();
            $table->string('status', 20)->default('active'); // active|converted
            $table->foreignId('order_id')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('abandoned_notified_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'client_id', 'status']);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamps();
            $table->unique(['cart_id', 'product_variant_id']);
        });

        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token', 64)->nullable()->index(); // guest
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->constrained(); // fulfilment branch (stock source)
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cart_id')->nullable();
            $table->unsignedInteger('sequence');
            $table->string('number', 20); // SO-10001 (per tenant)
            $table->string('public_token', 40)->unique();
            $table->string('status', 24)->default('pending')->index();
            // pending|confirmed|preparing|ready_for_pickup|out_for_delivery|delivered|cancelled|returned
            $table->string('fulfilment', 12)->default('delivery'); // delivery|pickup|same_day
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete();
            $table->json('address')->nullable(); // snapshot
            $table->string('customer_name');
            $table->string('customer_phone', 20);
            $table->string('customer_email')->nullable();
            $table->string('delivery_slot', 40)->nullable(); // "2026-11-22 16:00-18:00"
            $table->timestamp('ready_estimate_at')->nullable(); // pickup ready time / delivery ETA
            $table->boolean('gift_wrap')->default(false);
            $table->string('gift_message', 300)->nullable();
            $table->foreignId('shop_coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coupon_code', 40)->nullable();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('delivery_fee')->default(0);
            $table->unsignedBigInteger('gift_wrap_fee')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->boolean('tax_inclusive')->default(true);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('paid')->default(0);
            $table->unsignedBigInteger('refunded')->default(0);
            $table->unsignedBigInteger('wallet_applied')->default(0);
            $table->unsignedBigInteger('gift_card_applied')->default(0);
            $table->foreignId('gift_card_id')->nullable();
            $table->string('payment_method', 12)->default('cod'); // online|cod|wallet|gift_card (method for the remainder)
            $table->string('cod_method', 15)->nullable(); // cash|card_terminal (collected on delivery)
            $table->boolean('cod_otp_required')->default(false);
            $table->timestamp('cod_otp_verified_at')->nullable();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->string('delivery_partner', 30)->nullable();
            $table->string('partner_reference')->nullable();
            $table->string('stock_state', 12)->default('none'); // none|reserved|deducted|released|returned
            $table->text('notes')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->string('locale', 5)->default('en');
            $table->string('currency', 3)->default('QAR');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'customer_phone']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->json('name'); // {"en": "...", "ar": "..."} snapshot
            $table->string('variant_name')->nullable();
            $table->string('sku', 60)->nullable();
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('total');
            $table->unsignedInteger('returned_qty')->default(0);
            $table->timestamps();
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('user_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable();
            $table->string('author_name');
            $table->unsignedTinyInteger('rating'); // 1..5
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
            $table->index(['product_id', 'is_published']);
        });

        // "Used in these services" mapping (falls back to matching category slugs).
        Schema::create('shop_product_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unique(['product_id', 'service_id']);
        });

        // "Recommended by your stylist": products a staff member recommends.
        Schema::create('shop_staff_picks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('note')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['staff_id', 'product_id']);
        });

        // Arabic-aware search index (normalized names in both locales, SKUs, barcodes, brand).
        Schema::create('shop_search_index', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['shop_search_index', 'shop_staff_picks', 'shop_product_service', 'product_reviews', 'order_status_history', 'order_items', 'orders',
            'wishlists', 'cart_items', 'carts', 'shop_coupons', 'drivers', 'addresses', 'delivery_zones'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
