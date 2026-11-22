<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->json('name');
            $table->string('slug');
            $table->string('icon')->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('service_category_id')->nullable()->constrained()->nullOnDelete();
            $table->json('name');
            $table->string('slug');
            $table->json('description')->nullable();
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->unsignedInteger('buffer_before')->default(0);
            $table->unsignedInteger('buffer_after')->default(0);
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedBigInteger('sale_price')->nullable();
            $table->string('gender', 10)->default('women'); // women|men|kids|unisex
            $table->string('image_path')->nullable();
            $table->string('video_url')->nullable();
            $table->boolean('online_bookable')->default(true);
            $table->string('deposit_type', 10)->nullable(); // null|percent|fixed
            $table->unsignedBigInteger('deposit_value')->default(0);
            $table->json('resource_types')->nullable(); // ["chair","wash_basin"]
            $table->unsignedInteger('rebook_after_days')->nullable(); // service cycle for rebook reminders
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('service_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->json('name'); // e.g. Short / Medium / Long
            $table->unsignedInteger('duration_minutes');
            $table->unsignedBigInteger('price');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('service_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->unsignedBigInteger('price')->default(0);
            $table->timestamps();
        });

        Schema::create('branch_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('price')->nullable(); // override
            $table->unsignedInteger('duration_minutes')->nullable(); // override
            $table->unique(['branch_id', 'service_id']);
        });

        Schema::create('staff_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('price')->nullable(); // senior stylist premium
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unique(['staff_id', 'service_id']);
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('parent_id')->nullable();
            $table->json('name');
            $table->string('slug');
            $table->string('image_path')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('name');
            $table->string('slug');
            $table->string('logo_path')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('product_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->json('name');
            $table->string('slug');
            $table->json('description')->nullable();
            $table->json('images')->nullable();
            $table->string('video_url')->nullable();
            $table->decimal('tax_percent', 5, 2)->nullable(); // null = branch default
            $table->string('usage', 15)->default('retail'); // retail|professional|both
            $table->boolean('track_stock')->default(true);
            $table->boolean('sold_online')->default(true);
            $table->json('attributes')->nullable(); // hair_type, skin_type, ...
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->json('name')->nullable(); // "250ml" / "Rose Nude"
            $table->string('sku', 60);
            $table->string('barcode', 60)->nullable()->index();
            $table->string('swatch', 9)->nullable(); // shade color
            $table->unsignedBigInteger('cost')->default(0);
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedBigInteger('sale_price')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
        });

        Schema::create('branch_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->integer('qty')->default(0); // on hand
            $table->integer('reserved')->default(0); // reserved by shop orders
            $table->integer('low_stock_threshold')->default(3);
            $table->timestamps();
            $table->unique(['branch_id', 'product_variant_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // purchase|sale|return|transfer_in|transfer_out|adjustment|used_in_service|order
            $table->integer('qty'); // signed
            $table->integer('balance_after');
            $table->unsignedBigInteger('unit_cost')->nullable();
            $table->nullableMorphs('reference');
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'product_variant_id', 'created_at']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30);
            $table->string('status', 20)->default('draft'); // draft|ordered|partially_received|received|cancelled
            $table->bigInteger('total')->default(0);
            $table->date('expected_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('qty');
            $table->unsignedInteger('received_qty')->default(0);
            $table->unsignedBigInteger('unit_cost');
            $table->timestamps();
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('from_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('status', 20)->default('completed'); // draft|completed|cancelled
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['stock_transfer_items', 'stock_transfers', 'purchase_order_items', 'purchase_orders', 'suppliers', 'stock_movements', 'branch_stock', 'product_variants', 'products', 'brands', 'product_categories', 'staff_services', 'branch_service', 'service_addons', 'service_variants', 'services', 'service_categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
