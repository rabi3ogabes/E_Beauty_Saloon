<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-level providers (tenant_id null) + optional tenant "bring your own key" rows.
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->index();
            $table->string('driver', 30); // gemini|openai|higgsfield|anthropic|http
            $table->string('name');
            $table->text('api_key')->nullable(); // encrypted cast
            $table->string('base_url')->nullable();
            $table->json('models')->nullable(); // {"image":"...","text":"..."}
            $table->json('capabilities')->nullable(); // ["image","text","video"]
            $table->json('unit_costs')->nullable(); // provider cost per call in USD cents: {"image":4,"text":1}
            $table->json('options')->nullable(); // driver specific (generic http paths...)
            $table->unsignedInteger('priority')->default(10); // lower = preferred
            $table->unsignedInteger('timeout_seconds')->default(60);
            $table->boolean('enabled')->default(true);
            $table->string('health_status', 20)->default('healthy'); // healthy|degraded|down
            $table->unsignedInteger('latency_ms_ema')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('total_calls')->default(0);
            $table->unsignedInteger('total_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_task_prices', function (Blueprint $table) {
            $table->id();
            $table->string('task', 40)->unique(); // hair_tryon|nails_tryon|full_look|consultant|marketing_banner|marketing_copy...
            $table->json('name');
            $table->string('capability', 10)->default('image'); // image|text|video
            $table->unsignedInteger('credits');
            $table->json('preferred_providers')->nullable(); // ordered list of drivers
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_credit_packs', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->unsignedInteger('credits');
            $table->unsignedBigInteger('price'); // minor units
            $table->string('currency', 3)->default('QAR');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique();
            $table->bigInteger('balance')->default(0); // credits (denormalized from transactions)
            $table->unsignedInteger('monthly_included')->default(0); // from plan included_ai_credits
            $table->string('included_period', 7)->nullable(); // last granted period "2026-07"
            $table->unsignedInteger('low_balance_threshold')->default(50);
            $table->timestamp('low_balance_notified_at')->nullable();
            $table->boolean('auto_topup')->default(false);
            $table->foreignId('auto_topup_pack_id')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('ai_wallet_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount'); // signed credits
            $table->bigInteger('balance_after');
            $table->string('type', 20); // purchase|included|charge|refund|adjustment
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->nullable()->index();
            $table->foreignId('user_id')->nullable();
            $table->string('task', 40)->index();
            $table->string('source', 20)->default('studio'); // studio|kiosk|consultant|marketing|dashboard
            $table->foreignId('style_id')->nullable();
            $table->foreignId('ai_provider_id')->nullable()->index();
            $table->string('provider_driver', 30)->nullable();
            $table->string('model')->nullable();
            $table->json('input_refs')->nullable(); // {"photo":"ai-studio/..."} (encrypted files)
            $table->json('output_refs')->nullable(); // {"photo":"..."} or text result
            $table->text('prompt')->nullable();
            $table->unsignedInteger('cost_credits')->default(0); // price of the task
            $table->unsignedInteger('charged_credits')->default(0); // actually charged (0 until success)
            $table->unsignedInteger('provider_cost')->default(0); // USD cents
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('status', 20)->default('queued')->index(); // queued|running|succeeded|failed|refunded
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('attempt_log')->nullable(); // [{"provider":..,"ok":false,"error":"timeout","ms":..}]
            $table->string('device_fingerprint', 64)->nullable();
            $table->string('locale', 5)->default('en');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('style_library', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('category', 20); // hair|eyes|lips|nails|pedicure|makeup|bridal
            $table->string('option', 30)->nullable(); // cut|length|color|highlights|balayage|curls|lashes|brows|...
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('reference_path')->nullable(); // public disk (owner-curated reference image)
            $table->string('swatch', 9)->nullable();
            $table->text('prompt');
            $table->string('task', 40)->default('hair_tryon');
            $table->foreignId('service_id')->nullable();
            $table->json('product_variant_ids')->nullable(); // shop shades
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'category']);
        });

        Schema::create('client_looks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->nullable()->index();
            $table->foreignId('ai_job_id')->nullable()->index();
            $table->foreignId('style_id')->nullable();
            $table->string('reference', 20)->unique(); // used in /book?look=
            $table->string('category', 20)->nullable();
            $table->string('before_path')->nullable(); // local (private) disk, encrypted
            $table->string('after_path')->nullable();
            $table->string('device_fingerprint', 64)->nullable();
            $table->boolean('saved')->default(false);
            $table->timestamp('shared_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('ai_client_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('subject', 80); // client:{id} | device:{fingerprint}
            $table->foreignId('client_id')->nullable();
            $table->string('device_fingerprint', 64)->nullable();
            $table->string('period', 7); // 2026-07
            $table->unsignedInteger('free_used')->default(0);
            $table->unsignedInteger('paid_used')->default(0);
            $table->unsignedInteger('paid_balance')->default(0); // looks bought and not used (carried over)
            $table->timestamps();
            $table->unique(['tenant_id', 'subject', 'period']);
        });

        Schema::create('ai_look_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->nullable();
            $table->string('subject', 80);
            $table->unsignedInteger('looks');
            $table->unsignedBigInteger('amount');
            $table->string('status', 20)->default('pending'); // pending|paid|failed
            $table->timestamps();
        });

        Schema::create('ai_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->nullable()->index();
            $table->string('device_fingerprint', 64)->nullable()->index();
            $table->string('version', 20);
            $table->boolean('age_confirmed')->default(false); // 18+
            $table->boolean('is_minor')->default(false);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['ai_consents', 'ai_look_purchases', 'ai_client_quotas', 'client_looks', 'style_library', 'ai_jobs', 'ai_wallet_transactions', 'ai_wallets', 'ai_credit_packs', 'ai_task_prices', 'ai_providers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
