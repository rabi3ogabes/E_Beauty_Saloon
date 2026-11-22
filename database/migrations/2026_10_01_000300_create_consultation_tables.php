<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Client consultation forms (patch test, allergies, medical notes) with e-signature (PNG on the private disk).
        Schema::create('consultation_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->index();
            $table->foreignId('booking_id')->nullable()->index();
            $table->string('token', 64)->unique(); // public link /consultation/{token}
            $table->string('status', 20)->default('sent')->index(); // sent|signed|expired
            $table->string('locale', 5)->default('en');
            $table->text('answers')->nullable(); // encrypted:array — health data
            $table->string('signed_name')->nullable();
            $table->string('signature_path')->nullable(); // local (private) disk
            $table->string('signed_ip', 45)->nullable();
            $table->string('channel', 20)->nullable(); // whatsapp|sms|email|link
            $table->foreignId('sent_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_forms');
    }
};
