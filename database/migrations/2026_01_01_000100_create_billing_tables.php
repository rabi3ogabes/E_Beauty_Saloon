<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('pricing_mode', 20)->default('per_account'); // per_account|per_branch
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->string('slug')->unique();
            $table->json('tagline')->nullable();
            $table->unsignedInteger('included_branches')->default(1);
            $table->unsignedInteger('included_staff')->default(5);
            $table->json('included_modules')->nullable(); // module keys
            $table->unsignedBigInteger('monthly_price')->default(0); // minor units
            $table->unsignedBigInteger('extra_branch_price')->default(0); // per branch per month
            $table->unsignedInteger('included_ai_credits')->default(0); // per month
            $table->string('currency', 3)->default('QAR');
            $table->unsignedInteger('trial_days')->default(14);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('billing_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->nullable()->constrained()->cascadeOnDelete(); // null = platform default
            $table->unsignedTinyInteger('months');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->timestamps();
            $table->unique(['plan_id', 'months']);
        });

        Schema::create('module_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->cascadeOnDelete(); // null = any plan
            $table->unsignedBigInteger('price_per_month');
            $table->boolean('per_branch')->default(false);
            $table->timestamps();
            $table->unique(['module_id', 'plan_id']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('type', 10); // percent|fixed
            $table->unsignedBigInteger('value'); // percent (x100 basis points) or minor units
            $table->string('applies_to', 20)->default('first_cycle'); // first_cycle|forever
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('cycle_months')->default(1);
            $table->unsignedInteger('branches_count')->default(1);
            $table->json('modules'); // all entitled module keys (plan included + extras)
            $table->string('status', 20)->default('pending')->index(); // pending|trialing|active|past_due|grace|suspended|cancelled|expired
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable()->index();
            $table->boolean('auto_renew')->default(true);
            $table->json('price_snapshot')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30)->unique(); // INV-2026-000123
            $table->string('type', 20)->default('subscription'); // subscription|renewal|proration|credits|addon
            $table->string('status', 20)->default('open')->index(); // draft|open|paid|void|refunded
            $table->string('currency', 3)->default('QAR');
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount_total')->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('amount_paid')->default(0);
            $table->date('issued_at');
            $table->date('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('billing_details')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // plan|extra_branch|module|discount|coupon|proration|credits
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('unit_amount');
            $table->bigInteger('amount');
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway', 30); // sadad|skipcash|myfatoorah|stripe|manual
            $table->string('status', 20)->default('pending')->index(); // pending|awaiting_approval|succeeded|failed|refunded
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('QAR');
            $table->string('reference')->nullable()->index();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->string('proof_path')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('payload')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('salon_name')->nullable();
            $table->unsignedInteger('branches')->nullable();
            $table->string('source', 40)->nullable();
            $table->string('stage', 20)->default('new')->index(); // new|contacted|demo|won|lost
            $table->text('message')->nullable();
            $table->foreignId('assigned_to')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['leads', 'platform_payments', 'invoice_items', 'invoices', 'subscriptions', 'coupons', 'module_prices', 'billing_cycles', 'plans', 'modules'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
