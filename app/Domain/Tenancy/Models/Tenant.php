<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Branches\Models\Branch;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Tenant extends Model
{
    use LogsActivity, SoftDeletes;

    protected $guarded = ['id'];

    /** Default branding tokens — the "quiet luxury" palette. */
    public const DEFAULT_BRANDING = [
        'primary' => '#C8A96A',
        'accent' => '#E9D6CF',
        'ink' => '#1C1A17',
        'background' => '#FAF7F2',
        'font_display' => 'Playfair Display',
        'font_body' => 'Inter',
        'button_style' => 'pill',
        'theme' => 'maison',
        'logo_path' => null,
        'logo_dark_path' => null,
        'favicon_path' => null,
        'hero_path' => null,
        'about' => null,
        'social' => [],
        'receipt_footer' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'trial_ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'branding' => 'array',
            'settings' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'status', 'slug', 'trial_ends_at'])->logOnlyDirty();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function primaryDomain(): HasOne
    {
        return $this->hasOne(Domain::class)->where('is_primary', true);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class)->withoutGlobalScopes();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** The most recent subscription that is not cancelled/expired. */
    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereNotIn('status', [SubscriptionStatus::Cancelled->value, SubscriptionStatus::Expired->value, SubscriptionStatus::Pending->value])
            ->latestOfMany('starts_at');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Module keys this tenant is entitled to right now. */
    public function entitledModules(): array
    {
        $sub = $this->currentSubscription;

        if (! $sub || ! in_array($sub->status, SubscriptionStatus::entitled(), true)) {
            return [];
        }

        return array_values($sub->modules ?? []);
    }

    public function hasModule(string $key): bool
    {
        return in_array($key, $this->entitledModules(), true);
    }

    public function branchLimit(): int
    {
        return (int) ($this->currentSubscription?->branches_count ?? 1);
    }

    public function brand(string $key, mixed $default = null): mixed
    {
        return data_get(array_replace(self::DEFAULT_BRANDING, $this->branding ?? []), $key, $default);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public function url(string $path = ''): string
    {
        $domain = $this->primaryDomain ?? $this->domains()->first();

        return $domain ? $domain->url($path) : url($path);
    }

    public function isReadOnly(): bool
    {
        return $this->status === TenantStatus::Grace;
    }
}
