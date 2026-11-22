<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->string('slug');
            $table->json('address')->nullable();
            $table->string('area')->nullable(); // e.g. "West Bay" (SEO landing pages)
            $table->string('city')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('timezone')->default('Asia/Qatar');
            // {"mon":{"open":"10:00","close":"22:00","closed":false,"breaks":[["14:00","15:00"]]},...}
            $table->json('opening_hours')->nullable();
            $table->string('cover_path')->nullable();
            $table->json('gallery')->nullable();
            $table->json('branding')->nullable(); // overrides of tenant branding
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->boolean('tax_inclusive')->default(true);
            $table->string('status', 20)->default('active'); // active|inactive
            $table->boolean('booking_enabled')->default(true);
            $table->boolean('shop_pickup_enabled')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('branch_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name')->nullable();
            $table->time('opens_at')->nullable(); // partial day; null = closed all day
            $table->time('closes_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'date']);
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['branch_id', 'user_id']);
        });

        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 30); // chair|room|pedicure_station|wash_basin|bed
            $table->unsignedInteger('capacity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('name');
            $table->json('title')->nullable();
            $table->json('bio')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('color', 9)->default('#C8A96A');
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('ratings_count')->default(0);
            $table->boolean('is_bookable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branch_staff', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->primary(['branch_id', 'staff_id']);
        });

        Schema::create('staff_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0=Sunday .. 6=Saturday
            $table->time('starts_at');
            $table->time('ends_at');
            $table->json('breaks')->nullable(); // [["13:00","14:00"]]
            $table->timestamps();
            $table->index(['staff_id', 'branch_id', 'weekday']);
        });

        Schema::create('time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('type', 20)->default('leave'); // leave|sick|block|training
            $table->string('reason')->nullable();
            $table->string('status', 20)->default('pending'); // pending|approved|rejected
            $table->foreignId('approved_by')->nullable();
            $table->timestamps();
            $table->index(['staff_id', 'starts_at']);
        });

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->cascadeOnDelete(); // null = default for all
            $table->string('applies_to', 20); // service|product|package|all
            $table->nullableMorphs('target', 'commission_target_idx'); // null = any item of applies_to
            $table->string('type', 10)->default('percent'); // percent|fixed
            $table->unsignedBigInteger('value'); // basis points (1500 = 15%) or minor units
            $table->json('tiers')->nullable(); // [{"from":0,"value":1000},{"from":2000000,"value":1500}]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['commission_rules', 'time_off', 'staff_schedules', 'branch_staff', 'staff', 'resources', 'branch_user', 'branch_holidays', 'branches'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
