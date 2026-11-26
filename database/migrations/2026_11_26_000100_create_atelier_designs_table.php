<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Live Atelier designs a client saved (settings only, never a photo). Booked via /book?look={reference}.
        Schema::create('atelier_designs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('client_id')->nullable()->index();
            $table->string('device_fingerprint', 64)->nullable()->index();
            $table->string('reference', 20)->unique();
            $table->string('category', 20);
            $table->json('params');
            $table->string('summary', 190);
            $table->foreignId('service_id')->nullable();
            $table->timestamps();
        });

        // An AI render made from the Atelier keeps its design next to the photos.
        Schema::table('client_looks', function (Blueprint $table) {
            $table->json('atelier')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('client_looks', fn (Blueprint $table) => $table->dropColumn('atelier'));
        Schema::dropIfExists('atelier_designs');
    }
};
