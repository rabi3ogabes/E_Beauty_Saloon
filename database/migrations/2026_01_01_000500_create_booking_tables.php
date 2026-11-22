<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking schema is part of the shared core because Booking, POS, Marketing (attribution)
 * and the API all read it. Logic lives in App\Domain\Booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 12); // human friendly e.g. LX7K2Q
            $table->string('status', 24)->default('pending_payment')->index();
            // pending_payment|confirmed|checked_in|in_service|completed|no_show|cancelled_by_client|cancelled_by_salon
            $table->string('source', 20)->default('website'); // website|app|dashboard|walk_in|whatsapp_ai|phone|pos
            $table->dateTime('starts_at')->index(); // stored UTC
            $table->dateTime('ends_at');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('deposit_required')->default(0);
            $table->unsignedBigInteger('amount_paid')->default(0); // online payments/deposits collected before visit
            $table->string('currency', 3)->default('QAR');
            $table->string('payment_preference', 20)->default('pay_at_salon'); // deposit|full|pay_at_salon
            $table->foreignId('group_id')->nullable()->index(); // group bookings share a group id (lead booking id)
            $table->foreignId('recurring_parent_id')->nullable()->index();
            $table->string('recurrence_rule')->nullable(); // e.g. "every:4:weeks:count:6"
            $table->text('client_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->unsignedTinyInteger('reschedule_count')->default(0);
            $table->string('cancellation_reason')->nullable();
            $table->unsignedBigInteger('fee_charged')->default(0); // late cancel / no-show fee
            $table->foreignId('campaign_id')->nullable()->index(); // marketing attribution
            $table->string('utm_source', 60)->nullable();
            $table->string('look_reference')->nullable(); // AI Studio "Book this look"
            $table->foreignId('sale_id')->nullable()->index(); // POS sale that settled it
            $table->foreignId('created_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('reminder_24h_sent_at')->nullable();
            $table->timestamp('reminder_2h_sent_at')->nullable();
            $table->timestamp('review_requested_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'branch_id', 'starts_at']);
        });

        Schema::create('booking_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained();
            $table->foreignId('service_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('resource_id')->nullable()->constrained()->nullOnDelete();
            $table->json('addon_ids')->nullable();
            $table->dateTime('starts_at'); // UTC
            $table->dateTime('ends_at');   // UTC, excludes buffer_after
            $table->unsignedInteger('buffer_before')->default(0);
            $table->unsignedInteger('buffer_after')->default(0);
            $table->unsignedBigInteger('price');
            $table->unsignedInteger('duration_minutes');
            $table->string('guest_name')->nullable(); // group booking member (bridesmaid)
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['staff_id', 'starts_at']);
            $table->index(['resource_id', 'starts_at']);
        });

        Schema::create('booking_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('user_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('slot_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->index();
            $table->foreignId('staff_id')->nullable()->index();
            $table->foreignId('resource_id')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('token', 64)->unique();
            $table->foreignId('booking_id')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->json('service_ids');
            $table->foreignId('staff_id')->nullable();
            $table->date('date_from');
            $table->date('date_to');
            $table->string('time_window', 20)->default('any'); // any|morning|afternoon|evening
            $table->string('status', 20)->default('waiting'); // waiting|offered|booked|expired|cancelled
            $table->timestamp('offered_at')->nullable();
            $table->dateTime('offered_starts_at')->nullable();
            $table->timestamp('offer_expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['waitlist_entries', 'slot_holds', 'booking_status_history', 'booking_items', 'bookings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
