<?php

namespace App\Domain\CRM\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Loyalty\Models\LoyaltyEntry;
use App\Domain\Payments\Models\WalletTransaction;
use App\Domain\Team\Models\Staff;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * A salon's end customer. Authenticatable on the salon website / app (guard "client").
 */
class Client extends Authenticatable
{
    use BelongsToTenant, HasApiTokens, Notifiable, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'allergies'];

    public const CHANNELS = ['whatsapp', 'sms', 'email', 'push'];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'last_visit_at' => 'datetime',
            'allergies' => 'encrypted',
            'is_vip' => 'boolean',
            'is_blocked' => 'boolean',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->phone = static::normalizePhone($c->phone);
            $c->referral_code ??= strtoupper(Str::random(8));
        });
    }

    /** Normalize to E.164, defaulting to Qatar (+974) for local 8-digit numbers. */
    public static function normalizePhone(?string $phone, string $defaultCode = '974'): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (str_starts_with((string) $phone, '+')) {
            return '+'.$digits;
        }
        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }
        if (strlen($digits) <= 8) {
            return '+'.$defaultCode.$digits;
        }

        return '+'.$digits;
    }

    public function routeNotificationForWhatsapp(): string
    {
        return $this->phone;
    }

    public function preferredBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'preferred_branch_id');
    }

    public function preferredStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'preferred_staff_id');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(ClientConsent::class);
    }

    public function notesTimeline(): HasMany
    {
        return $this->hasMany(ClientNote::class)->latest();
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ClientPhoto::class)->latest();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest('id');
    }

    public function loyaltyEntries(): HasMany
    {
        return $this->hasMany(LoyaltyEntry::class)->latest('id');
    }

    public function hasConsent(string $channel): bool
    {
        $consent = $this->relationLoaded('consents')
            ? $this->consents->firstWhere('channel', $channel)
            : $this->consents()->where('channel', $channel)->first();

        return (bool) $consent?->granted;
    }

    public function setConsent(string $channel, bool $granted, string $source = 'dashboard', ?string $ip = null): void
    {
        $this->consents()->updateOrCreate(['channel' => $channel], [
            'tenant_id' => $this->tenant_id, 'granted' => $granted, 'source' => $source, 'ip' => $ip,
        ]);
        $this->unsetRelation('consents');
    }

    public function scopeWithConsent($q, string $channel)
    {
        return $q->whereHas('consents', fn ($c) => $c->where('channel', $channel)->where('granted', true));
    }

    public function firstName(): string
    {
        return Str::before(trim($this->name), ' ');
    }
}
