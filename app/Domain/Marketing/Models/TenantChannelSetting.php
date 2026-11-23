<?php

namespace App\Domain\Marketing\Models;

use App\Support\Exceptions\DomainException;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A salon's own credentials for a channel, or "platform_shared" mode (billed from the message credit wallet). */
class TenantChannelSetting extends Model
{
    use BelongsToTenant;

    public const MODES = ['own', 'platform_shared'];

    public const PROVIDERS = [
        'whatsapp' => ['meta'],
        'sms' => ['twilio', 'unifonic', 'http'],
        'email' => ['mail', 'ses'],
        'push' => ['fcm'],
    ];

    protected $guarded = ['id'];

    protected $attributes = ['mode' => 'own', 'is_active' => true];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'is_active' => 'boolean', 'last_test_ok' => 'boolean', 'last_tested_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $s) {
            if ($s->channel === 'whatsapp') {
                $s->external_id = data_get($s->credentials, 'phone_number_id');
                // A WhatsApp number belongs to exactly one salon.
                if ($s->external_id && static::withoutGlobalScopes()->where('channel', 'whatsapp')->where('external_id', $s->external_id)
                    ->where('tenant_id', '!=', $s->tenant_id ?? tenant()?->id)->exists()) {
                    throw new DomainException(__('marketing.channel_settings.number_taken'));
                }
            }
        });
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials ?? [], $key, $default);
    }

    public function isShared(): bool
    {
        return $this->mode === 'platform_shared';
    }
}
