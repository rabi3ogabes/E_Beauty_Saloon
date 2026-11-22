<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — Marketing & CRM automation, packages & promotions, loyalty, referrals and reviews.
 * Money columns are integer minor units; percentages are basis points (1500 = 15%).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Channels & message credits ─────────────────────────────────────────
        // Platform-wide (super admin): shared WhatsApp/SMS senders + price per message billed to tenants.
        Schema::create('platform_message_channels', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20)->unique(); // whatsapp|sms|email|push
            $table->string('provider', 30)->nullable(); // meta|twilio|unifonic|http|mail|fcm
            $table->text('credentials')->nullable(); // encrypted:array
            $table->unsignedBigInteger('price_per_message')->default(0); // minor units charged to tenant wallet
            $table->string('currency', 3)->default('QAR');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('tenant_channel_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('channel', 20); // whatsapp|sms|email|push
            $table->string('mode', 20)->default('own'); // own|platform_shared
            $table->string('provider', 30)->nullable(); // meta|twilio|unifonic|http|mail|ses|fcm
            $table->text('credentials')->nullable(); // encrypted:array (tokens, keys, from, reply_to ...)
            $table->string('external_id', 120)->nullable()->index(); // e.g. WhatsApp phone_number_id (webhook routing)
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'channel']);
        });

        Schema::create('message_credit_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique();
            $table->bigInteger('balance')->default(0); // minor units
            $table->string('currency', 3)->default('QAR');
            $table->bigInteger('low_balance_threshold')->default(5000);
            $table->timestamps();
        });

        Schema::create('message_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('type', 20); // topup|debit|refund|adjustment
            $table->bigInteger('amount'); // signed minor units
            $table->bigInteger('balance_after');
            $table->string('channel', 20)->nullable();
            $table->string('reference', 120)->nullable();
            $table->string('idempotency_key', 150)->nullable()->unique();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('name', 120); // snake_case Meta template name
            $table->string('language', 10)->default('en');
            $table->string('category', 20)->default('MARKETING'); // MARKETING|UTILITY|AUTHENTICATION
            $table->text('body'); // with {{1}} placeholders
            $table->string('header_type', 10)->default('none'); // none|text|image|video|document
            $table->string('header_text')->nullable();
            $table->string('header_media_url', 1024)->nullable();
            $table->string('footer')->nullable();
            $table->json('buttons')->nullable(); // [{type: url|phone|quick_reply, text, url|phone}]
            $table->string('status', 20)->default('draft'); // draft|pending|approved|rejected|paused
            $table->string('provider_template_id', 80)->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name', 'language']);
        });

        // ── Packages, memberships, promotions ───────────────────────────────────
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('slug', 120);
            $table->string('pricing', 10)->default('fixed'); // fixed|percent
            $table->unsignedBigInteger('price')->default(0); // fixed price (minor units)
            $table->unsignedInteger('discount_bp')->default(0); // percent off item value (basis points)
            $table->unsignedInteger('sessions_count')->default(1); // total redeemable sessions
            $table->unsignedInteger('validity_days')->nullable();
            $table->json('branch_ids')->nullable(); // null = all branches
            $table->boolean('sellable_online')->default(true);
            $table->boolean('sellable_pos')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('image_path')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->morphs('itemable'); // service|product_variant
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
        });

        Schema::create('client_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained();
            $table->string('status', 20)->default('pending'); // pending|active|used_up|expired|cancelled
            $table->unsignedInteger('sessions_total');
            $table->unsignedInteger('sessions_remaining');
            $table->unsignedBigInteger('price_paid')->default(0);
            $table->string('currency', 3)->default('QAR');
            $table->string('channel', 20)->default('online'); // online|pos|dashboard
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('expiry_reminded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_package_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->index();
            $table->foreignId('service_id')->nullable();
            $table->unsignedInteger('sessions')->default(1);
            $table->string('idempotency_key', 150)->nullable()->unique();
            $table->foreignId('user_id')->nullable();
            $table->timestamp('used_at');
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->json('name');
            $table->json('description')->nullable();
            $table->unsignedBigInteger('monthly_price');
            $table->string('billing', 10)->default('prepaid'); // recurring|prepaid
            $table->unsignedInteger('term_months')->default(1);
            $table->json('perks')->nullable(); // {"included":[{"service_id":1,"quantity":2}],"product_discount_bp":1000,"service_discount_bp":0}
            $table->boolean('sellable_online')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('client_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained();
            $table->string('status', 20)->default('active'); // pending|active|past_due|cancelled|expired
            $table->timestamp('starts_at');
            $table->timestamp('renews_at')->nullable()->index();
            $table->json('perks_used')->nullable(); // {"service:1": 1}
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('renewal_reminded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->json('name');
            $table->string('type', 20); // coupon|happy_hour|first_visit|birthday|flash_sale|bogo
            $table->string('code', 40)->nullable(); // coupons
            $table->string('discount_type', 10)->default('percent'); // percent|fixed
            $table->unsignedBigInteger('discount_value')->default(0); // basis points or minor units
            $table->json('rules')->nullable(); // see ApplyPromotions docs
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_client_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->json('branch_ids')->nullable();
            $table->boolean('show_countdown')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('promotion_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->index();
            $table->nullableMorphs('reference');
            $table->unsignedBigInteger('discount_amount');
            $table->string('idempotency_key', 150)->nullable()->unique();
            $table->timestamps();
        });

        // ── Audiences, banners, campaigns ───────────────────────────────────────
        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('rules'); // {"match":"all","conditions":[{"field":"not_visited_days","value":60}]}
            $table->unsignedInteger('cached_count')->default(0);
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('name');
            $table->string('format', 20)->default('post'); // story (9:16) | post (1:1) | whatsapp (1.91:1)
            $table->string('template', 30)->nullable();
            $table->json('design')->nullable(); // {background, elements:[...]}
            $table->string('exported_path')->nullable(); // PNG on the public disk
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('name');
            $table->foreignId('segment_id')->nullable()->index();
            $table->string('status', 20)->default('draft'); // draft|scheduled|sending|testing|sent|cancelled|failed
            $table->json('channels'); // ordered fallback chain, e.g. ["whatsapp","sms"]
            $table->foreignId('whatsapp_template_id')->nullable();
            $table->json('content'); // {"en":{"subject":..,"body":..},"ar":{...}}
            $table->foreignId('banner_id')->nullable();
            $table->string('media_url', 1024)->nullable();
            $table->string('cta_url', 1024)->nullable(); // default: /book
            $table->string('cta_label')->nullable();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->boolean('ab_enabled')->default(false);
            $table->unsignedTinyInteger('ab_test_percent')->default(20);
            $table->string('ab_metric', 10)->default('click'); // click|booking
            $table->unsignedInteger('ab_wait_hours')->default(4);
            $table->foreignId('ab_winner_variant_id')->nullable();
            $table->timestamp('ab_decided_at')->nullable();
            $table->unsignedBigInteger('estimated_cost')->default(0);
            $table->unsignedInteger('recipients_count')->default(0);
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('key', 4); // A|B
            $table->json('content'); // same shape as campaigns.content
            $table->foreignId('whatsapp_template_id')->nullable();
            $table->boolean('is_winner')->default(false);
            $table->timestamps();
        });

        Schema::create('campaign_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_variant_id')->nullable();
            $table->foreignId('client_id')->index();
            $table->string('channel', 20)->nullable(); // channel actually used
            $table->string('status', 24)->default('queued')->index();
            // held (A/B remainder)|queued|sent|delivered|read|clicked|failed|skipped_no_consent|skipped_quiet_hours|skipped_credits
            $table->string('provider_message_id', 190)->nullable()->index();
            $table->string('error')->nullable();
            $table->string('token', 40)->unique(); // tracked short link /c/{token} + unsubscribe /u/{token}
            $table->unsignedBigInteger('cost')->default(0);
            $table->timestamp('scheduled_for')->nullable()->index(); // quiet-hours deferral
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->foreignId('booking_id')->nullable();
            $table->timestamp('booked_at')->nullable();
            $table->unsignedBigInteger('revenue')->default(0);
            $table->timestamps();
            $table->unique(['campaign_id', 'client_id']);
        });

        // ── Automations ─────────────────────────────────────────────────────────
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('name');
            $table->string('recipe', 40)->nullable(); // welcome_series|birthday|win_back|...
            $table->string('trigger', 40)->index(); // trigger registry key
            $table->json('trigger_config')->nullable(); // e.g. {"days":60}
            $table->json('steps'); // [{"type":"wait","hours":24},{"type":"send_message",...}]
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('runs_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'trigger', 'is_active']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->index();
            $table->string('trigger', 40);
            $table->json('context')->nullable();
            $table->string('status', 20)->default('running'); // running|waiting|completed|stopped|failed
            $table->unsignedInteger('current_step')->default(0);
            $table->timestamp('resume_at')->nullable()->index();
            $table->string('idempotency_key', 190)->unique();
            $table->json('log')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // ── Loyalty, referrals, reviews ─────────────────────────────────────────
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('referrer_client_id')->index();
            $table->foreignId('referred_client_id')->unique();
            $table->string('status', 20)->default('pending'); // pending|rewarded|cancelled
            $table->foreignId('promotion_id')->nullable(); // friend's first-visit coupon
            $table->foreignId('first_booking_id')->nullable();
            $table->unsignedInteger('points_awarded')->default(0);
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->nullable()->index();
            $table->foreignId('booking_id')->nullable()->unique();
            $table->foreignId('branch_id')->nullable()->index();
            $table->foreignId('staff_id')->nullable()->index();
            $table->unsignedTinyInteger('rating'); // 1..5
            $table->text('comment')->nullable();
            $table->string('status', 20)->default('pending'); // published|private|pending
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->foreignId('replied_by')->nullable();
            $table->string('source', 20)->default('website'); // website|whatsapp|google|import|dashboard
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'rating']);
        });

        // ── Gift card online orders (Payable wrapper around core gift_cards) ──────
        Schema::create('gift_card_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->uuid('uuid')->unique();
            $table->foreignId('gift_card_id')->nullable()->index();
            $table->foreignId('purchaser_client_id')->nullable();
            $table->string('purchaser_name');
            $table->string('purchaser_phone', 20)->nullable();
            $table->string('purchaser_email')->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('QAR');
            $table->string('design', 30)->default('champagne');
            $table->string('status', 20)->default('pending'); // pending|paid|failed
            $table->timestamp('delivered_at')->nullable();
            $table->json('delivery')->nullable(); // channels used
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'gift_card_orders', 'reviews', 'referrals', 'automation_runs', 'automations', 'campaign_messages', 'campaign_variants',
            'campaigns', 'banners', 'segments', 'promotion_redemptions', 'promotions', 'client_memberships', 'memberships',
            'client_package_usages', 'client_packages', 'package_items', 'packages', 'whatsapp_templates',
            'message_credit_transactions', 'message_credit_wallets', 'tenant_channel_settings', 'platform_message_channels',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
