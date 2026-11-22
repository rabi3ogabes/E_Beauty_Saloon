<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WhatsApp AI receptionist: one conversation per (tenant, channel, phone).
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('channel', 20)->default('whatsapp');
            $table->string('phone', 20);
            $table->foreignId('client_id')->nullable()->index();
            $table->string('contact_name')->nullable(); // WhatsApp profile name
            $table->string('locale', 5)->default('en');
            $table->string('status', 20)->default('bot')->index(); // bot|handoff|closed
            $table->string('handoff_reason', 40)->nullable(); // requested|low_confidence|ai_unavailable|tool
            $table->unsignedInteger('unread_count')->default(0);
            $table->foreignId('last_booking_id')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'channel', 'phone']);
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('assistant_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3); // in|out
            $table->string('author', 10); // client|bot|staff|system
            $table->text('body');
            $table->json('tool_calls')->nullable(); // [{"name":"book","arguments":{...},"result":{...}}]
            $table->decimal('confidence', 3, 2)->nullable();
            $table->string('provider_message_id', 190)->nullable();
            $table->foreignId('user_id')->nullable(); // staff author
            $table->timestamps();
            $table->unique(['tenant_id', 'provider_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
