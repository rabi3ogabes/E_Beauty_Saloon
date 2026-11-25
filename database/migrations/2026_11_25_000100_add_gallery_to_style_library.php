<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('style_library', function (Blueprint $table) {
            // Extra example photos (public disk paths), shown in the Studio look viewer after reference_path.
            $table->json('gallery')->nullable()->after('reference_path');
            // Shown on example photos that are illustrations rather than the salon's own work.
            $table->boolean('illustrative')->default(false)->after('gallery');
        });
    }

    public function down(): void
    {
        Schema::table('style_library', function (Blueprint $table) {
            $table->dropColumn(['gallery', 'illustrative']);
        });
    }
};
