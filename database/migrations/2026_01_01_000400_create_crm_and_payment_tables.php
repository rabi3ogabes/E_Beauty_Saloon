<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('name');
            $table->string('phone', 20); // E.164
            $table->string('email')->nullable();
            $table->string('password')->nullable(); // optional website login (OTP is default)
            $table->string('gender', 10)->nullable();
            $table->date('birthday')->nullable();
            $table->string('locale', 5)->default('en');
            $table->foreignId('preferred_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('preferred_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->text('allergies')->nullable(); // encrypted cast, staff-only
            $table->string('source', 30)->nullable(); // walk_in|website|app|instagram|referral|import|whatsapp
            $table->foreignId('referred_by_client_id')->nullable();
            $table->string('referral_code', 16)->nullable();
            $table->bigInteger('lifetime_value')->default(0);
            $table->unsignedInteger('visits_count')->default(0);
            $table->timestamp('last_visit_at')->nullable();
            $table->unsignedInteger('no_shows_count')->default(0);
            $table->bigInteger('wallet_balance')->default(0); // denormalized from wallet ledger
            $table->integer('loyalty_points')->default(0); // denormalized from loyalty ledger
            $table->string('loyalty_tier', 20)->nullable();
            $table->boolean('is_vip')->default(false);
            $table->boolean('is_blocked')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'phone']);
            $table->unique(['tenant_id', 'referral_code']);
            $table->index(['tenant_id', 'last_visit_at']);
        });

        Schema::create('client_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20); // whatsapp|sms|email|push|ai_photos
            $table->boolean('granted');
            $table->string('source', 30)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'channel']);
        });

        Schema::create('client_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->text('body');
            $table->boolean('is_private')->default(true);
            $table->timestamps();
        });

        Schema::create('client_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('kind', 10)->default('before'); // before|after|reference
            $table->string('caption')->nullable();
            $table->nullableMorphs('reference');
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('name');
            $table->string('color', 9)->default('#C8A96A');
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('client_tag', function (Blueprint $table) {
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['client_id', 'tag_id']);
        });

        Schema::create('client_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('phone', 20)->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('driver', 30); // sadad|skipcash|myfatoorah|tap|stripe|cash|card_terminal|bank_transfer|cod
            $table->string('label')->nullable();
            $table->text('credentials')->nullable(); // encrypted:array
            $table->string('mode', 10)->default('test'); // test|live
            $table->json('enabled_for')->nullable(); // ["booking","shop","pos","deposits"]
            $table->json('branch_ids')->nullable(); // null = all branches
            $table->decimal('fee_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('fee_fixed')->default(0);
            $table->string('webhook_secret', 64);
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->nullable()->index();
            $table->foreignId('client_id')->nullable()->index();
            $table->foreignId('tenant_payment_gateway_id')->nullable()->constrained()->nullOnDelete();
            $table->morphs('payable'); // booking|sale|order|gift_card|client_package|ai_credit...
            $table->string('method', 30); // cash|card_terminal|online|wallet|gift_card|points|bank_transfer|cod
            $table->string('driver', 30)->nullable();
            $table->string('purpose', 20)->default('payment'); // payment|deposit|tip|refund
            $table->string('status', 20)->default('pending')->index(); // pending|succeeded|failed|refunded|partially_refunded|cancelled
            $table->bigInteger('amount');
            $table->bigInteger('refunded_amount')->default(0);
            $table->string('currency', 3)->default('QAR');
            $table->string('provider_reference')->nullable()->index();
            $table->string('checkout_url', 1024)->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->foreignId('parent_payment_id')->nullable(); // for refunds
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->index();
            $table->string('driver', 30);
            $table->string('event_id', 150);
            $table->string('event_type', 80)->nullable();
            $table->json('payload');
            $table->string('status', 20)->default('received'); // received|processed|ignored|failed
            $table->string('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['driver', 'event_id']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // topup|spend|refund|store_credit|adjustment|cashback
            $table->bigInteger('amount'); // signed
            $table->bigInteger('balance_after');
            $table->nullableMorphs('reference');
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('code', 24);
            $table->bigInteger('initial_amount');
            $table->bigInteger('balance');
            $table->string('currency', 3)->default('QAR');
            $table->foreignId('purchaser_client_id')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone', 20)->nullable();
            $table->string('recipient_email')->nullable();
            $table->text('message')->nullable();
            $table->string('design', 30)->default('champagne');
            $table->string('status', 20)->default('active'); // pending|active|redeemed|expired|void
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('gift_card_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gift_card_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount'); // signed
            $table->bigInteger('balance_after');
            $table->nullableMorphs('reference');
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('loyalty_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // earn|redeem|referral|bonus|expire|adjustment|reversal
            $table->integer('points'); // signed
            $table->integer('balance_after');
            $table->nullableMorphs('reference');
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->string('note')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['loyalty_ledger', 'gift_card_transactions', 'gift_cards', 'wallet_transactions', 'payment_webhooks', 'payments', 'tenant_payment_gateways', 'client_otps', 'client_tag', 'tags', 'client_photos', 'client_notes', 'client_consents', 'clients'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
