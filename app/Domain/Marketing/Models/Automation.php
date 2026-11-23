<?php

namespace App\Domain\Marketing\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * steps (executed top to bottom by AutomationEngine):
 *   {"type":"wait","hours":24}
 *   {"type":"condition","check":"not_visited_since_start"|"has_consent"|"no_booking_since_start"|"tier_is"|"has_tag","value":..}  (stops the run when false)
 *   {"type":"send_message","channels":["whatsapp","sms"],"template":null,"body":{"en":"..","ar":".."},"subject":{"en":..}}
 *   {"type":"award_points","points":100}
 *   {"type":"add_tag","tag":"Win-back"}
 *   {"type":"coupon","discount_bp":1500,"valid_days":14}  (personal one-time code, available as {code} afterwards)
 */
class Automation extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected $attributes = ['is_active' => false, 'runs_count' => 0];

    protected function casts(): array
    {
        return ['steps' => 'array', 'trigger_config' => 'array', 'is_active' => 'boolean', 'last_run_at' => 'datetime'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->trigger_config ?? [], $key, $default);
    }
}
