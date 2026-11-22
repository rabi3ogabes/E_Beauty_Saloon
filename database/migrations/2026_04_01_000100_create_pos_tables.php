<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Point of sale: registers, devices, shifts & cash drawer, sales, payments link,
 * refunds and staff commissions. All money in integer minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 12)->nullable(); // short prefix printed on receipts
            $table->unsignedBigInteger('default_float')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_register_id')->nullable()->constrained('pos_registers')->nullOnDelete();
            $table->string('name');
            $table->string('type', 20)->default('tablet'); // tablet|desktop|printer|card_terminal|scanner
            $table->string('identifier', 120)->nullable(); // browser fingerprint / serial
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_register_id')->constrained('pos_registers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained(); // opened by
            $table->foreignId('closed_by')->nullable();
            $table->string('status', 10)->default('open')->index(); // open|closed
            $table->unsignedBigInteger('opening_float')->default(0);
            $table->bigInteger('expected_cash')->nullable();
            $table->bigInteger('counted_cash')->nullable();
            $table->bigInteger('variance')->nullable(); // counted - expected
            $table->json('summary')->nullable(); // snapshot of totals by method at close
            $table->text('notes')->nullable();
            $table->string('z_report_path')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['pos_register_id', 'status']);
        });

        Schema::create('pos_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('pos_shift_id')->constrained('pos_shifts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->string('type', 4); // in|out
            $table->unsignedBigInteger('amount');
            $table->string('reason');
            $table->timestamps();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_register_id')->nullable()->constrained('pos_registers')->nullOnDelete();
            $table->foreignId('pos_shift_id')->nullable()->constrained('pos_shifts')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->index();
            $table->foreignId('user_id')->nullable(); // cashier
            $table->string('number', 40)->nullable(); // e.g. WB-20261122-0007
            $table->date('business_date')->nullable();
            $table->unsignedInteger('sequence')->nullable(); // per branch per business day
            $table->string('status', 20)->default('draft')->index(); // draft|completed|refunded|partially_refunded|voided
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('tips')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('paid')->default(0);
            $table->unsignedBigInteger('change_due')->default(0);
            $table->unsignedBigInteger('refunded_total')->default(0);
            $table->string('currency', 3)->default('QAR');
            $table->decimal('tax_percent', 5, 2)->default(0); // branch rate snapshot
            $table->boolean('tax_inclusive')->default(true);
            $table->json('discount_meta')->nullable(); // {"type":"percent","value":1000,"approved_by":3}
            $table->json('tips_meta')->nullable(); // [{"staff_id":1,"amount":2000}]
            $table->string('idempotency_key', 64)->unique();
            $table->boolean('is_offline')->default(false);
            $table->timestamp('offline_created_at')->nullable();
            $table->json('sync_conflicts')->nullable(); // stock that went negative on offline sync
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'business_date', 'sequence']);
            $table->index(['tenant_id', 'branch_id', 'completed_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // service|product|gift_card|package
            $table->nullableMorphs('sellable'); // Service, ProductVariant, GiftCard, (Package)
            $table->string('name'); // snapshot in the sale locale
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedInteger('refunded_qty')->default(0);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('discount')->default(0); // line + allocated ticket discount
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total'); // amount charged for the line (incl. tax)
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->foreignId('staff_id')->nullable()->index(); // commission
            $table->foreignId('booking_item_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->index(); // core payments row (payable = Sale, or the booking deposit)
            $table->string('method', 20); // cash|card_terminal|online|wallet|gift_card|points|deposit
            $table->unsignedBigInteger('amount'); // applied to the sale
            $table->unsignedBigInteger('tendered')->nullable(); // cash handed over
            $table->unsignedBigInteger('change')->default(0);
            $table->string('reference')->nullable(); // card terminal slip / gift card code
            $table->timestamps();
        });

        Schema::create('sale_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_shift_id')->nullable()->index(); // drawer the cash left from
            $table->string('kind', 10)->default('refund'); // refund|void
            $table->json('items'); // [{"sale_item_id":1,"qty":1,"amount":1500}]
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('cash_amount')->default(0);
            $table->string('method', 20); // original|store_credit
            $table->json('breakdown')->nullable(); // per payment method
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->timestamps();
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->index();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->index();
            $table->foreignId('commission_rule_id')->nullable();
            $table->bigInteger('base_amount'); // commissionable net (excl. tax), signed for reversals
            $table->bigInteger('amount'); // signed: reversals are negative
            $table->json('rule_snapshot')->nullable();
            $table->string('period', 7)->index(); // YYYY-MM
            $table->foreignId('reversal_of_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['commissions', 'sale_refunds', 'sale_payments', 'sale_items', 'sales', 'pos_cash_movements', 'pos_shifts', 'pos_devices', 'pos_registers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
