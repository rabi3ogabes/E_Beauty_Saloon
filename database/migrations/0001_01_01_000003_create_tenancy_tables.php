<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('owner_user_id')->nullable()->index();
            $table->string('status', 20)->default('trial')->index(); // trial|active|grace|suspended|cancelled
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('country', 2)->default('QA');
            $table->string('currency', 3)->default('QAR');
            $table->string('timezone')->default('Asia/Qatar');
            $table->string('default_locale', 5)->default('en');
            $table->string('legal_name')->nullable();
            $table->string('tax_number')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('onboarded_at')->nullable();
            $table->json('branding')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->string('type', 20)->default('subdomain'); // subdomain|custom
            $table->boolean('is_primary')->default(false);
            $table->string('dns_token', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('ssl_status', 20)->default('pending'); // pending|issuing|active|failed
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('impersonation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->index();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('impersonated_user_id')->index();
            $table->string('reason');
            $table->string('token', 80)->nullable()->unique();
            $table->string('ip', 45)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_logs');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('tenants');
    }
};
