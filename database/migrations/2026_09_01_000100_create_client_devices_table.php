<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mobile push tokens (FCM). Read by MarketingMessenger through the resolver set in ApiServiceProvider.
        Schema::create('client_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('token', 255);
            $table->string('platform', 10); // ios|android|web
            $table->string('app', 10)->default('client'); // client|staff|owner
            $table->string('locale', 5)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'token']);
            $table->index(['client_id', 'last_seen_at']);
        });

        // Replay store for POST requests carrying an Idempotency-Key header (mobile retries on flaky networks).
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('actor', 60); // "{morph}:{id}"
            $table->string('key', 100);
            $table->string('fingerprint', 64); // sha256(method|path|body)
            $table->unsignedSmallInteger('status_code');
            $table->longText('response_body');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'actor', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('client_devices');
    }
};
