<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI insight cards (nightly, idempotent per tenant + fingerprint).
        Schema::create('insight_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->nullable()->index();
            $table->string('type', 40); // off_peak|churn_risk|reorder|no_show|revenue_trend|peak_pricing|off_peak_pricing|demand
            $table->string('fingerprint', 120); // type + scope + ISO week → one card per week, refreshed nightly
            $table->string('period_key', 20); // e.g. 2026-W47
            $table->json('title'); // {"en": "...", "ar": "..."}
            $table->json('body');
            $table->json('metrics'); // aggregated numbers only (no client PII)
            $table->json('action')->nullable(); // {"type":"campaign_draft","params":{...}}
            $table->string('status', 20)->default('new')->index(); // new|done|approved|dismissed
            $table->string('source', 10)->default('template'); // ai|template
            $table->unsignedSmallInteger('priority')->default(50);
            $table->json('result')->nullable(); // {"campaign_id": 12} after the one-click action
            $table->foreignId('acted_by')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'fingerprint']);
        });

        Schema::create('insight_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->date('run_date');
            $table->string('status', 20)->default('running'); // running|done|failed
            $table->unsignedInteger('cards')->default(0);
            $table->boolean('used_ai')->default(false);
            $table->string('error')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'run_date']);
        });

        // Morning report deliveries: one per branch per local business date.
        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->index();
            $table->date('business_date');
            $table->json('channels')->nullable(); // {"email":true,"whatsapp":false}
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'business_date']);
        });

        // Owner alerts log (dedupe by fingerprint; the bell uses Laravel database notifications).
        Schema::create('owner_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('type', 30); // big_sale|vip_checkin|bad_review|low_stock|failed_payment
            $table->string('fingerprint', 150);
            $table->json('data')->nullable();
            $table->boolean('sent_whatsapp')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_alerts');
        Schema::dropIfExists('report_deliveries');
        Schema::dropIfExists('insight_runs');
        Schema::dropIfExists('insight_cards');
    }
};
