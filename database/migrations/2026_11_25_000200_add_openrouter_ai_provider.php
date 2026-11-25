<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing installs: add a platform OpenRouter provider (keyless until the super admin pastes a key, so it is
 * never routed before then) and slot it right after Gemini in the image tasks' preferred providers.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fresh installs get their providers from the seeder; only touch platforms that already have some.
        if (DB::table('ai_providers')->whereNull('tenant_id')->exists()
            && ! DB::table('ai_providers')->whereNull('tenant_id')->where('driver', 'openrouter')->exists()) {
            DB::table('ai_providers')->insert([
                'driver' => 'openrouter', 'name' => 'OpenRouter (Gemini images)', 'priority' => 2,
                'capabilities' => json_encode(['image', 'text']), 'unit_costs' => json_encode(['image' => 4, 'text' => 1]),
                'timeout_seconds' => 120, 'enabled' => true, 'health_status' => 'healthy',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (DB::table('ai_task_prices')->where('capability', 'image')->get(['id', 'preferred_providers']) as $row) {
            $preferred = json_decode((string) $row->preferred_providers, true) ?: [];
            if (in_array('openrouter', $preferred, true)) {
                continue;
            }
            $at = array_search('gemini', $preferred, true);
            array_splice($preferred, $at === false ? 0 : $at + 1, 0, ['openrouter']);
            DB::table('ai_task_prices')->where('id', $row->id)->update(['preferred_providers' => json_encode($preferred)]);
        }
    }

    public function down(): void
    {
        DB::table('ai_providers')->whereNull('tenant_id')->where('driver', 'openrouter')->whereNull('api_key')->delete();
    }
};
